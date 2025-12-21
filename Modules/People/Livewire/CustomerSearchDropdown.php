<?php

namespace Modules\People\Livewire;

use Livewire\Component;
use Livewire\Attributes\Modelable;
use Livewire\Attributes\Reactive;
use Modules\People\Entities\Customer;
use Modules\Purchase\Livewire\PaymentTermSearchDropdown;

class CustomerSearchDropdown extends Component
{
    #[Modelable]
    public int|string|null $selected = null;
    public string $name = 'customer_id';
    public string $placeholder = 'Pilih pelanggan...';
    public string $search = '';
    public bool $open = false;
    public bool $allowCreate = false;
    #[Reactive]
    public ?string $error = null;

    public int $perPage = 20;
    public ?string $selectedLabel = null;

    protected $listeners = [
        'customerCreated' => 'handleCustomerCreated',
    ];

    public ?string $dispatchTo = null;

    public function mount(
        array $options = [],
        int|string|null $selected = null,
        string $name = 'customer_id',
        string $placeholder = 'Pilih pelanggan...',
        bool $allowCreate = false,
        ?string $error = null,
        ?string $dispatchTo = null
    ): void {
        $this->name = $name;
        $this->placeholder = $placeholder;
        $this->allowCreate = $allowCreate;
        $this->error = $error;
        $this->dispatchTo = $dispatchTo;

        $this->selected = $selected ?: null;
        $this->selectedLabel = $this->resolveLabel($this->selected);
    }

    public function render()
    {
        return view('livewire.modules.people.customer-search-dropdown');
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

        // Notify other components about the selected customer (includes payment term)
        $this->dispatchSelection();
    }

    public function updatedSelected($value): void
    {
        $this->selectedLabel = $this->resolveLabel($value);
    }

    public function getOptionsProperty(): array
    {
        $options = $this->fetchCustomers($this->search);

        if ($this->selected) {
            $alreadyIncluded = collect($options)->first(function ($opt) {
                return (string) $opt['id'] === (string) $this->selected;
            });

            if (! $alreadyIncluded) {
                $label = $this->resolveLabel($this->selected);
                if ($label) {
                    array_unshift($options, [
                        'id' => is_numeric($this->selected) ? (int) $this->selected : $this->selected,
                        'name' => $label,
                    ]);
                    $options = $this->dedupeById($options);
                }
            }
        }

        return array_slice($options, 0, $this->perPage);
    }

    /**
     * @return array<int, array{id:int|string,name:string}>
     */
    public function getFilteredOptionsProperty(): array
    {
        return $this->options;
    }

    public function handleCustomerCreated(array $customer): void
    {
        $option = [
            'id' => $customer['id'] ?? null,
            'name' => isset($customer['display_name']) 
                ? $customer['display_name'] 
                : ($customer['customer_name'] ?? ''),
        ];

        // Construct display name if not preset
        if (empty($option['name']) && !empty($customer['customer_name'])) {
             $option['name'] = !empty($customer['contact_name'])
                ? "{$customer['contact_name']} - {$customer['customer_name']}"
                : $customer['customer_name'];
        }

        if (($option['id'] ?? null) === null || ($option['name'] ?? null) === null) {
            return;
        }

        // Update the dropdown selection UI
        $this->selected = $option['id'];
        $this->selectedLabel = $option['name'];
        $this->open = false;
        $this->search = '';
        
        // Note: We do NOT call dispatchSelection() here because CreateForm
        // already listens for 'customerCreated' directly and handles the
        // payment term sync. Dispatching 'customerSelected' would cause
        // duplicate event handling and a 419 error.
    }

    private function resolveLabel(int|string|null $id): ?string
    {
        if (!$id) {
            return null;
        }

        $customer = Customer::query()->find($id);

        if (!$customer) {
            return null;
        }

        $name = $customer->contact_name 
            ? "{$customer->contact_name} - {$customer->customer_name}" 
            : $customer->customer_name;

        return $name;
    }

    /**
     * @return array<int, array{id:int|string,name:string}>
     */
    private function fetchCustomers(string $keyword = ''): array
    {
        $query = Customer::query();

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('contact_name', 'like', '%' . $keyword . '%')
                    ->orWhere('customer_name', 'like', '%' . $keyword . '%');
            });
        }

        return $query->orderBy('customer_name')
            ->limit($this->perPage)
            ->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->contact_name 
                    ? "{$customer->contact_name} - {$customer->customer_name}" 
                    : $customer->customer_name,
            ])
            ->all();
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
        // Fetch full customer data for the event
        $customer = Customer::find($this->selected);
        
        if ($customer) {
            // Dispatch full customer data as expected by ProductCart
            $this->dispatch('customerSelected', [
                'id' => $customer->id,
                'contact_name' => $customer->contact_name,
                'customer_name' => $customer->customer_name,
                'tier' => $customer->tier ?? null,
                'payment_term_id' => $customer->payment_term_id,
            ]);
        }
    }
}
