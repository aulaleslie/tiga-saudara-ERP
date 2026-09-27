<?php

namespace App\Livewire\PurchaseReturn;

use Livewire\Component;
use Livewire\Attributes\Modelable;
use Livewire\Attributes\Reactive;
use Modules\Purchase\Entities\Purchase;

class UnpaidPurchaseSearchDropdown extends Component
{
    #[Modelable]
    public int|string|null $selected = null;
    public string $name = 'allocation_purchase_id';
    public string $placeholder = 'Pilih nota...';
    public string $search = '';
    public bool $open = false;
    public int|string|null $supplier_id = null;
    public int|string|null $exclude_purchase_id = null;
    public int $zIndex = 1100;

    #[Reactive]
    public ?string $error = null;

    /** @var array<int, array{id:int|string,name:string}> */
    public array $options = [];
    public ?string $selectedLabel = null;

    public function mount(
        array $options = [],
        int|string|null $selected = null,
        string $name = 'allocation_purchase_id',
        string $placeholder = 'Pilih nota...',
        ?string $error = null,
        int|string|null $supplier_id = null,
        int|string|null $exclude_purchase_id = null,
        int $zIndex = 1100
    ): void {
        $this->name = $name;
        $this->placeholder = $placeholder;
        $this->error = $error;
        $this->supplier_id = $supplier_id;
        $this->exclude_purchase_id = $exclude_purchase_id;
        $this->zIndex = $zIndex;

        $this->options = $this->prepareOptions($options);
        if (!count($this->options)) {
            $this->options = $this->fetchPurchases();
        }

        $this->selected = $selected ?: null;
        $this->selectedLabel = $this->resolveLabel($this->selected);
    }

    public function render()
    {
        return view('livewire.purchase-return.unpaid-purchase-search-dropdown');
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

        $purchase = Purchase::find($id);

        if (!$purchase) {
            return null;
        }

        $name = ($purchase->supplier_purchase_number ?: $purchase->reference) . ' (Sisa: ' . format_currency($purchase->due_amount) . ')';

        $option = [
            'id' => $purchase->id,
            'name' => $name,
        ];

        $this->upsertOption($option);

        return $option['name'];
    }

    private function prepareOptions(array $options): array
    {
        $normalized = $this->normalizeOptions($options);
        return $this->dedupeById($normalized);
    }

    private function fetchPurchases(): array
    {
        if (!$this->supplier_id) {
            return [];
        }

        return Purchase::where('supplier_id', $this->supplier_id)
            ->where('due_amount', '>', 0)
            ->whereIn('status', [
                Purchase::STATUS_RECEIVED,
                Purchase::STATUS_RECEIVED_PARTIALLY,
                Purchase::STATUS_APPROVED,
            ])
            ->when($this->exclude_purchase_id, fn($q) => $q->where('id', '!=', $this->exclude_purchase_id))
            ->orderBy('date', 'desc')
            ->get()
            ->map(fn (Purchase $purchase) => [
                'id' => $purchase->id,
                'name' => ($purchase->supplier_purchase_number ?: $purchase->reference) . ' (Sisa: ' . format_currency($purchase->due_amount) . ')',
            ])
            ->all();
    }

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
        $this->dispatch('purchaseSelected', name: $this->name, value: $this->selected);
    }
}
