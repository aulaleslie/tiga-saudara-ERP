<?php

namespace Modules\Adjustment\Services;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Entities\TransferReturnObligation;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use App\Services\SerialNumberHistoryService;
use Modules\Product\Entities\SerialNumberHistory;

class TransferMovementService
{
    /**
     * Dispatch the transfer, allocating stock authoritatively, checking for drift,
     * deducting inventory, and setting up return obligations.
     * 
     * @return array Dispatch info: ['drift_detected' => bool, 'current_hash' => string, 'actual_allocations' => array]
     */
    public function dispatch(Transfer $transfer, ?string $acknowledgedHash = null): array
    {
        $transfer->loadMissing(['products.product', 'originLocation.setting', 'destinationLocation.setting']);
        
        $productIds = $transfer->products->pluck('product_id')->sort()->values()->all();
        
        $stocks = ProductStock::whereIn('product_id', $productIds)
            ->where('location_id', $transfer->origin_location_id)
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');

        $isCrossTenant = $transfer->originLocation->setting_id !== $transfer->destinationLocation->setting_id;
        
        $actualAllocations = [];
        $hasDrift = false;
        
        foreach ($transfer->products as $transferProduct) {
            $stock = $stocks->get($transferProduct->product_id);
            if (!$stock) {
                throw new Exception("Data stok tidak ditemukan untuk produk ID {$transferProduct->product_id} di lokasi asal.");
            }
            
            $product = $transferProduct->product;
            
            if ($product->serial_number_required) {
                $allocation = $this->allocateSerialized($transferProduct, $transfer->origin_location_id);
            } else {
                $allocation = $this->allocateNonSerialized($transferProduct, $stock);
            }
            
            $actualAllocations[$transferProduct->id] = [
                'allocation' => $allocation,
                'stock' => $stock
            ];
            
            $previewTax = (int) $transferProduct->quantity_tax;
            $previewBrokenTax = (int) $transferProduct->quantity_broken_tax;
            
            $actualTax = $allocation['tax'];
            $actualBrokenTax = $allocation['broken_tax'];
            
            if ($actualTax > $previewTax || $actualBrokenTax > $previewBrokenTax) {
                $hasDrift = true;
            }
        }
        
        $currentHash = $this->computeAllocationHash($transfer->id, $transfer->revision, $actualAllocations);
        if ($hasDrift && $acknowledgedHash !== $currentHash) {
            throw new \Modules\Adjustment\Exceptions\AllocationDriftException(
                "Alokasi stok berubah sejak persetujuan. Eksposur pajak atau pengembalian wajib meningkat.",
                $currentHash,
                $actualAllocations
            );
        }
        
        foreach ($transfer->products as $transferProduct) {
            $allocationData = $actualAllocations[$transferProduct->id];
            $allocation = $allocationData['allocation'];
            $stock = $allocationData['stock'];
            
            $transferProduct->update([
                'dispatched_at' => now(),
                'dispatched_by' => auth()->id(),
                'dispatched_quantity' => $allocation['total'],
                'dispatched_quantity_tax' => $allocation['tax'],
                'dispatched_quantity_non_tax' => $allocation['non_tax'],
                'dispatched_quantity_broken_tax' => $allocation['broken_tax'],
                'dispatched_quantity_broken_non_tax' => $allocation['broken_non_tax'],
                'dispatched_serial_numbers' => $allocation['serials'],
            ]);
            
            if (!empty($allocation['serials'])) {
                $serialIds = collect($allocation['serials'])->pluck('id')->all();
                ProductSerialNumber::whereIn('id', $serialIds)
                    ->update(['location_id' => $transfer->destination_location_id]);

                foreach ($allocation['serials'] as $serialData) {
                    SerialNumberHistoryService::record(
                        $serialData['id'],
                        SerialNumberHistory::EVENT_LOCATION_TRANSFER,
                        $transfer->destination_location_id,
                        $transfer,
                        sprintf(
                            'Transfer dari %s ke %s',
                            $transfer->originLocation->name ?? '-',
                            $transfer->destinationLocation->name ?? '-'
                        )
                    );
                }
            }
            
            $snapshot = $this->applyInventoryChange($transferProduct->product, $stock, $allocation, false);
            
            $reason = sprintf(
                'Transfer stock to %s - %s (#%d)',
                $transfer->destinationLocation->setting->company_name ?? '-',
                $transfer->destinationLocation->name ?? '-',
                $transfer->id
            );

            $this->recordTransaction(
                $transfer,
                $transferProduct->product_id,
                $snapshot,
                $transfer->origin_location_id,
                $transfer->originLocation->setting_id,
                $reason,
                false
            );
            
            if ($isCrossTenant) {
                $taxQty = $allocation['tax'];
                $brokenTaxQty = $allocation['broken_tax'];
                
                if ($taxQty > 0 || $brokenTaxQty > 0) {
                    $taxedSerials = collect($allocation['serials'] ?? [])
                        ->filter(fn($s) => !empty($s['taxable']) || !empty($s['tax_id']))
                        ->values()
                        ->toArray();
                        
                    TransferReturnObligation::updateOrCreate(
                        [
                            'transfer_id' => $transfer->id,
                            'transfer_product_id' => $transferProduct->id,
                        ],
                        [
                            'required_quantity_tax' => $taxQty,
                            'required_quantity_broken_tax' => $brokenTaxQty,
                            'return_dispatched_quantity_tax' => 0,
                            'return_dispatched_quantity_broken_tax' => 0,
                            'return_received_quantity_tax' => 0,
                            'return_received_quantity_broken_tax' => 0,
                            'exact_serialized_obligations' => !empty($taxedSerials) ? $taxedSerials : null,
                        ]
                    );
                }
            }
        }
        
        // Return dispatch review information for history recording
        return [
            'drift_detected' => $hasDrift,
            'current_hash' => $currentHash,
            'actual_allocations' => $actualAllocations,
            'acknowledged_hash' => $acknowledgedHash,
        ];
    }

    public function receive(Transfer $transfer): string
    {
        $transfer->loadMissing(['products.product', 'originLocation.setting', 'destinationLocation.setting']);

        $isCrossTenant = $transfer->originLocation->setting_id !== $transfer->destinationLocation->setting_id;
        $hasTaxObligation = false;

        foreach ($transfer->products as $transferProduct) {
            $allocation = [
                'total' => (int) $transferProduct->dispatched_quantity,
                'tax' => (int) $transferProduct->dispatched_quantity_tax,
                'non_tax' => (int) $transferProduct->dispatched_quantity_non_tax,
                'broken_tax' => (int) $transferProduct->dispatched_quantity_broken_tax,
                'broken_non_tax' => (int) $transferProduct->dispatched_quantity_broken_non_tax,
            ];

            $stock = ProductStock::firstOrCreate(
                ['product_id' => $transferProduct->product_id, 'location_id' => $transfer->destination_location_id],
                [
                    'quantity' => 0, 'quantity_non_tax' => 0, 'quantity_tax' => 0,
                    'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'broken_quantity' => 0
                ]
            );
            
            $stock = ProductStock::where('id', $stock->id)->lockForUpdate()->first();

            $snapshot = $this->applyInventoryChange($transferProduct->product, $stock, $allocation, true);

            $reason = sprintf(
                'Receive stock from %s - %s (#%d)',
                $transfer->originLocation->setting->company_name ?? '-',
                $transfer->originLocation->name ?? '-',
                $transfer->id
            );

            $this->recordTransaction(
                $transfer,
                $transferProduct->product_id,
                $snapshot,
                $transfer->destination_location_id,
                $transfer->destinationLocation->setting_id,
                $reason,
                true
            );

            if ($isCrossTenant && ($allocation['tax'] > 0 || $allocation['broken_tax'] > 0)) {
                $hasTaxObligation = true;
                
                // Create return obligation for tax items
                TransferReturnObligation::updateOrCreate(
                    [
                        'transfer_id' => $transfer->id,
                        'transfer_product_id' => $transferProduct->id,
                    ],
                    [
                        'required_quantity_tax' => (int) $allocation['tax'],
                        'required_quantity_broken_tax' => (int) $allocation['broken_tax'],
                        'return_dispatched_quantity_tax' => 0,
                        'return_dispatched_quantity_broken_tax' => 0,
                        'return_received_quantity_tax' => 0,
                        'return_received_quantity_broken_tax' => 0,
                        'exact_serialized_obligations' => null,
                    ]
                );
            }
        }

        $newStatus = ($isCrossTenant && $hasTaxObligation) 
            ? Transfer::STATUS_AWAITING_RETURN 
            : Transfer::STATUS_COMPLETED;

        $transfer->update([
            'status'      => $newStatus,
            'received_by' => auth()->id(),
            'received_at' => now(),
        ]);
        
        return $newStatus;
    }

    public function dispatchReturn(Transfer $transfer): void
    {
        $transfer->loadMissing(['products.product', 'originLocation.setting', 'destinationLocation.setting', 'returnObligations']);
        
        foreach ($transfer->products as $transferProduct) {
            $obligation = $transfer->returnObligations->where('transfer_product_id', $transferProduct->id)->first();
            if (!$obligation) continue;

            $allocation = [
                'total' => (int) ($obligation->required_quantity_tax + $obligation->required_quantity_broken_tax),
                'tax' => (int) $obligation->required_quantity_tax,
                'non_tax' => 0,
                'broken_tax' => (int) $obligation->required_quantity_broken_tax,
                'broken_non_tax' => 0,
            ];
            
            if ($allocation['total'] === 0) continue;

            $stock = ProductStock::where('product_id', $transferProduct->product_id)
                ->where('location_id', $transfer->destination_location_id)
                ->lockForUpdate()
                ->first();
                
            if (!$stock) throw new Exception("Data stok tidak ditemukan untuk return.");

            $taxedSerials = $obligation->exact_serialized_obligations ?? [];
            if (!empty($taxedSerials)) {
                $serialIds = collect($taxedSerials)->pluck('id')->all();
                
                // Lock and validate all obligated serials before moving
                $serialModels = ProductSerialNumber::whereIn('id', $serialIds)
                    ->lockForUpdate()
                    ->get();
                
                // Verify every obligated serial exists and has correct properties
                if ($serialModels->count() !== count($serialIds)) {
                    throw new Exception("Some obligated serial numbers were not found or have been deleted.");
                }
                
                foreach ($serialModels as $serial) {
                    // Must be at the destination location
                    if ($serial->location_id !== $transfer->destination_location_id) {
                        throw new Exception("Serial {$serial->serial_number} is not at the destination location.");
                    }
                    
                    // Validate serial against required conditions
                    if ($serial->status !== ProductSerialNumber::STATUS_ACTIVE) {
                        throw new Exception("Serial {$serial->serial_number} is not active.");
                    }
                    
                    if ($serial->dispatch_detail_id !== null) {
                        throw new Exception("Serial {$serial->serial_number} is reserved by another dispatch.");
                    }
                    
                    if ($serial->is_in_return_process === true) {
                        throw new Exception("Serial {$serial->serial_number} is already in return process.");
                    }
                    
                    // Must be taxed
                    if (!$serial->tax_id) {
                        throw new Exception("Serial {$serial->serial_number} is not taxed but was obligated for return.");
                    }
                    
                    // Find matching obligation data to verify broken provenance
                    $obligationData = collect($taxedSerials)->firstWhere('id', $serial->id);
                    if ($obligationData && isset($obligationData['is_broken'])) {
                        if ((bool) $obligationData['is_broken'] !== (bool) $serial->is_broken) {
                            throw new Exception("Serial {$serial->serial_number} broken status does not match obligation.");
                        }
                    }
                }
                
                // Update locations and record history
                ProductSerialNumber::whereIn('id', $serialIds)
                    ->update(['location_id' => $transfer->origin_location_id]);

                foreach ($serialModels as $serial) {
                    SerialNumberHistoryService::record(
                        $serial->id,
                        SerialNumberHistory::EVENT_LOCATION_TRANSFER,
                        $transfer->origin_location_id,
                        $transfer,
                        sprintf(
                            'Return dari %s ke %s',
                            $transfer->destinationLocation->name ?? '-',
                            $transfer->originLocation->name ?? '-'
                        )
                    );
                }
            }

            $snapshot = $this->applyInventoryChange($transferProduct->product, $stock, $allocation, false);

            $reason = sprintf(
                'Return stock to %s - %s (#%d)',
                $transfer->originLocation->setting->company_name ?? '-',
                $transfer->originLocation->name ?? '-',
                $transfer->id
            );

            $this->recordTransaction(
                $transfer,
                $transferProduct->product_id,
                $snapshot,
                $transfer->destination_location_id,
                $transfer->destinationLocation->setting_id,
                $reason,
                false
            );
            
            $obligation->update([
                'return_dispatched_quantity_tax' => $allocation['tax'],
                'return_dispatched_quantity_broken_tax' => $allocation['broken_tax'],
            ]);
        }

        $transfer->update([
            'status'                => Transfer::STATUS_RETURN_DISPATCHED,
            'return_dispatched_by'  => auth()->id(),
            'return_dispatched_at'  => now(),
        ]);
    }

    public function receiveReturn(Transfer $transfer): void
    {
        $transfer->loadMissing(['products.product', 'originLocation.setting', 'destinationLocation.setting', 'returnObligations']);
        
        foreach ($transfer->products as $transferProduct) {
            $obligation = $transfer->returnObligations->where('transfer_product_id', $transferProduct->id)->first();
            if (!$obligation) continue;
            
            $allocation = [
                'total' => (int) ($obligation->return_dispatched_quantity_tax + $obligation->return_dispatched_quantity_broken_tax),
                'tax' => (int) $obligation->return_dispatched_quantity_tax,
                'non_tax' => 0,
                'broken_tax' => (int) $obligation->return_dispatched_quantity_broken_tax,
                'broken_non_tax' => 0,
            ];
            
            if ($allocation['total'] === 0) continue;

            $stock = ProductStock::where('product_id', $transferProduct->product_id)
                ->where('location_id', $transfer->origin_location_id)
                ->lockForUpdate()
                ->first();
                
            if (!$stock) throw new Exception("Data stok tidak ditemukan untuk receive return.");

            $snapshot = $this->applyInventoryChange($transferProduct->product, $stock, $allocation, true);

            $reason = sprintf(
                'Receive returned stock from %s - %s (#%d)',
                $transfer->destinationLocation->setting->company_name ?? '-',
                $transfer->destinationLocation->name ?? '-',
                $transfer->id
            );

            $this->recordTransaction(
                $transfer,
                $transferProduct->product_id,
                $snapshot,
                $transfer->origin_location_id,
                $transfer->originLocation->setting_id,
                $reason,
                true
            );
            
            $obligation->update([
                'return_received_quantity_tax' => $allocation['tax'],
                'return_received_quantity_broken_tax' => $allocation['broken_tax'],
            ]);
        }

        $transfer->update([
            'status'               => Transfer::STATUS_COMPLETED,
            'return_received_by'   => auth()->id(),
            'return_received_at'   => now(),
        ]);
    }
    
    public function computeAllocationHash(int $transferId, int $revision, array $allocations): string
    {
        $data = [
            'transfer_id' => $transferId,
            'revision' => $revision,
            'allocations' => []
        ];
        
        foreach ($allocations as $transferProductId => $allocData) {
            $alloc = $allocData['allocation'];
            $data['allocations'][$transferProductId] = [
                'tax' => $alloc['tax'],
                'non_tax' => $alloc['non_tax'],
                'broken_tax' => $alloc['broken_tax'],
                'broken_non_tax' => $alloc['broken_non_tax'],
            ];
        }
        
        return hash('sha256', json_encode($data));
    }
    
    private function allocateSerialized(TransferProduct $transferProduct, int $locationId): array
    {
        $serials = $transferProduct->serial_numbers ?? [];
        $tax = 0;
        $nonTax = 0;
        $brokenTax = 0;
        $brokenNonTax = 0;
        
        $serialIds = collect($serials)->pluck('id')->filter()->values()->all();
        
        if (empty($serialIds)) {
            throw new Exception("Produk membutuhkan nomor seri tetapi tidak ada yang dipilih.");
        }
        
        $serialModels = ProductSerialNumber::whereIn('id', $serialIds)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->orderBy('id')
            ->get();
            
        if ($serialModels->count() !== count($serialIds)) {
            throw new Exception("Beberapa nomor seri tidak ditemukan atau tidak tersedia di lokasi ini.");
        }

        $normalizedSerials = [];

        foreach ($serialModels as $serial) {
            $isTax = (bool) $serial->tax_id;
            $isBroken = (bool) $serial->is_broken;

            if ($isBroken) {
                if ($isTax) $brokenTax++;
                else $brokenNonTax++;
            } else {
                if ($isTax) $tax++;
                else $nonTax++;
            }
            
            $normalizedSerials[] = [
                'id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'tax_id' => $serial->tax_id,
                'taxable' => $isTax,
                'is_broken' => $isBroken,
            ];
        }

        return [
            'total' => $tax + $nonTax + $brokenTax + $brokenNonTax,
            'tax' => $tax,
            'non_tax' => $nonTax,
            'broken_tax' => $brokenTax,
            'broken_non_tax' => $brokenNonTax,
            'serials' => $normalizedSerials,
        ];
    }
    
    private function allocateNonSerialized(TransferProduct $transferProduct, ProductStock $stock): array
    {
        $requestedQuantity = (int) $transferProduct->quantity;
        
        $requestedBroken = (int) ($transferProduct->quantity_broken_tax + $transferProduct->quantity_broken_non_tax);
        $requestedNormal = $requestedQuantity - $requestedBroken;
        
        $allocatedTax = 0;
        $allocatedNonTax = 0;
        $allocatedBrokenTax = 0;
        $allocatedBrokenNonTax = 0;

        if ($requestedNormal > 0) {
            $availableNonTax = (int) $stock->quantity_non_tax;
            $availableTax = (int) $stock->quantity_tax;

            $allocNonTax = min($requestedNormal, $availableNonTax);
            $rem = $requestedNormal - $allocNonTax;
            $allocTax = min($rem, $availableTax);

            if ($allocNonTax + $allocTax < $requestedNormal) {
                throw new Exception("Stok tidak mencukupi untuk dialokasikan ke produk ID {$stock->product_id}.");
            }
            
            $allocatedNonTax = $allocNonTax;
            $allocatedTax = $allocTax;
        }
        
        if ($requestedBroken > 0) {
            $availableBrokenNonTax = (int) $stock->broken_quantity_non_tax;
            $availableBrokenTax = (int) $stock->broken_quantity_tax;
            
            $allocBrokenNonTax = min($requestedBroken, $availableBrokenNonTax);
            $rem = $requestedBroken - $allocBrokenNonTax;
            $allocBrokenTax = min($rem, $availableBrokenTax);
            
            if ($allocBrokenNonTax + $allocBrokenTax < $requestedBroken) {
                throw new Exception("Stok rusak tidak mencukupi untuk dialokasikan ke produk ID {$stock->product_id}.");
            }
            
            $allocatedBrokenNonTax = $allocBrokenNonTax;
            $allocatedBrokenTax = $allocBrokenTax;
        }

        return [
            'total' => $allocatedTax + $allocatedNonTax + $allocatedBrokenTax + $allocatedBrokenNonTax,
            'tax' => $allocatedTax,
            'non_tax' => $allocatedNonTax,
            'broken_tax' => $allocatedBrokenTax,
            'broken_non_tax' => $allocatedBrokenNonTax,
            'serials' => null,
        ];
    }
    
    private function applyInventoryChange($product, ProductStock $stock, array $allocation, bool $increase): array
    {
        $total = $allocation['total'];
        $brokenTotal = $allocation['broken_tax'] + $allocation['broken_non_tax'];

        $previousStock = [
            'quantity_tax'       => (int) ($stock->quantity_tax ?? 0),
            'quantity_non_tax'   => (int) ($stock->quantity_non_tax ?? 0),
            'broken_tax'         => (int) ($stock->broken_quantity_tax ?? 0),
            'broken_non_tax'     => (int) ($stock->broken_quantity_non_tax ?? 0),
        ];

        $previousStockQuantity = (int) ($stock->quantity ?? array_sum($previousStock));
        $previousBrokenQuantity = (int) ($stock->broken_quantity ?? ($previousStock['broken_tax'] + $previousStock['broken_non_tax']));
        $previousProductQuantity = (int) ($product->product_quantity ?? 0);
        $previousProductBroken   = (int) ($product->broken_quantity ?? 0);

        if (! $increase) {
            // Before subtraction, enforce that sufficient inventory exists
            if ($previousStock['quantity_tax'] < $allocation['tax']) {
                throw new Exception("Insufficient tax quantity. Available: {$previousStock['quantity_tax']}, Required: {$allocation['tax']}.");
            }
            if ($previousStock['quantity_non_tax'] < $allocation['non_tax']) {
                throw new Exception("Insufficient non-tax quantity. Available: {$previousStock['quantity_non_tax']}, Required: {$allocation['non_tax']}.");
            }
            if ($previousStock['broken_tax'] < $allocation['broken_tax']) {
                throw new Exception("Insufficient broken tax quantity. Available: {$previousStock['broken_tax']}, Required: {$allocation['broken_tax']}.");
            }
            if ($previousStock['broken_non_tax'] < $allocation['broken_non_tax']) {
                throw new Exception("Insufficient broken non-tax quantity. Available: {$previousStock['broken_non_tax']}, Required: {$allocation['broken_non_tax']}.");
            }

            $stock->quantity_tax            = $previousStock['quantity_tax'] - $allocation['tax'];
            $stock->quantity_non_tax        = $previousStock['quantity_non_tax'] - $allocation['non_tax'];
            $stock->broken_quantity_tax     = $previousStock['broken_tax'] - $allocation['broken_tax'];
            $stock->broken_quantity_non_tax = $previousStock['broken_non_tax'] - $allocation['broken_non_tax'];

            $product->product_quantity = max(0, $previousProductQuantity - $total);
            $product->broken_quantity  = max(0, $previousProductBroken - $brokenTotal);
        } else {
            $stock->quantity_tax            = $previousStock['quantity_tax'] + $allocation['tax'];
            $stock->quantity_non_tax        = $previousStock['quantity_non_tax'] + $allocation['non_tax'];
            $stock->broken_quantity_tax     = $previousStock['broken_tax'] + $allocation['broken_tax'];
            $stock->broken_quantity_non_tax = $previousStock['broken_non_tax'] + $allocation['broken_non_tax'];

            $product->product_quantity = $previousProductQuantity + $total;
            $product->broken_quantity  = $previousProductBroken + $brokenTotal;
        }

        $stock->quantity        = max(0, $stock->quantity_tax + $stock->quantity_non_tax + $stock->broken_quantity_tax + $stock->broken_quantity_non_tax);
        $stock->broken_quantity = max(0, $stock->broken_quantity_tax + $stock->broken_quantity_non_tax);

        $stock->save();
        $product->save();

        return [
            'total'            => $total,
            'quantities'       => [
                'tax' => $allocation['tax'],
                'non_tax' => $allocation['non_tax'],
                'broken_tax' => $allocation['broken_tax'],
                'broken_non_tax' => $allocation['broken_non_tax'],
            ],
            'previous_stock'   => [
                'quantity'      => $previousStockQuantity,
                'broken'        => $previousBrokenQuantity,
                'quantity_tax'  => $previousStock['quantity_tax'],
                'quantity_non_tax' => $previousStock['quantity_non_tax'],
                'broken_tax'    => $previousStock['broken_tax'],
                'broken_non_tax'=> $previousStock['broken_non_tax'],
            ],
            'current_stock'    => [
                'quantity'      => (int) $stock->quantity,
                'broken'        => (int) $stock->broken_quantity,
                'quantity_tax'  => (int) $stock->quantity_tax,
                'quantity_non_tax' => (int) $stock->quantity_non_tax,
                'broken_tax'    => (int) $stock->broken_quantity_tax,
                'broken_non_tax'=> (int) $stock->broken_quantity_non_tax,
            ],
            'previous_product' => [
                'quantity' => $previousProductQuantity,
                'broken'   => $previousProductBroken,
            ],
            'current_product'  => [
                'quantity' => (int) $product->product_quantity,
                'broken'   => (int) $product->broken_quantity,
            ],
        ];
    }
    
    private function recordTransaction(
        Transfer $transfer,
        int $productId,
        array $snapshot,
        int $locationId,
        int $settingId,
        string $reason,
        bool $increase
    ): void {
        Transaction::create([
            'product_id'                   => $productId,
            'setting_id'                   => $settingId,
            'type'                         => 'TRF',
            'quantity'                     => $increase ? $snapshot['total'] : -$snapshot['total'],
            'current_quantity'             => $snapshot['current_stock']['quantity'],
            'broken_quantity'              => $snapshot['current_stock']['broken'],
            'previous_quantity'            => $snapshot['previous_product']['quantity'],
            'previous_quantity_at_location'=> $snapshot['previous_stock']['quantity'],
            'after_quantity'               => $snapshot['current_product']['quantity'],
            'after_quantity_at_location'   => $snapshot['current_stock']['quantity'],
            'quantity_tax'                 => $snapshot['current_stock']['quantity_tax'],
            'quantity_non_tax'             => $snapshot['current_stock']['quantity_non_tax'],
            'broken_quantity_tax'          => $snapshot['current_stock']['broken_tax'],
            'broken_quantity_non_tax'      => $snapshot['current_stock']['broken_non_tax'],
            'location_id'                  => $locationId,
            'user_id'                      => auth()->id(),
            'reason'                       => $reason,
        ]);
    }
}
