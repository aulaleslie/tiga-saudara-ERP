<?php

namespace Modules\Consignment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Entities\ConsignmentSoldSourceSerial;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Setting\Entities\Location;

class ConsignmentSoldSourceDiscoveryService
{
    /**
     * Snapshot/hash contract version written by this service. Historical sources carry
     * no version (or version 1) and must keep being validated with their original payload.
     */
    public const SNAPSHOT_VERSION = 2;

    /**
     * Optional hook for testing concurrency and state mutation between selection and persistence.
     */
    public ?\Closure $beforePersistHook = null;

    /**
     * Discover and process eligible consignment sold sources for a setting.
     *
     * @param int $settingId
     * @param bool $previewOnly If true, do not persist any changes.
     * @return array Summary of discovery results.
     */
    public function discoverForSetting(int $settingId, bool $previewOnly = false): array
    {
        $consignmentLocationIds = Location::where('setting_id', $settingId)
            ->where('is_consignment', true)
            ->pluck('id')
            ->toArray();

        if (empty($consignmentLocationIds)) {
            return [
                'created' => 0,
                'existing' => 0,
                'excluded' => 0,
                'blocked' => 0,
                'details' => [],
            ];
        }

        $dispatchDetails = DispatchDetail::whereHas('dispatch', function ($query) {
            $query->where('status', Dispatch::STATUS_APPROVED);
        })
            ->whereIn('location_id', $consignmentLocationIds)
            ->with(['dispatch', 'sale', 'product', 'location'])
            ->get();

        $created = 0;
        $existing = 0;
        $excluded = 0;
        $blocked = 0;
        $details = [];

        foreach ($dispatchDetails as $detail) {
            // Check if already captured
            $existingSource = ConsignmentSoldSource::where('dispatch_detail_id', $detail->id)->first();
            if ($existingSource) {
                $existing++;
                $details[] = [
                    'dispatch_detail_id' => $detail->id,
                    'status' => 'EXISTING',
                    'source_id' => $existingSource->id,
                ];
                continue;
            }

            // Execute test hook prior to persistence if configured
            if ($this->beforePersistHook !== null) {
                ($this->beforePersistHook)($detail);
            }

            if (! $previewOnly) {
                $resultStatus = DB::transaction(function () use ($settingId, $detail) {
                    $candidateLocationId = (int) $detail->location_id;
                    $candidateDispatchId = (int) $detail->dispatch_id;
                    $candidateDetailId = (int) $detail->id;

                    // 1. Lock Location first (hierarchical lock order)
                    $lockedLocation = Location::whereKey($candidateLocationId)->lockForUpdate()->first();
                    if (! $lockedLocation || (int) $lockedLocation->setting_id !== $settingId || ! $lockedLocation->is_consignment || ! $lockedLocation->is_active) {
                        return false;
                    }

                    // 2. Lock Dispatch header second
                    $lockedDispatch = Dispatch::whereKey($candidateDispatchId)->lockForUpdate()->first();
                    if (! $lockedDispatch || $lockedDispatch->status !== Dispatch::STATUS_APPROVED) {
                        return false;
                    }

                    // 3. Lock DispatchDetail third
                    $lockedDispatchDetail = DispatchDetail::whereKey($candidateDetailId)
                        ->lockForUpdate()
                        ->first();

                    if (! $lockedDispatchDetail || (int) $lockedDispatchDetail->location_id !== (int) $lockedLocation->id || (int) $lockedDispatchDetail->dispatch_id !== (int) $lockedDispatch->id) {
                        return false;
                    }

                    // Re-check existing source under lock
                    if (ConsignmentSoldSource::where('dispatch_detail_id', $lockedDispatchDetail->id)->exists()) {
                        return false;
                    }

                    // 4. Lock Product last: eager loading would issue a separate unlocked
                    // query, letting stock_managed change under a compatibility
                    // classification while this transaction is still deciding.
                    $lockedProduct = $lockedDispatchDetail->product_id
                        ? Product::whereKey((int) $lockedDispatchDetail->product_id)->lockForUpdate()->first()
                        : null;
                    $lockedDispatchDetail->setRelation('product', $lockedProduct);

                    $classification = $this->classifyInventoryEvidence($lockedDispatchDetail, $lockedProduct, $lockedLocation);
                    $unsupportedBlocker = $classification['blocker_reason'];

                    // 5. Resolve and lock serial authority after Product, preserving the
                    // Product -> ProductSerialNumber hierarchy used elsewhere.
                    $serialResolution = $this->resolveLockedSerialAuthority($lockedDispatchDetail, $lockedProduct);

                    // Re-resolve original quantity and blockers using locked detail
                    $reconstruction = $this->resolveOriginalQuantityAndBlocker($lockedDispatchDetail);
                    $originalBaseQty = $reconstruction['original_base_quantity'];
                    $hasBlocker = $reconstruction['has_blocker']
                        || $unsupportedBlocker !== null
                        || $serialResolution['blocker_reason'] !== null;
                    $blockerReason = $unsupportedBlocker
                        ?? $reconstruction['blocker_reason']
                        ?? $serialResolution['blocker_reason'];
                    $reconstructionNotes = $reconstruction['notes'];

                    // Link POS checkout if exists
                    $posCheckoutId = null;
                    if (class_exists(PosCheckoutSale::class)) {
                        $checkoutSale = PosCheckoutSale::where('sale_id', $lockedDispatchDetail->sale_id)->first();
                        if ($checkoutSale) {
                            $posCheckoutId = $checkoutSale->pos_checkout_id;
                        }
                    }

                    // Canonical serial identities, normalized once by the resolver above.
                    $serialIdentities = $serialResolution['serial_identities'];

                    // Re-calculate source hash and snapshot using locked dispatch, detail & location
                    $dispatchedAt = $lockedDispatch->approved_at ?? $lockedDispatch->created_at ?? $lockedDispatchDetail->created_at;
                    $dispatchedAtCarbon = $dispatchedAt ? \Carbon\Carbon::parse($dispatchedAt) : null;
                    $dispatchedAtStr = $dispatchedAtCarbon ? $dispatchedAtCarbon->format('Y-m-d H:i:s') : null;
                    $hashPayload = self::buildCanonicalPayload(self::SNAPSHOT_VERSION, [
                        'dispatch_detail_id' => $lockedDispatchDetail->id,
                        'setting_id' => $settingId,
                        'location_id' => $lockedDispatchDetail->location_id,
                        'product_id' => $lockedDispatchDetail->product_id,
                        'original_base_quantity' => (string) $originalBaseQty,
                        'dispatched_at' => $dispatchedAtStr,
                        'serials' => $serialIdentities,
                        'dispatch_status' => $lockedDispatch->status,
                        'is_consignment_location' => (bool) $lockedLocation->is_consignment,
                        'pos_checkout_id' => $posCheckoutId,
                        'tax_id' => $lockedDispatchDetail->tax_id,
                        'bundle_id' => $lockedDispatchDetail->bundle_id,
                        'is_inventory_managed' => $classification['persisted_is_inventory_managed'],
                    ]);
                    $sourceHash = hash('sha256', json_encode($hashPayload));

                    $snapshot = [
                        'dispatch_detail_id' => $lockedDispatchDetail->id,
                        'dispatch_id' => $lockedDispatchDetail->dispatch_id,
                        'sale_id' => $lockedDispatchDetail->sale_id,
                        'pos_checkout_id' => $posCheckoutId,
                        'product_id' => $lockedDispatchDetail->product_id,
                        'product_name' => $lockedProduct->product_name ?? null,
                        'location_id' => $lockedDispatchDetail->location_id,
                        'location_name' => $lockedLocation->name ?? null,
                        'original_base_quantity' => $originalBaseQty,
                        'current_dispatched_quantity' => $lockedDispatchDetail->dispatched_quantity,
                        'tax_id' => $lockedDispatchDetail->tax_id,
                        'serial_numbers' => $serialIdentities,
                        'dispatched_at' => $dispatchedAtCarbon ? $dispatchedAtCarbon->toIso8601String() : null,
                        'source_hash' => $sourceHash,
                        'snapshot_version' => self::SNAPSHOT_VERSION,
                        'bundle_id' => $lockedDispatchDetail->bundle_id,
                        'is_inventory_managed' => $classification['persisted_is_inventory_managed'],
                        'inventory_classification' => $classification['classification'],
                        'used_historical_compatibility_rule' => $classification['used_compatibility_rule'],
                    ];

                    $soldSource = ConsignmentSoldSource::create([
                        'setting_id' => $settingId,
                        'dispatch_detail_id' => $lockedDispatchDetail->id,
                        'sale_id' => $lockedDispatchDetail->sale_id,
                        'pos_checkout_id' => $posCheckoutId,
                        'product_id' => $lockedDispatchDetail->product_id,
                        'location_id' => $lockedDispatchDetail->location_id,
                        'original_base_quantity' => $originalBaseQty,
                        'dispatched_at' => $dispatchedAt,
                        'tax_context' => $lockedDispatchDetail->tax_id ? ['tax_id' => $lockedDispatchDetail->tax_id] : null,
                        'serial_identities' => $serialIdentities,
                        'source_hash' => $sourceHash,
                        'source_snapshot' => $snapshot,
                        'reconstruction_notes' => $reconstructionNotes,
                        'has_reconstruction_blocker' => $hasBlocker,
                        'blocker_reason' => $blockerReason,
                    ]);

                    // Link serial provenance from the locked collection. Partial linkage is
                    // never written: the resolver already recorded a blocker for missing,
                    // foreign, duplicated, or ambiguous serial evidence, and in that case
                    // no ConsignmentSoldSourceSerial row is created at all.
                    if ($serialResolution['blocker_reason'] === null) {
                        foreach ($serialResolution['locked_serials'] as $psn) {
                            ConsignmentSoldSourceSerial::create([
                                'consignment_sold_source_id' => $soldSource->id,
                                'product_serial_number_id' => $psn->id,
                            ]);
                        }
                    }

                    return ['created' => true, 'has_blocker' => $hasBlocker];
                });

                if ($resultStatus && ! empty($resultStatus['created'])) {
                    $created++;
                    if ($resultStatus['has_blocker']) {
                        $blocked++;
                    }
                    $details[] = [
                        'dispatch_detail_id' => $detail->id,
                        'status' => 'CREATED',
                        'has_blocker' => $resultStatus['has_blocker'],
                    ];
                } else {
                    $excluded++;
                    $details[] = [
                        'dispatch_detail_id' => $detail->id,
                        'status' => 'SKIPPED_RECLASSIFIED_OR_DUPLICATE',
                        'has_blocker' => false,
                    ];
                }
            } else {
                $product = $detail->product;
                $location = $detail->location;
                $previewLocationValid = $location
                    && (int) $location->setting_id === $settingId
                    && $location->is_consignment
                    && $location->is_active;
                $classification = $this->classifyInventoryEvidence($detail, $product, $previewLocationValid ? $location : null);
                $unsupportedBlocker = $classification['blocker_reason'];
                $reconstruction = $this->resolveOriginalQuantityAndBlocker($detail);
                $serialResolution = $this->resolveLockedSerialAuthority($detail, $product, false);
                $hasBlocker = $reconstruction['has_blocker']
                    || $unsupportedBlocker !== null
                    || $serialResolution['blocker_reason'] !== null;
                if ($hasBlocker) {
                    $blocked++;
                }

                $created++;
                $details[] = [
                    'dispatch_detail_id' => $detail->id,
                    'status' => 'PREVIEW_CREATED',
                    'has_blocker' => $hasBlocker,
                ];
            }
        }

        return [
            'created' => $created,
            'existing' => $existing,
            'excluded' => $excluded,
            'blocked' => $blocked,
            'details' => $details,
        ];
    }

    /**
     * Build the canonical hash payload for a given snapshot version.
     *
     * Version 1 (and unversioned historical sources) keep the exact original key set so
     * their stored hashes stay valid; version 2 adds bundle identity and the persisted
     * inventory classification so later mutation of either is detectable.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public static function buildCanonicalPayload(int $version, array $fields): array
    {
        $payload = [
            'dispatch_detail_id' => $fields['dispatch_detail_id'] ?? null,
            'setting_id' => $fields['setting_id'] ?? null,
            'location_id' => $fields['location_id'] ?? null,
            'product_id' => $fields['product_id'] ?? null,
            'original_base_quantity' => $fields['original_base_quantity'] ?? null,
            'dispatched_at' => $fields['dispatched_at'] ?? null,
            'serials' => $fields['serials'] ?? [],
            'dispatch_status' => $fields['dispatch_status'] ?? null,
            'is_consignment_location' => $fields['is_consignment_location'] ?? null,
            'pos_checkout_id' => $fields['pos_checkout_id'] ?? null,
            'tax_id' => $fields['tax_id'] ?? null,
        ];

        if ($version >= 2) {
            $payload['bundle_id'] = $fields['bundle_id'] ?? null;
            $payload['is_inventory_managed'] = $fields['is_inventory_managed'] ?? null;
        }

        return $payload;
    }

    /**
     * Every snapshot/hash contract version this service knows how to reproduce.
     */
    public const SUPPORTED_SNAPSHOT_VERSIONS = [1, 2];

    /**
     * Every inventory classification the classifier can return. Version-2 evidence must
     * name exactly one of these; anything else is a corrupted snapshot.
     */
    public const SUPPORTED_CLASSIFICATIONS = [
        'EXPLICIT_INVENTORY',
        'EXPLICIT_NON_INVENTORY',
        'HISTORICAL_COMPATIBILITY',
        'AMBIGUOUS_HISTORICAL',
        'MISSING_PRODUCT',
        'INVALID_LOCATION',
    ];

    /**
     * Resolve the snapshot/hash contract version recorded on a stored sold source.
     *
     * An absent key is the historical unversioned contract (version 1). Anything present
     * must be an exact supported integer, or its canonical decimal string: casting
     * arbitrary values would let a corrupted or partially repaired snapshot
     * ("invalid" → 0, 2.5 → 2, "01", an explicit null) silently pick a payload shape,
     * so every unrecognised value fails closed instead.
     *
     * @return int|null Null when the stored version is not a supported contract.
     */
    public static function resolveSnapshotVersion(?array $snapshot): ?int
    {
        if ($snapshot === null || ! array_key_exists('snapshot_version', $snapshot)) {
            return 1;
        }

        $version = $snapshot['snapshot_version'];

        foreach (self::SUPPORTED_SNAPSHOT_VERSIONS as $supported) {
            if ($version === $supported || $version === (string) $supported) {
                return $supported;
            }
        }

        return null;
    }

    /**
     * Classify whether a dispatch detail represents an authoritative inventory-managed
     * movement that may become a consignment sold source.
     *
     * Bundle association is provenance, not a blocker: an approved stock-managed movement
     * for a bundle parent or component is as authoritative as any other physical dispatch.
     *
     * @return array{
     *     is_inventory_managed: bool,
     *     blocker_reason: ?string,
     *     persisted_is_inventory_managed: ?bool,
     *     classification: string,
     *     used_compatibility_rule: bool
     * }
     */
    public function classifyInventoryEvidence(DispatchDetail $detail, $product, $location = null): array
    {
        $raw = $detail->getAttribute('is_inventory_managed');
        $persisted = $raw === null ? null : (bool) $raw;

        $result = [
            'is_inventory_managed' => false,
            'blocker_reason' => null,
            'persisted_is_inventory_managed' => $persisted,
            'classification' => 'UNSUPPORTED',
            'used_compatibility_rule' => false,
        ];

        if (! $product) {
            $result['blocker_reason'] = 'Missing product lineage on dispatch detail.';
            $result['classification'] = 'MISSING_PRODUCT';

            return $result;
        }

        if ($persisted === false) {
            $result['blocker_reason'] = 'Non-inventory or service dispatch detail.';
            $result['classification'] = 'EXPLICIT_NON_INVENTORY';

            return $result;
        }

        // Every supported classification requires a valid, active consignment source
        // location. Callers pass null when the location is missing, reclassified, or
        // inactive; without this gate preview would report an explicitly inventory-managed
        // row as eligible while locked persistence skips it during revalidation.
        if (! $location) {
            $result['blocker_reason'] = 'Dispatch detail has no valid active consignment source location.';
            $result['classification'] = 'INVALID_LOCATION';

            return $result;
        }

        if ($persisted === true) {
            $result['is_inventory_managed'] = true;
            $result['classification'] = 'EXPLICIT_INVENTORY';

            return $result;
        }

        // Historical rows posted before explicit classification existed. Support them only
        // when the surviving evidence is unambiguous: a valid consignment source location
        // and a product that is still stock-managed. Anything else is blocked, not guessed.
        if (! $product->stock_managed) {
            $result['blocker_reason'] = 'Historical dispatch detail has no inventory classification and its product is not stock-managed.';
            $result['classification'] = 'AMBIGUOUS_HISTORICAL';

            return $result;
        }

        $result['is_inventory_managed'] = true;
        $result['classification'] = 'HISTORICAL_COMPATIBILITY';
        $result['used_compatibility_rule'] = true;

        return $result;
    }

    /**
     * Resolve and lock the ProductSerialNumber authority backing a dispatch detail.
     *
     * Serial provenance must be exact: every captured serial has to resolve to exactly one
     * live row belonging to this detail's locked product. Anything else — a missing row, a
     * serial owned by another product, or the same serial text captured twice — is
     * ambiguous evidence and yields a blocker instead of partial linkage.
     *
     * Rows are fetched in a single query and locked in deterministic ID order, after the
     * Product lock, matching the Product -> ProductSerialNumber hierarchy used by
     * Consignment receiving and by the billing lifecycle.
     *
     * @return array{
     *     serial_identities: array<int, string>,
     *     locked_serials: \Illuminate\Support\Collection,
     *     blocker_reason: ?string
     * }
     */
    protected function resolveLockedSerialAuthority(DispatchDetail $detail, $lockedProduct, bool $lock = true): array
    {
        $rawSerials = array_values(array_map(
            fn ($sn) => ProductSerialNumber::normalize((string) $sn),
            $this->parseSerials($detail->serial_numbers)
        ));
        sort($rawSerials);

        $result = [
            'serial_identities' => $rawSerials,
            'locked_serials' => collect(),
            'blocker_reason' => null,
        ];

        if (empty($rawSerials)) {
            return $result;
        }

        if (! $lockedProduct) {
            $result['blocker_reason'] = 'Serialized dispatch detail has no resolvable product for serial provenance.';

            return $result;
        }

        // The same serial text must not appear twice: one physical item cannot be sold
        // twice on one movement, and it would otherwise double the linked provenance.
        $duplicates = array_keys(array_filter(array_count_values($rawSerials), fn ($n) => $n > 1));
        if (! empty($duplicates)) {
            sort($duplicates);
            $result['blocker_reason'] = 'Duplicate serial identities captured on dispatch detail: ' . implode(', ', $duplicates) . '.';

            return $result;
        }

        // Serials are identified by the composite (product_id, serial_number): the same
        // serial text may legitimately exist under a different product, and that row is
        // unrelated authority, not conflicting evidence. Resolve on both dimensions so
        // only this product's rows are read and locked.
        //
        // One query, locked in deterministic ID order. Preview classifies the same
        // evidence but takes no locks, since it persists nothing.
        $query = ProductSerialNumber::where('product_id', $lockedProduct->id)
            ->whereIn('serial_number', $rawSerials)
            ->orderBy('id');
        $locked = ($lock ? $query->lockForUpdate() : $query)->get();

        $matched = $locked->keyBy(fn ($psn) => ProductSerialNumber::normalize((string) $psn->serial_number));

        // Two live rows sharing one serial for this product is ambiguous authority.
        if ($matched->count() !== $locked->count()) {
            $result['blocker_reason'] = 'Serial identities resolve to multiple product serial records.';

            return $result;
        }

        // A serial with no row for THIS product is unusable here, whether it does not
        // exist at all or belongs to some other product.
        $missing = array_values(array_diff($rawSerials, $matched->keys()->all()));
        if (! empty($missing)) {
            $result['blocker_reason'] = 'Serial identities have no product serial record for this dispatch product: ' . implode(', ', $missing) . '.';

            return $result;
        }

        $result['locked_serials'] = $locked->values();

        return $result;
    }

    /**
     * Resolve original quantity and check for ambiguous reconstruction blockers.
     */
    protected function resolveOriginalQuantityAndBlocker(DispatchDetail $detail): array
    {
        $currentQty = (float) $detail->dispatched_quantity;

        // Ordinary Sales Returns do NOT reduce dispatch_details.dispatched_quantity in DB.
        // Only POS Cash Returns (with resolution cash_return) reduce dispatch_details.dispatched_quantity.
        $posCashReturnedQty = 0.0;
        $hasBlocker = false;
        $blockerReason = null;
        $notes = null;

        if (class_exists(\Modules\Pos\Entities\PosReturnLine::class)) {
            $returns = \Modules\Pos\Entities\PosReturnLine::where('dispatch_detail_id', $detail->id)
                ->with(['posReturn', 'saleReturn'])
                ->get();
            
            foreach ($returns as $ret) {
                if ($ret->resolution === \Modules\Pos\Entities\PosReturnLine::RESOLUTION_CASH_RETURN) {
                    $isCompleted = false;
                    if ($ret->posReturn && $ret->posReturn->status === 'completed') {
                        $isCompleted = true;
                    } elseif ($ret->saleReturn && in_array($ret->saleReturn->status, ['Completed', 'AWAITING SETTLEMENT', 'Awaiting Settlement', 'COMPLETED'])) {
                        $isCompleted = true;
                    }
                    
                    if ($isCompleted) {
                        $posCashReturnedQty += (float) $ret->quantity;
                    }
                }
            }
        }

        $originalQty = $currentQty + $posCashReturnedQty;

        if (!$hasBlocker && $originalQty <= 0) {
            $hasBlocker = true;
            $blockerReason = "Original dispatched quantity is zero or negative ({$originalQty}).";
        }

        return [
            'original_base_quantity' => $originalQty,
            'has_blocker' => $hasBlocker,
            'blocker_reason' => $blockerReason,
            'notes' => $notes,
        ];
    }

    /**
     * Parse serial number string/JSON into clean string array.
     */
    protected function parseSerials($serials): array
    {
        if (empty($serials)) {
            return [];
        }

        if (is_array($serials)) {
            return array_map('trim', array_filter($serials));
        }

        if (is_string($serials)) {
            $decoded = json_decode($serials, true);
            if (is_array($decoded)) {
                return array_map('trim', array_filter($decoded));
            }
            return array_map('trim', array_filter(explode(',', $serials)));
        }

        return [];
    }
}
