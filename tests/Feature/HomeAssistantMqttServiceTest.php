<?php

declare(strict_types=1);

use App\Enums\FirmwareModel;
use App\Models\Device;
use App\Models\Firmware;
use App\Services\HomeAssistantMqttService;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'app.url' => 'https://larapaper.example']);
});

test('it describes a device for Home Assistant', function (): void {
    Carbon::setTestNow('2026-01-01 12:00:00');
    $device = Device::factory()->create([
        'name' => 'Kitchen',
        'mac_address' => 'aa:bb:cc:dd:ee:ff',
        'last_battery_voltage' => 3.6,
        'last_battery_charging' => true,
        'last_rssi_level' => -60,
        'last_firmware_version' => '1.6.0',
        'last_refreshed_at' => now()->subMinutes(5),
        'default_refresh_interval' => 900,
    ]);
    Firmware::factory()->create(['model' => FirmwareModel::Trmnl, 'version_tag' => '1.7.0', 'latest' => true]);

    $service = app(HomeAssistantMqttService::class);
    $id = 'larapaper_'.$service->instance().'_aabbccddeeff';
    $devices = $service->devices();

    expect($devices)->toHaveKey($id);
    $config = $devices[$id]['config'];
    expect($config['dev'])->toMatchArray([
        'ids' => [$id],
        'cns' => [['mac', 'aa:bb:cc:dd:ee:ff']],
        'name' => 'Kitchen',
        'sw' => '1.6.0',
        'cu' => "https://larapaper.example/devices/{$device->id}/configure",
    ])
        ->and($config['stat_t'])->toBe($service->baseTopic().'/aabbccddeeff/state')
        ->and($config['cmps']['battery'])->toMatchArray(['p' => 'sensor', 'device_class' => 'battery', 'unique_id' => "{$id}_battery"])
        ->and($config['cmps']['sleep_mode']['command_topic'])->toBe($service->baseTopic().'/aabbccddeeff/set/sleep_mode')
        ->and($config['cmps']['firmware']['p'])->toBe('update')
        ->and($devices[$id]['state'])->toMatchArray([
            'battery' => 50.0,
            'charging' => 'ON',
            'online' => 'ON',
            'rssi' => -60,
            'firmware' => '1.6.0',
            'latest_firmware' => '1.7.0',
            'firmware_installing' => false,
            'sleep_mode' => 'OFF',
            'sleep_mode_from' => '22:00',
        ]);
});

test('it reports a device as offline when it missed two refreshes', function (): void {
    Device::factory()->create([
        'mac_address' => 'aa:bb:cc:dd:ee:ff',
        'last_refreshed_at' => now()->subHour(),
        'default_refresh_interval' => 300,
    ]);

    $state = collect(app(HomeAssistantMqttService::class)->devices())->first()['state'];

    expect($state['online'])->toBe('OFF');
});

test('it skips devices without a valid MAC address', function (): void {
    Device::factory()->create(['mac_address' => 'not a mac']);

    expect(app(HomeAssistantMqttService::class)->devices())->toBeEmpty();
});

test('it leaves out the firmware update until the device reported its firmware', function (): void {
    Device::factory()->create(['mac_address' => 'aa:bb:cc:dd:ee:ff', 'last_firmware_version' => null]);

    $config = collect(app(HomeAssistantMqttService::class)->devices())->first()['config'];

    expect($config['cmps'])->not->toHaveKey('firmware');
});

test('it applies controls from Home Assistant', function (): void {
    $device = Device::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF', 'sleep_mode_enabled' => false]);
    $firmware = Firmware::factory()->create(['model' => FirmwareModel::Trmnl, 'latest' => true]);
    $service = app(HomeAssistantMqttService::class);

    expect($service->applyCommand('aabbccddeeff', 'sleep_mode', 'ON'))->toBeTrue()
        ->and($service->applyCommand('aabbccddeeff', 'sleep_mode_to', '07:30'))->toBeTrue()
        ->and($service->applyCommand('aabbccddeeff', 'refresh_interval', '10'))->toBeTrue()
        ->and($service->applyCommand('aabbccddeeff', 'firmware', 'install'))->toBeTrue();

    $device->refresh();
    expect($device->sleep_mode_enabled)->toBeTrue()
        ->and($device->sleep_mode_from->format('H:i'))->toBe('22:00')
        ->and($device->sleep_mode_to->format('H:i'))->toBe('07:30')
        ->and($device->default_refresh_interval)->toBe(60)
        ->and($device->update_firmware_id)->toBe($firmware->id);
});

test('it ignores invalid controls and unknown devices', function (): void {
    Device::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
    $service = app(HomeAssistantMqttService::class);

    expect($service->applyCommand('aabbccddeeff', 'sleep_mode_from', '25:00'))->toBeFalse()
        ->and($service->applyCommand('aabbccddeeff', 'refresh_interval', 'soon'))->toBeFalse()
        ->and($service->applyCommand('aabbccddeeff', 'name', 'x'))->toBeFalse()
        ->and($service->applyCommand('001122334455', 'sleep_mode', 'ON'))->toBeFalse();
});
