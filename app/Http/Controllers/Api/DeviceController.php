<?php

namespace App\Http\Controllers\Api;

use App\Enums\FirmwareModel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Models\Firmware;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class DeviceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return DeviceResource::collection($request->user()->devices);
    }

    public function show(Request $request, Device $device): DeviceResource
    {
        abort_unless($device->user_id === $request->user()->id, 404);

        return new DeviceResource($device);
    }

    public function update(UpdateDeviceRequest $request, Device $device): DeviceResource
    {
        abort_unless($device->user_id === $request->user()->id, 404);

        $device->update([...$request->deviceAttributes(), ...$this->firmwareUpdate($request, $device)]);

        return new DeviceResource($device->refresh());
    }

    /**
     * Schedule the latest firmware for the device's model, as the device page does, or
     * cancel a scheduled update. The device installs it at its next request.
     *
     * @return array<string, int|null>
     */
    private function firmwareUpdate(UpdateDeviceRequest $request, Device $device): array
    {
        if (! $request->has('update_firmware')) {
            return [];
        }

        if (! $request->boolean('update_firmware')) {
            return ['update_firmware_id' => null];
        }

        $firmware = Firmware::getLatest(FirmwareModel::forDevice($device));

        if (! $firmware instanceof Firmware) {
            throw ValidationException::withMessages(['update_firmware' => 'No firmware is known for this device yet.']);
        }

        return ['update_firmware_id' => $firmware->id];
    }
}
