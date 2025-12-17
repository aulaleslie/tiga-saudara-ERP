<?php

namespace Modules\People\Livewire;

use Livewire\Component;
use Livewire\Attributes\Modelable;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Livewire\PaymentTermSearchDropdown;

class SupplierSearchDropdown extends Component
{
    #[Modelable]
    public int|string|null $selected = null;
    public string $name = 'supplier_id';
    public string $placeholder = 'Pilih pemasok...';
    public string $search = '';
    public bool $open = false;
    public bool $allowCreate = false;
    public ?string $error = null;

    /** @var array<int, array{id:int|string,name:string}> */
    public array $options = [];
    public ?string $selectedLabel = null;

    protected $listeners = [
        'supplierCreated' => 'handleSupplierCreated',
    ];

    public ?string $dispatchTo = null;

    public function mount(
        array $options = [],
        int|string|null $selected = null,
        string $name = 'supplier_id',
        string $placeholder = 'Pilih pemasok...',
        bool $allowCreate = false,
        ?string $error = null,
        ?string $dispatchTo = null
    ): void {
        $this->name = $name;
        $this->placeholder = $placeholder;
        $this->allowCreate = $allowCreate;
        $this->error = $error;
        $this->dispatchTo = $dispatchTo;

        $this->options = $this->prepareOptions($options);
        if (!count($this->options)) {
            $this->options = $this->fetchSuppliers();
        }

        $this->selected = $selected ?: null;
        $this->selectedLabel = $this->resolveLabel($this->selected);
    }

    public function render()
    {
        return view('livewire.modules.people.supplier-search-dropdown');
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

        // Fetch supplier to get payment_term_id
        $supplier = Supplier::find($id);
        $paymentTermId = $supplier?->payment_term_id;

        $this->dispatchSelection($paymentTermId);

        // Keep the payment term dropdown in sync when supplier changes
        $this->dispatch('setPaymentTerm', $paymentTermId)
            ->to(PaymentTermSearchDropdown::class);
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

    public function handleSupplierCreated(array $supplier): void
    {
        // $supplier is expected to be an array from Supplier::create(...)->toArray()
        // or passed from the modal. The modal emits 'supplierCreated' with supplier array.
        
        $option = [
            'id' => $supplier['id'] ?? null,
            // Display name strategy: "contact_name - supplier_name" or just "supplier_name"
            'name' => isset($supplier['display_name']) 
                ? $supplier['display_name'] 
                : ($supplier['supplier_name'] ?? ''),
        ];

        // If display_name wasn't preset, constructing it similar to modal logic:
        if (empty($option['name']) && !empty($supplier['supplier_name'])) {
             $option['name'] = !empty($supplier['contact_name'])
                ? "{$supplier['contact_name']} - {$supplier['supplier_name']}"
                : $supplier['supplier_name'];
        }

        $this->upsertOption($option);
        if ($option['id'] !== null) {
            // We can directly select without re-fetching since we have the data
            $this->selected = $option['id'];
            $this->selectedLabel = $option['name'];
            $this->open = false;
            $this->search = '';
            
            // Dispatch with the payment term ID from the created supplier array
            $paymentTermId = $supplier['payment_term_id'] ?? null;
            $this->dispatchSelection($paymentTermId);
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
        $supplier = Supplier::query()
            ->find($id);

        if (!$supplier) {
            return null;
        }

        $name = $supplier->contact_name 
            ? "{$supplier->contact_name} - {$supplier->supplier_name}" 
            : $supplier->supplier_name;

        $option = [
            'id' => $supplier->id,
            'name' => $name,
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
    private function fetchSuppliers(): array
    {
        $settingId = session('setting_id');

        return Supplier::query()
            ->orderBy('supplier_name')
            ->get()
            ->map(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->contact_name 
                    ? "{$supplier->contact_name} - {$supplier->supplier_name}" 
                    : $supplier->supplier_name,
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

    private function dispatchSelection(?int $paymentTermId = null): void
    {
        // Notify other components (e.g., product search) about the supplier change
        $this->dispatch('supplierSelected', $this->selected);
    }
}
