<?php

namespace App\Http\Resources;

use App\Enums\FirmwareModel;
use App\Models\Firmware;
use App\Services\DeviceImageResolver;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * A device in the shape of TRMNL's Account API (GET /api/devices/{id}), followed by
 * what LaraPaper knows beyond it. The firmware, screen and sensor details take queries
 * of their own, so only a single device has them, not the list.
 *
 * @mixin \App\Models\Device
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lastPingAt = $this->last_refreshed_at?->toIso8601ZuluString();

        $attributes = [
            'id' => $this->id,
            'name' => $this->name,
            'friendly_id' => $this->friendly_id,
            'mac_address' => $this->mac_address,
            'battery_voltage' => $this->last_battery_voltage,
            'rssi' => $this->last_rssi_level,
            'last_ping_at' => $lastPingAt,
            'percent_charged' => $this->battery_percent,
            'wifi_strength' => $this->wifi_strength,
            'hardware_last_ping_at' => $lastPingAt,
            'sleep_mode_enabled' => $this->sleep_mode_enabled,
            'sleep_start_time' => $this->minutesSinceMidnight($this->sleep_mode_from),
            'sleep_end_time' => $this->minutesSinceMidnight($this->sleep_mode_to),
            'sleep_until' => $this->pause_until?->toIso8601ZuluString(),
            'firmware_version' => $this->last_firmware_version,
            'refresh_interval' => $this->default_refresh_interval,
            'battery_charging' => $this->last_battery_charging,
            'usb_connected' => $this->last_usb_connected,
            'update_firmware' => $this->update_firmware,
        ];

        if ($request->route('device') === null) {
            return $attributes;
        }

        return [
            ...$attributes,
            'latest_firmware_version' => Firmware::getLatest(FirmwareModel::forDevice($this->resource))?->version_tag,
            'current_screen_image_url' => $this->currentScreenImageUrl(),
            'sensors' => $this->sensorContext()['latest'],
        ];
    }

    /**
     * The public URL of the screen the device was last given, in the format it was sent.
     */
    private function currentScreenImageUrl(): ?string
    {
        if (! $this->current_screen_image) {
            return null;
        }

        $path = app(DeviceImageResolver::class)->resolve($this->resource, $this->current_screen_image);

        return Storage::disk('public')->exists($path) ? Storage::disk('public')->url($path) : null;
    }

    private function minutesSinceMidnight(?DateTimeInterface $time): ?int
    {
        if (! $time instanceof DateTimeInterface) {
            return null;
        }

        return (int) $time->format('G') * 60 + (int) $time->format('i');
    }
}
