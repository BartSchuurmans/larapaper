<?php

use App\Enums\FirmwareModel;
use App\Jobs\FirmwarePollJob;
use App\Models\Firmware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('it creates new firmware record when polling', function (): void {
    $baseUrl = config('services.trmnl.base_url');

    Http::fake([
        $baseUrl.'/api/firmware/latest' => Http::response([
            'version' => '1.0.0',
            'url' => 'https://example.com/firmware.bin',
        ], 200),
    ]);

    (new FirmwarePollJob)->handle();

    expect(Firmware::where('version_tag', '1.0.0')->exists())->toBeTrue()
        ->and(Firmware::where('version_tag', '1.0.0')->first())
        ->url->toBe('https://example.com/firmware.bin')
        ->model->toBe(FirmwareModel::Trmnl)
        ->latest->toBeTrue();
});

test('it updates existing firmware record when polling', function (): void {
    $existingFirmware = Firmware::factory()->trmnl()->create([
        'version_tag' => '1.0.0',
        'url' => 'https://old-url.com/firmware.bin',
        'latest' => true,
    ]);

    $baseUrl = config('services.trmnl.base_url');

    Http::fake([
        $baseUrl.'/api/firmware/latest' => Http::response([
            'version' => '1.0.0',
            'url' => 'https://new-url.com/firmware.bin',
        ], 200),
    ]);

    (new FirmwarePollJob)->handle();

    expect($existingFirmware->fresh())
        ->url->toBe('https://new-url.com/firmware.bin')
        ->latest->toBeTrue();
});

test('it marks previous firmware as not latest when new version is found for the same model', function (): void {
    $oldFirmware = Firmware::factory()->trmnl()->latest()->create([
        'version_tag' => '1.0.0',
    ]);

    $baseUrl = config('services.trmnl.base_url');

    Http::fake([
        $baseUrl.'/api/firmware/latest' => Http::response([
            'version' => '1.1.0',
            'url' => 'https://example.com/firmware.bin',
        ], 200),
    ]);

    (new FirmwarePollJob)->handle();

    expect($oldFirmware->fresh()->latest)->toBeFalse()
        ->and(Firmware::where('version_tag', '1.1.0')->forModel(FirmwareModel::Trmnl)->first()->latest)->toBeTrue();
});

test('it keeps latest flag scoped per model when polling', function (): void {
    $xFirmware = Firmware::factory()->trmnlX()->latest()->create([
        'version_tag' => '1.0.0',
    ]);

    $baseUrl = config('services.trmnl.base_url');

    Http::fake([
        $baseUrl.'/api/firmware/latest' => Http::response([
            'model' => 'trmnl',
            'version' => '1.1.0',
            'url' => 'https://example.com/firmware.bin',
        ], 200),
    ]);

    (new FirmwarePollJob)->handle();

    expect($xFirmware->fresh()->latest)->toBeTrue()
        ->and(Firmware::query()->forModel(FirmwareModel::Trmnl)->latestVersion()->exists())->toBeTrue();
});

test('it discovers TRMNL X firmware when _x URL returns 200', function (): void {
    $baseUrl = config('services.trmnl.base_url');
    $ogUrl = 'https://trmnl-fw.s3.us-east-2.amazonaws.com/trmnl_og/FW1.8.12.bin';
    $xUrl = 'https://trmnl-fw.s3.us-east-2.amazonaws.com/trmnl_x/FW1.8.12.bin';
    $bwryUrl = 'https://trmnl-fw.s3.us-east-2.amazonaws.com/trmnl_bwry/FW1.8.12.bin';

    Http::fake([
        $baseUrl.'/api/firmware/latest' => Http::response([
            'model' => 'trmnl',
            'version' => '1.8.12',
            'url' => $ogUrl,
        ], 200),
        $xUrl => Http::response('', 200),
        $bwryUrl => Http::response('Not Found', 404),
    ]);

    (new FirmwarePollJob)->handle();

    expect(Firmware::query()->forModel(FirmwareModel::Trmnl)->where('version_tag', '1.8.12')->exists())->toBeTrue()
        ->and(Firmware::query()->forModel(FirmwareModel::TrmnlX)->where('version_tag', '1.8.12')->exists())->toBeTrue()
        ->and(Firmware::query()->forModel(FirmwareModel::TrmnlX)->first()->url)->toBe($xUrl)
        ->and(Firmware::query()->forModel(FirmwareModel::TrmnlX)->first()->latest)->toBeTrue();
});

test('it skips TRMNL X firmware when _x URL does not return 200', function (): void {
    $baseUrl = config('services.trmnl.base_url');
    $ogUrl = 'https://trmnl-fw.s3.us-east-2.amazonaws.com/trmnl_og/FW1.8.12.bin';
    $xUrl = 'https://trmnl-fw.s3.us-east-2.amazonaws.com/trmnl_x/FW1.8.12.bin';
    $bwryUrl = 'https://trmnl-fw.s3.us-east-2.amazonaws.com/trmnl_bwry/FW1.8.12.bin';

    Http::fake([
        $baseUrl.'/api/firmware/latest' => Http::response([
            'model' => 'trmnl',
            'version' => '1.8.12',
            'url' => $ogUrl,
        ], 200),
        $xUrl => Http::response('Not Found', 404),
        $bwryUrl => Http::response('Not Found', 404),
    ]);

    (new FirmwarePollJob)->handle();

    expect(Firmware::query()->forModel(FirmwareModel::Trmnl)->where('version_tag', '1.8.12')->exists())->toBeTrue()
        ->and(Firmware::query()->forModel(FirmwareModel::TrmnlX)->exists())->toBeFalse();
});

test('it discovers TRMNL BWRY firmware when _bwry URL returns 200', function (): void {
    $baseUrl = config('services.trmnl.base_url');
    $ogUrl = 'https://trmnl-fw.s3.us-east-2.amazonaws.com/trmnl_og/FW1.8.17.bin';
    $xUrl = 'https://trmnl-fw.s3.us-east-2.amazonaws.com/trmnl_x/FW1.8.17.bin';
    $bwryUrl = 'https://trmnl-fw.s3.us-east-2.amazonaws.com/trmnl_bwry/FW1.8.17.bin';

    Http::fake([
        $baseUrl.'/api/firmware/latest' => Http::response([
            'model' => 'trmnl',
            'version' => '1.8.17',
            'url' => $ogUrl,
        ], 200),
        $xUrl => Http::response('Not Found', 404),
        $bwryUrl => Http::response('', 200),
    ]);

    (new FirmwarePollJob)->handle();

    expect(Firmware::query()->forModel(FirmwareModel::Trmnl)->where('version_tag', '1.8.17')->exists())->toBeTrue()
        ->and(Firmware::query()->forModel(FirmwareModel::TrmnlBwry)->where('version_tag', '1.8.17')->exists())->toBeTrue()
        ->and(Firmware::query()->forModel(FirmwareModel::TrmnlBwry)->first()->url)->toBe($bwryUrl)
        ->and(Firmware::query()->forModel(FirmwareModel::TrmnlBwry)->first()->latest)->toBeTrue();
});

test('it skips TRMNL BWRY firmware when _bwry URL does not return 200', function (): void {
    $baseUrl = config('services.trmnl.base_url');
    $ogUrl = 'https://trmnl-fw.s3.us-east-2.amazonaws.com/trmnl_og/FW1.8.17.bin';
    $xUrl = 'https://trmnl-fw.s3.us-east-2.amazonaws.com/trmnl_x/FW1.8.17.bin';
    $bwryUrl = 'https://trmnl-fw.s3.us-east-2.amazonaws.com/trmnl_bwry/FW1.8.17.bin';

    Http::fake([
        $baseUrl.'/api/firmware/latest' => Http::response([
            'model' => 'trmnl',
            'version' => '1.8.17',
            'url' => $ogUrl,
        ], 200),
        $xUrl => Http::response('Not Found', 404),
        $bwryUrl => Http::response('Not Found', 404),
    ]);

    (new FirmwarePollJob)->handle();

    expect(Firmware::query()->forModel(FirmwareModel::Trmnl)->where('version_tag', '1.8.17')->exists())->toBeTrue()
        ->and(Firmware::query()->forModel(FirmwareModel::TrmnlBwry)->exists())->toBeFalse();
});

test('it handles connection exception gracefully', function (): void {
    $baseUrl = config('services.trmnl.base_url');

    Http::fake([
        $baseUrl.'/api/firmware/latest' => function (): void {
            throw new ConnectionException('Connection failed');
        },
    ]);

    (new FirmwarePollJob)->handle();

    expect(Firmware::count())->toBe(0);
});

test('it handles invalid response gracefully', function (): void {
    $baseUrl = config('services.trmnl.base_url');

    Http::fake([
        $baseUrl.'/api/firmware/latest' => Http::response(null, 200),
    ]);

    (new FirmwarePollJob)->handle();

    expect(Firmware::count())->toBe(0);
});

test('it handles missing version in response gracefully', function (): void {
    $baseUrl = config('services.trmnl.base_url');

    Http::fake([
        $baseUrl.'/api/firmware/latest' => Http::response([
            'url' => 'https://example.com/firmware.bin',
        ], 200),
    ]);

    (new FirmwarePollJob)->handle();

    expect(Firmware::count())->toBe(0);
});

test('it handles missing url in response gracefully', function (): void {
    $baseUrl = config('services.trmnl.base_url');

    Http::fake([
        $baseUrl.'/api/firmware/latest' => Http::response([
            'version' => '1.0.0',
        ], 200),
    ]);

    (new FirmwarePollJob)->handle();

    expect(Firmware::count())->toBe(0);
});
