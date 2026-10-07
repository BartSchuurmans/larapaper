<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

        $device->update($request->deviceAttributes());

        return new DeviceResource($device->refresh());
    }
}
