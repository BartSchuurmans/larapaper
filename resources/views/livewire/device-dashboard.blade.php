<?php

use Livewire\Component;

new class extends Component
{
    public function mount()
    {
        return view('livewire.device-dashboard', ['devices' => auth()->user()->devices()->paginate(10)]);
    }
}
?>

<div>
    <div class="bg-muted flex flex-col items-center justify-center p-4 sm:p-6">
        <div class="mx-auto flex w-full max-w-3xl flex-col gap-4">
            @if ($devices->isEmpty())
                <flux:card>
                    <flux:card.header>
                        <flux:card.heading>Add your first device</flux:card.heading>
                    </flux:card.header>

                    <flux:card.body>
                        <flux:button
                            href="{{ route('devices') }}"
                            class="mt-2 w-full sm:w-auto"
                            icon="plus-circle"
                            variant="primary"
                        >
                            Add Device
                        </flux:button>
                    </flux:card.body>
                </flux:card>
            @endif

            @foreach ($devices as $device)
                @php
                    $current_image_uuid = $device->current_screen_image;
                    if ($current_image_uuid) {
                        $file_extension = Storage::disk('public')->exists('images/generated/'.$current_image_uuid.'.png') ? 'png' : 'bmp';
                        $current_image_url = Storage::disk('public')->url('images/generated/'.$current_image_uuid.'.'.$file_extension);
                    } else {
                        $current_image_url = asset('storage/images/setup-logo.bmp');
                    }
                @endphp

                <flux:card body="divided">
                    <flux:card.header class="items-start sm:items-center">
                        <flux:card.heading class="w-full min-w-0 flex-1">
                            <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center sm:gap-6">
                                <flux:tooltip
                                    content="Friendly ID: {{ $device->friendly_id }}"
                                    position="bottom"
                                    class="min-w-0 shrink-0 sm:max-w-[12rem]"
                                >
                                    <a
                                        href="{{ route('devices.configure', $device) }}"
                                        wire:navigate
                                        class="block truncate text-base font-medium hover:underline"
                                    >{{ $device->name }}</a>
                                </flux:tooltip>

                                <div class="flex w-full flex-wrap items-center justify-evenly gap-x-3 gap-y-2 text-sm font-normal sm:mx-auto sm:flex-1">
                                    <flux:tooltip content="Last refresh" position="bottom" class="mx-auto shrink-0">
                                        <span class="whitespace-nowrap">{{ $device->last_refreshed_at?->diffForHumans() }}</span>
                                    </flux:tooltip>

                                    <flux:tooltip
                                        content="MAC Address"
                                        position="bottom"
                                        class="mx-auto min-w-0 shrink-0"
                                    >
                                        <span class="text-center font-mono text-xs break-all sm:text-sm">{{ $device->mac_address }}</span>
                                    </flux:tooltip>

                                    @if ($device->last_firmware_version)
                                        <flux:tooltip
                                            content="Firmware Version"
                                            position="bottom"
                                            class="mx-auto shrink-0"
                                        >
                                            <span class="whitespace-nowrap">{{ $device->last_firmware_version }}</span>
                                        </flux:tooltip>
                                    @endif

                                    @if ($device->wifiStrength)
                                        <flux:tooltip content="Wi-Fi signal" position="bottom" class="mx-auto shrink-0">
                                            <x-responsive-icons.wifi
                                                :strength="$device->wifiStrength"
                                                :rssi="$device->last_rssi_level"
                                                class="dark:text-zinc-200"
                                            />
                                        </flux:tooltip>
                                    @endif

                                    @if ($device->batteryPercent)
                                        <flux:tooltip content="Battery" position="bottom" class="mx-auto shrink-0">
                                            <x-responsive-icons.battery :percent="$device->batteryPercent" />
                                        </flux:tooltip>
                                    @endif
                                </div>
                            </div>
                        </flux:card.heading>

                        <flux:card.actions>
                            <flux:dropdown>
                                <flux:button icon="ellipsis-horizontal" aria-label="Device actions" />
                                <flux:menu>
                                    <flux:menu.item icon="eye" href="{{ route('devices.configure', $device) }}">
                                        View
                                    </flux:menu.item>
                                    <flux:menu.item
                                        icon="bars-3"
                                        href="{{ route('devices.logs', $device) }}"
                                        wire:navigate
                                    >
                                        Show Logs
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:card.actions>
                    </flux:card.header>

                    <flux:card.body>
                        @if ($device->mirror_device_id)
                            <flux:callout variant="info">
                                <div class="flex items-center gap-2">
                                    <flux:icon.link />
                                    <flux:text>
                                        This device is mirrored from
                                        <a
                                            href="{{ route('devices.configure', $device->mirrorDevice) }}"
                                            wire:navigate
                                            class="font-medium hover:underline"
                                        >
                                            {{ $device->mirrorDevice->name }}
                                        </a>
                                    </flux:text>
                                </div>
                            </flux:callout>
                        @elseif ($current_image_url)
                            <div class="flex justify-center overflow-hidden rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                                <div class="relative origin-center rotate-[{{ $device->preview_rotation }}deg]">
                                    <img
                                        src="{{ $current_image_url }}"
                                        class="max-h-[min(480px,70vh)] max-w-full object-contain"
                                        alt="Current screen"
                                    />
                                </div>
                            </div>
                        @endif
                    </flux:card.body>
                </flux:card>
            @endforeach
        </div>
    </div>
</div>
