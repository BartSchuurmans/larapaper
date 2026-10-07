<?php

use App\Enums\FirmwareModel;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Firmware;

test('firmware model labels and options are defined', function (): void {
    expect(FirmwareModel::Trmnl->label())->toBe('TRMNL (OG)')
        ->and(FirmwareModel::TrmnlX->label())->toBe('TRMNL X')
        ->and(FirmwareModel::TrmnlBwry->label())->toBe('TRMNL OG (B/W/R/Y)')
        ->and(FirmwareModel::options())->toBe([
            'trmnl' => 'TRMNL (OG)',
            'trmnl_x' => 'TRMNL X',
            'trmnl_bwry' => 'TRMNL OG (B/W/R/Y)',
        ]);
});

test('firmware model is inferred from device touchbar capability', function (): void {
    $ogModel = DeviceModel::query()->where('name', 'og_png')->first()
        ?? DeviceModel::factory()->create(['name' => 'og_png', 'kind' => 'trmnl']);
    $xModel = DeviceModel::query()->where('name', 'v2')->first()
        ?? DeviceModel::factory()->create(['name' => 'v2', 'kind' => 'trmnl']);

    $ogDevice = Device::factory()->create(['device_model_id' => $ogModel->id]);
    $xDevice = Device::factory()->create(['device_model_id' => $xModel->id]);

    expect(FirmwareModel::forDevice($ogDevice))->toBe(FirmwareModel::Trmnl)
        ->and(FirmwareModel::forDevice($xDevice))->toBe(FirmwareModel::TrmnlX);
});

test('firmware model is inferred for og bwry devices', function (): void {
    $bwryModel = DeviceModel::query()->where('name', 'og_bwry')->first()
        ?? DeviceModel::factory()->create(['name' => 'og_bwry', 'kind' => 'trmnl']);

    $bwryDevice = Device::factory()->create(['device_model_id' => $bwryModel->id]);

    expect(FirmwareModel::forDevice($bwryDevice))->toBe(FirmwareModel::TrmnlBwry);
});

test('sibling firmware urls are derived from og url', function (): void {
    $ogUrl = 'https://trmnl-fw.s3.us-east-2.amazonaws.com/trmnl_og/FW1.8.12.bin';

    expect(FirmwareModel::siblingUrlFromOg($ogUrl, '_x'))
        ->toBe('https://trmnl-fw.s3.us-east-2.amazonaws.com/trmnl_x/FW1.8.12.bin')
        ->and(FirmwareModel::siblingUrlFromOg($ogUrl, '_bwry'))
        ->toBe('https://trmnl-fw.s3.us-east-2.amazonaws.com/trmnl_bwry/FW1.8.12.bin')
        ->and(FirmwareModel::siblingUrlFromOg('https://example.com/firmware.bin', '_x'))
        ->toBeNull();
});

test('firmware upsert marks previous versions for the same model as not latest', function (): void {
    $old = Firmware::factory()->trmnl()->latest()->create(['version_tag' => '1.0.0']);
    $otherModel = Firmware::factory()->trmnlX()->latest()->create(['version_tag' => '1.0.0']);

    $new = Firmware::upsertAsLatest(FirmwareModel::Trmnl, '1.1.0', 'https://example.com/fw.bin');

    expect($new->latest)->toBeTrue()
        ->and($new->model)->toBe(FirmwareModel::Trmnl)
        ->and($old->fresh()->latest)->toBeFalse()
        ->and($otherModel->fresh()->latest)->toBeTrue()
        ->and($new->storagePath())->toBe('firmwares/trmnl/FW1.1.0.bin')
        ->and($new->needsDownload())->toBeTrue();
});
