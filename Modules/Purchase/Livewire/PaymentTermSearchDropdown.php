<?php

namespace Modules\Purchase\Livewire;

use Livewire\Attributes\Modelable;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Modules\Purchase\Entities\PaymentTerm;

class PaymentTermSearchDropdown extends Component
{
    #[Modelable]
    public int|string|null $selected = null; // two-way bound to parent
    public string $name = 'payment_term';
    public string $placeholder = 'Pilih term pembayaran...';
    public string $search = '';
    public bool $open = false;
    public bool $allowCreate = false;
    #[Reactive]
    public ?string $error = null;

    /** @var array<int, array{id:int|string,name:string}> */
    public array $options = [];
    public ?string $selectedLabel = null;





    public function mount(
        array $options = [],
        int|string|null $selected = null,
        string $name = 'payment_term',
        string $placeholder = 'Pilih term pembayaran...',
        bool $allowCreate = false,
        ?string $error = null,
        ?string $dispatchTo = null
    ): void {
        $this->name = $name;
        $this->placeholder = $placeholder;
        $this->allowCreate = $allowCreate;
        $this->error = $error;

        $this->options = $this->prepareOptions($options);
        if (!count($this->options)) {
            $this->options = $this->fetchPaymentTerms();
        }

        $this->selected = $selected ?: null;
        $this->selectedLabel = $this->resolveLabel($this->selected);
    }

    public function rendering(): void
    {
        if ($this->selected !== null) {
            $this->selectedLabel = $this->resolveLabel($this->selected);
        } else {
            $this->selectedLabel = null;
        }
    }

    public function render()
    {
        return view('livewire.modules.purchase.payment-term-search-dropdown');
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

        // Dispatch browser event for JavaScript to update due_date
        $this->dispatch('payment-term-changed', paymentTermId: $id);
    }

    public function updatedSelected($value): void
    {
        $this->selected = $value ?: null;
        $this->selectedLabel = $this->resolveLabel($this->selected);
    }

    public function setPaymentTerm(?int $paymentTermId = null): void
    {
        if ($paymentTermId === null) {
            $this->selected = null;
            $this->selectedLabel = null;
            return;
        }

        $this->selected = $paymentTermId;
        $this->selectedLabel = $this->resolveLabel($paymentTermId);
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

    public function handlePaymentTermCreated(array $data): void
    {
        // $data is expected to be an array with 'id', 'name' etc.
        $option = [
            'id' => $data['id'] ?? null,
            'name' => $data['name'] ?? '',
        ];

        // If display_name is present, prefer it, though payment terms usually just have name
        if (isset($data['display_name'])) {
            $option['name'] = $data['display_name'];
        }

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

        $term = PaymentTerm::find($id);

        if (!$term) {
            return null;
        }

        $option = [
            'id' => $term->id,
            'name' => $term->name,
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
    private function fetchPaymentTerms(): array
    {
        return PaymentTerm::query()
            ->orderBy('name')
            ->get()
            ->map(fn (PaymentTerm $term) => [
                'id' => $term->id,
                'name' => $term->name,
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

}
