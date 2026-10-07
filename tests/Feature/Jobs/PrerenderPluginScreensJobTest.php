<?php

use App\Jobs\PrerenderPluginScreenJob;
use App\Jobs\PrerenderPluginScreensJob;
use App\Models\Device;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Plugin;
use App\Services\ImageGenerationService;
use Bnussbau\EpaperPipeline\EpaperPipeline;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    EpaperPipeline::fake();
    Storage::fake('public');
    Storage::disk('public')->makeDirectory('/images/generated');
});

/**
 * Create a device with an always-active playlist showing one polling recipe.
 *
 * @param  array<string, mixed>  $pluginAttributes
 * @param  array<string, mixed>  $deviceAttributes
 * @param  array<string, mixed>  $playlistAttributes
 * @return array{0: Device, 1: Plugin}
 */
function deviceShowingPollingRecipe(array $pluginAttributes = [], array $deviceAttributes = [], array $playlistAttributes = []): array
{
    $device = Device::factory()->create(['proxy_cloud' => false, ...$deviceAttributes]);
    $plugin = Plugin::factory()->create([
        'plugin_type' => 'recipe',
        'data_strategy' => 'polling',
        'polling_url' => 'https://example.com/data',
        'polling_verb' => 'get',
        'data_stale_minutes' => 15,
        'data_payload_updated_at' => null,
        'markup_language' => 'liquid',
        'render_markup' => '<div>{{ value }}</div>',
        ...$pluginAttributes,
    ]);
    $playlist = Playlist::factory()->create([
        'device_id' => $device->id,
        'is_active' => true,
        'weekdays' => null,
        'active_from' => null,
        'active_until' => null,
        ...$playlistAttributes,
    ]);
    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'plugin_id' => $plugin->id,
        'order' => 1,
        'is_active' => true,
    ]);

    return [$device, $plugin];
}

/**
 * Give the plugin a screen made for the device, with data polled the given minutes ago.
 */
function renderedForDevice(Plugin $plugin, Device $device, int $minutesAgo): void
{
    $plugin->update([
        'current_image' => 'current-image',
        'current_image_metadata' => ImageGenerationService::buildImageMetadataFromDevice($device),
        'data_payload_updated_at' => now()->subMinutes($minutesAgo),
    ]);
}

test('it dispatches a render for a polling recipe without a screen', function (): void {
    [$device, $plugin] = deviceShowingPollingRecipe();
    Queue::fake([PrerenderPluginScreenJob::class]);

    PrerenderPluginScreensJob::dispatchSync();

    Queue::assertPushed(
        PrerenderPluginScreenJob::class,
        fn (PrerenderPluginScreenJob $job): bool => $job->plugin->is($plugin) && $job->device->is($device),
    );
});

test('it dispatches a render when the data goes stale within the lead time', function (): void {
    $this->freezeTime();
    [$device, $plugin] = deviceShowingPollingRecipe();
    renderedForDevice($plugin, $device, minutesAgo: 14);
    Queue::fake([PrerenderPluginScreenJob::class]);

    PrerenderPluginScreensJob::dispatchSync();

    Queue::assertPushed(PrerenderPluginScreenJob::class);
});

test('it does not dispatch a render while the data stays fresh beyond the lead time', function (): void {
    $this->freezeTime();
    [$device, $plugin] = deviceShowingPollingRecipe();
    renderedForDevice($plugin, $device, minutesAgo: 12);
    Queue::fake([PrerenderPluginScreenJob::class]);

    PrerenderPluginScreensJob::dispatchSync();

    Queue::assertNotPushed(PrerenderPluginScreenJob::class);
});

test('it dispatches a render when the screen was made for another device model', function (): void {
    [, $plugin] = deviceShowingPollingRecipe([
        'current_image' => 'current-image',
        'current_image_metadata' => ['width' => 1, 'height' => 1, 'rotation' => 0, 'palette_id' => null, 'mime_type' => 'image/png'],
        'data_payload_updated_at' => now(),
    ]);
    Queue::fake([PrerenderPluginScreenJob::class]);

    PrerenderPluginScreensJob::dispatchSync();

    Queue::assertPushed(PrerenderPluginScreenJob::class);
});

test('it does not dispatch a render for recipes and devices the display cycle handles', function (array $pluginAttributes, array $deviceAttributes, array $playlistAttributes): void {
    $this->travelTo('2026-10-07 12:00:00');
    deviceShowingPollingRecipe($pluginAttributes, $deviceAttributes, $playlistAttributes);
    Queue::fake([PrerenderPluginScreenJob::class]);

    PrerenderPluginScreensJob::dispatchSync();

    Queue::assertNotPushed(PrerenderPluginScreenJob::class);
})->with([
    'webhook recipe' => [['data_strategy' => 'webhook'], [], []],
    'refresh interval under five minutes' => [['data_stale_minutes' => 4], [], []],
    'paused device' => [[], ['pause_until' => '2026-10-07 13:00:00'], []],
    'inactive playlist' => [[], [], ['is_active' => false]],
    'playlist outside its active hours' => [[], [], ['active_from' => '00:00', 'active_until' => '00:01']],
]);

test('it dispatches a render for a sleeping device only shortly before sleep mode ends', function (string $time, bool $dispatches): void {
    $this->travelTo("2026-10-07 {$time}:00");
    deviceShowingPollingRecipe(deviceAttributes: [
        'sleep_mode_enabled' => true,
        'sleep_mode_from' => '01:00',
        'sleep_mode_to' => '06:00',
    ]);
    Queue::fake([PrerenderPluginScreenJob::class]);

    PrerenderPluginScreensJob::dispatchSync();

    Queue::assertPushedTimes(PrerenderPluginScreenJob::class, $dispatches ? 1 : 0);
})->with([
    'hours before waking' => ['03:00', false],
    'a minute before waking' => ['05:59', true],
]);

test('it does not dispatch a render for a recipe whose last pre-render failed', function (): void {
    [, $plugin] = deviceShowingPollingRecipe();
    Cache::put(PrerenderPluginScreenJob::failedCacheKey($plugin), true, now()->addMinutes(15));
    Queue::fake([PrerenderPluginScreenJob::class]);

    PrerenderPluginScreensJob::dispatchSync();

    Queue::assertNotPushed(PrerenderPluginScreenJob::class);
});

test('it dispatches one render for a recipe that several devices show', function (): void {
    [, $plugin] = deviceShowingPollingRecipe();
    $otherDevice = Device::factory()->create(['proxy_cloud' => false]);
    $otherPlaylist = Playlist::factory()->create(['device_id' => $otherDevice->id, 'is_active' => true, 'weekdays' => null, 'active_from' => null, 'active_until' => null]);
    PlaylistItem::factory()->create(['playlist_id' => $otherPlaylist->id, 'plugin_id' => $plugin->id, 'order' => 1, 'is_active' => true]);
    Queue::fake([PrerenderPluginScreenJob::class]);

    PrerenderPluginScreensJob::dispatchSync();

    Queue::assertPushedTimes(PrerenderPluginScreenJob::class, 1);
});

test('it lets the display endpoint serve the pre-rendered screen without polling', function (): void {
    $this->freezeSecond();
    Http::preventStrayRequests();
    Http::fake(['https://example.com/data' => Http::response(['value' => 42])]);
    [$device, $plugin] = deviceShowingPollingRecipe();
    PrerenderPluginScreensJob::dispatchSync();
    $this->travel(14)->minutes();
    PrerenderPluginScreensJob::dispatchSync();
    $prerenderedAt = now();
    $this->travel(2)->minutes();

    $this->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'rssi' => -70,
        'battery_voltage' => 3.8,
        'fw-version' => '1.0.0',
    ])->get('/api/display')->assertOk();

    $plugin->refresh();
    expect($plugin->data_payload_updated_at->equalTo($prerenderedAt))->toBeTrue()
        ->and($device->refresh()->current_screen_image)->toBe($plugin->current_image);
    Http::assertSentCount(2);
});
