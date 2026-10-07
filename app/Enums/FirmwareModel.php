<?php

namespace App\Enums;

use App\Models\Device;
use Illuminate\Support\Str;

enum FirmwareModel: string
{
    case Trmnl = 'trmnl';
    case TrmnlX = 'trmnl_x';
    case TrmnlBwry = 'trmnl_bwry';

    public function label(): string
    {
        return match ($this) {
            self::Trmnl => 'TRMNL (OG)',
            self::TrmnlX => 'TRMNL X',
            self::TrmnlBwry => 'TRMNL OG (B/W/R/Y)',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $model): array => [$model->value => $model->label()])
            ->all();
    }

    public static function forDevice(Device $device): self
    {
        if ($device->deviceModel?->name === 'og_bwry') {
            return self::TrmnlBwry;
        }

        return $device->usesTouchBar() ? self::TrmnlX : self::Trmnl;
    }

    /**
     * Derive a sibling firmware URL from an OG firmware URL when possible.
     */
    public static function siblingUrlFromOg(string $ogUrl, string $suffix): ?string
    {
        $siblingUrl = Str::replaceFirst('_og', $suffix, $ogUrl);

        return $siblingUrl !== $ogUrl ? $siblingUrl : null;
    }
}
