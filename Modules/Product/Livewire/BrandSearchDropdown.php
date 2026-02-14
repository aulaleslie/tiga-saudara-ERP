<?php

namespace Modules\Product\Livewire;

use Livewire\Component;
use Modules\Product\Entities\Brand;

class BrandSearchDropdown extends Component
{
    public int|string|null $selected = null;
    public string $name = 'brand_id';
    public string $placeholder = 'Pilih merek...';
    public string $search = '';
    public bool $open = false;
    public bool $allowCreate = false;
    public bool $clearable = false;
    public ?string $error = null;

    /** @var array<int, array{id:int|string,name:string}> */
    public array $options = [];
    public ?string $selectedLabel = null;

    protected $listeners = [
        'brandCreated' => 'handleBrandCreated',
    ];

    public ?string $dispatchTo = null;

    public function mount(
        array $options = [],
        int|string|null $selected = null,
        string $name = 'brand_id',
        string $placeholder = 'Pilih merek...',
        bool $allowCreate = false,
        ?string $error = null,
        ?string $dispatchTo = null,
        bool $clearable = false
    ): void {
        $this->name = $name;
        $this->placeholder = $placeholder;
        $this->allowCreate = $allowCreate;
        $this->clearable = $clearable;
        $this->error = $error;
        $this->dispatchTo = $dispatchTo;

        $this->options = $this->prepareOptions($options);
        if (!count($this->options)) {
            $this->options = $this->fetchBrands();
        }

        $this->selected = $selected ?: null;
        $this->selectedLabel = $this->resolveLabel($this->selected);
    }

    public function render()
    {
        return view('livewire.modules.product.brand-search-dropdown');
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

    public function clearSelection(): void
    {
        $this->selected = null;
        $this->selectedLabel = null;
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

    public function handleBrandCreated(array $brand): void
    {
        $option = [
            'id' => $brand['id'] ?? null,
            'name' => $brand['name'] ?? '',
        ];

        $this->upsertOption($option);
        if ($option['id'] !== null) {
            $this->select($option['id']);
        }
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
        $brand = Brand::query()
            ->when($settingId, fn ($q) => $q->where('setting_id', $settingId))
            ->find($id);

        if (!$brand) {
            return null;
        }

        $option = [
            'id' => $brand->id,
            'name' => $brand->name,
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
    private function fetchBrands(): array
    {
        $settingId = session('setting_id');

        return Brand::query()
            ->when($settingId, fn ($q) => $q->where('setting_id', $settingId))
            ->orderBy('name')
            ->get()
            ->map(fn (Brand $brand) => [
                'id' => $brand->id,
                'name' => $brand->name,
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

        $event = $this->dispatch('brandDropdownSelected', name: $this->name, value: $this->selected);
        if (method_exists($event, 'to')) {
            $event->to($this->dispatchTo);
        }
    }
}
