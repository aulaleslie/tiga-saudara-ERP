<?php

namespace App\Livewire\PurchaseReturn;

use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;

class PurchaseReturnTable extends Component
{
    public $supplier_id = '';
    public $selectedProducts = [];

    protected $listeners = [
        'supplierSelected' => 'resetTable',
        'productSelected' => 'updateProductRow',
        'purchaseOrderSelected' => 'updatePurchaseOrderRow',
        'serialNumberSelected' => 'updateSerialNumberRow',
    ];

    public function resetTable($supplier): void
    {
        Log::info('supplier', [
            'supplier' => $supplier
        ]);
        $this->supplier_id = $supplier['id'];
        $this->selectedProducts = []; // Clear table when supplier changes
    }

    public function addProductRow(): void
    {
        if (!$this->supplier_id) {
            return; // Prevent adding rows if no supplier is selected
        }

        $this->selectedProducts[] = [
            'product_id' => null,
            'product_name' => '',
            'quantity' => 0,
            'purchase_order_id' => null,
            'purchase_order_date' => '',
            'product_price' => null, // New dynamic column
            'serial_numbers' => []
        ];
    }

    public function updateProductRow($index, $product): void
    {
        Log::info('update product row called', [
            'index' => $index,
            'product' => $product,
            'apalah' => isset($this->selectedProducts[$index])
        ]);
        if (isset($this->selectedProducts[$index])) {
//            $this->selectedProducts[$index] = $product;
            $this->selectedProducts[$index]['product_id'] = $product['id'];
            $this->selectedProducts[$index]['product_name'] = $product['product_name'];
            $this->selectedProducts[$index]['purchase_price'] = $product['purchase_price'];
            $this->selectedProducts[$index]['product_quantity'] = $product['product_quantity'];
            $this->selectedProducts[$index]['serial_number_required'] = $product['serial_number_required'];
        }
    }

    public function updatePurchaseOrderRow($index, $purchase): void
    {
        Log::info('update purchase row called', [
            'index' => $index,
            'purchase' => $purchase,
        ]);
        if (isset($this->selectedProducts[$index])) {
            $this->selectedProducts[$index]['purchase_order_id'] = $purchase['id'];
            $this->selectedProducts[$index]['purchase_order_date'] = $purchase['date'];
        }
    }

    public function updateSerialNumberRow($index, $serial_number): void
    {
        Log::info('update purchase row called', [
            'index' => $index,
            'serial_number' => $serial_number,
        ]);

        if (!isset($this->selectedProducts[$index]['serial_numbers'])) {
            $this->selectedProducts[$index]['serial_numbers'] = [];
            $this->selectedProducts[$index]['serial_number_ids'] = [];
        }

        // Check if Serial Number Already Exists
        if (in_array($serial_number['serial_number'], $this->selectedProducts[$index]['serial_numbers'])) {
            // Trigger toast notification with error type
            session()->flash('message', 'Serial Number sudah ada dimasukkan!');
            return; // Exit the function
        }

        // If not exists, add serial number
        $this->selectedProducts[$index]['serial_numbers'][] = $serial_number['serial_number'];
        $this->selectedProducts[$index]['serial_number_ids'][] = $serial_number['id'];
    }

    public function removeSerialNumber($index, $serialIndex)
    {
        if (isset($this->selectedProducts[$index]['serial_numbers'][$serialIndex])) {
            unset($this->selectedProducts[$index]['serial_numbers'][$serialIndex]);
            $this->selectedProducts[$index]['serial_numbers'] = array_values($this->selectedProducts[$index]['serial_numbers']);
            unset($this->selectedProducts[$index]['serial_number_ids'][$serialIndex]);
            $this->selectedProducts[$index]['serial_number_ids'] = array_values($this->selectedProducts[$index]['serial_number_ids']);
        }
    }

    public function removeProductRow($index)
    {
        if (isset($this->selectedProducts[$index])) {
            unset($this->selectedProducts[$index]);
            $this->selectedProducts = array_values($this->selectedProducts); // Reindex the array
        }
    }

    public function render()
    {
        return view('livewire.purchase-return.purchase-return-table');
    }
}
