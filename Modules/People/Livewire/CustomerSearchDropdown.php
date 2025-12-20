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

    /** @var array<int, array{id:int|string,name:string}> */
    public array $options = [];
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

        $this->options = $this->prepareOptions($options);
        if (!count($this->options)) {
            $this->options = $this->fetchCustomers();
        }

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

        // Fetch customer to get payment_term_id
        $customer = Customer::find($id);
        $paymentTermId = $customer?->payment_term_id;

        $this->dispatchSelection($paymentTermId);

        // Keep the payment term dropdown in sync when customer changes
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

        $this->upsertOption($option);
        if ($option['id'] !== null) {
            $this->selected = $option['id'];
            $this->selectedLabel = $option['name'];
            $this->open = false;
            $this->search = '';
            
            // Dispatch with the payment term ID from the created customer array
            $paymentTermId = $customer['payment_term_id'] ?? null;
            $this->dispatchSelection($paymentTermId);

            // Sync payment term dropdown
            $this->dispatch('setPaymentTerm', $paymentTermId)
                ->to(PaymentTermSearchDropdown::class);
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

        $customer = Customer::query()->find($id);

        if (!$customer) {
            return null;
        }

        $name = $customer->contact_name 
            ? "{$customer->contact_name} - {$customer->customer_name}" 
            : $customer->customer_name;

        $option = [
            'id' => $customer->id,
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
    private function fetchCustomers(): array
    {
        return Customer::query()
            ->orderBy('customer_name')
            ->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->contact_name 
                    ? "{$customer->contact_name} - {$customer->customer_name}" 
                    : $customer->customer_name,
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
