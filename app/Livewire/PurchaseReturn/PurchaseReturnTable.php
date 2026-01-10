<?php

namespace App\Livewire\PurchaseReturn;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\PurchaseDetail;

use Livewire\Attributes\Reactive;

class PurchaseReturnTable extends Component
{
    #[Reactive]
    public $supplierId = '';
    
    public $rows = [];
    public $validationErrors = [];
    
    #[Reactive]
    public $locationId = null;

    protected $listeners = [
        'productSelected' => 'updateProductRow',
        'purchaseOrderSelected' => 'updatePurchaseOrderRow',
        'serialNumberSelected' => 'updateSerialNumberRow',
        'updateTableErrors' => 'handleValidationErrors',
        'supplierUpdated' => 'handleSupplierUpdated',
    ];

    public function updatedSupplierId($value): void
    {
        if ($value) {
            Log::info('Updated supplier id: ', ['supplierId' => $value]);
        }
        $this->rows = [];
    }

    public function handleSupplierUpdated($supplierId): void
    {
        Log::info('PurchaseReturnTable: handleSupplierUpdated called', ['supplierId' => $supplierId]);
        $this->supplierId = $supplierId;
        $this->rows = [];
        $this->dispatch('updateRows', $this->rows);
    }

    public function updatedLocationId($value): void
    {
        Log::info('PurchaseReturnTable: updatedLocationId called', ['locationId' => $value]);
        
        foreach (array_keys($this->rows) as $index) {
            $this->populateStockForRow($index);
        }

        $this->dispatch('updateRows', $this->rows);
    }

    public function mount($rows = [], $locationId = null, $supplierId = null)
    {
        $this->rows = $rows;
        $this->locationId = $locationId;
    }

    public function addProductRow(): void
    {
        if (!$this->supplierId) {
            return;
        }

        $this->rows[] = [
            'product_id' => null,
            'product_name' => '',
            'product_code' => '',
            'quantity' => 0,
            'purchase_order_id' => null,
            'purchase_order_date' => '',
            'purchase_price' => null,
            'serial_numbers' => [],
            'serial_number_required' => false,
            'total' => 0,
            'available_quantity_tax' => 0,
            'available_quantity_non_tax' => 0,
        ];

        $this->dispatch('updateRows', $this->rows);
    }

    protected function computeRowTotal(&$row): void
    {
        $price = (float) ($row['purchase_price'] ?? 0);
        $qty = (int) ($row['quantity'] ?? 0);
        $row['total'] = round($price * $qty, 2);
    }

    public function updateProductRow($index, $product): void
    {
        if (isset($this->rows[$index])) {
            $this->rows[$index]['product_id'] = $product['id'];
            $this->rows[$index]['product_name'] = $product['product_name'];
            $this->rows[$index]['purchase_price'] = $product['last_purchase_price'];
            $this->rows[$index]['product_code'] = $product['product_code'] ?? '';
            $this->rows[$index]['serial_number_required'] = $product['serial_number_required'];
            $this->rows[$index]['serial_numbers'] = [];
            $this->computeRowTotal($this->rows[$index]);
            $this->populateStockForRow($index);
        }

        $this->dispatch('updateRows', $this->rows);
    }

    public function updatePurchaseOrderRow($index, $purchase): void
    {
        if (isset($this->rows[$index])) {
            $this->rows[$index]['purchase_order_id'] = $purchase['id'];
            $this->rows[$index]['purchase_order_date'] = $purchase['date'];

            $purchase_detail = PurchaseDetail::where('purchase_id', $purchase['id'])->where('product_id', $this->rows[$index]['product_id'])->first();
            $this->rows[$index]['purchase_price'] = optional($purchase_detail)->price ?? 0;
            $this->computeRowTotal($this->rows[$index]);
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

    public function setLocation($locationId): void
    {
        Log::info('PurchaseReturnTable: setLocation called', ['locationId' => $locationId]);
        $this->locationId = $locationId;

        foreach (array_keys($this->rows) as $index) {
            $this->populateStockForRow($index);
        }

        $this->dispatch('updateRows', $this->rows);
    }

    public function emitUpdatedQuantity($index): void
    {
        $this->computeRowTotal($this->rows[$index]);
        $this->dispatch('updateRows', $this->rows);
    }

    protected function populateStockForRow(int $index): void
    {
        if (! isset($this->rows[$index]['product_id'])) {
            $this->rows[$index]['available_quantity_tax'] = 0;
            $this->rows[$index]['available_quantity_non_tax'] = 0;
            return;
        }

        if (! $this->locationId) {
            Log::warning('PurchaseReturnTable: populateStockForRow - No locationId set', ['index' => $index]);
            $this->rows[$index]['available_quantity_tax'] = 0;
            $this->rows[$index]['available_quantity_non_tax'] = 0;
            return;
        }

        $stock = ProductStock::query()
            ->where('product_id', $this->rows[$index]['product_id'])
            ->where('location_id', (int) $this->locationId)
            ->first();

        Log::info('PurchaseReturnTable: populateStockForRow', [
            'index' => $index,
            'product_id' => $this->rows[$index]['product_id'],
            'location_id' => $this->locationId,
            'stock_found' => (bool) $stock,
            'qty_tax' => $stock->quantity_tax ?? 0,
            'broken_tax' => $stock->broken_quantity_tax ?? 0,
            'qty_non_tax' => $stock->quantity_non_tax ?? 0,
            'broken_non_tax' => $stock->broken_quantity_non_tax ?? 0
        ]);

        $this->rows[$index]['available_quantity_tax'] = (int) (($stock->quantity_tax ?? 0) + ($stock->broken_quantity_tax ?? 0));
        $this->rows[$index]['available_quantity_non_tax'] = (int) (($stock->quantity_non_tax ?? 0) + ($stock->broken_quantity_non_tax ?? 0));
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
        $this->computeRowTotal($this->rows[$index]);

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
        $this->computeRowTotal($this->rows[$index]);

        $this->dispatch('updateRows', $this->rows);
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.purchase-return.purchase-return-table');
    }
}
