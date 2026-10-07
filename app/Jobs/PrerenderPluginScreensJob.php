<?php

namespace App\Jobs;

use App\Models\Device;
use App\Models\Playlist;
use App\Models\Plugin;
use App\Services\ImageGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Find the polling recipes in devices' active playlists whose data goes stale
 * soon, and dispatch a PrerenderPluginScreenJob for each, so the display endpoint
 * serves a fresh screen instead of polling and rendering while the device waits.
 *
 * A plugin keeps one screen, so it is rendered for the first device that shows it.
 * Mirrors show their source's screen. Mashups are left to the display cycle,
 * which renders them on every request.
 */
class PrerenderPluginScreensJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Seconds before the data goes stale to render: more than the schedule's
     * interval, so a waking device never finds it stale.
     */
    public const int LEAD_SECONDS = 120;

    /**
     * Shorter refresh intervals are left to the display cycle, as rendering them
     * ahead would not save a render.
     */
    public const int MIN_STALE_MINUTES = 5;

    public function handle(): void
    {
        $seenPluginIds = [];

        Device::with(['deviceModel', 'deviceModel.palette', 'palette', 'user'])
            ->whereNull('mirror_device_id')
            ->orderBy('id')
            ->each(function (Device $device) use (&$seenPluginIds): void {
                if ($this->isAsleep($device)) {
                    return;
                }

                $playlists = $device->playlists()->where('is_active', true)->get()
                    ->filter(fn (Playlist $playlist): bool => $playlist->isActiveNow());

                foreach ($playlists as $playlist) {
                    foreach ($playlist->getActiveItems() as $item) {
                        $plugin = $item->isMashup() ? null : $item->plugin;

                        if (! $plugin instanceof Plugin || isset($seenPluginIds[$plugin->id])) {
                            continue;
                        }

                        $seenPluginIds[$plugin->id] = true;

                        if ($this->isDue($plugin, $device)) {
                            PrerenderPluginScreenJob::dispatch($plugin, $device);
                        }
                    }
                }
            });
    }

    /**
     * Whether the device is paused, or in sleep mode for longer than the lead time,
     * so the screen is rendered for its wake-up rather than during sleep.
     */
    private function isAsleep(Device $device): bool
    {
        if ($device->isPauseActive()) {
            return true;
        }

        return $device->isSleepModeActive()
            && ($device->getSleepModeEndsInSeconds() ?? 0) > self::LEAD_SECONDS;
    }

    /**
     * Whether a polling recipe has no screen for this device's model yet, or its
     * data goes stale within the lead time.
     */
    private function isDue(Plugin $plugin, Device $device): bool
    {
        if ($plugin->plugin_type !== 'recipe' || $plugin->data_strategy !== 'polling') {
            return false;
        }

        if ((int) $plugin->data_stale_minutes < self::MIN_STALE_MINUTES
            || Cache::has(PrerenderPluginScreenJob::failedCacheKey($plugin))) {
            return false;
        }

        if ($plugin->current_image === null || $plugin->data_payload_updated_at === null) {
            return true;
        }

        if (! ImageGenerationService::imageMetadataMatches($plugin->current_image_metadata, $device)) {
            return true;
        }

        return $plugin->data_payload_updated_at->copy()
            ->addMinutes($plugin->data_stale_minutes)
            ->subSeconds(self::LEAD_SECONDS)
            ->isPast();
    }
}
