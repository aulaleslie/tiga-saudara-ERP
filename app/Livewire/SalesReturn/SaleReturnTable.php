<?php

namespace App\Livewire\SalesReturn;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;

class SaleReturnTable extends Component
{
    public array $rows = [];
    public ?int $saleId = null;
    public array $validationErrors = [];
    public ?int $saleReturnId = null;

    protected $listeners = [
        'hydrateSaleReturnRows' => 'setRowsFromParent',
        'updateTableErrors' => 'handleValidationErrors',
        'serialNumberSelected' => 'updateSerialNumberRow',
    ];

    public function mount(array $rows = [], ?int $saleId = null, ?int $saleReturnId = null): void
    {
        $this->rows = $rows;
        $this->saleId = $saleId;
        $this->saleReturnId = $saleReturnId;
    }

    public function setRowsFromParent(array $rows, ?int $saleId = null, ?int $saleReturnId = null): void
    {
        $this->rows = $rows;
        $this->saleId = $saleId;
        $this->saleReturnId = $saleReturnId;
    }

    public function handleValidationErrors(array $errors): void
    {
        $this->validationErrors = $errors;
    }

    public function render(): View|Factory|Application
    {
        return view('livewire.sales-return.sale-return-table', [
            'rows' => $this->rows,
            'saleReturnId' => $this->saleReturnId,
            'validationErrors' => $this->validationErrors,
        ]);
    }

    public function updateQuantity(int $index, $value = null): void
    {
        if (! isset($this->rows[$index])) {
            return;
        }

        $quantity = $value ?? $this->rows[$index]['quantity'] ?? 0;
        $quantity = (int) $quantity;
        $available = (int) ($this->rows[$index]['available_quantity'] ?? 0);

        if ($quantity < 0) {
            $quantity = 0;
        }

        if ($available > 0 && $quantity > $available) {
            $quantity = $available;
        }

        $this->rows[$index]['quantity'] = $quantity;
        $this->computeRowTotal($index);
        $this->dispatch('updateRows', $this->rows);
    }

    public function removeRow(int $index): void
    {
        if (! isset($this->rows[$index])) {
            return;
        }

        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
        $this->dispatch('updateRows', $this->rows);
    }

    protected function computeRowTotal(int $index): void
    {
        if (! isset($this->rows[$index])) {
            return;
        }

        $quantity = (int) ($this->rows[$index]['quantity'] ?? 0);
        $unitPrice = (float) ($this->rows[$index]['unit_price'] ?? 0);
        $this->rows[$index]['total'] = round($quantity * $unitPrice, 2);
    }

    public function updateSerialNumberRow(int $index, array $serial): void
    {
        if (! isset($this->rows[$index]) || empty($this->rows[$index]['serial_number_required'])) {
            return;
        }

        $current = collect($this->rows[$index]['serial_numbers'] ?? []);

        $exists = $current->firstWhere('id', $serial['id'] ?? null);
        if ($exists) {
            return;
        }

        $current->push([
            'id' => $serial['id'] ?? null,
            'serial_number' => $serial['serial_number'] ?? null,
        ]);

        $this->rows[$index]['serial_numbers'] = $current->values()->all();
        $this->rows[$index]['quantity'] = $current->count();
        $this->computeRowTotal($index);
        $this->dispatch('updateRows', $this->rows);
    }

    public function removeSerialNumber(int $index, int $serialIndex): void
    {
        if (! isset($this->rows[$index]['serial_numbers'][$serialIndex])) {
            return;
        }

        unset($this->rows[$index]['serial_numbers'][$serialIndex]);
        $this->rows[$index]['serial_numbers'] = array_values($this->rows[$index]['serial_numbers']);
        $this->rows[$index]['quantity'] = count($this->rows[$index]['serial_numbers']);
        $this->computeRowTotal($index);
        $this->dispatch('updateRows', $this->rows);
    }
}
