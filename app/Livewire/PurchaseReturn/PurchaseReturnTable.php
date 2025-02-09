<?php

namespace App\Livewire\PurchaseReturn;

use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;

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
        Log::info('supplier', ['supplier' => $supplier]);
        $this->supplier_id = $supplier['id'];
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
            'quantity' => 1,
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
            $this->rows[$index]['purchase_price'] = $product['purchase_price'];
            $this->rows[$index]['product_quantity'] = $product['product_quantity'];
            $this->rows[$index]['serial_number_required'] = $product['serial_number_required'];
        }

        $this->dispatch('updateRows', $this->rows);
    }

    public function updatePurchaseOrderRow($index, $purchase): void
    {
        if (isset($this->rows[$index])) {
            $this->rows[$index]['purchase_order_id'] = $purchase['id'];
            $this->rows[$index]['purchase_order_date'] = $purchase['date'];
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
        Log::info("Table received errors: ", ['errors' => $errors]);
        $this->render();
    }

    public function render()
    {
        Log::info(
            "Table render called: ", [
                'errors' => $this->getErrorBag()->messages()
            ]
        );
        return view('livewire.purchase-return.purchase-return-table');
    }
}
