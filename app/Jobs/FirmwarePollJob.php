<?php

namespace App\Jobs;

use App\Enums\FirmwareModel;
use App\Models\Firmware;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FirmwarePollJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private bool $download = false) {}

    public function handle(): void
    {
        try {
            $firmwareEndpoint = config('services.trmnl.base_url').'/api/firmware/latest';

            $response = Http::get($firmwareEndpoint)->json();

            if (! is_array($response) || ! isset($response['version'], $response['url'])) {
                Log::error('Invalid firmware response format received');

                return;
            }

            $model = FirmwareModel::tryFrom($response['model'] ?? '') ?? FirmwareModel::Trmnl;
            $version = $response['version'];
            $url = $response['url'];

            $this->persistFirmware($model, $version, $url);

            $siblingFirmware = [
                [FirmwareModel::TrmnlX, '_x'],
                [FirmwareModel::TrmnlBwry, '_bwry'],
            ];

            foreach ($siblingFirmware as [$siblingModel, $suffix]) {
                $siblingUrl = FirmwareModel::siblingUrlFromOg($url, $suffix);

                if ($siblingUrl !== null && Http::head($siblingUrl)->successful()) {
                    $this->persistFirmware($siblingModel, $version, $siblingUrl);
                }
            }
        } catch (ConnectionException $e) {
            Log::error('Firmware download failed: '.$e->getMessage());
        } catch (Exception $e) {
            Log::error('Unexpected error in firmware polling: '.$e->getMessage());
        }
    }

    private function persistFirmware(FirmwareModel $model, string $version, string $url): Firmware
    {
        $firmware = Firmware::upsertAsLatest($model, $version, $url);

        if ($this->download && $firmware->needsDownload()) {
            FirmwareDownloadJob::dispatchSync($firmware);
        }

        return $firmware;
    }
}
