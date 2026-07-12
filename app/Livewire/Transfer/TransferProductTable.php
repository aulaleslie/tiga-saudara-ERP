<?php

namespace App\Livewire\Transfer;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Adjustment\Services\TransferAllocationPreviewService;

class TransferProductTable extends Component
{
    protected $listeners = [
        'productSelected',
        'serialNumberSelected',
        'serialScanned',
        'removeSerialNumber',
        'locationsConfirmed'      => 'resetOnNewLocations',
        'tableValidationErrors'   => 'onTableValidationErrors',
    ];

    public $products = [];
    public $originLocationId;
    public $destinationLocationId;
    public $serialNumberErrors = [];

    // holds validation errors for table rows
    public $tableValidationErrors = [];

    /**
     * Mount with the two location IDs passed in via wire:key
     */
    public function mount($originLocationId = null, $destinationLocationId = null, $existingProducts = []): void
    {
        $this->originLocationId      = $originLocationId;
        $this->destinationLocationId = $destinationLocationId;
        $this->products              = $existingProducts;
        $this->serialNumberErrors    = array_fill(0, count($existingProducts), null);
    }

    /**
     * Clear the table whenever parent confirms new locations
     */
    public function resetOnNewLocations(array $payload): void
    {
        $this->originLocationId      = $payload['originLocationId'];
        $this->destinationLocationId = $payload['destinationLocationId'];
        $this->products              = [];
        $this->serialNumberErrors    = [];
        $this->tableValidationErrors = [];

        // notify parent of reset
        $this->dispatch('rowsUpdated', $this->products);
    }

    /**
     * Add a product (with its stock snapshot) to the table
     */
    public function productSelected(array $product): void
    {
        $isBrokenMode = $product['is_broken_mode'] ?? false;
        $existingKey = collect($this->products)->search(fn ($p) => 
            ($p['id'] ?? null) == ($product['id'] ?? null) &&
            ((bool)($p['is_broken_mode'] ?? false)) === (bool)$isBrokenMode
        );
        $scanMultiplier = max(1, (int) ($product['scan_quantity_multiplier'] ?? 1));

        // Get the allocation service
        $allocationService = app(TransferAllocationPreviewService::class);
        
        // Get stock record
        $stock = ProductStock::where('product_id', $product['id'])
            ->where('location_id', $this->originLocationId)
            ->first();

        if ($existingKey !== false) {
            // For serial products, we don't automatically increment quantities, the user must scan the serials.
            if (empty($product['serial_number_required'])) {
                if ($isBrokenMode) {
                    // Calculate remaining available for broken mode
                    $currentBrokenTotal = (int) ($this->products[$existingKey]['broken_quantity_tax'] ?? 0) 
                                        + (int) ($this->products[$existingKey]['broken_quantity_non_tax'] ?? 0);
                    $availableBrokenStock = ($stock?->broken_quantity ?? 0) - $currentBrokenTotal;
                    
                    if ($availableBrokenStock >= $scanMultiplier && $stock) {
                        // Create a temporary stock object with remaining quantities for allocation
                        $tempStock = (object)[
                            'broken_quantity_non_tax' => ($stock->broken_quantity_non_tax ?? 0) - (int) ($this->products[$existingKey]['broken_quantity_non_tax'] ?? 0),
                            'broken_quantity_tax' => ($stock->broken_quantity_tax ?? 0) - (int) ($this->products[$existingKey]['broken_quantity_tax'] ?? 0),
                            'broken_quantity' => $availableBrokenStock,
                            'quantity_non_tax' => 0,
                            'quantity_tax' => 0,
                            'quantity' => 0,
                        ];
                        
                        $allocation = $allocationService->previewAllocation($tempStock, $scanMultiplier, true);
                        $this->products[$existingKey]['broken_quantity_non_tax'] = (int) ($this->products[$existingKey]['broken_quantity_non_tax'] ?? 0) + (int) $allocation->allocatedNonTax;
                        $this->products[$existingKey]['broken_quantity_tax'] = (int) ($this->products[$existingKey]['broken_quantity_tax'] ?? 0) + (int) $allocation->allocatedTax;
                    } else {
                        session()->flash('message', 'Stok rusak tidak mencukupi untuk ditambah lagi.');
                    }
                } else {
                    // Calculate remaining available for normal mode
                    $currentTotal = (int) ($this->products[$existingKey]['quantity_tax'] ?? 0) 
                                  + (int) ($this->products[$existingKey]['quantity_non_tax'] ?? 0);
                    $availableStock = ($stock?->quantity ?? 0) - $currentTotal;
                    
                    if ($availableStock >= $scanMultiplier && $stock) {
                        // Create a temporary stock object with remaining quantities for allocation
                        $tempStock = (object)[
                            'quantity_non_tax' => ($stock->quantity_non_tax ?? 0) - (int) ($this->products[$existingKey]['quantity_non_tax'] ?? 0),
                            'quantity_tax' => ($stock->quantity_tax ?? 0) - (int) ($this->products[$existingKey]['quantity_tax'] ?? 0),
                            'quantity' => $availableStock,
                            'broken_quantity_non_tax' => 0,
                            'broken_quantity_tax' => 0,
                            'broken_quantity' => 0,
                        ];
                        
                        $allocation = $allocationService->previewAllocation($tempStock, $scanMultiplier, false);
                        $this->products[$existingKey]['quantity_non_tax'] = (int) ($this->products[$existingKey]['quantity_non_tax'] ?? 0) + (int) $allocation->allocatedNonTax;
                        $this->products[$existingKey]['quantity_tax'] = (int) ($this->products[$existingKey]['quantity_tax'] ?? 0) + (int) $allocation->allocatedTax;
                    } else {
                        session()->flash('message', 'Stok tidak mencukupi untuk ditambah lagi.');
                    }
                }
                
                $this->dispatch('rowsUpdated', $this->products);
            }
            return;
        }

        // merge in all the relevant stock columns
        $product['stock'] = [
            'total'                   => $stock?->quantity                 ?? 0,
            'quantity_tax'            => $stock?->quantity_tax             ?? 0,
            'quantity_non_tax'        => $stock?->quantity_non_tax         ?? 0,
            'broken_quantity_tax'     => $stock?->broken_quantity_tax      ?? 0,
            'broken_quantity_non_tax' => $stock?->broken_quantity_non_tax  ?? 0,
        ];

        // initialize transfer inputs
        $product['quantity_tax']            = 0;
        $product['quantity_non_tax']        = 0;
        $product['broken_quantity_tax']     = 0;
        $product['broken_quantity_non_tax'] = 0;
        $product['serial_number_required']  = (bool) ($product['serial_number_required'] ?? false);
        $product['serial_numbers']          = [];

        // Apply initial multiplier if stock is sufficient and product doesn't require serials
        if (!$product['serial_number_required'] && $stock) {
            if ($isBrokenMode) {
                $allocation = $allocationService->previewAllocation($stock, $scanMultiplier, true);
                $product['broken_quantity_non_tax'] = (int) $allocation->allocatedNonTax;
                $product['broken_quantity_tax'] = (int) $allocation->allocatedTax;
                
                if ($allocation->isInsufficient) {
                    session()->flash('message', 'Stok rusak tidak mencukupi untuk jumlah yang dipindai.');
                }
            } else {
                $allocation = $allocationService->previewAllocation($stock, $scanMultiplier, false);
                $product['quantity_non_tax'] = (int) $allocation->allocatedNonTax;
                $product['quantity_tax'] = (int) $allocation->allocatedTax;
                
                if ($allocation->isInsufficient) {
                    session()->flash('message', 'Stok tidak mencukupi untuk jumlah yang dipindai.');
                }
            }
        }

        $this->products[] = $product;
        $this->serialNumberErrors[] = null;
        $this->tableValidationErrors = [];

        // notify parent that rows have changed
        $this->dispatch('rowsUpdated', $this->products);
    }

    /**
     * Remove a product from the table
     */
    public function removeProduct(int $key): void
    {
        unset($this->products[$key]);
        $this->products = array_values($this->products);
        unset($this->serialNumberErrors[$key]);
        $this->serialNumberErrors = array_values($this->serialNumberErrors);
        $this->tableValidationErrors = [];

        // notify parent that rows have changed
        $this->dispatch('rowsUpdated', $this->products);
    }

    public function serialNumberSelected($payload): void
    {
        $productCompositeKey = $payload['productCompositeKey'] ?? null;

        if (! is_numeric($productCompositeKey)) {
            return;
        }

        $rowKey = (int) $productCompositeKey;

        if (! isset($this->products[$rowKey])) {
            return;
        }

        if (empty($this->products[$rowKey]['serial_number_required'])) {
            return;
        }

        $serialNumber = $payload['serialNumber'] ?? null;
        $serialId     = (int) ($serialNumber['id'] ?? 0);

        if ($serialId <= 0) {
            return;
        }

        // Prevent duplicate selections across all rows
        if ($this->serialExistsInRows($serialId, $rowKey)) {
            $this->serialNumberErrors[$rowKey] = 'Nomor seri sudah dipilih.';
            return;
        }

        $serial = ProductSerialNumber::find($serialId);

        if (! $serial) {
            $this->serialNumberErrors[$rowKey] = 'Nomor seri tidak ditemukan.';
            return;
        }

        $currentSerials = collect($this->products[$rowKey]['serial_numbers'] ?? []);

        if ($currentSerials->pluck('id')->contains($serial->id)) {
            $this->serialNumberErrors[$rowKey] = 'Nomor seri sudah dipilih.';
            return;
        }

        $this->serialNumberErrors[$rowKey] = null;

        $this->products[$rowKey]['serial_numbers'][] = [
            'id'            => $serial->id,
            'serial_number' => $serial->serial_number,
            'tax_id'        => $serial->tax_id,
            'taxable'       => (bool) $serial->tax_id,
            'is_broken'     => (bool) $serial->is_broken,
        ];

        $this->recalculateSerialQuantities($rowKey);

        $this->dispatch('rowsUpdated', $this->products);
    }

    public function removeSerialNumber($rowKey, $serialIndex = null): void
    {
        if (is_array($rowKey)) {
            $serialIndex = $rowKey['serialIndex'] ?? null;
            $rowKey      = $rowKey['productCompositeKey'] ?? $rowKey['row'] ?? null;
        }

        if (! is_numeric($rowKey)) {
            return;
        }

        $rowKey = (int) $rowKey;

        if (! isset($this->products[$rowKey]) || $serialIndex === null) {
            return;
        }

        if (! isset($this->products[$rowKey]['serial_numbers'][$serialIndex])) {
            return;
        }

        unset($this->products[$rowKey]['serial_numbers'][$serialIndex]);
        $this->products[$rowKey]['serial_numbers'] = array_values($this->products[$rowKey]['serial_numbers']);

        $this->serialNumberErrors[$rowKey] = null;

        $this->recalculateSerialQuantities($rowKey);

        $this->dispatch('rowsUpdated', $this->products);
    }

    protected function serialExistsInRows(int $serialId, int $currentRowKey): bool
    {
        return collect($this->products)
            ->filter(fn ($_, $index) => $index !== $currentRowKey)
            ->pluck('serial_numbers')
            ->flatten(1)
            ->pluck('id')
            ->contains($serialId);
    }

    protected function recalculateSerialQuantities(int $rowKey): void
    {
        $serials = $this->products[$rowKey]['serial_numbers'] ?? [];

        $quantityTax            = 0;
        $quantityNonTax        = 0;
        $brokenQuantityTax     = 0;
        $brokenQuantityNonTax  = 0;

        foreach ($serials as $serial) {
            $isBroken = (bool) ($serial['is_broken'] ?? false);
            $isTaxed  = (bool) ($serial['taxable'] ?? false);

            if ($isBroken && $isTaxed) {
                $brokenQuantityTax++;
            } elseif ($isBroken && ! $isTaxed) {
                $brokenQuantityNonTax++;
            } elseif (! $isBroken && $isTaxed) {
                $quantityTax++;
            } else {
                $quantityNonTax++;
            }
        }

        $this->products[$rowKey]['quantity_tax']            = $quantityTax;
        $this->products[$rowKey]['quantity_non_tax']        = $quantityNonTax;
        $this->products[$rowKey]['broken_quantity_tax']     = $brokenQuantityTax;
        $this->products[$rowKey]['broken_quantity_non_tax'] = $brokenQuantityNonTax;
    }

    /**
     * Livewire hook: whenever any nested product property updates, re-dispatch rows and clear row errors
     */
    public function updated($name, $value)
    {
        if (strpos($name, 'products.') === 0) {
            // clear any existing validation for this field
            unset($this->tableValidationErrors[$name]);
            unset($this->tableValidationErrors['rows']);
            $this->dispatch('rowsUpdated', $this->products);
        }
    }

    /**
     * Handle validation errors dispatched from parent
     */
    public function onTableValidationErrors(array $errors): void
    {
        $this->tableValidationErrors = $errors;
    }

    public function serialScanned(array $payload): void
    {
        $productId = $payload['product_id'] ?? null;
        $isBroken = $payload['is_broken'] ?? false;
        if (!$productId) return;

        // Find the product row
        $rowKey = collect($this->products)->search(fn ($p) => 
            ($p['id'] ?? null) == $productId &&
            ((bool)($p['is_broken_mode'] ?? false)) === (bool)$isBroken
        );
        if ($rowKey === false) {
            return; // Should not happen since productSelected is dispatched right before
        }

        $payload['productCompositeKey'] = $rowKey;
        $payload['serialNumber'] = $payload;

        $this->serialNumberSelected($payload);
    }

    public function render(): View
    {
        return view('livewire.transfer.transfer-product-table');
    }
}
