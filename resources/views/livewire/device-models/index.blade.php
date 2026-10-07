<?php

use App\Jobs\FetchDeviceModelsJob;
use App\Models\DeviceModel;
use App\Models\DevicePalette;
use Livewire\Component;

new class extends Component
{
    public $deviceModels;

    public $devicePalettes;

    public $name;

    public $label;

    public $description;

    public $width;

    public $height;

    public $colors;

    public $bit_depth;

    public $scale_factor = 1.0;

    public $rotation = 0;

    public $mime_type = 'image/png';

    public $offset_x = 0;

    public $offset_y = 0;

    public $published_at;

    public $palette_id;

    public $css_name;

    /** @var array<int, array{key: string, value: string}> */
    public array $css_variables = [];

    protected $rules = [
        'name' => 'required|string|max:255|unique:device_models,name',
        'label' => 'required|string|max:255',
        'description' => 'required|string',
        'width' => 'required|integer|min:1',
        'height' => 'required|integer|min:1',
        'colors' => 'required|integer|min:1',
        'bit_depth' => 'required|integer|min:1',
        'scale_factor' => 'required|numeric|min:0.1',
        'rotation' => 'required|integer',
        'mime_type' => 'required|string|max:255',
        'offset_x' => 'required|integer',
        'offset_y' => 'required|integer',
        'published_at' => 'nullable|date',
    ];

    public function mount()
    {
        $this->deviceModels = DeviceModel::all();
        $this->devicePalettes = DevicePalette::all();

        return view('livewire.device-models.index');
    }

    public $editingDeviceModelId;

    public $viewingDeviceModelId;

    public function updateFromApi(): void
    {
        FetchDeviceModelsJob::dispatchSync();
        $this->deviceModels = DeviceModel::all();
        $this->devicePalettes = DevicePalette::all();
        Flux::toast(variant: 'success', text: 'Device models updated from API.');
    }

    public function openDeviceModelModal(?string $deviceModelId = null, bool $viewOnly = false): void
    {
        if ($deviceModelId) {
            $deviceModel = DeviceModel::findOrFail($deviceModelId);

            if ($viewOnly) {
                $this->viewingDeviceModelId = $deviceModel->id;
                $this->editingDeviceModelId = null;
            } else {
                $this->editingDeviceModelId = $deviceModel->id;
                $this->viewingDeviceModelId = null;
            }

            $this->name = $deviceModel->name;
            $this->label = $deviceModel->label;
            $this->description = $deviceModel->description;
            $this->width = $deviceModel->width;
            $this->height = $deviceModel->height;
            $this->colors = $deviceModel->colors;
            $this->bit_depth = $deviceModel->bit_depth;
            $this->scale_factor = $deviceModel->scale_factor;
            $this->rotation = $deviceModel->rotation;
            $this->mime_type = $deviceModel->mime_type;
            $this->offset_x = $deviceModel->offset_x;
            $this->offset_y = $deviceModel->offset_y;
            $this->published_at = $deviceModel->published_at?->format('Y-m-d\TH:i');
            $this->palette_id = $deviceModel->palette_id;
            $this->css_name = $deviceModel->css_name;
            $this->css_variables = collect($deviceModel->css_variables ?? [])->map(fn (string $value, string $key): array => ['key' => $key, 'value' => $value])->values()->all();
        } else {
            $this->editingDeviceModelId = null;
            $this->viewingDeviceModelId = null;
            $this->reset(['name', 'label', 'description', 'width', 'height', 'colors', 'bit_depth', 'scale_factor', 'rotation', 'mime_type', 'offset_x', 'offset_y', 'published_at', 'palette_id', 'css_name', 'css_variables']);
            $this->mime_type = 'image/png';
            $this->scale_factor = 1.0;
            $this->rotation = 0;
            $this->offset_x = 0;
            $this->offset_y = 0;
        }
    }

    public function saveDeviceModel(): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'label' => 'required|string|max:255',
            'description' => 'required|string',
            'width' => 'required|integer|min:1',
            'height' => 'required|integer|min:1',
            'colors' => 'required|integer|min:1',
            'bit_depth' => 'required|integer|min:1',
            'scale_factor' => 'required|numeric|min:0.1',
            'rotation' => 'required|integer',
            'mime_type' => 'required|string|max:255',
            'offset_x' => 'required|integer',
            'offset_y' => 'required|integer',
            'published_at' => 'nullable|date',
            'palette_id' => 'nullable|exists:device_palettes,id',
            'css_name' => 'nullable|string|max:255',
            'css_variables' => 'nullable|array',
            'css_variables.*.key' => 'nullable|string|max:255',
            'css_variables.*.value' => 'nullable|string|max:500',
        ];

        if ($this->editingDeviceModelId) {
            $rules['name'] = 'required|string|max:255|unique:device_models,name,'.$this->editingDeviceModelId;
        } else {
            $rules['name'] = 'required|string|max:255|unique:device_models,name';
        }

        $this->validate($rules);

        if ($this->editingDeviceModelId) {
            $deviceModel = DeviceModel::findOrFail($this->editingDeviceModelId);
            $deviceModel->update([
                'name' => $this->name,
                'label' => $this->label,
                'description' => $this->description,
                'width' => $this->width,
                'height' => $this->height,
                'colors' => $this->colors,
                'bit_depth' => $this->bit_depth,
                'scale_factor' => $this->scale_factor,
                'rotation' => $this->rotation,
                'mime_type' => $this->mime_type,
                'offset_x' => $this->offset_x,
                'offset_y' => $this->offset_y,
                'published_at' => $this->published_at,
                'palette_id' => $this->palette_id ?: null,
                'css_name' => $this->css_name ?: null,
                'css_variables' => $this->normalizeCssVariables(),
            ]);
            $message = 'Device model updated successfully.';
        } else {
            DeviceModel::create([
                'name' => $this->name,
                'label' => $this->label,
                'description' => $this->description,
                'width' => $this->width,
                'height' => $this->height,
                'colors' => $this->colors,
                'bit_depth' => $this->bit_depth,
                'scale_factor' => $this->scale_factor,
                'rotation' => $this->rotation,
                'mime_type' => $this->mime_type,
                'offset_x' => $this->offset_x,
                'offset_y' => $this->offset_y,
                'published_at' => $this->published_at,
                'palette_id' => $this->palette_id ?: null,
                'css_name' => $this->css_name ?: null,
                'css_variables' => $this->normalizeCssVariables(),
                'source' => 'manual',
            ]);
            $message = 'Device model created successfully.';
        }

        $this->reset(['name', 'label', 'description', 'width', 'height', 'colors', 'bit_depth', 'scale_factor', 'rotation', 'mime_type', 'offset_x', 'offset_y', 'published_at', 'palette_id', 'css_name', 'css_variables', 'editingDeviceModelId', 'viewingDeviceModelId']);
        Flux::modal('device-model-modal')->close();

        $this->deviceModels = DeviceModel::all();
        Flux::toast(variant: 'success', text: $message);
    }

    public function deleteDeviceModel(string $deviceModelId): void
    {
        $deviceModel = DeviceModel::findOrFail($deviceModelId);
        $deviceModel->delete();

        $this->deviceModels = DeviceModel::all();
        Flux::toast(variant: 'success', text: 'Device model deleted successfully.');
    }

    public function duplicateDeviceModel(string $deviceModelId): void
    {
        $deviceModel = DeviceModel::findOrFail($deviceModelId);

        $this->editingDeviceModelId = null;
        $this->viewingDeviceModelId = null;
        $this->name = $deviceModel->name.'_copy';
        $this->label = $deviceModel->label.' (Copy)';
        $this->description = $deviceModel->description;
        $this->width = $deviceModel->width;
        $this->height = $deviceModel->height;
        $this->colors = $deviceModel->colors;
        $this->bit_depth = $deviceModel->bit_depth;
        $this->scale_factor = $deviceModel->scale_factor;
        $this->rotation = $deviceModel->rotation;
        $this->mime_type = $deviceModel->mime_type;
        $this->offset_x = $deviceModel->offset_x;
        $this->offset_y = $deviceModel->offset_y;
        $this->published_at = $deviceModel->published_at?->format('Y-m-d\TH:i');
        $this->palette_id = $deviceModel->palette_id;
        $this->css_name = $deviceModel->css_name;
        $this->css_variables = collect($deviceModel->css_variables ?? [])->map(fn (string $value, string $key): array => ['key' => $key, 'value' => $value])->values()->all();

        $this->js('Flux.modal("device-model-modal").show()');
    }

    public function addCssVariable(): void
    {
        $this->css_variables = array_merge($this->css_variables, [['key' => '', 'value' => '']]);
    }

    public function removeCssVariable(int $index): void
    {
        $vars = $this->css_variables;
        array_splice($vars, $index, 1);
        $this->css_variables = array_values($vars);
    }

    /**
     * @return array<string, string>|null
     */
    private function normalizeCssVariables(): ?array
    {
        $pairs = collect($this->css_variables)
            ->filter(fn (array $p): bool => mb_trim($p['key'] ?? '') !== '');

        if ($pairs->isEmpty()) {
            return null;
        }

        return $pairs->mapWithKeys(fn (array $p): array => [$p['key'] => $p['value'] ?? ''])->all();
    }
}

?>

<div>
    <div class="py-12">
        <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
            <div class="mb-6 flex items-center justify-between">
                <livewire:device-resource-nav />
                <flux:button.group>
                    <flux:modal.trigger name="device-model-modal">
                        <flux:button wire:click="openDeviceModelModal()" icon="plus" variant="primary"
                            >Add Device Model</flux:button>
                    </flux:modal.trigger>
                    <flux:dropdown>
                        <flux:button icon="chevron-down" variant="primary"></flux:button>
                        <flux:menu>
                            <flux:menu.item icon="arrow-path" wire:click="updateFromApi">
                                Update from Models API</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </flux:button.group>
            </div>
            <flux:modal name="device-model-modal" class="md:w-96">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">
                            @if ($viewingDeviceModelId)
                                View Device Model
                            @elseif ($editingDeviceModelId)
                                Edit Device Model
                            @else
                                Add Device Model
                            @endif
                        </flux:heading>
                    </div>

                    <form wire:submit="saveDeviceModel">
                        <div class="mb-4">
                            <flux:input
                                label="Name (Identifier)"
                                wire:model="name"
                                id="name"
                                class="mt-1 block w-full"
                                type="text"
                                name="name"
                                autofocus
                                :disabled="(bool) $viewingDeviceModelId"
                            />
                        </div>

                        <div class="mb-4">
                            <flux:input
                                label="Label"
                                wire:model="label"
                                id="label"
                                class="mt-1 block w-full"
                                type="text"
                                name="label"
                                :disabled="(bool) $viewingDeviceModelId"
                            />
                        </div>

                        {{--<div class="mb-4">--}}
                        {{--    <flux:input label="Description" wire:model="description" id="description"--}}
                        {{--                class="block mt-1 w-full" name="description" :disabled="(bool) $viewingDeviceModelId"/>--}}
                        {{--</div>--}}

                        <div class="mb-4 grid grid-cols-2 gap-4">
                            <flux:input
                                label="Width"
                                wire:model="width"
                                id="width"
                                class="mt-1 block w-full"
                                type="number"
                                name="width"
                                :disabled="(bool) $viewingDeviceModelId"
                            />
                            <flux:input
                                label="Height"
                                wire:model="height"
                                id="height"
                                class="mt-1 block w-full"
                                type="number"
                                name="height"
                                :disabled="(bool) $viewingDeviceModelId"
                            />
                        </div>

                        <div class="mb-4 grid grid-cols-2 gap-4">
                            <flux:input
                                label="Colors"
                                wire:model="colors"
                                id="colors"
                                class="mt-1 block w-full"
                                type="number"
                                name="colors"
                                :disabled="(bool) $viewingDeviceModelId"
                            />
                            <flux:input
                                label="Bit Depth"
                                wire:model="bit_depth"
                                id="bit_depth"
                                class="mt-1 block w-full"
                                type="number"
                                name="bit_depth"
                                :disabled="(bool) $viewingDeviceModelId"
                            />
                        </div>

                        <div class="mb-4 grid grid-cols-2 gap-4">
                            <flux:input
                                label="Scale Factor"
                                wire:model="scale_factor"
                                id="scale_factor"
                                class="mt-1 block w-full"
                                type="number"
                                name="scale_factor"
                                step="0.0001"
                                :disabled="(bool) $viewingDeviceModelId"
                            />
                            <flux:input
                                label="Rotation"
                                wire:model="rotation"
                                id="rotation"
                                class="mt-1 block w-full"
                                type="number"
                                name="rotation"
                                :disabled="(bool) $viewingDeviceModelId"
                            />
                        </div>

                        <div class="mb-4">
                            <flux:select
                                label="MIME Type"
                                wire:model="mime_type"
                                id="mime_type"
                                name="mime_type"
                                :disabled="(bool) $viewingDeviceModelId"
                            >
                                <flux:select.option>image/png</flux:select.option>
                                <flux:select.option>image/bmp</flux:select.option>
                            </flux:select>
                        </div>

                        <div class="mb-4 grid grid-cols-2 gap-4">
                            <flux:input
                                label="Offset X"
                                wire:model="offset_x"
                                id="offset_x"
                                class="mt-1 block w-full"
                                type="number"
                                name="offset_x"
                                :disabled="(bool) $viewingDeviceModelId"
                            />
                            <flux:input
                                label="Offset Y"
                                wire:model="offset_y"
                                id="offset_y"
                                class="mt-1 block w-full"
                                type="number"
                                name="offset_y"
                                :disabled="(bool) $viewingDeviceModelId"
                            />
                        </div>

                        <div class="mb-4">
                            <flux:select
                                label="Color Palette"
                                wire:model="palette_id"
                                id="palette_id"
                                name="palette_id"
                                :disabled="(bool) $viewingDeviceModelId"
                            >
                                <flux:select.option value="">None</flux:select.option>
                                @foreach ($devicePalettes as $palette)
                                    <flux:select.option value="{{ $palette->id }}">
                                        {{ $palette->description ?? $palette->name }} ({{ $palette->name }})</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>

                        <div class="mb-4">
                            <flux:input
                                label="CSS Model Identifier"
                                wire:model="css_name"
                                id="css_name"
                                class="mt-1 block w-full"
                                type="text"
                                name="css_name"
                                :disabled="(bool) $viewingDeviceModelId"
                            />
                            @if (! $viewingDeviceModelId)
                                <flux:callout variant="warning" icon="exclamation-circle" class="mt-3">
                                    <flux:callout.text>
                                        The TRMNL Framework uses the CSS Model Identifier to apply conditional CSS, which can override CSS variables defined below. Remove it if you need more control over the styling.
                                        <flux:link
                                            href="https://trmnl.com/framework/docs/3.4/screen#css-variables"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >Docs (CSS)</flux:link>
                                    </flux:callout.text>
                                </flux:callout>
                            @endif
                        </div>

                        <div class="mb-4">
                            <flux:heading size="sm" class="mb-2">CSS Variables</flux:heading>
                            @if ($viewingDeviceModelId)
                                @if (count($css_variables) > 0)
                                    <dl class="space-y-1.5 text-sm">
                                        @foreach ($css_variables as $var)
                                            <div class="flex gap-2">
                                                <dt class="min-w-[120px] font-medium text-zinc-600 dark:text-zinc-400">
                                                    {{ $var['key'] }}
                                                </dt>
                                                <dd class="text-zinc-800 dark:text-zinc-200">{{ $var['value'] }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @else
                                    <p class="text-sm text-zinc-500 dark:text-zinc-400">No CSS variables</p>
                                @endif
                            @else
                                <div class="space-y-3">
                                    @foreach ($css_variables as $index => $var)
                                        <div class="flex items-start gap-2" wire:key="css-var-{{ $index }}">
                                            <flux:input
                                                wire:model="css_variables.{{ $index }}.key"
                                                placeholder="e.g. --screen-w"
                                                class="min-w-0 flex-1"
                                                type="text"
                                            />
                                            <flux:input
                                                wire:model="css_variables.{{ $index }}.value"
                                                placeholder="e.g. 800px"
                                                class="min-w-0 flex-1"
                                                type="text"
                                            />
                                            <flux:button
                                                type="button"
                                                wire:click="removeCssVariable({{ $index }})"
                                                icon="trash"
                                                variant="ghost"
                                                iconVariant="outline"
                                            />
                                        </div>
                                    @endforeach
                                    <flux:button
                                        type="button"
                                        wire:click="addCssVariable"
                                        variant="ghost"
                                        icon="plus"
                                        size="sm"
                                    >Add variable</flux:button>
                                </div>
                            @endif
                        </div>

                        @if (! $viewingDeviceModelId)
                            <div class="flex">
                                <flux:spacer />
                                <flux:button type="submit" variant="primary"
                                    >{{ $editingDeviceModelId ? 'Update' : 'Create' }} Device Model</flux:button>
                            </div>
                        @else
                            <div class="flex">
                                <flux:spacer />
                                <flux:button
                                    type="button"
                                    wire:click="duplicateDeviceModel({{ $viewingDeviceModelId }})"
                                    variant="primary"
                                >Duplicate</flux:button>
                            </div>
                        @endif
                    </form>
                </div>
            </flux:modal>

            <table
                class="min-w-full table-fixed divide-y divide-zinc-800/10 text-zinc-800 dark:divide-white/20"
                data-flux-table
            >
                <thead data-flux-columns>
                    <tr>
                        <th
                            class="px-3 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 dark:text-white"
                            data-flux-column
                        >
                            <div class="group-[]/right-align:justify-end flex whitespace-nowrap">Description</div>
                        </th>
                        <th
                            class="px-3 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 dark:text-white"
                            data-flux-column
                        >
                            <div class="group-[]/right-align:justify-end flex whitespace-nowrap">Width</div>
                        </th>
                        <th
                            class="px-3 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 dark:text-white"
                            data-flux-column
                        >
                            <div class="group-[]/right-align:justify-end flex whitespace-nowrap">Height</div>
                        </th>
                        <th
                            class="px-3 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 dark:text-white"
                            data-flux-column
                        >
                            <div class="group-[]/right-align:justify-end flex whitespace-nowrap">Bit Depth</div>
                        </th>
                        <th
                            class="px-3 py-3 text-left text-sm font-medium text-zinc-800 first:pl-0 last:pr-0 dark:text-white"
                            data-flux-column
                        >
                            <div class="group-[]/right-align:justify-end flex whitespace-nowrap">Actions</div>
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-800/10 dark:divide-white/20" data-flux-rows>
                    @foreach ($deviceModels as $deviceModel)
                        <tr data-flux-row>
                            <td class="px-3 py-3 text-sm whitespace-nowrap text-zinc-500 first:pl-0 last:pr-0 dark:text-zinc-300">
                                <div>
                                    <div class="font-medium text-zinc-800 dark:text-white">
                                        {{ $deviceModel->label }}
                                    </div>
                                    <div class="text-xs text-zinc-500">{{ Str::limit($deviceModel->name, 50) }}</div>
                                </div>
                            </td>
                            <td class="px-3 py-3 text-sm whitespace-nowrap text-zinc-500 first:pl-0 last:pr-0 dark:text-zinc-300">
                                {{ $deviceModel->width }}
                            </td>
                            <td class="px-3 py-3 text-sm whitespace-nowrap text-zinc-500 first:pl-0 last:pr-0 dark:text-zinc-300">
                                {{ $deviceModel->height }}
                            </td>
                            <td class="px-3 py-3 text-sm whitespace-nowrap text-zinc-500 first:pl-0 last:pr-0 dark:text-zinc-300">
                                {{ $deviceModel->bit_depth }}
                            </td>
                            <td class="px-3 py-3 text-sm font-medium whitespace-nowrap text-zinc-800 first:pl-0 last:pr-0 dark:text-white">
                                <div class="flex items-center gap-4">
                                    <flux:button.group>
                                        @if ($deviceModel->source === 'api')
                                            <flux:modal.trigger name="device-model-modal">
                                                <flux:button
                                                    wire:click="openDeviceModelModal('{{ $deviceModel->id }}', true)"
                                                    icon="eye"
                                                    iconVariant="outline"
                                                >
                                                </flux:button>
                                            </flux:modal.trigger>
                                            <flux:button
                                                wire:click="duplicateDeviceModel('{{ $deviceModel->id }}')"
                                                icon="document-duplicate"
                                                iconVariant="outline"
                                            >
                                            </flux:button>
                                        @else
                                            <flux:modal.trigger name="device-model-modal">
                                                <flux:button
                                                    wire:click="openDeviceModelModal('{{ $deviceModel->id }}')"
                                                    icon="pencil"
                                                    iconVariant="outline"
                                                >
                                                </flux:button>
                                            </flux:modal.trigger>
                                            <flux:button
                                                wire:click="deleteDeviceModel('{{ $deviceModel->id }}')"
                                                icon="trash"
                                                iconVariant="outline"
                                            >
                                            </flux:button>
                                        @endif
                                    </flux:button.group>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
