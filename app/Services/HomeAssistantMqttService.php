<?php

namespace App\Services;

use App\Enums\FirmwareModel;
use App\Models\Device;
use App\Models\Firmware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Describes devices to Home Assistant through MQTT discovery and applies the controls
 * Home Assistant sends back (see the mqtt:publish command).
 *
 * Every device with a MAC address becomes one Home Assistant device with battery,
 * charging, Wi-Fi signal, firmware, last seen, an online sensor, the screen it shows and
 * the latest reading of each attached sensor. Its controls (sleep mode and its times, the
 * refresh interval, installing the latest firmware) change the device the way the device
 * page does, so the device picks them up at its next request.
 */
class HomeAssistantMqttService
{
    /**
     * A device that hasn't asked for a screen in twice its refresh interval plus this
     * long is offline.
     */
    private const int OFFLINE_GRACE_SECONDS = 300;

    /**
     * What the device page uses when sleep mode is turned on without times.
     */
    private const string DEFAULT_SLEEP_MODE_FROM = '22:00';

    private const string DEFAULT_SLEEP_MODE_TO = '06:00';

    private const int MIN_REFRESH_INTERVAL = 60;

    private const int MAX_REFRESH_INTERVAL = 86400;

    private const string TIME_PATTERN = '^([01][0-9]|2[0-3]):[0-5][0-9]$';

    /**
     * Component platforms Home Assistant can send commands for.
     */
    private const array COMMAND_PLATFORMS = ['switch', 'text', 'number', 'update'];

    public function __construct(
        private readonly DeviceSensorService $sensors,
        private readonly DeviceImageResolver $images,
    ) {}

    /**
     * Tells this installation's devices apart from another's on the same broker.
     */
    public function instance(): string
    {
        return mb_substr(hash('sha256', (string) config('app.key')), 0, 8);
    }

    /**
     * The topic under which this installation's states and controls live.
     */
    public function baseTopic(): string
    {
        return 'larapaper/'.$this->instance();
    }

    /**
     * The discovery config, state and screen of every device with a MAC address, by
     * Home Assistant device id.
     *
     * @return array<string, array{config: array<string, mixed>, state: array<string, mixed>, state_topic: string, screen_topic: string, screen: ?string}>
     */
    public function devices(): array
    {
        $devices = [];

        foreach (Device::query()->with(['deviceModel', 'mirrorDevice'])->get() as $device) {
            $mac = mb_strtolower((string) $device->mac_address);
            if (! preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
                continue;
            }

            $id = "larapaper_{$this->instance()}_".str_replace(':', '', $mac);
            $topic = $this->baseTopic().'/'.str_replace(':', '', $mac);
            $screen = $this->screenPath($device);
            $state = $this->state($device);
            $components = $this->components($device, $id, $topic, $screen);

            foreach ($this->sensors->latestPerKind($device) as $kind => $reading) {
                $state[$kind] = $reading['value'];
                $components[$kind] = $this->sensorComponent($id, $kind, (string) $reading['unit']);
            }

            $devices[$id] = [
                'config' => [
                    'dev' => $this->deviceInfo($device, $id, $mac),
                    'o' => array_filter(['name' => 'LaraPaper', 'sw' => config('app.version'), 'url' => 'https://github.com/usetrmnl/larapaper']),
                    'cmps' => $components,
                    'stat_t' => "$topic/state",
                ],
                'state' => $state,
                'state_topic' => "$topic/state",
                'screen_topic' => "$topic/screen",
                'screen' => $screen,
            ];
        }

        return $devices;
    }

    /**
     * Applies a control from Home Assistant to the device with this MAC address (12 hex
     * digits) and returns whether it changed anything.
     */
    public function applyCommand(string $mac, string $key, string $payload): bool
    {
        $device = Device::query()->where('mac_address', mb_strtoupper(implode(':', mb_str_split($mac, 2))))->first();
        if ($device === null) {
            return false;
        }

        $changes = match ($key) {
            'sleep_mode' => $payload === 'ON'
                ? [
                    'sleep_mode_enabled' => true,
                    'sleep_mode_from' => $device->sleep_mode_from?->format('H:i') ?? self::DEFAULT_SLEEP_MODE_FROM,
                    'sleep_mode_to' => $device->sleep_mode_to?->format('H:i') ?? self::DEFAULT_SLEEP_MODE_TO,
                ]
                : ['sleep_mode_enabled' => false],
            'sleep_mode_from', 'sleep_mode_to' => preg_match('/'.self::TIME_PATTERN.'/', $payload) ? [$key => $payload] : [],
            'refresh_interval' => is_numeric($payload)
                ? ['default_refresh_interval' => max(self::MIN_REFRESH_INTERVAL, min(self::MAX_REFRESH_INTERVAL, (int) round((float) $payload)))]
                : [],
            'firmware' => $payload === 'install' && ($firmware = $this->latestFirmware(FirmwareModel::forDevice($device)))
                ? ['update_firmware_id' => $firmware->id]
                : [],
            default => [],
        };

        if ($changes === []) {
            return false;
        }

        $device->update($changes);

        return true;
    }

    /**
     * The device's state, as the components' value templates read it. Sleep mode times
     * that were never set are the ones turning sleep mode on would use.
     *
     * @return array<string, mixed>
     */
    private function state(Device $device): array
    {
        $lastSeen = $device->last_refreshed_at;
        $interval = max(self::MIN_REFRESH_INTERVAL, (int) $device->default_refresh_interval);
        $isOnline = $lastSeen !== null
            && ($lastSeen->getTimestamp() + 2 * $interval + self::OFFLINE_GRACE_SECONDS >= now()->getTimestamp()
                || $device->isSleepModeActive()
                || $device->isPauseActive());
        $firmware = $device->last_firmware_version;

        return [
            'battery' => $device->last_battery_voltage === null ? null : $device->battery_percent,
            'battery_voltage' => $device->last_battery_voltage,
            'charging' => $this->onOff($device->last_battery_charging),
            'usb' => $this->onOff($device->last_usb_connected),
            'online' => $this->onOff($isOnline),
            'last_seen' => $lastSeen?->toIso8601String(),
            'rssi' => $device->last_rssi_level,
            'refresh_interval' => $device->default_refresh_interval,
            'sleep_mode' => $this->onOff((bool) $device->sleep_mode_enabled),
            'sleep_mode_from' => $device->sleep_mode_from?->format('H:i') ?? self::DEFAULT_SLEEP_MODE_FROM,
            'sleep_mode_to' => $device->sleep_mode_to?->format('H:i') ?? self::DEFAULT_SLEEP_MODE_TO,
            'firmware' => $firmware,
            'latest_firmware' => $this->latestFirmware(FirmwareModel::forDevice($device))->version_tag ?? $firmware,
            'firmware_installing' => $device->update_firmware_id !== null,
        ];
    }

    /**
     * The device's entities. The firmware update appears once the device has reported
     * its firmware, as Home Assistant rejects an update entity without an installed
     * version. Command entities use QoS 1 so the broker keeps their commands while
     * mqtt:publish is not connected.
     *
     * @return array<string, array<string, mixed>>
     */
    private function components(Device $device, string $id, string $topic, ?string $screen): array
    {
        $diagnostic = ['entity_category' => 'diagnostic'];
        $config = ['entity_category' => 'config'];
        $time = fn (string $name): array => ['p' => 'text', 'name' => $name, 'icon' => 'mdi:clock-outline',
            'min' => 5, 'max' => 5, 'pattern' => self::TIME_PATTERN] + $config;

        $components = [
            'battery' => ['p' => 'sensor', 'name' => 'Battery', 'device_class' => 'battery', 'state_class' => 'measurement',
                'unit_of_measurement' => '%'],
            'charging' => ['p' => 'binary_sensor', 'name' => 'Charging', 'device_class' => 'battery_charging'],
            'usb' => ['p' => 'binary_sensor', 'name' => 'USB connected', 'device_class' => 'plug'],
            'online' => ['p' => 'binary_sensor', 'name' => 'Online', 'device_class' => 'connectivity'] + $diagnostic,
            'last_seen' => ['p' => 'sensor', 'name' => 'Last seen', 'device_class' => 'timestamp'] + $diagnostic,
            'rssi' => ['p' => 'sensor', 'name' => 'Wi-Fi signal', 'device_class' => 'signal_strength', 'state_class' => 'measurement',
                'unit_of_measurement' => 'dBm'] + $diagnostic,
            'battery_voltage' => ['p' => 'sensor', 'name' => 'Battery voltage', 'device_class' => 'voltage', 'state_class' => 'measurement',
                'unit_of_measurement' => 'V', 'suggested_display_precision' => 2, 'enabled_by_default' => false] + $diagnostic,
            'screen' => ['p' => 'image', 'name' => 'Screen', 'image_topic' => "$topic/screen",
                'content_type' => $screen !== null && str_ends_with($screen, '.bmp') ? 'image/bmp' : 'image/png'],
            'sleep_mode' => ['p' => 'switch', 'name' => 'Sleep mode', 'icon' => 'mdi:sleep'] + $config,
            'sleep_mode_from' => $time('Sleep from'),
            'sleep_mode_to' => $time('Sleep until'),
            'refresh_interval' => ['p' => 'number', 'name' => 'Refresh interval', 'device_class' => 'duration',
                'unit_of_measurement' => 's', 'min' => self::MIN_REFRESH_INTERVAL, 'max' => self::MAX_REFRESH_INTERVAL,
                'step' => 60, 'mode' => 'box'] + $config,
        ];

        if ($device->last_firmware_version !== null) {
            $components['firmware'] = ['p' => 'update', 'name' => 'Firmware', 'device_class' => 'firmware',
                'payload_install' => 'install',
                'value_template' => "{{ {'installed_version': value_json.firmware, 'latest_version': value_json.latest_firmware,"
                    ." 'in_progress': value_json.firmware_installing} | tojson }}"];
        }

        foreach ($components as $key => $component) {
            $component['unique_id'] = "{$id}_$key";
            if ($component['p'] !== 'image') {
                $component['value_template'] ??= "{{ value_json.$key }}";
            }
            if (in_array($component['p'], self::COMMAND_PLATFORMS, true)) {
                $component['command_topic'] = "$topic/set/$key";
                $component['qos'] = 1;
            }
            $components[$key] = $component;
        }

        return $components;
    }

    /**
     * An attached sensor's entity (temperature, humidity, ...), named after its kind.
     *
     * @return array<string, mixed>
     */
    private function sensorComponent(string $id, string $kind, string $unit): array
    {
        return [
            'p' => 'sensor',
            'name' => $kind === 'carbon_dioxide' ? 'CO2' : Str::ucfirst(str_replace('_', ' ', $kind)),
            'device_class' => $kind,
            'state_class' => 'measurement',
            'unit_of_measurement' => $this->unit($unit),
            'unique_id' => "{$id}_$kind",
            'value_template' => "{{ value_json.$kind }}",
        ];
    }

    /**
     * The Home Assistant device. Its MAC connection merges it with the same TRMNL in other
     * integrations; it links to the device page unless APP_URL is left at a local default.
     *
     * @return array<string, mixed>
     */
    private function deviceInfo(Device $device, string $id, string $mac): array
    {
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return array_filter([
            'ids' => [$id],
            'cns' => [['mac', $mac]],
            'name' => $device->name ?: $device->friendly_id ?: 'TRMNL',
            'mf' => 'TRMNL',
            'mdl' => $device->deviceModel?->label,
            'sw' => $device->last_firmware_version,
            'cu' => in_array($appHost, [null, false, 'localhost', '127.0.0.1'], true) ? null : route('devices.configure', $device),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * The newest firmware known for this model, looked up once per run.
     */
    private function latestFirmware(FirmwareModel $model): ?Firmware
    {
        return once(fn (): ?Firmware => Firmware::getLatest($model));
    }

    /**
     * The file of the screen the device was last given; a mirror shows its source's.
     */
    private function screenPath(Device $device): ?string
    {
        $uuid = $device->mirrorDevice->current_screen_image ?? $device->current_screen_image;
        if (! $uuid) {
            return null;
        }

        $path = $this->images->resolve($device, $uuid);

        return Storage::disk('public')->exists($path) ? Storage::disk('public')->path($path) : null;
    }

    private function onOff(?bool $value): ?string
    {
        return $value === null ? null : ($value ? 'ON' : 'OFF');
    }

    /**
     * Home Assistant's unit for what a sensor reports, where it differs.
     */
    private function unit(string $unit): string
    {
        return match (mb_strtolower($unit)) {
            'c', 'celsius', 'degc' => '°C',
            'f', 'fahrenheit', 'degf' => '°F',
            'pct', 'percent' => '%',
            default => $unit,
        };
    }
}
