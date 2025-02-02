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
    ];

    public function resetTable($supplier)
    {
        Log::info('supplier', [
            'supplier' => $supplier
        ]);
        $this->supplier_id = $supplier['id'];
        $this->selectedProducts = []; // Clear table when supplier changes
    }

    public function addProductRow()
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
        ];
    }

    public function updateProductRow($index, $product)
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
        }
    }

    public function updatePurchaseOrderRow($index, $purchase)
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
