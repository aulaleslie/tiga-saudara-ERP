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
    private TransferFormStateMapper $formStateMapper;

    public function __construct(
        TransferAllocationPreviewService $allocationPreviewService,
        TransferLifecycleService $lifecycleService,
        TransferFormStateMapper $formStateMapper
    ) {
        $this->allocationPreviewService = $allocationPreviewService;
        $this->lifecycleService = $lifecycleService;
        $this->formStateMapper = $formStateMapper;
    }

    private function isMixedConditionHistory(Transfer $transfer): bool
    {
        return $this->formStateMapper->isMixedConditionHistory($transfer);
    }

    /**
     * Save a transfer as a DRAFT. Destination is optional: it may be left
     * empty while origin, mode, and product rows are prepared, and supplied
     * later. Any destination that is supplied is authoritatively validated.
     */
    public function saveDraft(
        TransferFormState $state,
        User $actor,
        int $tenantSettingId,
        ?Transfer $transfer = null,
        ?string $idempotencyKey = null
    ): Transfer {
        $origin = $this->validateOrigin($state, $tenantSettingId, $transfer);
        $destination = $this->validateOptionalDestination($state, $origin, $transfer);

        if (!in_array($state->stockCondition, Transfer::CONDITIONS, true)) {
            throw new InvalidArgumentException("A valid stock condition (GOOD or BREAKAGE) must be selected.");
        }

        if (empty($state->lines)) {
            throw new InvalidArgumentException("At least one product row is required to save a draft.");
        }

        if ($transfer) {
            $this->assertSameTenant($transfer, $origin);
        }

        if (!$transfer) {
            $productsData = $this->buildProductsData($state, $origin, $tenantSettingId, $transfer);

            return $this->lifecycleService->createDraft(
                $origin->id,
                $destination?->id,
                $state->stockCondition,
                $productsData,
                $actor->id,
                $idempotencyKey
            );
        }

        if (!in_array($transfer->status, [Transfer::STATUS_DRAFT, Transfer::STATUS_PENDING], true)) {
            throw new InvalidArgumentException(
                "Transfer is in an uneditable state. REJECTED transfers must be acknowledged back to DRAFT first."
            );
        }

        // Historical transfers whose lines mix good and broken stock (no
        // explicit persisted condition) predate the single-condition
        // constraint and can never be rewritten through ordinary editing:
        // their contents are preserved as view-only, and no condition may be
        // assigned or changed through this boundary.
        if ($this->isMixedConditionHistory($transfer)) {
            throw new InvalidArgumentException(
                "This transfer contains a historical mix of good and broken stock and cannot be edited."
            );
        }

        // Stock condition is immutable once a transfer is first persisted,
        // regardless of its current DRAFT or PENDING status: condition
        // determines stock/serial eligibility for every row, and an existing
        // transfer's rows are always validated against its persisted
        // condition. Checked before building product rows so this fast,
        // clear rejection is never masked by an unrelated stock error
        // produced while validating rows against the wrong mode.
        if ($transfer->stock_condition !== null && $transfer->stock_condition !== $state->stockCondition) {
            throw new InvalidArgumentException(
                "Stock condition cannot be changed on an existing transfer."
            );
        }

        // Destination is immutable once a transfer is PENDING: only DRAFT
        // edits may change it (via this same method or authoritatively at
        // submission). A PENDING edit must submit the header's existing
        // destination, or be rejected outright, so product rows can never be
        // saved under a destination that disagrees with the persisted
        // header.
        if ($transfer->status === Transfer::STATUS_PENDING) {
            if ((int) $transfer->destination_location_id !== (int) $destination?->id) {
                throw new InvalidArgumentException(
                    "Destination cannot be changed on a PENDING transfer."
                );
            }
        }

        $productsData = $this->buildProductsData($state, $origin, $tenantSettingId, $transfer);

        return $this->lifecycleService->updateTransfer($transfer, $destination?->id, $state->stockCondition, $productsData, $actor->id);
    }

    /**
     * Create a new transfer and immediately submit it for approval, as one
     * atomic operation. Used by legacy HTTP callers whose contract has
     * always required a destination and expects an atomic create-and-submit
     * outcome: if submission fails, no draft is left behind.
     */
    public function createAndSubmitForApproval(
        TransferFormState $state,
        User $actor,
        int $tenantSettingId,
        ?string $idempotencyKey = null
    ): Transfer {
        return DB::transaction(function () use ($state, $actor, $tenantSettingId, $idempotencyKey) {
            $transfer = $this->saveDraft($state, $actor, $tenantSettingId, null, $idempotencyKey);

            return $this->submitForApproval($state, $actor, $tenantSettingId, $transfer);
        });
    }

    /**
     * Submit an existing DRAFT for approval. Locks the transfer first, then
     * revalidates everything (destination, origin, mode, products, stock,
     * conversions, serials) against that locked row inside a single
     * transaction, and atomically transitions it to PENDING. No other
     * process can observe or act on the transfer between validation and the
     * PENDING transition.
     */
    public function submitForApproval(
        TransferFormState $state,
        User $actor,
        int $tenantSettingId,
        Transfer $transfer
    ): Transfer {
        if ($transfer->status !== Transfer::STATUS_DRAFT) {
            throw new InvalidArgumentException("Only DRAFT transfers can be submitted for approval.");
        }

        if (!$state->destinationLocationId) {
            throw new InvalidArgumentException("A destination location is required to submit for approval.");
        }

        if (!in_array($state->stockCondition, Transfer::CONDITIONS, true)) {
            throw new InvalidArgumentException("A valid stock condition (GOOD or BREAKAGE) must be selected.");
        }

        if (empty($state->lines)) {
            throw new InvalidArgumentException("At least one product row is required to submit for approval.");
        }

        // Pre-flight checks above give fast, clear errors for missing input.
        // The authoritative revalidation happens again below against the
        // locked row, since origin/destination/product/stock/serial state
        // could have changed since the form was rendered.
        $origin = $this->validateOriginForSubmission($state, $tenantSettingId, $transfer);
        $destination = $this->validateDestinationForSubmission($state->destinationLocationId, $origin, $transfer);

        return $this->lifecycleService->submitTransfer(
            $transfer,
            $actor->id,
            function (Transfer $lockedTransfer) use ($state, $tenantSettingId) {
                $this->assertSameTenant($lockedTransfer, null, $tenantSettingId);

                $origin = $this->validateOriginForSubmission($state, $tenantSettingId, $lockedTransfer);
                $destination = $this->validateDestinationForSubmission($state->destinationLocationId, $origin, $lockedTransfer);

                return $this->buildProductsData($state, $origin, $tenantSettingId, $lockedTransfer);
            },
            $destination->id,
            $state->stockCondition
        );
    }

    private function validateOrigin(TransferFormState $state, int $tenantSettingId, ?Transfer $transfer): Location
    {
        $origin = Location::where('id', $state->originLocationId)->first();
        if (!$origin || (int) $origin->setting_id !== $tenantSettingId) {
            throw new InvalidArgumentException("Invalid origin location for current tenant.");
        }

        if (!$origin->is_active && (!$transfer || (int) $transfer->origin_location_id !== $origin->id)) {
            throw new InvalidArgumentException("Origin location is inactive.");
        }

        // Origin is immutable once a transfer exists: document numbering is
        // sequenced per origin, and stock/serial validation is scoped to the
        // transfer's persisted origin. Reject any state whose origin differs
        // from the existing transfer rather than silently validating rows
        // against a different origin while never persisting the change.
        if ($transfer && (int) $transfer->origin_location_id !== $origin->id) {
            throw new InvalidArgumentException(
                "Origin location cannot be changed on an existing transfer. Create a new transfer instead."
            );
        }

        return $origin;
    }

    private function validateOptionalDestination(TransferFormState $state, Location $origin, ?Transfer $transfer): ?Location
    {
        if (!$state->destinationLocationId) {
            return null;
        }

        return $this->validateDestination($state->destinationLocationId, $origin, $transfer);
    }

    private function validateDestination(int $destinationLocationId, Location $origin, ?Transfer $transfer): Location
    {
        $destination = Location::where('id', $destinationLocationId)->first();
        if (!$destination) {
            throw new InvalidArgumentException("Invalid destination location.");
        }

        if ($origin->id === $destination->id) {
            throw new InvalidArgumentException("Origin and destination cannot be the same.");
        }

        if (!$destination->is_active && (!$transfer || (int) $transfer->destination_location_id !== $destination->id)) {
            throw new InvalidArgumentException("Destination location is inactive.");
        }

        if ((bool) $origin->is_consignment !== (bool) $destination->is_consignment) {
            throw new InvalidArgumentException("Transfer stok antara lokasi standar dan lokasi konsinyasi tidak diperbolehkan.");
        }

        return $destination;
    }

    /**
     * Origin validation for the DRAFT->PENDING submission boundary. Unlike
     * draft retention, submission requires the origin to be active right
     * now with no "already selected" exception: a location deactivated
     * after the draft was saved must block submission rather than silently
     * passing through because it is already on the transfer.
     */
    private function validateOriginForSubmission(TransferFormState $state, int $tenantSettingId, Transfer $transfer): Location
    {
        $origin = Location::where('id', $state->originLocationId)->first();
        if (!$origin || (int) $origin->setting_id !== $tenantSettingId) {
            throw new InvalidArgumentException("Invalid origin location for current tenant.");
        }

        if (!$origin->is_active) {
            throw new InvalidArgumentException("Origin location is inactive and cannot be submitted for approval.");
        }

        if ((int) $transfer->origin_location_id !== $origin->id) {
            throw new InvalidArgumentException(
                "Origin location cannot be changed on an existing transfer. Create a new transfer instead."
            );
        }

        return $origin;
    }

    /**
     * Destination validation for the DRAFT->PENDING submission boundary.
     * Unlike draft retention, submission requires the destination to be
     * active right now with no "already selected" exception.
     */
    private function validateDestinationForSubmission(int $destinationLocationId, Location $origin, Transfer $transfer): Location
    {
        $destination = Location::where('id', $destinationLocationId)->first();
        if (!$destination) {
            throw new InvalidArgumentException("Invalid destination location.");
        }

        if ($origin->id === $destination->id) {
            throw new InvalidArgumentException("Origin and destination cannot be the same.");
        }

        if (!$destination->is_active) {
            throw new InvalidArgumentException("Destination location is inactive and cannot be submitted for approval.");
        }

        if ((bool) $origin->is_consignment !== (bool) $destination->is_consignment) {
            throw new InvalidArgumentException("Transfer stok antara lokasi standar dan lokasi konsinyasi tidak diperbolehkan.");
        }

        return $destination;
    }

    private function assertSameTenant(Transfer $transfer, ?Location $origin = null, ?int $tenantSettingId = null): void
    {
        $transferOriginSettingId = $transfer->relationLoaded('originLocation')
            ? $transfer->originLocation?->setting_id
            : Location::where('id', $transfer->origin_location_id)->value('setting_id');

        $expectedSettingId = $origin?->setting_id ?? $tenantSettingId;

        if ((int) $transferOriginSettingId !== (int) $expectedSettingId) {
            throw new InvalidArgumentException("Cannot modify transfer from another tenant.");
        }
    }

    /**
     * @return array<int, array>
     */
    private function buildProductsData(TransferFormState $state, Location $origin, int $tenantSettingId, ?Transfer $transfer): array
    {
        $productsData = [];

        $expectedBrokenMode = $state->stockCondition === Transfer::CONDITION_BREAKAGE;

        foreach ($state->lines as $line) {
            if ($line->isBrokenMode !== $expectedBrokenMode) {
                throw new InvalidArgumentException("Product row stock condition does not match the transfer's selected condition.");
            }

            $product = Product::find($line->productId);
            if (!$product) {
                throw new InvalidArgumentException("Product {$line->productId} not found.");
            }

            // Validate product belongs to current tenant
            if ((int) $product->setting_id !== $tenantSettingId) {
                throw new InvalidArgumentException("Product {$product->id} does not belong to current tenant.");
            }

            // Validate stock_managed requirement
            if (!$product->stock_managed) {
                throw new InvalidArgumentException("Product {$product->id} must have stock management enabled.");
            }

            // Inactive products may only be retained if already present on the transfer being
            // edited; any newly added line must reference an active product.
            $productAlreadyOnTransfer = $transfer
                && $transfer->products()->where('product_id', $product->id)->exists();
            if (!$product->is_active && !$productAlreadyOnTransfer) {
                throw new InvalidArgumentException("Product {$product->id} is inactive.");
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

                // Verify serialized quantity consistency: selected serial count must equal requestedBaseQuantity
                if (count($normalizedSerialIds) !== (int) $line->requestedBaseQuantity) {
                    throw new InvalidArgumentException(
                        "Selected serial count (" . count($normalizedSerialIds) . ") must match requested quantity (" . $line->requestedBaseQuantity . ")."
                    );
                }

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
                    if ($line->isBrokenMode) {
                        if (!$serial->isAvailableBroken()) {
                            throw new InvalidArgumentException("Serial {$serial->serial_number} tidak tersedia untuk transfer barang rusak.");
                        }
                    } else {
                        if (!$serial->isSellable()) {
                            throw new InvalidArgumentException("Serial {$serial->serial_number} tidak aktif atau tidak siap jual.");
                        }
                    }
                    if ($serial->dispatch_detail_id !== null) {
                        throw new InvalidArgumentException("Serial {$serial->serial_number} is already dispatched.");
                    }
                    if ($serial->is_in_return_process === true) {
                        throw new InvalidArgumentException("Serial {$serial->serial_number} is already in return process.");
                    }
                }

                // Verify mode compatibility using canonical helpers
                $normalSerials = $validSerials->filter(fn($s) => $s->isSellable());
                $brokenSerials = $validSerials->filter(fn($s) => $s->isAvailableBroken());

                if ($line->isBrokenMode && $normalSerials->count() > 0) {
                    throw new InvalidArgumentException("Cannot include non-broken serials in broken mode.");
                }
                if (!$line->isBrokenMode && $brokenSerials->count() > 0) {
                    throw new InvalidArgumentException("Cannot include broken serials in normal mode.");
                }

                // Derive provenance from tax_id and canonical broken condition
                $quantityTax = $validSerials->filter(fn($s) => $s->isSellable() && $s->tax_id !== null)->count();
                $quantityNonTax = $validSerials->filter(fn($s) => $s->isSellable() && $s->tax_id === null)->count();
                $quantityBrokenTax = $validSerials->filter(fn($s) => $s->isAvailableBroken() && $s->tax_id !== null)->count();
                $quantityBrokenNonTax = $validSerials->filter(fn($s) => $s->isAvailableBroken() && $s->tax_id === null)->count();

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

            // Store authoritative serial snapshots with full data
            $serialSnapshots = null;
            if ($line->isSerialNumberRequired && !empty($normalizedSerialIds)) {
                $serialSnapshots = $validSerials->map(fn($s) => [
                    'id' => $s->id,
                    'serial_number' => $s->serial_number,
                    'tax_id' => $s->tax_id,
                    'is_broken' => $s->isAvailableBroken(),
                ])->values()->all();
            }

            $pid = $product->id;
            if (!isset($productsData[$pid])) {
                $productsData[$pid] = [
                    'product_id' => $pid,
                    'quantity' => 0,
                    'quantities' => [
                        'quantity_tax' => 0,
                        'quantity_non_tax' => 0,
                        'quantity_broken_tax' => 0,
                        'quantity_broken_non_tax' => 0,
                    ],
                    'serial_numbers' => [],
                ];
            }

            $productsData[$pid]['quantity'] += $line->requestedBaseQuantity;
            $productsData[$pid]['quantities']['quantity_tax'] += $taxQuantity;
            $productsData[$pid]['quantities']['quantity_non_tax'] += $nonTaxQuantity;
            $productsData[$pid]['quantities']['quantity_broken_tax'] += $brokenTaxQuantity;
            $productsData[$pid]['quantities']['quantity_broken_non_tax'] += $brokenNonTaxQuantity;

            $serialsToMerge = $serialSnapshots ?: $line->selectedSerials;
            if (!empty($serialsToMerge)) {
                $productsData[$pid]['serial_numbers'] = array_merge($productsData[$pid]['serial_numbers'], $serialsToMerge);
            }
        }

        return array_values($productsData);
    }
}
