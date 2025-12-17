<?php

namespace Modules\Product\Livewire;

use Livewire\Component;
use Modules\Setting\Entities\Unit;

class UnitSearchDropdown extends Component
{
    public int|string|null $selected = null;
    public string $name = 'unit_id';
    public string $placeholder = 'Pilih unit...';
    public string $search = '';
    public bool $open = false;
    public bool $allowCreate = false;
    public ?string $error = null;
    public string $width = '220px';
    public bool $awaitingCreated = false;
    public bool $disabled = false;

    /** @var array<int, array{id:int|string,name:string}> */
    public array $options = [];
    public ?string $selectedLabel = null;

    protected $listeners = [
        'unitCreated' => 'handleUnitCreated',
    ];

    public ?string $dispatchTo = null;

    public function mount(
        array $options = [],
        int|string|null $selected = null,
        string $name = 'unit_id',
        string $placeholder = 'Pilih unit...',
        bool $allowCreate = false,
        ?string $error = null,
        string $width = '220px',
        bool $disabled = false,
        ?string $dispatchTo = null
    ): void {
        $this->name = $name;
        $this->placeholder = $placeholder;
        $this->allowCreate = $allowCreate;
        $this->error = $error;
        $this->width = $width;
        $this->disabled = $disabled;
        $this->dispatchTo = $dispatchTo;

        $this->options = $this->prepareOptions($options);
        if (!count($this->options)) {
            $this->options = $this->fetchUnits();
        }

        $this->selected = $selected ?: null;
        $this->selectedLabel = $this->resolveLabel($this->selected);
    }

    public function render()
    {
        return view('livewire.modules.product.unit-search-dropdown');
    }

    public function openCreateModal(): void
    {
        $this->awaitingCreated = true;
        $this->dispatch('openUnitModal');
    }

    public function toggleDropdown(): void
    {
        $this->open = !$this->open;
        if ($this->open) {
            $this->search = '';
        }
    }

    public function closeDropdown(): void
    {
        $this->open = false;
    }

    public function select(int|string $id): void
    {
        $this->selected = $id;
        $this->selectedLabel = $this->resolveLabel($id);
        $this->open = false;
        $this->search = '';
        
        $this->dispatchSelection();
    }

    public function updatedSelected($value): void
    {
        $this->selectedLabel = $this->resolveLabel($value);
    }

    /**
     * @return array<int, array{id:int|string,name:string}>
     */
    public function getFilteredOptionsProperty(): array
    {
        if ($this->search === '') {
            return $this->options;
        }

        $keyword = mb_strtolower($this->search);

        return array_values(array_filter($this->options, function ($option) use ($keyword) {
            return mb_stripos($option['name'], $keyword) !== false;
        }));
    }

    public function handleUnitCreated(array $unit): void
    {
        $option = [
            'id' => $unit['id'] ?? null,
            'name' => $unit['name'] ?? '',
        ];

        $this->upsertOption($option);
        if ($this->awaitingCreated && $option['id'] !== null) {
            $this->select($option['id']);
        }
        $this->awaitingCreated = false;
    }

    private function resolveLabel(int|string|null $id): ?string
    {
        if (!$id) {
            return null;
        }

        foreach ($this->options as $option) {
            if ((string) $option['id'] === (string) $id) {
                return $option['name'];
            }
        }

        $settingId = session('setting_id');
        $unit = Unit::query()
            ->when($settingId, fn ($q) => $q->where('setting_id', $settingId))
            ->find($id);

        if (!$unit) {
            return null;
        }

        $option = [
            'id' => $unit->id,
            'name' => $unit->name,
        ];

        $this->upsertOption($option);

        return $option['name'];
    }

    /**
     * @param  array<int, mixed>  $options
     * @return array<int, array{id:int|string,name:string}>
     */
    private function prepareOptions(array $options): array
    {
        $normalized = $this->normalizeOptions($options);
        return $this->dedupeById($normalized);
    }

    /**
     * @return array<int, array{id:int|string,name:string}>
     */
    private function fetchUnits(): array
    {
        $settingId = session('setting_id');

        return Unit::query()
            ->when($settingId, fn ($q) => $q->where('setting_id', $settingId))
            ->orderBy('name')
            ->get()
            ->map(fn (Unit $unit) => [
                'id' => $unit->id,
                'name' => $unit->name,
            ])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $options
     * @return array<int, array{id:int|string,name:string}>
     */
    private function normalizeOptions(array $options): array
    {
        $normalized = [];

        foreach ($options as $key => $value) {
            $id = null;
            $label = null;

            if (is_array($value)) {
                $id = $value['id'] ?? $key;
                $label = $value['name'] ?? $value['display_name'] ?? null;
            } else {
                $id = $key;
                $label = (string) $value;
            }

            if ($id === null || $label === null) {
                continue;
            }

            $normalized[] = [
                'id' => is_numeric($id) ? (int) $id : $id,
                'name' => $label,
            ];
        }

        return $normalized;
    }

    private function upsertOption(array $option): void
    {
        if (($option['id'] ?? null) === null || ($option['name'] ?? null) === null) {
            return;
        }

        $foundIndex = null;
        foreach ($this->options as $index => $item) {
            if ((string) $item['id'] === (string) $option['id']) {
                $foundIndex = $index;
                break;
            }
        }

        if ($foundIndex !== null) {
            $this->options[$foundIndex] = array_merge($this->options[$foundIndex], $option);
        } else {
            $this->options[] = $option;
        }

        $this->options = $this->dedupeById($this->options);
    }

    /**
     * @param  array<int, array{id:int|string,name:string}>  $options
     * @return array<int, array{id:int|string,name:string}>
     */
    private function dedupeById(array $options): array
    {
        $seen = [];
        $deduped = [];

        foreach ($options as $option) {
            $key = (string) $option['id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $option;
        }

        return $deduped;
    }

    private function dispatchSelection(): void
    {
        if (!$this->dispatchTo) {
            return;
        }

        $event = $this->dispatch('unitDropdownSelected', name: $this->name, value: $this->selected);
        if (method_exists($event, 'to')) {
            $event->to($this->dispatchTo);
        }
    }
}
