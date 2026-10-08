<?php

declare(strict_types=1);

use App\Enums\ScaleLevel;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Plugin;
use App\Services\ImageGenerationService;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    Config::set('app.puppeteer_window_size_strategy', 'v2');
});

test('scale level follows the device model when the device has none', function (): void {
    $deviceModel = DeviceModel::factory()->create(['width' => 1872, 'height' => 1404]);
    $device = Device::factory()->create(['device_model_id' => $deviceModel->id, 'scale_level' => null]);

    expect($device->scaleLevel())->toBe('xxlarge');
});

test('scale level set on the device overrides the device model', function (): void {
    $deviceModel = DeviceModel::factory()->create(['width' => 1872, 'height' => 1404]);
    $device = Device::factory()->create(['device_model_id' => $deviceModel->id, 'scale_level' => ScaleLevel::REGULAR]);

    expect($device->scaleLevel())->toBe('regular');
});

test('plugin render uses the device scale level', function (): void {
    $deviceModel = DeviceModel::factory()->create(['width' => 1872, 'height' => 1404]);
    $device = Device::factory()->create(['device_model_id' => $deviceModel->id, 'scale_level' => ScaleLevel::LARGE]);
    $plugin = Plugin::factory()->create([
        'markup_language' => 'blade',
        'render_markup' => '<div>Hello</div>',
    ]);

    expect($plugin->render(device: $device))->toContain('screen--scale-large')
        ->not->toContain('screen--scale-xxlarge');
});

test('cached image metadata no longer matches after the device scale level changes', function (): void {
    $deviceModel = DeviceModel::factory()->create(['width' => 1872, 'height' => 1404]);
    $device = Device::factory()->create(['device_model_id' => $deviceModel->id, 'scale_level' => null]);
    $stored = ImageGenerationService::buildImageMetadataFromDevice($device);

    $device->update(['scale_level' => ScaleLevel::REGULAR]);

    expect(ImageGenerationService::imageMetadataMatches($stored, $device->refresh()))->toBeFalse();
});
