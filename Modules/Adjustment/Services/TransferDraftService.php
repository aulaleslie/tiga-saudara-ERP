<?php

namespace Modules\Adjustment\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Adjustment\DTOs\TransferFormState;
use Modules\Adjustment\Entities\Transfer;
use Modules\Setting\Entities\Location;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductSerialNumber;
use InvalidArgumentException;

class TransferDraftService
{
    private TransferAllocationPreviewService $allocationPreviewService;
    private TransferLifecycleService $lifecycleService;

    public function __construct(
        TransferAllocationPreviewService $allocationPreviewService,
        TransferLifecycleService $lifecycleService
    ) {
        $this->allocationPreviewService = $allocationPreviewService;
        $this->lifecycleService = $lifecycleService;
    }

    /**
     * Create, update, or resubmit a transfer from a draft state.
     * 
     * @param bool $atomicSubmit If true and creating a new transfer, atomically submit to PENDING; otherwise create as DRAFT
     */
    public function saveDraft(
        TransferFormState $state,
        User $actor,
        int $tenantSettingId,
        ?Transfer $transfer = null,
        ?string $idempotencyKey = null,
        bool $atomicSubmit = false
    ): Transfer {
        // Authoritatively validate locations
        $origin = Location::where('id', $state->originLocationId)->first();
        if (!$origin || (int) $origin->setting_id !== $tenantSettingId) {
            throw new InvalidArgumentException("Invalid origin location for current tenant.");
        }
        
        $destination = Location::where('id', $state->destinationLocationId)->first();
        if (!$destination) {
            throw new InvalidArgumentException("Invalid destination location.");
        }
        
        if ($origin->id === $destination->id) {
            throw new InvalidArgumentException("Origin and destination cannot be the same.");
        }

        if ($transfer) {
            $transferOriginSettingId = $transfer->relationLoaded('originLocation') 
                ? $transfer->originLocation?->setting_id 
                : Location::where('id', $transfer->origin_location_id)->value('setting_id');
                
            if ((int) $transferOriginSettingId !== $origin->setting_id) {
                throw new InvalidArgumentException("Cannot modify transfer from another tenant.");
            }
        }

        $productsData = [];

        foreach ($state->lines as $line) {
            $product = Product::find($line->productId);
            if (!$product) {
                throw new InvalidArgumentException("Product {$line->productId} not found.");
            }

            // Validate stock_managed requirement
            if (!$product->stock_managed) {
                throw new InvalidArgumentException("Product {$product->id} must have stock management enabled.");
            }

            $stock = ProductStock::where('product_id', $product->id)
                ->where('location_id', $origin->id)
                ->first();
            
            // Stock must exist for all transfers
            if (!$stock) {
                throw new InvalidArgumentException("No stock found for product {$product->id} at origin.");
            }

            $normalizedSerialIds = [];
            
            if ($line->isSerialNumberRequired) {
                // Extract serial IDs from selectedSerials (handle both ID arrays and object arrays)
                $serialIds = collect($line->selectedSerials)->map(function ($serial) {
                    return is_array($serial) || is_object($serial) ? $serial['id'] ?? $serial->id : $serial;
                })->toArray();
                
                // Normalize and deduplicate serial IDs
                $normalizedSerialIds = array_unique(array_map('intval', $serialIds));
                
                // Query by ID and validate all required properties
                $validSerials = ProductSerialNumber::whereKey($normalizedSerialIds)->get();
                    
                // Verify exact ID count matches after normalization
                if ($validSerials->count() !== count($normalizedSerialIds)) {
                    throw new InvalidArgumentException("One or more selected serials are invalid.");
                }
                
                // Validate each serial: product, location, status, dispatch availability, return process, mode
                foreach ($validSerials as $serial) {
                    if ((int) $serial->product_id !== (int) $product->id) {
                        throw new InvalidArgumentException("Serial {$serial->serial_number} does not belong to this product.");
                    }
                    if ((int) $serial->location_id !== (int) $origin->id) {
                        throw new InvalidArgumentException("Serial {$serial->serial_number} is not at the origin location.");
                    }
                    if ($serial->status !== ProductSerialNumber::STATUS_ACTIVE) {
                        throw new InvalidArgumentException("Serial {$serial->serial_number} is not active.");
                    }
                    if ($serial->dispatch_detail_id !== null) {
                        throw new InvalidArgumentException("Serial {$serial->serial_number} is already dispatched.");
                    }
                    if ($serial->is_in_return_process === true) {
                        throw new InvalidArgumentException("Serial {$serial->serial_number} is already in return process.");
                    }
                }
                
                // Verify mode compatibility: normal vs broken
                $normalSerials = $validSerials->filter(fn($s) => !$s->is_broken);
                $brokenSerials = $validSerials->filter(fn($s) => $s->is_broken);
                
                if ($line->isBrokenMode && $normalSerials->count() > 0) {
                    throw new InvalidArgumentException("Cannot include non-broken serials in broken mode.");
                }
                if (!$line->isBrokenMode && $brokenSerials->count() > 0) {
                    throw new InvalidArgumentException("Cannot include broken serials in normal mode.");
                }
                
                // Derive provenance from tax_id and is_broken
                // Tax = (is_broken=false AND tax_id!=null), NonTax = (is_broken=false AND tax_id=null)
                // BrokenTax = (is_broken=true AND tax_id!=null), BrokenNonTax = (is_broken=true AND tax_id=null)
                $quantityTax = $validSerials->filter(fn($s) => !$s->is_broken && $s->tax_id !== null)->count();
                $quantityNonTax = $validSerials->filter(fn($s) => !$s->is_broken && $s->tax_id === null)->count();
                $quantityBrokenTax = $validSerials->filter(fn($s) => $s->is_broken && $s->tax_id !== null)->count();
                $quantityBrokenNonTax = $validSerials->filter(fn($s) => $s->is_broken && $s->tax_id === null)->count();
                
                $taxQuantity = $line->isBrokenMode ? 0 : $quantityTax;
                $nonTaxQuantity = $line->isBrokenMode ? 0 : $quantityNonTax;
                $brokenTaxQuantity = $line->isBrokenMode ? $quantityBrokenTax : 0;
                $brokenNonTaxQuantity = $line->isBrokenMode ? $quantityBrokenNonTax : 0;
            } else {
                // For non-serialized products, allocate using stock preview
                $preview = $this->allocationPreviewService->previewAllocation($stock, $line->requestedBaseQuantity, $line->isBrokenMode);
                if ($preview->isInsufficient) {
                    throw new InvalidArgumentException("Insufficient stock for product {$product->id}.");
                }
                
                $taxQuantity = $line->isBrokenMode ? 0 : $preview->allocatedTax;
                $nonTaxQuantity = $line->isBrokenMode ? 0 : $preview->allocatedNonTax;
                $brokenTaxQuantity = $line->isBrokenMode ? $preview->allocatedTax : 0;
                $brokenNonTaxQuantity = $line->isBrokenMode ? $preview->allocatedNonTax : 0;
            }

            $productsData[] = [
                'product_id' => $product->id,
                'quantity' => $line->requestedBaseQuantity,
                'quantities' => [
                    'quantity_tax' => $taxQuantity,
                    'quantity_non_tax' => $nonTaxQuantity,
                    'quantity_broken_tax' => $brokenTaxQuantity,
                    'quantity_broken_non_tax' => $brokenNonTaxQuantity,
                ],
                'serial_numbers' => $normalizedSerialIds ?: $line->selectedSerials,
            ];
        }

        if (!$transfer) {
            if ($atomicSubmit) {
                return $this->lifecycleService->createPending(
                    $origin->id,
                    $destination->id,
                    $productsData,
                    $actor->id,
                    $idempotencyKey
                );
            }
            
            return $this->lifecycleService->createDraft(
                $origin->id,
                $destination->id,
                $productsData,
                $actor->id,
                $idempotencyKey
            );
        }

        // It's an update or resubmit
        if ($transfer->status === Transfer::STATUS_DRAFT) {
            // Is it a normal draft or a rejected draft being resubmitted?
            // Actually, TransferLifecycleService distinguishes resubmit by calling `resubmit()`.
            // Wait, resubmit moves it to PENDING. If we just want to save it as DRAFT, we use updateTransfer.
            return $this->lifecycleService->updateTransfer($transfer, $productsData, $actor->id);
        } elseif ($transfer->status === Transfer::STATUS_PENDING) {
            return $this->lifecycleService->updateTransfer($transfer, $productsData, $actor->id);
        } elseif ($transfer->status === Transfer::STATUS_REJECTED) {
            return $this->lifecycleService->resubmit($transfer, $productsData, $actor->id, $tenantSettingId);
        }

        throw new InvalidArgumentException("Transfer is in an uneditable state.");
    }
}
