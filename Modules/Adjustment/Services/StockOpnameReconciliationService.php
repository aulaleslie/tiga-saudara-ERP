<?php

namespace Modules\Adjustment\Services;

use Illuminate\Support\Collection;
use Modules\Adjustment\DTOs\ProductReconciliation;
use Modules\Adjustment\DTOs\ReconciliationResult;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;

/**
 * Single server-side reconciliation engine shared by the reviewer preview
 * (informative, can go stale) and locked approval (recomputed inside
 * approval's database transaction after locking affected rows).
 *
 * All data is bulk-loaded up front: no query runs inside a per-product or
 * per-serial loop.
 */
class StockOpnameReconciliationService
{
    public function __construct(
        private StockOpnameSerialClassifier $serialClassifier,
    ) {
    }

    /**
     * Compute reconciliation for an adjustment's saved count_draft.
     *
     * @param bool $locked Pass true only from inside approval's transaction,
     *   after locking affected ProductStock/serial rows with a locking query
     *   built by the caller (this method itself issues plain reads; the
     *   caller is responsible for row locks before invoking it in locked mode).
     */
    public function reconcile(Adjustment $adjustment, bool $locked = false): ReconciliationResult
    {
        $draft = $adjustment->count_draft;
        $rows = is_array($draft) ? ($draft['rows'] ?? []) : [];

        $location = Location::with('setting')->find($adjustment->location_id);
        $setting = $location?->setting;
        $isPkp = (bool) ($setting?->is_pkp ?? false);
        $settingId = (int) ($setting?->id ?? 0);

        if (!$location || !$setting) {
            return new ReconciliationResult(
                adjustmentId: (int) $adjustment->id,
                locked: $locked,
                locationId: (int) $adjustment->location_id,
                locationName: $location?->name ?? '',
                settingId: $settingId,
                isPkp: $isPkp,
                products: [],
                warnings: [],
                conflicts: empty($rows) ? [] : ['Lokasi atau pengaturan tujuan tidak ditemukan.'],
                computedAt: now()->toIso8601String(),
            );
        }

        $productIds = collect($rows)
            ->map(fn ($row) => (int) ($row['product_id'] ?? 0))
            ->filter()
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return new ReconciliationResult(
                adjustmentId: (int) $adjustment->id,
                locked: $locked,
                locationId: (int) $location->id,
                locationName: (string) $location->name,
                settingId: $settingId,
                isPkp: $isPkp,
                products: [],
                computedAt: now()->toIso8601String(),
            );
        }

        // Bulk-loaded context, keyed for O(1) per-product/per-serial lookup
        // in the classification pass below. Nothing below this block queries
        // inside the per-product or per-serial loop.
        $eligibleLocationIds = $this->eligibleLocationIds($settingId);

        // Products belonging to a different setting are foreign and must be
        // rejected as conflicts rather than reconciled, since product/location
        // resolution is constrained to the relevant owner scope.
        $productsById = Product::with('baseUnit')
            ->whereIn('id', $productIds)
            ->where('setting_id', $settingId)
            ->get()
            ->keyBy('id');

        $selectedLocationStocksByProduct = ProductStock::where('location_id', $location->id)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        // `quantity` is already the per-row TOTAL (good + broken); summing it
        // directly across locations gives the all-location grand total.
        // `total_good`/`total_bad` are derived separately (from the good
        // buckets and from broken_quantity respectively) purely so callers
        // that want the good/bad split can have it without re-deriving it
        // from `quantity - broken_quantity` themselves.
        $allLocationTotalsByProduct = ProductStock::whereIn('product_id', $productIds)
            ->whereIn('location_id', $eligibleLocationIds)
            ->selectRaw('product_id, SUM(quantity_tax + quantity_non_tax) as total_good, SUM(broken_quantity) as total_bad, SUM(quantity) as grand_total')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $enteredSerialTextsByProduct = [];
        foreach ($rows as $row) {
            $pId = (int) ($row['product_id'] ?? 0);
            foreach ($row['serials'] ?? [] as $serial) {
                $text = ProductSerialNumber::normalize((string) ($serial['serial_number'] ?? ''));
                if ($text !== '') {
                    $enteredSerialTextsByProduct[$pId][$text] = true;
                }
            }
        }

        $allSerialTexts = collect($enteredSerialTextsByProduct)
            ->flatMap(fn ($texts) => array_keys($texts))
            ->unique()
            ->values();

        // Serials matching entered product+text pairs, wherever they currently
        // are (needed to detect cross-location moves and same-text-other-product).
        // Eager-load product (with its setting) so foreign-product warnings
        // never trigger a per-serial lookup.
        $matchingSerialsByText = collect();
        if ($allSerialTexts->isNotEmpty()) {
            $matchingSerialsByText = ProductSerialNumber::with(['location', 'tax', 'product'])
                ->whereIn('serial_number', $allSerialTexts)
                ->get()
                ->groupBy('serial_number');
        }

        // Bulk-load unsafe-conflict signals for every candidate serial (the
        // entered product's own matches plus any destination serials that
        // might be omitted) so per-serial classification never queries.
        $candidateSerialIds = $matchingSerialsByText
            ->flatten()
            ->pluck('id')
            ->unique()
            ->values();

        // Registered available serials currently at the destination for entered
        // serialized products, to detect destination omissions. Eager-load
        // the same relations as $matchingSerialsByText above so the shared
        // classifier's per-serial label/warning reads never lazy-load.
        $serializedProductIds = $productsById->filter(fn (Product $p) => (bool) $p->serial_number_required)->keys();
        $destinationSerialsByProduct = collect();
        if ($serializedProductIds->isNotEmpty()) {
            $destinationSerialsByProduct = ProductSerialNumber::with(['location', 'tax', 'product'])
                ->where('location_id', $location->id)
                ->whereIn('product_id', $serializedProductIds)
                ->get()
                ->groupBy('product_id');

            $candidateSerialIds = $candidateSerialIds
                ->concat($destinationSerialsByProduct->flatten()->pluck('id'))
                ->unique()
                ->values();
        }

        $activeClaimSerialIds = collect();
        $allocatedSerialIds = collect();
        if ($candidateSerialIds->isNotEmpty() && class_exists(\Modules\Consignment\Entities\ConsignmentActiveSerialClaim::class)) {
            $activeClaimSerialIds = \Modules\Consignment\Entities\ConsignmentActiveSerialClaim::whereIn('product_serial_number_id', $candidateSerialIds)
                ->pluck('product_serial_number_id')
                ->unique();
        }
        if ($candidateSerialIds->isNotEmpty() && class_exists(\Modules\Consignment\Entities\ConsignmentSerializedAllocation::class)) {
            $allocatedSerialIds = \Modules\Consignment\Entities\ConsignmentSerializedAllocation::whereIn('product_serial_number_id', $candidateSerialIds)
                ->pluck('product_serial_number_id')
                ->unique();
        }

        $products = [];
        $documentWarnings = [];
        $documentConflicts = [];

        foreach ($rows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $product = $productsById->get($productId);
            if (!$product) {
                $documentConflicts[] = "Produk dengan ID {$productId} tidak ditemukan atau bukan milik pengaturan aktif ini.";
                continue;
            }

            $isSerialized = (bool) $product->serial_number_required;
            $baseUnit = (string) ($product->baseUnit?->unit_name ?? $product->baseUnit?->name ?? '');

            $baselineGood = (int) ($row['baseline']['existing_good_total'] ?? 0);
            $baselineBad = (int) ($row['baseline']['existing_bad_total'] ?? 0);

            // ProductStock::quantity is the TOTAL (good + broken) per the
            // established convention (see ProductController::
            // handleStockInitialization / TransferMovementService::
            // applyInventoryChange / StockOpnameApprovalService); current
            // good must be derived from the two good buckets directly, never
            // from `quantity`, or broken units get double-counted as good.
            $stock = $selectedLocationStocksByProduct->get($productId);
            $currentGood = $stock
                ? (int) round((float) $stock->quantity_tax) + (int) round((float) $stock->quantity_non_tax)
                : 0;
            $currentBad = $stock ? (int) round((float) $stock->broken_quantity) : 0;

            $enteredGood = (int) ($row['good_count'] ?? 0);
            $enteredBad = (int) ($row['bad_count'] ?? 0);

            $totals = $allLocationTotalsByProduct->get($productId);
            $allLocationCurrentTotal = $totals ? (float) $totals->grand_total : 0.0;

            $enteredTotal = $enteredGood + $enteredBad;
            $currentTotal = $currentGood + $currentBad;

            $exceedsAllLocationTotal = $enteredTotal > $allLocationCurrentTotal;
            $potentialGlobalIncrease = $exceedsAllLocationTotal
                ? $enteredTotal - $allLocationCurrentTotal
                : 0.0;

            $classification = $this->serialClassifier->classify(
                product: $product,
                row: $row,
                destinationLocation: $location,
                isPkp: $isPkp,
                eligibleLocationIds: $eligibleLocationIds,
                matchingSerialsByText: $matchingSerialsByText,
                destinationSerialsByProduct: $destinationSerialsByProduct,
                activeClaimSerialIds: $activeClaimSerialIds,
                allocatedSerialIds: $allocatedSerialIds,
            );
            $serialClassifications = $classification['entered'];
            $omittedSerials = $classification['omitted'];
            $serialWarnings = $classification['warnings'];
            $serialConflicts = $classification['conflicts'];
            $newSerialCount = $classification['newSerialCount'];

            if ($isSerialized) {
                // Serialized global effect is computed per serial identity,
                // never by the ordinary-product destination-delta formula:
                // a moved or reclassified serial is the same physical unit
                // moving/reclassifying (net zero globally), while only a
                // genuinely new (previously unregistered) serial adds one
                // unit to the global total. Conflicting serials are excluded
                // (blocked, not applied).
                $projectedGlobalTotal = $allLocationCurrentTotal + $newSerialCount;
            } else {
                // Projected global total: current global total, net of the
                // entered selected-location change.
                $projectedGlobalTotal = $allLocationCurrentTotal + ($enteredTotal - $currentTotal);
            }

            $drift = ($currentGood + $currentBad) - ($baselineGood + $baselineBad);

            if ($exceedsAllLocationTotal) {
                $documentWarnings[] = sprintf(
                    "Produk %s: total hitung di lokasi terpilih (%d) melebihi total stok saat ini di seluruh lokasi yang memenuhi syarat (%s), potensi penambahan stok global %s unit.",
                    $product->product_name,
                    $enteredTotal,
                    rtrim(rtrim(number_format($allLocationCurrentTotal, 3), '0'), '.'),
                    rtrim(rtrim(number_format($potentialGlobalIncrease, 3), '0'), '.')
                );
            }

            if ($drift !== 0) {
                $documentWarnings[] = sprintf(
                    'Produk %s: stok saat ini berbeda dari saat perhitungan dimulai (drift %+d unit).',
                    $product->product_name,
                    $drift
                );
            }

            $documentWarnings = array_merge($documentWarnings, $serialWarnings);
            $documentConflicts = array_merge($documentConflicts, $serialConflicts);

            $products[] = new ProductReconciliation(
                productId: $productId,
                productName: (string) $product->product_name,
                productCode: (string) $product->product_code,
                baseUnit: $baseUnit,
                isSerialized: $isSerialized,
                baselineGood: $baselineGood,
                baselineBad: $baselineBad,
                currentGood: $currentGood,
                currentBad: $currentBad,
                enteredGood: $enteredGood,
                enteredBad: $enteredBad,
                goodDifference: $enteredGood - $currentGood,
                badDifference: $enteredBad - $currentBad,
                allLocationCurrentTotal: $allLocationCurrentTotal,
                projectedGlobalTotal: $projectedGlobalTotal,
                drift: $drift,
                exceedsAllLocationTotal: $exceedsAllLocationTotal,
                potentialGlobalIncrease: $potentialGlobalIncrease,
                serials: $serialClassifications,
                omittedSerials: $omittedSerials,
            );
        }

        return new ReconciliationResult(
            adjustmentId: (int) $adjustment->id,
            locked: $locked,
            locationId: (int) $location->id,
            locationName: (string) $location->name,
            settingId: $settingId,
            isPkp: $isPkp,
            products: $products,
            warnings: array_values(array_unique($documentWarnings)),
            conflicts: array_values(array_unique($documentConflicts)),
            computedAt: now()->toIso8601String(),
        );
    }

    /**
     * Non-consignment location IDs in the given setting's owner scope.
     * "All locations" is scoped to this set, never across settings.
     */
    private function eligibleLocationIds(int $settingId): Collection
    {
        return Location::standard()
            ->where('setting_id', $settingId)
            ->pluck('id');
    }

}
