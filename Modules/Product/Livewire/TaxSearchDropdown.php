<?php

namespace Modules\Product\Livewire;

use Livewire\Component;
use Modules\Setting\Entities\Tax;

class TaxSearchDropdown extends Component
{
    public int|string|null $selected = null;
    public string $name = 'tax_id';
    public string $placeholder = 'Pilih pajak...';
    public string $search = '';
    public bool $open = false;
    public bool $allowCreate = false;
    public bool $clearable = false;
    public ?string $error = null;
    public bool $disabled = false;
    public ?string $inputId = null;

    /**
     * @var array<int, array{id:int|string,name:string,value:int|float|null}>
     */
    public array $options = [];
    public ?string $selectedLabel = null;

    protected $listeners = [
        'taxCreated' => 'handleTaxCreated',
        'taxDropdownToggle' => 'handleToggle',
    ];

    public ?string $dispatchTo = null;

    public function mount(
        array $options = [],
        int|string|null $selected = null,
        string $name = 'tax_id',
        string $placeholder = 'Pilih pajak...',
        bool $allowCreate = false,
        ?string $error = null,
        bool $disabled = false,
        ?string $inputId = null,
        ?string $dispatchTo = null,
        bool $clearable = false
    ): void {
        $this->name = $name;
        $this->placeholder = $placeholder;
        $this->allowCreate = $allowCreate;
        $this->clearable = $clearable;
        $this->error = $error;
        $this->disabled = $disabled;
        $this->inputId = $inputId;
        $this->dispatchTo = $dispatchTo;

        $this->options = $this->prepareOptions($options);
        if (!count($this->options)) {
            $this->options = $this->fetchTaxes();
        }

        $this->selected = $selected ?: null;
        $this->selectedLabel = $this->resolveLabel($this->selected);
    }

    public function render()
    {
        return view('livewire.modules.product.tax-search-dropdown');
    }

    public function toggleDropdown(): void
    {
        if ($this->disabled) {
            return;
        }

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
        if ($this->disabled) {
            return;
        }

        $this->selected = $id;
        $this->selectedLabel = $this->resolveLabel($id);
        $this->open = false;
        $this->search = '';

        $this->dispatchSelection();
    }

    public function clearSelection(): void
    {
        if ($this->disabled) {
            return;
        }

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

    public function updatedDisabled($value): void
    {
        $this->open = false;
    }

    /**
     * Toggle disabled state from external events (e.g., checkbox control).
     */
    public function handleToggle($payload = null, $second = null): void
    {
        // Support both array payloads and positional parameters
        if (!is_array($payload)) {
            $payload = ['name' => $payload, 'disabled' => $second];
        }

        if (($payload['name'] ?? null) !== $this->name) {
            return;
        }

        $this->disabled = (bool) ($payload['disabled'] ?? false);
        if ($this->disabled) {
            $this->open = false;
        }
    }

    /**
     * @return array<int, array{id:int|string,name:string,value:int|float|null}>
     */
    public function getFilteredOptionsProperty(): array
    {
        if ($this->search === '') {
            return $this->options;
        }

        $keyword = mb_strtolower($this->search);

        return array_values(array_filter($this->options, function ($option) use ($keyword) {
            $nameMatch = mb_stripos($option['name'] ?? '', $keyword) !== false;
            $valueMatch = isset($option['value']) && mb_stripos((string) $option['value'], $keyword) !== false;

            return $nameMatch || $valueMatch;
        }));
    }

    public function handleTaxCreated(int $id, string $name, $value): void
    {
        $option = [
            'id' => $id,
            'name' => $name,
            'value' => $value,
        ];

        $this->upsertOption($option);
        if ($option['id'] !== null) {
            // Force select the new tax (bypassing disabled check)
            $this->selected = $option['id'];
            $this->selectedLabel = $this->resolveLabel($option['id']);
            $this->open = false;
            $this->search = '';
        }
    }

    private function resolveLabel(int|string|null $id): ?string
    {
        if (!$id) {
            return null;
        }

        foreach ($this->options as $option) {
            if ((string) $option['id'] === (string) $id) {
                return $this->formatLabel($option);
            }
        }

        $tax = Tax::find($id);
        if (!$tax) {
            return null;
        }

        $option = [
            'id' => $tax->id,
            'name' => $tax->name,
            'value' => $tax->value,
        ];

        $this->upsertOption($option);

        return $this->formatLabel($option);
    }

    /**
     * @param  array<int, mixed>  $options
     * @return array<int, array{id:int|string,name:string,value:int|float|null}>
     */
    private function prepareOptions(array $options): array
    {
        $normalized = $this->normalizeOptions($options);
        return $this->dedupeById($normalized);
    }

    /**
     * @return array<int, array{id:int|string,name:string,value:int|float|null}>
     */
    private function fetchTaxes(): array
    {
        return Tax::query()
            ->orderBy('name')
            ->get()
            ->map(function (Tax $tax) {
                return [
                    'id' => $tax->id,
                    'name' => $tax->name,
                    'value' => $tax->value,
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, mixed>  $options
     * @return array<int, array{id:int|string,name:string,value:int|float|null}>
     */
    private function normalizeOptions(array $options): array
    {
        $normalized = [];

        foreach ($options as $key => $value) {
            $id = null;
            $label = null;
            $taxValue = null;

            if (is_array($value)) {
                $id = $value['id'] ?? $key;
                $label = $value['name'] ?? null;
                $taxValue = $value['value'] ?? null;
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
                'value' => is_numeric($taxValue) ? (float) $taxValue : $taxValue,
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
     * @param  array<int, array{id:int|string,name:string,value:int|float|null}>  $options
     * @return array<int, array{id:int|string,name:string,value:int|float|null}>
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

        $event = $this->dispatch('taxDropdownSelected', name: $this->name, value: $this->selected);
        if (method_exists($event, 'to')) {
            $event->to($this->dispatchTo);
        }
    }

    /**
     * @param  array{id:int|string|null,name:string|null,value:int|float|string|null}  $option
     */
    private function formatLabel(array $option): string
    {
        $label = $option['name'] ?? '';
        $value = $option['value'] ?? null;

        if ($value === null || $value === '') {
            return $label;
        }

        if (is_numeric($value)) {
            $value = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        }

        return trim($label . ' (' . $value . '%)');
    }
}
