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
    public bool $hidePrice = false;

    protected $listeners = [
        'productSelected' => 'updateProductRow',
        'purchaseOrderSelected' => 'updatePurchaseOrderRow',
        'serialNumberSelected' => 'updateSerialNumberRow',
        'locationSelected' => 'updateLocationRow',
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


    public function mount($rows = [], $supplierId = null)
    {
        $this->rows = $rows;
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
            'location_id' => null,
            'location_name' => '',
            'location_locked' => false,
            'purchase_order_id' => null,
            'purchase_order_date' => '',
            'purchase_order_locked' => false,
            'purchase_price' => null,
            'serial_numbers' => [],
            'serial_number_required' => false,
            'total' => 0,
            'stock_at_location' => 0,
        ];

        $this->dispatch('updateRows', $this->rows);
    }

    protected function computeRowTotal(&$row): void
    {
        if (!empty($row['serial_number_required'])) {
            $row['quantity'] = count($row['serial_numbers'] ?? []);
        }

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
            $this->rows[$index]['location_id'] = null;
            $this->rows[$index]['location_name'] = '';
            $this->rows[$index]['location_locked'] = $product['serial_number_required'] ? true : false;
            $this->rows[$index]['purchase_order_id'] = null;
            $this->rows[$index]['purchase_order_date'] = '';
            $this->rows[$index]['purchase_order_locked'] = $product['serial_number_required'] ? true : false;
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

    public function updateLocationRow($index, $location): void
    {
        if (isset($this->rows[$index])) {
            $this->rows[$index]['location_id'] = $location['id'];
            $this->rows[$index]['location_name'] = $location['label'] ?? $location['name'];
            $this->populateStockForRow($index);
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
        $this->computeRowTotal($this->rows[$index]);
        $this->dispatch('updateRows', $this->rows);
    }

    protected function populateStockForRow(int $index): void
    {
        if (! isset($this->rows[$index]['product_id'])) {
            $this->rows[$index]['stock_at_location'] = 0;
            return;
        }

        // Stock at SELECTED location
        if (!empty($this->rows[$index]['location_id'])) {
            $this->rows[$index]['stock_at_location'] = ProductStock::where('product_id', $this->rows[$index]['product_id'])
                ->where('location_id', $this->rows[$index]['location_id'])
                ->value('quantity') ?? 0;
        } else {
            $this->rows[$index]['stock_at_location'] = 0;
        }
    }

    public function updateSerialNumberRow($index, $serialNumber): void
    {
        if (!isset($this->rows[$index]) || !$this->rows[$index]['serial_number_required']) {
            return;
        }

        // Check for uniqueness using ID
        $exists = collect($this->rows[$index]['serial_numbers'])->contains('id', $serialNumber['id']);

        if (!$exists) {
            $this->rows[$index]['serial_numbers'][] = $serialNumber;

            // Auto-fill and lock location and PO from first serial
            if (count($this->rows[$index]['serial_numbers']) === 1) {
                // Location
                $this->rows[$index]['location_id'] = $serialNumber['location_id'] ?? null;
                $this->rows[$index]['location_name'] = $serialNumber['location_label'] ?? $serialNumber['location_name'] ?? '';
                $this->rows[$index]['location_locked'] = true;
                
                // Purchase Order
                $this->rows[$index]['purchase_order_id'] = $serialNumber['purchase_order_id'] ?? null;
                $this->rows[$index]['purchase_order_reference'] = $serialNumber['purchase_order_reference'] ?? '';
                $this->rows[$index]['purchase_order_date'] = $serialNumber['purchase_order_date'] ?? '';
                $this->rows[$index]['purchase_order_locked'] = true;

                // Update purchase price from the selected PO
                if ($this->rows[$index]['purchase_order_id']) {
                    $purchase_detail = PurchaseDetail::where('purchase_id', $this->rows[$index]['purchase_order_id'])
                        ->where('product_id', $this->rows[$index]['product_id'])
                        ->first();
                    $this->rows[$index]['purchase_price'] = optional($purchase_detail)->price ?? 0;
                }
                
                $this->populateStockForRow($index);
            }
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

        // Unlock location/PO if no serials remain
        if (empty($this->rows[$index]['serial_numbers'])) {
            if ($this->rows[$index]['serial_number_required']) {
                $this->rows[$index]['location_id'] = null;
                $this->rows[$index]['location_name'] = '';
                $this->rows[$index]['location_locked'] = true; // Still locked because we expect serial
                
                $this->rows[$index]['purchase_order_id'] = null;
                $this->rows[$index]['purchase_order_reference'] = '';
                $this->rows[$index]['purchase_order_date'] = '';
                $this->rows[$index]['purchase_order_locked'] = true;
            } else {
                $this->rows[$index]['location_locked'] = false;
                $this->rows[$index]['purchase_order_locked'] = false;
            }
            $this->populateStockForRow($index);
        }

        $this->dispatch('updateRows', $this->rows);
    }

    public function render(): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.purchase-return.purchase-return-table');
    }
}
