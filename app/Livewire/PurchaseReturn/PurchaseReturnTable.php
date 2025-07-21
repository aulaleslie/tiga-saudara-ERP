<?php

namespace App\Livewire\PurchaseReturn;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;

class PurchaseReturnTable extends Component
{
    public $supplier_id = '';
    public $rows = [];
    public $validationErrors = [];

    protected $listeners = [
        'supplierSelected' => 'resetTable',
        'productSelected' => 'updateProductRow',
        'purchaseOrderSelected' => 'updatePurchaseOrderRow',
        'serialNumberSelected' => 'updateSerialNumberRow',
        'updateTableErrors' => 'handleValidationErrors',
    ];

    public function mount($rows = [])
    {
        $this->rows = $rows; // ✅ Initialize `rows` from parent
    }

    public function resetTable($supplier): void
    {
        if ($supplier) {
            Log::info('Updated supplier id: ', ['$supplier' => $supplier]);
            $this->supplier_id = $supplier['id'];
        } else {
            $this->supplier_id = null;
        }
        $this->rows = []; // Clear table when supplier changes
    }

    public function addProductRow(): void
    {
        if (!$this->supplier_id) {
            return;
        }

        $this->rows[] = [
            'product_id' => null,
            'product_name' => '',
            'quantity' => 0,
            'purchase_order_id' => null,
            'purchase_order_date' => '',
            'purchase_price' => null,
            'serial_numbers' => [],
            'serial_number_required' => false,
        ];

        $this->dispatch('updateRows', $this->rows);
    }

    public function updateProductRow($index, $product): void
    {
        if (isset($this->rows[$index])) {
            $this->rows[$index]['product_id'] = $product['id'];
            $this->rows[$index]['product_name'] = $product['product_name'];
            $this->rows[$index]['purchase_price'] = $product['last_purchase_price'];
            $this->rows[$index]['product_quantity'] = $product['broken_quantity'];
            $this->rows[$index]['serial_number_required'] = $product['serial_number_required'];
            $this->rows[$index]['serial_numbers'] = [];
        }

        $this->dispatch('updateRows', $this->rows);
    }

    public function updatePurchaseOrderRow($index, $purchase): void
    {
        if (isset($this->rows[$index])) {
            $this->rows[$index]['purchase_order_id'] = $purchase['id'];
            $this->rows[$index]['purchase_order_date'] = $purchase['date'];

            $purchase_detail = PurchaseDetail::where('purchase_id', $purchase['id'])->where('product_id', $this->rows[$index]['product_id'])->first();
            $this->rows[$index]['purchase_price'] = $purchase_detail['price'];
        }

        $this->dispatch('updateRows', $this->rows);
    }

    public function removeProductRow($index)
    {
        if (isset($this->rows[$index])) {
            unset($this->rows[$index]);
            $this->rows = array_values($this->rows);
        }

        $this->dispatch('updateRows', $this->rows);
    }

    public function handleValidationErrors($errors)
    {
        $this->validationErrors = $errors;
    }

    public function emitUpdatedQuantity($index): void
    {
        $this->dispatch('updateRows', $this->rows);
    }

    public function updateSerialNumberRow($index, $serialNumber): void
    {
        if (!isset($this->rows[$index]) || !$this->rows[$index]['serial_number_required']) {
            return;
        }

        if (!in_array($serialNumber, $this->rows[$index]['serial_numbers'])) {
            $this->rows[$index]['serial_numbers'][] = $serialNumber;
        }

        // ✅ Sync quantity
        $this->rows[$index]['quantity'] = count($this->rows[$index]['serial_numbers']);

        $this->dispatch('updateRows', $this->rows);
    }

    public function removeSerialNumber($index, $serialIndex): void
    {
        if (isset($this->rows[$index]['serial_numbers'][$serialIndex])) {
            unset($this->rows[$index]['serial_numbers'][$serialIndex]);
            $this->rows[$index]['serial_numbers'] = array_values($this->rows[$index]['serial_numbers']);
        }

        // ✅ Sync quantity
        $this->rows[$index]['quantity'] = count($this->rows[$index]['serial_numbers']);

        $this->dispatch('updateRows', $this->rows);
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.purchase-return.purchase-return-table');
    }
}
