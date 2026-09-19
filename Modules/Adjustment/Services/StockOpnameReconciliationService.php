<?php

namespace Modules\Adjustment\Services;

use Illuminate\Support\Collection;
use InvalidArgumentException;
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
 * Fully supports multi-location Stock Opname (schema version 2) and historical
 * single-location documents (schema version 1) via SelectedLocationPoolResolver.
 *
 * All data is bulk-loaded up front: no query runs inside a per-product or
 * per-serial loop.
 */
class StockOpnameReconciliationService
{
    public function __construct(
        private StockOpnameSerialClassifier $serialClassifier,
        private SelectedLocationPoolResolver $locationPoolResolver,
        private StockOpnameAllocationPlanner $allocationPlanner,
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

        try {
            $selectedLocations = $this->locationPoolResolver->resolve($adjustment);
        } catch (InvalidArgumentException $e) {
            $unattributed = [$e->getMessage()];
            return new ReconciliationResult(
                adjustmentId: (int) $adjustment->id,
                locked: $locked,
                locationId: (int) ($adjustment->location_id ?? 0),
                locationName: '',
                settingId: 0,
                isPkp: false,
                products: [],
                warnings: [],
                conflicts: $unattributed,
                unattributedConflicts: $unattributed,
                computedAt: now()->toIso8601String(),
                selectedLocations: [],
            );
        }

        if ($selectedLocations->isEmpty()) {
            $unattributed = empty($rows) ? [] : ['Dokumen stock opname tidak memiliki lokasi tujuan yang valid.'];
            return new ReconciliationResult(
                adjustmentId: (int) $adjustment->id,
                locked: $locked,
                locationId: (int) ($adjustment->location_id ?? 0),
                locationName: '',
                settingId: 0,
                isPkp: false,
                products: [],
                warnings: [],
                conflicts: $unattributed,
                unattributedConflicts: $unattributed,
                computedAt: now()->toIso8601String(),
                selectedLocations: [],
            );
        }

        $primaryLocation = $selectedLocations->first();
        $primarySetting = $primaryLocation?->setting;
        $primaryLocationId = (int) ($primaryLocation?->id ?? 0);
        $primaryLocationName = (string) ($primaryLocation?->name ?? '');
        $primarySettingId = (int) ($primarySetting?->id ?? 0);
        $primaryIsPkp = (bool) ($primarySetting?->is_pkp ?? false);

        $selectedLocationsData = $selectedLocations->map(fn (Location $loc) => [
            'id' => (int) $loc->id,
            'name' => (string) $loc->name,
            'setting_id' => (int) $loc->setting_id,
            'company_name' => (string) ($loc->setting?->company_name ?? ''),
            'is_pkp' => (bool) ($loc->setting?->is_pkp ?? false),
        ])->all();

        $selectedLocationIds = $selectedLocations->pluck('id')->map(fn ($id) => (int) $id)->all();

        $productIds = collect($rows)
            ->map(fn ($row) => (int) ($row['product_id'] ?? 0))
            ->filter()
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return new ReconciliationResult(
                adjustmentId: (int) $adjustment->id,
                locked: $locked,
                locationId: $primaryLocationId,
                locationName: $primaryLocationName,
                settingId: $primarySettingId,
                isPkp: $primaryIsPkp,
                products: [],
                computedAt: now()->toIso8601String(),
                selectedLocations: $selectedLocationsData,
            );
        }

        // Bulk-loaded context, keyed for O(1) per-product/per-serial lookup.
        // - settingEligibleLocationIds: all standard locations belonging to any
        //   setting represented in the selected location pool.
        // - movementEligibleLocationIds: ANY existing non-consignment location.
        $selectedSettingIds = $selectedLocations->pluck('setting_id')->filter()->unique()->values()->all();
        $settingEligibleLocationIds = $this->settingEligibleLocationIds($selectedSettingIds);
        $movementEligibleLocationIds = $this->movementEligibleLocationIds();

        $productsById = Product::with('baseUnit')
            ->whereIn('id', $productIds)
            ->where('is_active', true)
            ->where('stock_managed', true)
            ->get()
            ->keyBy('id');

        $selectedLocationStocks = ProductStock::whereIn('location_id', $selectedLocationIds)
            ->whereIn('product_id', $productIds)
            ->get();
        $stocksByLocationAndProduct = $selectedLocationStocks->keyBy(fn ($s) => "{$s->location_id}_{$s->product_id}");

        $allLocationTotalsByProduct = ProductStock::whereIn('product_id', $productIds)
            ->whereIn('location_id', $settingEligibleLocationIds)
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

        $matchingSerialsByText = collect();
        if ($allSerialTexts->isNotEmpty()) {
            $matchingSerialsByText = ProductSerialNumber::with(['location', 'tax', 'product'])
                ->whereIn('serial_number', $allSerialTexts)
                ->get()
                ->groupBy('serial_number');
        }

        $candidateSerialIds = $matchingSerialsByText
            ->flatten()
            ->pluck('id')
            ->unique()
            ->values();

        $serializedProductIds = $productsById->filter(fn (Product $p) => (bool) $p->serial_number_required)->keys();
        $destinationSerialsByProduct = collect();
        if ($serializedProductIds->isNotEmpty()) {
            $destinationSerialsByProduct = ProductSerialNumber::with(['location', 'tax', 'product'])
                ->whereIn('location_id', $selectedLocationIds)
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
        $activeTransferClaimSerialIds = collect();
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
        if ($candidateSerialIds->isNotEmpty() && class_exists(\Modules\Adjustment\Entities\TransferActiveSerialClaim::class)) {
            $activeTransferClaimSerialIds = \Modules\Adjustment\Entities\TransferActiveSerialClaim::whereIn('product_serial_number_id', $candidateSerialIds)
                ->pluck('product_serial_number_id')
                ->unique();
        }

        $products = [];
        $documentWarnings = [];
        $documentConflicts = [];
        $unattributedConflicts = [];

        foreach ($rows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $product = $productsById->get($productId);
            if (!$product) {
                $message = "Produk dengan ID {$productId} tidak ditemukan, tidak aktif, atau bukan produk yang stoknya dikelola.";
                $documentConflicts[] = $message;
                $unattributedConflicts[] = $message;
                continue;
            }

            $isSerialized = (bool) $product->serial_number_required;
            $baseUnit = (string) ($product->baseUnit?->unit_name ?? $product->baseUnit?->name ?? '');

            // Per-location baselines
            $locationBaselines = [];
            $totalBaselineGood = 0;
            $totalBaselineBad = 0;
            $rawBaselines = $row['location_baselines'] ?? null;

            // Normalize raw baselines map indexed by location_id
            $baselinesByLocId = [];
            if (is_array($rawBaselines)) {
                foreach ($rawBaselines as $k => $lb) {
                    if (is_array($lb)) {
                        if (isset($lb['location_id'])) {
                            $baselinesByLocId[(int) $lb['location_id']] = $lb;
                        } elseif (is_numeric($k)) {
                            $baselinesByLocId[(int) $k] = $lb;
                        }
                    }
                }
            }

            foreach ($selectedLocations as $loc) {
                $locId = (int) $loc->id;
                $bGood = 0;
                $bBad = 0;

                if (isset($baselinesByLocId[$locId])) {
                    $entry = $baselinesByLocId[$locId];
                    $bGood = (int) ($entry['existing_good_total'] ?? $entry['good'] ?? 0);
                    $bBad = (int) ($entry['existing_bad_total'] ?? $entry['bad'] ?? 0);
                } elseif (isset($row['baseline'])) {
                    // v1 backward compatibility: single location baseline
                    if ($locId === (int) ($adjustment->location_id ?? $primaryLocationId)) {
                        $bGood = (int) ($row['baseline']['existing_good_total'] ?? $row['baseline']['good'] ?? 0);
                        $bBad = (int) ($row['baseline']['existing_bad_total'] ?? $row['baseline']['bad'] ?? 0);
                    }
                }

                $locationBaselines[$locId] = [
                    'location_id' => $locId,
                    'location_name' => (string) $loc->name,
                    'is_pkp' => (bool) ($loc->setting?->is_pkp ?? false),
                    'good' => $bGood,
                    'bad' => $bBad,
                    'total' => $bGood + $bBad,
                ];
                $totalBaselineGood += $bGood;
                $totalBaselineBad += $bBad;
            }

            // Per-location current stocks
            $locationCurrentStocks = [];
            $totalCurrentGood = 0;
            $totalCurrentBad = 0;

            foreach ($selectedLocations as $loc) {
                $locId = (int) $loc->id;
                $stock = $stocksByLocationAndProduct->get("{$locId}_{$productId}");
                $locGood = $stock
                    ? (int) round((float) $stock->quantity_tax) + (int) round((float) $stock->quantity_non_tax)
                    : 0;
                $locBad = $stock ? (int) round((float) $stock->broken_quantity) : 0;
                $locTax = $stock ? (float) $stock->quantity_tax : 0.0;
                $locNonTax = $stock ? (float) $stock->quantity_non_tax : 0.0;

                $locationCurrentStocks[$locId] = [
                    'location_id' => $locId,
                    'location_name' => (string) $loc->name,
                    'setting_id' => (int) $loc->setting_id,
                    'is_pkp' => (bool) ($loc->setting?->is_pkp ?? false),
                    'good' => $locGood,
                    'bad' => $locBad,
                    'total' => $locGood + $locBad,
                    'quantity_tax' => $locTax,
                    'quantity_non_tax' => $locNonTax,
                ];
                $totalCurrentGood += $locGood;
                $totalCurrentBad += $locBad;
            }

            $enteredGood = (int) ($row['good_count'] ?? 0);
            $enteredBad = (int) ($row['bad_count'] ?? 0);

            $totals = $allLocationTotalsByProduct->get($productId);
            $allLocationCurrentTotal = $totals ? (float) $totals->grand_total : 0.0;

            $enteredTotal = $enteredGood + $enteredBad;
            $currentTotal = $totalCurrentGood + $totalCurrentBad;

            $goodDifference = $enteredGood - $totalCurrentGood;
            $badDifference = $enteredBad - $totalCurrentBad;

            $exceedsAllLocationTotal = $enteredTotal > $allLocationCurrentTotal;
            $potentialGlobalIncrease = $exceedsAllLocationTotal
                ? $enteredTotal - $allLocationCurrentTotal
                : 0.0;

            // Allocation planning per condition
            $goodLocationRows = [];
            $badLocationRows = [];
            foreach ($selectedLocations as $loc) {
                $locId = (int) $loc->id;
                $curr = $locationCurrentStocks[$locId];
                $goodLocationRows[] = [
                    'location_id' => $locId,
                    'setting_id' => $curr['setting_id'],
                    'is_pkp' => $curr['is_pkp'],
                    'location_name' => $curr['location_name'],
                    'stock' => $curr['good'],
                ];
                $badLocationRows[] = [
                    'location_id' => $locId,
                    'setting_id' => $curr['setting_id'],
                    'is_pkp' => $curr['is_pkp'],
                    'location_name' => $curr['location_name'],
                    'stock' => $curr['bad'],
                ];
            }

            $goodAllocationPlan = $this->allocationPlanner->plan($goodLocationRows, $goodDifference, 'good');
            $badAllocationPlan = $this->allocationPlanner->plan($badLocationRows, $badDifference, 'bad');

            $classification = $this->serialClassifier->classify(
                product: $product,
                row: $row,
                destinationLocation: $selectedLocations,
                isPkp: $primaryIsPkp,
                movementEligibleLocationIds: $movementEligibleLocationIds,
                matchingSerialsByText: $matchingSerialsByText,
                destinationSerialsByProduct: $destinationSerialsByProduct,
                activeClaimSerialIds: $activeClaimSerialIds,
                allocatedSerialIds: $allocatedSerialIds,
                activeTransferClaimSerialIds: $activeTransferClaimSerialIds,
                locationStocks: $stocksByLocationAndProduct,
            );
            $serialClassifications = $classification['entered'];
            $omittedSerials = $classification['omitted'];
            $serialWarnings = $classification['warnings'];
            $serialConflicts = $classification['conflicts'];
            $newSerialCount = $classification['newSerialCount'];

            if ($isSerialized) {
                $projectedGlobalTotal = $allLocationCurrentTotal + $newSerialCount;
            } else {
                $projectedGlobalTotal = $allLocationCurrentTotal + ($enteredTotal - $currentTotal);
            }

            $drift = ($totalCurrentGood + $totalCurrentBad) - ($totalBaselineGood + $totalBaselineBad);

            $documentWarnings = array_merge($documentWarnings, $serialWarnings);
            $documentConflicts = array_merge($documentConflicts, $serialConflicts);

            $products[] = new ProductReconciliation(
                productId: $productId,
                productName: (string) $product->product_name,
                productCode: (string) $product->product_code,
                baseUnit: $baseUnit,
                isSerialized: $isSerialized,
                baselineGood: $totalBaselineGood,
                baselineBad: $totalBaselineBad,
                currentGood: $totalCurrentGood,
                currentBad: $totalCurrentBad,
                enteredGood: $enteredGood,
                enteredBad: $enteredBad,
                goodDifference: $goodDifference,
                badDifference: $badDifference,
                allLocationCurrentTotal: $allLocationCurrentTotal,
                projectedGlobalTotal: $projectedGlobalTotal,
                drift: (float) $drift,
                exceedsAllLocationTotal: $exceedsAllLocationTotal,
                potentialGlobalIncrease: $potentialGlobalIncrease,
                serials: $serialClassifications,
                omittedSerials: $omittedSerials,
                locationBaselines: $locationBaselines,
                locationCurrentStocks: $locationCurrentStocks,
                goodAllocationPlan: $goodAllocationPlan,
                badAllocationPlan: $badAllocationPlan,
            );
        }

        return new ReconciliationResult(
            adjustmentId: (int) $adjustment->id,
            locked: $locked,
            locationId: $primaryLocationId,
            locationName: $primaryLocationName,
            settingId: $primarySettingId,
            isPkp: $primaryIsPkp,
            products: $products,
            warnings: array_values(array_unique($documentWarnings)),
            conflicts: array_values(array_unique($documentConflicts)),
            unattributedConflicts: array_values(array_unique($unattributedConflicts)),
            computedAt: now()->toIso8601String(),
            selectedLocations: $selectedLocationsData,
        );
    }

    /**
     * Non-consignment location IDs in the given settings' owner scope.
     *
     * @param int[] $settingIds
     */
    private function settingEligibleLocationIds(array $settingIds): Collection
    {
        return Location::standard()
            ->whereIn('setting_id', $settingIds)
            ->pluck('id');
    }

    /**
     * Every existing non-consignment location ID, regardless of setting.
     */
    private function movementEligibleLocationIds(): Collection
    {
        return Location::standard()->pluck('id');
    }
}
