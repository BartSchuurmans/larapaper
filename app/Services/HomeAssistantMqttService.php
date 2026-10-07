<?php

namespace App\Services;

use App\Enums\FirmwareModel;
use App\Models\Device;
use App\Models\Firmware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Describes LaraPaper's devices to Home Assistant through MQTT discovery, and applies
 * the controls Home Assistant sends back (see the mqtt:publish command).
 *
 * Every device with a MAC address becomes one Home Assistant device with battery,
 * charging, Wi-Fi signal, firmware, last seen, an online sensor, the screen it shows and
 * the latest reading of each attached sensor. Controls: sleep mode and its times, the
 * refresh interval and installing the latest firmware, which the device picks up at its
 * next request.
 */
class HomeAssistantMqttService
{
    // A device that hasn't asked for a screen in twice its refresh interval plus this
    // long is offline
    private const int OFFLINE_GRACE_SECONDS = 300;

    // The device page's defaults when sleep mode is turned on without times
    private const string SLEEP_MODE_FROM = '22:00';

    private const string SLEEP_MODE_TO = '06:00';

    private const int REFRESH_INTERVAL_MIN = 60;

    private const int REFRESH_INTERVAL_MAX = 86400;

    private const string TIME_PATTERN = '^([01][0-9]|2[0-3]):[0-5][0-9]$';

    public function __construct(
        private readonly DeviceSensorService $sensors,
        private readonly DeviceImageResolver $images,
    ) {}

    /**
     * Tells this LaraPaper's devices apart from another's on the same broker.
     */
    public function instance(): string
    {
        return mb_substr(hash('sha256', (string) config('app.key')), 0, 8);
    }

    /**
     * Topic of this LaraPaper's states and controls.
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
        $result = [];
        foreach (Device::query()->with(['deviceModel', 'mirrorDevice'])->get() as $device) {
            $mac = mb_strtolower((string) $device->mac_address);
            if (! preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
                continue;
            }
            $id = "larapaper_{$this->instance()}_".str_replace(':', '', $mac);
            $topic = $this->baseTopic().'/'.str_replace(':', '', $mac);

            $result[$id] = $this->describe($device, $id, $mac, $topic);
        }

        return $result;
    }

    /**
     * Applies a control from Home Assistant to the device with this MAC address (12 hex
     * digits), the way the device page does. Returns whether it changed anything.
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
                    'sleep_mode_from' => $device->sleep_mode_from?->format('H:i') ?? self::SLEEP_MODE_FROM,
                    'sleep_mode_to' => $device->sleep_mode_to?->format('H:i') ?? self::SLEEP_MODE_TO,
                ]
                : ['sleep_mode_enabled' => false],
            'sleep_mode_from', 'sleep_mode_to' => preg_match('/'.self::TIME_PATTERN.'/', $payload) ? [$key => $payload] : [],
            'refresh_interval' => is_numeric($payload)
                ? ['default_refresh_interval' => max(self::REFRESH_INTERVAL_MIN, min(self::REFRESH_INTERVAL_MAX, (int) round((float) $payload)))]
                : [],
            'firmware' => $payload === 'install' && ($firmware = Firmware::getLatest(FirmwareModel::forDevice($device)))
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
     * @return array{config: array<string, mixed>, state: array<string, mixed>, state_topic: string, screen_topic: string, screen: ?string}
     */
    private function describe(Device $device, string $id, string $mac, string $topic): array
    {
        $lastSeen = $device->last_refreshed_at;
        $interval = max(self::REFRESH_INTERVAL_MIN, (int) $device->default_refresh_interval);
        $online = $lastSeen !== null && ($lastSeen->getTimestamp() + 2 * $interval + self::OFFLINE_GRACE_SECONDS >= now()->getTimestamp()
            || $device->isSleepModeActive() || $device->isPauseActive());
        $firmware = $device->last_firmware_version;
        $latestFirmware = Firmware::getLatest(FirmwareModel::forDevice($device))?->version_tag;
        $onOff = fn (?bool $value): ?string => $value === null ? null : ($value ? 'ON' : 'OFF');

        $state = [
            'battery' => $device->last_battery_voltage === null ? null : $device->battery_percent,
            'battery_voltage' => $device->last_battery_voltage,
            'charging' => $onOff($device->last_battery_charging),
            'usb' => $onOff($device->last_usb_connected),
            'online' => $onOff($online),
            'last_seen' => $lastSeen?->toIso8601String(),
            'rssi' => $device->last_rssi_level,
            'refresh_interval' => $device->default_refresh_interval,
            'sleep_mode' => $onOff((bool) $device->sleep_mode_enabled),
            // Never set: what turning sleep mode on would use
            'sleep_mode_from' => $device->sleep_mode_from?->format('H:i') ?? self::SLEEP_MODE_FROM,
            'sleep_mode_to' => $device->sleep_mode_to?->format('H:i') ?? self::SLEEP_MODE_TO,
            'firmware' => $firmware,
            'latest_firmware' => $latestFirmware ?? $firmware,
            'firmware_installing' => $device->update_firmware_id !== null,
        ];

        $measurement = fn (string $name, array $extra): array => ['p' => 'sensor', 'name' => $name, 'state_class' => 'measurement'] + $extra;
        $diagnostic = ['entity_category' => 'diagnostic'];
        $config = ['entity_category' => 'config'];
        $time = fn (string $name): array => ['p' => 'text', 'name' => $name, 'icon' => 'mdi:clock-outline',
            'min' => 5, 'max' => 5, 'pattern' => self::TIME_PATTERN] + $config;
        $screen = $this->screenPath($device);

        $components = [
            'battery' => $measurement('Battery', ['device_class' => 'battery', 'unit_of_measurement' => '%']),
            'charging' => ['p' => 'binary_sensor', 'name' => 'Charging', 'device_class' => 'battery_charging'],
            'usb' => ['p' => 'binary_sensor', 'name' => 'USB connected', 'device_class' => 'plug'],
            'online' => ['p' => 'binary_sensor', 'name' => 'Online', 'device_class' => 'connectivity'] + $diagnostic,
            'last_seen' => ['p' => 'sensor', 'name' => 'Last seen', 'device_class' => 'timestamp'] + $diagnostic,
            'rssi' => $measurement('Wi-Fi signal', ['device_class' => 'signal_strength', 'unit_of_measurement' => 'dBm'] + $diagnostic),
            'battery_voltage' => $measurement('Battery voltage', ['device_class' => 'voltage', 'unit_of_measurement' => 'V',
                'suggested_display_precision' => 2, 'enabled_by_default' => false] + $diagnostic),
            'screen' => ['p' => 'image', 'name' => 'Screen', 'image_topic' => "$topic/screen",
                'content_type' => $screen !== null && str_ends_with($screen, '.bmp') ? 'image/bmp' : 'image/png'],
            'sleep_mode' => ['p' => 'switch', 'name' => 'Sleep mode', 'icon' => 'mdi:sleep'] + $config,
            'sleep_mode_from' => $time('Sleep from'),
            'sleep_mode_to' => $time('Sleep until'),
            'refresh_interval' => ['p' => 'number', 'name' => 'Refresh interval', 'device_class' => 'duration',
                'unit_of_measurement' => 's', 'min' => self::REFRESH_INTERVAL_MIN, 'max' => self::REFRESH_INTERVAL_MAX,
                'step' => 60, 'mode' => 'box'] + $config,
        ];
        // Once the device has said which firmware it runs (Home Assistant rejects an
        // update entity without an installed version)
        if ($firmware !== null) {
            $components['firmware'] = ['p' => 'update', 'name' => 'Firmware', 'device_class' => 'firmware',
                'payload_install' => 'install',
                'value_template' => "{{ {'installed_version': value_json.firmware, 'latest_version': value_json.latest_firmware,"
                    ." 'in_progress': value_json.firmware_installing} | tojson }}"];
        }
        // Attached sensors (temperature, humidity, ...): the latest reading of each kind
        foreach ($this->sensors->latestPerKind($device) as $kind => $reading) {
            $state[$kind] = $reading['value'];
            $components[$kind] = $measurement($kind === 'carbon_dioxide' ? 'CO2' : Str::ucfirst(str_replace('_', ' ', $kind)),
                ['device_class' => $kind, 'unit_of_measurement' => $this->unit((string) $reading['unit'])]);
        }

        foreach ($components as $key => &$component) {
            $component['unique_id'] = "{$id}_$key";
            if (! in_array($component['p'], ['image', 'button'], true)) {
                $component['value_template'] ??= "{{ value_json.$key }}";
            }
            if (in_array($component['p'], ['switch', 'text', 'number', 'update'], true)) {
                $component['command_topic'] = "$topic/set/$key";
                // Queued by the broker while mqtt:publish is not connected
                $component['qos'] = 1;
            }
        }
        unset($component);

        // The device's page, unless APP_URL is left at a local default
        $appUrl = mb_rtrim((string) config('app.url'), '/');
        $appUrl = in_array(parse_url($appUrl, PHP_URL_HOST), [null, false, 'localhost', '127.0.0.1'], true) ? '' : $appUrl;

        return [
            'config' => [
                'dev' => array_filter([
                    'ids' => [$id],
                    'cns' => [['mac', $mac]],
                    'name' => $device->name ?: $device->friendly_id ?: 'TRMNL',
                    'mf' => 'TRMNL',
                    'mdl' => $device->deviceModel?->label,
                    'sw' => $firmware,
                    'cu' => $appUrl !== '' ? "$appUrl/devices/{$device->id}/configure" : null,
                ], fn ($value): bool => $value !== null),
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

    /**
     * The file of the screen the device was last given (a mirror shows its source's).
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
