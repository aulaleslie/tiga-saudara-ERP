<?php

namespace Modules\Adjustment\Services;

use App\Models\User;
use App\Services\Notification\DocumentNotificationService;
use App\Services\Notification\StockNotificationService;
use App\Services\SerialNumberHistoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentStatus;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Tax;

/**
 * Atomic approval poster for the redesigned multi-location and versioned Stock Opname
 * workflow. Locks and revalidates the adjustment and every affected stock/serial row
 * inside one database transaction, recomputes reconciliation against the locked
 * authoritative state via StockOpnameSerialClassifier and StockOpnameAllocationPlanner,
 * rejects on any conflict or insufficient bucket availability without mutating anything,
 * and otherwise applies condition-specific allocation plans and serial movements/creations/
 * omissions with exact per-location good/bad x tax/non-tax bucket accounting, writes
 * transactions/notifications for every actually affected location, and persists an
 * immutable `approval_result`.
 *
 * Idempotent: an already-APPROVED document is returned unchanged without reposting.
 * Any exception inside the transaction rolls back every mutation.
 */
class StockOpnameApprovalService
{
    public function __construct(
        private StockOpnameSerialClassifier $serialClassifier,
        private SelectedLocationPoolResolver $locationPoolResolver,
        private StockOpnameAllocationPlanner $allocationPlanner,
    ) {
    }

    public function approve(Adjustment $adjustment, User $actor, ?int $activeSettingId = null): Adjustment
    {
        $activeSettingId ??= (int) session('setting_id');

        if (!$actor->can('adjustments.approval')) {
            throw ValidationException::withMessages([
                'permission' => ['Anda tidak memiliki izin untuk menyetujui proposal stock opname ini.'],
            ]);
        }

        app(AdjustmentOwnershipGuard::class)->assertOwned($adjustment, $activeSettingId);

        return DB::transaction(function () use ($adjustment, $actor, $activeSettingId) {
            /** @var Adjustment $locked */
            $locked = Adjustment::where('id', $adjustment->id)->lockForUpdate()->firstOrFail();

            if (!$locked->isNormalVersioned()) {
                throw ValidationException::withMessages([
                    'status' => ['Hanya proposal stock opname format baru yang dapat disetujui melalui alur ini.'],
                ]);
            }

            $status = AdjustmentStatus::normalize($locked->status);

            if ($status === AdjustmentStatus::Approved) {
                // Idempotent: already approved, no-op re-posting.
                return $locked;
            }

            if ($status !== AdjustmentStatus::WaitingApproval) {
                throw ValidationException::withMessages([
                    'status' => ['Hanya dokumen berstatus menunggu persetujuan yang dapat disetujui.'],
                ]);
            }

            $rows = (array) ($locked->count_draft['rows'] ?? []);
            if (empty($rows)) {
                throw ValidationException::withMessages([
                    'count_draft' => ['Dokumen stock opname kosong tidak dapat disetujui.'],
                ]);
            }

            // Authoritative selected location ID resolution without queries to settings
            if ($locked->isSchemaVersion2()) {
                $selectedLocationIds = \Modules\Adjustment\Entities\AdjustmentLocation::where('adjustment_id', $locked->id)
                    ->orderBy('position')
                    ->orderBy('id')
                    ->pluck('location_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            } else {
                $selectedLocationIds = $locked->location_id ? [(int) $locked->location_id] : [];
            }

            if (empty($selectedLocationIds)) {
                throw ValidationException::withMessages([
                    'location_id' => ['Dokumen stock opname tidak memiliki lokasi yang valid.'],
                ]);
            }

            $productIds = collect($rows)
                ->map(fn ($row) => (int) ($row['product_id'] ?? 0))
                ->filter()
                ->unique()
                ->values();

            $enteredSerialTexts = collect();
            foreach ($rows as $row) {
                foreach ($row['serials'] ?? [] as $serial) {
                    $text = ProductSerialNumber::normalize((string) ($serial['serial_number'] ?? ''));
                    if ($text !== '') {
                        $enteredSerialTexts->push($text);
                    }
                }
            }
            $enteredSerialTexts = $enteredSerialTexts->unique()->values();

            // ---- Step 1 (discovery, unlocked): candidate location IDs ----
            $discovery = $this->discoverSerialLocations(
                selectedLocationIds: $selectedLocationIds,
                productIds: $productIds,
                enteredSerialTexts: $enteredSerialTexts,
            );

            // ---- Step 2 (lock): complete affected Location set, ascending ID, in ONE query ----
            $allLocationIdsToLock = collect($selectedLocationIds)
                ->concat($discovery['locationIds'])
                ->unique()
                ->sort()
                ->values();

            $lockedLocationsById = $this->lockAffectedLocations($allLocationIdsToLock);

            $lockedSelectedLocations = collect();
            foreach ($selectedLocationIds as $locId) {
                $lockedLoc = $lockedLocationsById->get($locId);
                if (!$lockedLoc) {
                    throw ValidationException::withMessages([
                        'location_id' => ["Lokasi terpilih #{$locId} tidak ditemukan."],
                    ]);
                }
                $lockedSelectedLocations->push($lockedLoc);
            }

            // ---- Step 3 (lock): all affected Settings in ascending ID order ----
            $affectedSettingIds = $lockedLocationsById->pluck('setting_id')->filter()->unique()->sort()->values();
            $lockedSettingsById = \Modules\Setting\Entities\Setting::whereIn('id', $affectedSettingIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($lockedLocationsById as $loc) {
                $st = $lockedSettingsById->get($loc->setting_id);
                if (!$st) {
                    throw ValidationException::withMessages([
                        'location_id' => ["Pengaturan pemilik lokasi #{$loc->id} tidak ditemukan."],
                    ]);
                }
                $loc->setRelation('setting', $st);
            }

            // ---- Step 4: revalidate selected locations ownership/eligibility ----
            foreach ($lockedSelectedLocations as $loc) {
                if ($loc->is_consignment) {
                    throw ValidationException::withMessages([
                        'location_id' => ['Penyesuaian stok tidak dapat dilakukan pada lokasi konsinyasi.'],
                    ]);
                }
                if (!$loc->is_active) {
                    throw ValidationException::withMessages([
                        'location_id' => ["Lokasi '{$loc->name}' tidak aktif."],
                    ]);
                }
                if (!$locked->isSchemaVersion2()) {
                    app(AdjustmentOwnershipGuard::class)->assertLocationOwned($loc, $activeSettingId);
                }
            }

            // Check PKP settings have applicable tax
            $applicableTaxesBySettingId = [];
            $defaultTax = Tax::where('is_default', true)->first() ?? Tax::orderByDesc('created_at')->first();
            foreach ($lockedSettingsById as $st) {
                if ($st->is_pkp) {
                    $taxId = $defaultTax?->id;
                    if ($taxId === null) {
                        throw ValidationException::withMessages([
                            'tax_id' => ['Terdapat lokasi bersifat Kena Pajak (PKP) tetapi tidak ada data pajak yang tersedia. Tambahkan data pajak sebelum menyetujui.'],
                        ]);
                    }
                    $applicableTaxesBySettingId[$st->id] = (int) $taxId;
                }
            }

            $primaryLocation = $lockedSelectedLocations->first();
            $primarySetting = $primaryLocation->setting;
            $primaryIsPkp = (bool) ($primarySetting?->is_pkp ?? false);
            $primaryApplicableTaxId = $applicableTaxesBySettingId[$primarySetting->id] ?? null;

            // ---- Step 5 (Product): locked in ascending ID order ----
            $products = Product::with('baseUnit')
                ->whereIn('id', $productIds)
                ->where('is_active', true)
                ->where('stock_managed', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($productIds as $productId) {
                if (!$products->has($productId)) {
                    throw ValidationException::withMessages([
                        'count_draft' => ["Produk dengan ID {$productId} tidak ditemukan, tidak aktif, atau bukan produk yang stoknya dikelola."],
                    ]);
                }
            }

            // ---- Steps 6-7: lock ProductStock then ProductSerialNumber ----
            [
                $matchingSerials,
                $selectedSerials,
                $stocksByProductAndLocation,
            ] = $this->lockStocksThenSerials(
                selectedLocationIds: $selectedLocationIds,
                productIds: $productIds,
                enteredSerialTexts: $enteredSerialTexts,
                products: $products,
                lockedLocationsById: $lockedLocationsById,
            );

            // ---- Step 8: revalidate serial locations against discovery ----
            $this->assertSerialLocationsUnchanged(
                matchingSerials: $matchingSerials,
                destinationSerials: $selectedSerials,
                discoveredLocationsByText: $discovery['locationsByText'],
                lockedLocationsById: $lockedLocationsById,
            );

            $selectedSettingIds = $lockedSelectedLocations->pluck('setting_id')->filter()->unique()->values()->all();
            $settingEligibleLocationIds = Location::standard()
                ->whereIn('setting_id', $selectedSettingIds)
                ->orderBy('id')
                ->pluck('id');

            $movementEligibleLocationIds = $lockedLocationsById
                ->filter(fn (Location $loc) => !$loc->is_consignment)
                ->keys();

            $matchingSerialsByText = $matchingSerials->groupBy('serial_number');
            $selectedSerialsByProduct = $selectedSerials->groupBy('product_id');

            $lockedSerialsById = $matchingSerials->concat($selectedSerials)
                ->unique('id')
                ->keyBy('id');

            $candidateSerialIds = $lockedSerialsById->keys();

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

            $allLocationTotalsByProduct = ProductStock::whereIn('product_id', $productIds)
                ->whereIn('location_id', $settingEligibleLocationIds)
                ->selectRaw('product_id, SUM(quantity) as grand_total')
                ->groupBy('product_id')
                ->get()
                ->keyBy('product_id');

            $stocksByLocationAndProduct = collect();
            foreach ($stocksByProductAndLocation as $compositeKey => $stockCollection) {
                $stock = $stockCollection->first();
                if ($stock) {
                    $stocksByLocationAndProduct->put("{$stock->location_id}_{$stock->product_id}", $stock);
                }
            }

            // ---- Pass 1: build the full mutation plan and reject on ANY conflict before mutating anything ----
            $conflicts = [];
            $plans = [];

            foreach ($rows as $row) {
                $productId = (int) ($row['product_id'] ?? 0);
                $product = $products->get($productId);
                if (!$product) {
                    continue;
                }

                $isSerialized = (bool) $product->serial_number_required;

                if ($isSerialized) {
                    $classification = $this->serialClassifier->classify(
                        product: $product,
                        row: $row,
                        destinationLocation: $lockedSelectedLocations,
                        isPkp: $primaryIsPkp,
                        movementEligibleLocationIds: $movementEligibleLocationIds,
                        matchingSerialsByText: $matchingSerialsByText,
                        destinationSerialsByProduct: $selectedSerialsByProduct,
                        activeClaimSerialIds: $activeClaimSerialIds,
                        allocatedSerialIds: $allocatedSerialIds,
                        activeTransferClaimSerialIds: $activeTransferClaimSerialIds,
                        locationStocks: $stocksByLocationAndProduct,
                    );

                    $plan = $this->planSerializedRow($product, $classification, $lockedSelectedLocations, $stocksByLocationAndProduct);
                    $conflicts = array_merge($conflicts, $plan['conflicts']);
                    $conflicts = array_merge($conflicts, $this->validateBucketAvailability($product, $plan, $stocksByProductAndLocation));
                    $plans[$productId] = $plan;
                } else {
                    $plan = $this->planNonSerializedRow(
                        product: $product,
                        row: $row,
                        selectedLocations: $lockedSelectedLocations,
                        stocksByProductAndLocation: $stocksByProductAndLocation,
                    );
                    $conflicts = array_merge($conflicts, $plan['conflicts']);
                    $plans[$productId] = $plan;
                }
            }

            if (!empty($conflicts)) {
                throw ValidationException::withMessages([
                    'count_draft' => array_values(array_unique($conflicts)),
                ]);
            }

            // ---- Pass 2: apply every planned mutation ----
            $appliedProducts = [];
            $stockNotificationChecks = [];
            $now = now();

            foreach ($rows as $row) {
                $productId = (int) ($row['product_id'] ?? 0);
                $product = $products->get($productId);
                if (!$product) {
                    continue;
                }

                $plan = $plans[$productId];

                if ($plan['type'] === 'non_serialized') {
                    $applied = $this->applyNonSerializedPlan(
                        product: $product,
                        selectedLocations: $lockedSelectedLocations,
                        stocksByProductAndLocation: $stocksByProductAndLocation,
                        plan: $plan,
                        adjustment: $locked,
                        actor: $actor,
                        stockNotificationChecks: $stockNotificationChecks,
                        lockedLocationsById: $lockedLocationsById,
                    );
                } else {
                    $applied = $this->applySerializedPlan(
                        product: $product,
                        selectedLocations: $lockedSelectedLocations,
                        plan: $plan,
                        adjustment: $locked,
                        actor: $actor,
                        applicableTaxesBySettingId: $applicableTaxesBySettingId,
                        stocksByProductAndLocation: $stocksByProductAndLocation,
                        lockedSerialsById: $lockedSerialsById,
                        lockedLocationsById: $lockedLocationsById,
                        stockNotificationChecks: $stockNotificationChecks,
                    );
                }

                $totals = $allLocationTotalsByProduct->get($productId);
                $applied['all_location_current_total_before'] = $totals
                    ? (float) $totals->grand_total
                    : 0.0;

                $appliedProducts[] = $applied;
            }

            foreach ($stockNotificationChecks as $check) {
                if ($check['scope'] === 'location' && (int) $check['before'] !== (int) $check['after']) {
                    app(StockNotificationService::class)->checkLocationStock($check['stock'], $check['before'], $check['after']);
                }
                if ($check['scope'] === 'global' && (int) $check['before'] !== (int) $check['after']) {
                    app(StockNotificationService::class)->checkGlobalStock($check['product'], $check['before'], $check['after']);
                }
            }

            $approvalWarnings = array_values(array_unique(
                collect($appliedProducts)->flatMap(fn (array $p) => $p['warnings'] ?? [])->all()
            ));

            $approvalResult = [
                'adjustment_id' => (int) $locked->id,
                'selected_locations' => $lockedSelectedLocations->map(fn (Location $l) => [
                    'location_id' => (int) $l->id,
                    'location_name' => (string) $l->name,
                    'setting_id' => (int) $l->setting_id,
                    'is_pkp' => (bool) ($l->setting?->is_pkp ?? false),
                ])->values()->all(),
                'location_id' => (int) $primaryLocation->id,
                'location_name' => (string) $primaryLocation->name,
                'setting_id' => (int) $primarySetting->id,
                'is_pkp' => $primaryIsPkp,
                'applicable_tax_id' => $primaryApplicableTaxId,
                'approved_by' => (int) $actor->id,
                'approved_by_name' => (string) ($actor->name ?? ''),
                'approved_at' => $now->toIso8601String(),
                'products' => $appliedProducts,
                'warnings' => $approvalWarnings,
                'conflicts' => [],
            ];

            $locked->update([
                'status' => AdjustmentStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => $now,
                'approval_result' => $approvalResult,
            ]);

            app(DocumentNotificationService::class)->resolveApproval($locked);
            app(DocumentNotificationService::class)->resolveRevision($locked);

            return $locked;
        });
    }

    private function discoverSerialLocations(
        array $selectedLocationIds,
        Collection $productIds,
        Collection $enteredSerialTexts,
    ): array {
        $discoveredLocationsByText = collect();
        if ($enteredSerialTexts->isNotEmpty()) {
            $discoveredLocationsByText = ProductSerialNumber::whereIn('serial_number', $enteredSerialTexts)
                ->pluck('location_id', 'id');
        }

        $selectedSerialLocationIds = collect();
        if ($productIds->isNotEmpty() && !empty($selectedLocationIds)) {
            $selectedSerialLocationIds = ProductSerialNumber::whereIn('location_id', $selectedLocationIds)
                ->whereIn('product_id', $productIds)
                ->pluck('location_id');
        }

        $discoveredLocationIds = collect($selectedLocationIds)
            ->concat($discoveredLocationsByText->values()->filter())
            ->concat($selectedSerialLocationIds)
            ->unique()
            ->sort()
            ->values();

        return [
            'locationIds' => $discoveredLocationIds,
            'locationsByText' => $discoveredLocationsByText,
        ];
    }

    private function lockAffectedLocations(Collection $locationIds): Collection
    {
        return Location::whereIn('id', $locationIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    private function lockStocksThenSerials(
        array $selectedLocationIds,
        Collection $productIds,
        Collection $enteredSerialTexts,
        Collection $products,
        Collection $lockedLocationsById,
    ): array {
        $stocksByProductAndLocation = ProductStock::whereIn('product_id', $productIds)
            ->whereIn('location_id', $lockedLocationsById->keys())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(function (ProductStock $s) use ($products, $lockedLocationsById) {
                if ($products->has($s->product_id)) {
                    $s->setRelation('product', $products->get($s->product_id));
                }
                if ($lockedLocationsById->has($s->location_id)) {
                    $s->setRelation('location', $lockedLocationsById->get($s->location_id));
                }
            })
            ->groupBy(fn (ProductStock $s) => $s->product_id . ':' . $s->location_id);

        $matchingSerials = collect();
        if ($enteredSerialTexts->isNotEmpty()) {
            $matchingSerials = ProductSerialNumber::whereIn('serial_number', $enteredSerialTexts)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }

        $selectedSerials = collect();
        if ($productIds->isNotEmpty() && !empty($selectedLocationIds)) {
            $selectedSerials = ProductSerialNumber::whereIn('location_id', $selectedLocationIds)
                ->whereIn('product_id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }

        $matchingSerials->concat($selectedSerials)
            ->unique('id')
            ->each(function (ProductSerialNumber $s) use ($products, $lockedLocationsById) {
                if ($products->has($s->product_id)) {
                    $s->setRelation('product', $products->get($s->product_id));
                }
                if ($s->location_id !== null && $lockedLocationsById->has($s->location_id)) {
                    $s->setRelation('location', $lockedLocationsById->get($s->location_id));
                }
            });

        return [$matchingSerials, $selectedSerials, $stocksByProductAndLocation];
    }

    private function assertSerialLocationsUnchanged(
        Collection $matchingSerials,
        Collection $destinationSerials,
        Collection $discoveredLocationsByText,
        Collection $lockedLocationsById,
    ): void {
        $locationChanged = $matchingSerials->contains(
            fn (ProductSerialNumber $s) => (int) $discoveredLocationsByText->get($s->id) !== (int) $s->location_id
        );

        $missingLockedLocation = $matchingSerials
            ->concat($destinationSerials)
            ->contains(
                fn (ProductSerialNumber $s) => $s->location_id !== null && !$lockedLocationsById->has((int) $s->location_id)
            );

        if ($locationChanged || $missingLockedLocation) {
            throw ValidationException::withMessages([
                'count_draft' => ['Lokasi salah satu nomor seri berubah atau tidak valid secara bersamaan saat proses persetujuan berjalan. Silakan coba lagi.'],
            ]);
        }
    }

    private function planNonSerializedRow(
        Product $product,
        array $row,
        Collection $selectedLocations,
        Collection $stocksByProductAndLocation,
    ): array {
        $enteredGood = (int) ($row['good_count'] ?? 0);
        $enteredBad = (int) ($row['bad_count'] ?? 0);

        $goodLocationRows = [];
        $badLocationRows = [];
        $totalCurrentGood = 0;
        $totalCurrentBad = 0;
        $locationCurrentStocks = [];

        foreach ($selectedLocations as $loc) {
            $locId = (int) $loc->id;
            $stock = $stocksByProductAndLocation->get($product->id . ':' . $locId, collect())->first();

            $locGoodTax = (int) round((float) ($stock?->quantity_tax ?? 0));
            $locGoodNonTax = (int) round((float) ($stock?->quantity_non_tax ?? 0));
            $locBadTax = (int) round((float) ($stock?->broken_quantity_tax ?? 0));
            $locBadNonTax = (int) round((float) ($stock?->broken_quantity_non_tax ?? 0));

            $locGood = $locGoodTax + $locGoodNonTax;
            $locBad = $locBadTax + $locBadNonTax;

            $totalCurrentGood += $locGood;
            $totalCurrentBad += $locBad;

            $isPkp = (bool) ($loc->setting?->is_pkp ?? false);

            $goodLocationRows[] = [
                'location_id' => $locId,
                'setting_id' => (int) $loc->setting_id,
                'is_pkp' => $isPkp,
                'location_name' => (string) $loc->name,
                'stock' => $locGood,
            ];

            $badLocationRows[] = [
                'location_id' => $locId,
                'setting_id' => (int) $loc->setting_id,
                'is_pkp' => $isPkp,
                'location_name' => (string) $loc->name,
                'stock' => $locBad,
            ];

            $locationCurrentStocks[$locId] = [
                'good' => $locGood,
                'bad' => $locBad,
                'good_tax' => $locGoodTax,
                'good_non_tax' => $locGoodNonTax,
                'bad_tax' => $locBadTax,
                'bad_non_tax' => $locBadNonTax,
                'total' => $locGood + $locBad,
                'is_pkp' => $isPkp,
            ];
        }

        $goodDifference = $enteredGood - $totalCurrentGood;
        $badDifference = $enteredBad - $totalCurrentBad;

        $goodAllocationPlan = $this->allocationPlanner->plan($goodLocationRows, $goodDifference, 'good');
        $badAllocationPlan = $this->allocationPlanner->plan($badLocationRows, $badDifference, 'bad');

        $goodStepsByLoc = collect($goodAllocationPlan['steps'])->keyBy('location_id');
        $badStepsByLoc = collect($badAllocationPlan['steps'])->keyBy('location_id');

        $locationPlans = [];
        $appliedGoodTax = 0;
        $appliedGoodNonTax = 0;
        $appliedBadTax = 0;
        $appliedBadNonTax = 0;

        foreach ($selectedLocations as $loc) {
            $locId = (int) $loc->id;
            $isPkp = (bool) ($loc->setting?->is_pkp ?? false);
            $curr = $locationCurrentStocks[$locId];

            $stepGood = $goodStepsByLoc->get($locId);
            $stepBad = $badStepsByLoc->get($locId);

            $targetGood = $stepGood ? (int) $stepGood['after_stock'] : $curr['good'];
            $targetBad = $stepBad ? (int) $stepBad['after_stock'] : $curr['bad'];

            $newGoodTax = $isPkp ? $targetGood : 0;
            $newGoodNonTax = $isPkp ? 0 : $targetGood;
            $newBadTax = $isPkp ? $targetBad : 0;
            $newBadNonTax = $isPkp ? 0 : $targetBad;

            $appliedGoodTax += $newGoodTax;
            $appliedGoodNonTax += $newGoodNonTax;
            $appliedBadTax += $newBadTax;
            $appliedBadNonTax += $newBadNonTax;

            $locationPlans[$locId] = [
                'location_id' => $locId,
                'is_pkp' => $isPkp,
                'before_good' => $curr['good'],
                'before_bad' => $curr['bad'],
                'before_total' => $curr['total'],
                'target_good' => $targetGood,
                'target_bad' => $targetBad,
                'target_total' => $targetGood + $targetBad,
                'new_good_tax' => $newGoodTax,
                'new_good_non_tax' => $newGoodNonTax,
                'new_bad_tax' => $newBadTax,
                'new_bad_non_tax' => $newBadNonTax,
                'good_delta' => $stepGood ? (int) $stepGood['delta'] : 0,
                'bad_delta' => $stepBad ? (int) $stepBad['delta'] : 0,
            ];
        }

        return [
            'type' => 'non_serialized',
            'product_id' => (int) $product->id,
            'conflicts' => [],
            'current_good' => $totalCurrentGood,
            'current_bad' => $totalCurrentBad,
            'entered_good' => $enteredGood,
            'entered_bad' => $enteredBad,
            'applied_good_tax' => $appliedGoodTax,
            'applied_good_non_tax' => $appliedGoodNonTax,
            'applied_bad_tax' => $appliedBadTax,
            'applied_bad_non_tax' => $appliedBadNonTax,
            'good_allocation_plan' => $goodAllocationPlan,
            'bad_allocation_plan' => $badAllocationPlan,
            'location_plans' => $locationPlans,
        ];
    }

    private function applyNonSerializedPlan(
        Product $product,
        Collection $selectedLocations,
        Collection $stocksByProductAndLocation,
        array $plan,
        Adjustment $adjustment,
        User $actor,
        array &$stockNotificationChecks,
        Collection $lockedLocationsById,
    ): array {
        $previousProductQuantity = (float) $product->product_quantity;
        $previousProductBroken = (float) ($product->broken_quantity ?? 0);

        $netProductDelta = 0.0;
        $netBrokenDelta = 0.0;
        $locationsEvidence = [];

        foreach ($selectedLocations as $loc) {
            $locId = (int) $loc->id;
            $locPlan = $plan['location_plans'][$locId];

            $stock = $stocksByProductAndLocation->get($product->id . ':' . $locId, collect())->first();
            if (!$stock) {
                $lockedLocation = $lockedLocationsById->get($locId) ?? $loc;
                $stock = ProductStock::create([
                    'product_id' => $product->id,
                    'location_id' => $locId,
                    'quantity' => 0, 'quantity_non_tax' => 0, 'quantity_tax' => 0,
                    'broken_quantity' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0,
                ]);
                $stock = ProductStock::where('id', $stock->id)->lockForUpdate()->first();
                $stock->setRelation('location', $lockedLocation);
            }
            $stock->setRelation('product', $product);

            $previousStockTotal = (float) $stock->quantity_tax + (float) $stock->quantity_non_tax
                + (float) $stock->broken_quantity_tax + (float) $stock->broken_quantity_non_tax;
            $previousStockBroken = (float) $stock->broken_quantity_tax + (float) $stock->broken_quantity_non_tax;

            $stock->quantity_tax = $locPlan['new_good_tax'];
            $stock->quantity_non_tax = $locPlan['new_good_non_tax'];
            $stock->broken_quantity_tax = $locPlan['new_bad_tax'];
            $stock->broken_quantity_non_tax = $locPlan['new_bad_non_tax'];
            $stock->broken_quantity = $locPlan['new_bad_tax'] + $locPlan['new_bad_non_tax'];
            $stock->quantity = $locPlan['new_good_tax'] + $locPlan['new_good_non_tax'] + $stock->broken_quantity;
            $stock->save();

            $afterStockTotal = (float) $stock->quantity;
            $afterStockBroken = (float) $stock->broken_quantity;

            $locationDelta = $afterStockTotal - $previousStockTotal;
            $locationBrokenDelta = $afterStockBroken - $previousStockBroken;

            $netProductDelta += $locationDelta;
            $netBrokenDelta += $locationBrokenDelta;

            $stockNotificationChecks["location:{$stock->id}"] = [
                'scope' => 'location',
                'stock' => $stock,
                'before' => $previousStockTotal,
                'after' => $afterStockTotal,
            ];

            Transaction::create([
                'product_id' => $product->id,
                'setting_id' => $loc->setting_id,
                'type' => 'ADJ',
                'quantity' => $locationDelta,
                'current_quantity' => (float) $stock->quantity,
                'broken_quantity' => (float) $stock->broken_quantity,
                'previous_quantity' => $previousProductQuantity,
                'previous_quantity_at_location' => $previousStockTotal,
                'after_quantity' => max(0, $previousProductQuantity + $netProductDelta),
                'after_quantity_at_location' => (float) $stock->quantity,
                'quantity_tax' => (float) $stock->quantity_tax,
                'quantity_non_tax' => (float) $stock->quantity_non_tax,
                'broken_quantity_tax' => (float) $stock->broken_quantity_tax,
                'broken_quantity_non_tax' => (float) $stock->broken_quantity_non_tax,
                'location_id' => $loc->id,
                'user_id' => $actor->id,
                'reason' => "Stock opname disetujui ({$adjustment->reference})",
            ]);

            $locationsEvidence[] = [
                'location_id' => (int) $loc->id,
                'before_total' => $previousStockTotal,
                'after_total' => (float) $stock->quantity,
                'quantity_tax' => (float) $stock->quantity_tax,
                'quantity_non_tax' => (float) $stock->quantity_non_tax,
                'broken_quantity_tax' => (float) $stock->broken_quantity_tax,
                'broken_quantity_non_tax' => (float) $stock->broken_quantity_non_tax,
            ];
        }

        $newProductQuantity = max(0, $previousProductQuantity + $netProductDelta);
        $newProductBroken = max(0, $previousProductBroken + $netBrokenDelta);
        $product->product_quantity = $newProductQuantity;
        $product->broken_quantity = $newProductBroken;
        $product->save();

        $stockNotificationChecks["global:{$product->id}"] = [
            'scope' => 'global',
            'product' => $product,
            'before' => $previousProductQuantity,
            'after' => (float) $product->product_quantity,
        ];

        $appliedGood = $plan['applied_good_tax'] + $plan['applied_good_non_tax'];
        $appliedBad = $plan['applied_bad_tax'] + $plan['applied_bad_non_tax'];

        return [
            'product_id' => (int) $product->id,
            'product_name' => (string) $product->product_name,
            'product_code' => (string) $product->product_code,
            'base_unit' => (string) ($product->baseUnit?->unit_name ?? $product->baseUnit?->name ?? ''),
            'is_serialized' => false,
            'before' => [
                'good' => $plan['current_good'],
                'bad' => $plan['current_bad'],
                'product_quantity' => $previousProductQuantity,
            ],
            'entered' => [
                'good' => $plan['entered_good'],
                'bad' => $plan['entered_bad'],
            ],
            'applied' => [
                'good' => $appliedGood,
                'bad' => $appliedBad,
                'good_tax' => $plan['applied_good_tax'],
                'good_non_tax' => $plan['applied_good_non_tax'],
                'bad_tax' => $plan['applied_bad_tax'],
                'bad_non_tax' => $plan['applied_bad_non_tax'],
                'product_quantity' => (float) $product->product_quantity,
            ],
            'net_delta' => $netProductDelta,
            'good_allocation_plan' => $plan['good_allocation_plan'],
            'bad_allocation_plan' => $plan['bad_allocation_plan'],
            'locations' => $locationsEvidence,
            'serials' => [],
            'omitted_serials' => [],
            'warnings' => [],
        ];
    }

    private function planSerializedRow(
        Product $product,
        array $classification,
        Collection $selectedLocations,
        Collection $locationStocks,
    ): array {
        $conflicts = $classification['conflicts'];
        $entries = [];
        $bucketDebits = [];

        foreach ($classification['entered'] as $serial) {
            /** @var \Modules\Adjustment\DTOs\SerialClassification $serial */
            if ($serial->status === \Modules\Adjustment\DTOs\SerialClassification::STATUS_CONFLICTING) {
                continue;
            }

            $isBad = $serial->enteredCondition === 'bad';

            if ($serial->status === \Modules\Adjustment\DTOs\SerialClassification::STATUS_NEW) {
                $targetLoc = $this->serialClassifier->determineSurplusDestination($selectedLocations, $locationStocks, (int) $product->id, $serial->enteredCondition);
                $targetIsTax = (bool) ($targetLoc->setting?->is_pkp ?? false);

                $entries[] = [
                    'action' => 'create',
                    'serial_id' => $serial->sourceSerialId,
                    'serial_number' => $serial->serialNumber,
                    'destination_location_id' => (int) $targetLoc->id,
                    'destination_location_name' => (string) $targetLoc->name,
                    'destination_setting_id' => (int) $targetLoc->setting_id,
                    'destination_is_tax' => $targetIsTax,
                    'is_bad' => $isBad,
                    'entered_condition' => $serial->enteredCondition,
                    'same_text_other_product' => $serial->sameTextOtherProduct,
                    'label' => $serial->label,
                ];
                continue;
            }

            $sourceLocationId = (int) $serial->sourceLocationId;
            $sourceIsBad = $serial->sourceCondition === 'bad';
            $sourceIsTax = (bool) $serial->sourceIsTax;

            $bucketKey = $sourceLocationId . ':' . ($sourceIsBad ? 'bad' : 'good') . ':' . ($sourceIsTax ? 'tax' : 'non_tax');
            $bucketDebits[$bucketKey] = ($bucketDebits[$bucketKey] ?? 0) + 1;

            $selectedLocationIds = $selectedLocations->pluck('id')->map(fn ($id) => (int) $id)->all();
            $isAlreadyInPool = in_array($sourceLocationId, $selectedLocationIds, true);

            if ($isAlreadyInPool) {
                $targetLoc = $selectedLocations->firstWhere('id', $sourceLocationId);
            } else {
                $targetLoc = $this->serialClassifier->determineSurplusDestination($selectedLocations, $locationStocks, (int) $product->id, $serial->enteredCondition);
            }
            $targetIsTax = (bool) ($targetLoc->setting?->is_pkp ?? false);

            $entries[] = [
                'action' => 'move',
                'serial_id' => $serial->sourceSerialId,
                'serial_number' => $serial->serialNumber,
                'source_location_id' => $sourceLocationId,
                'source_location_name' => $serial->sourceLocationName,
                'source_is_bad' => $sourceIsBad,
                'source_is_tax' => $sourceIsTax,
                'destination_location_id' => (int) $targetLoc->id,
                'destination_location_name' => (string) $targetLoc->name,
                'destination_setting_id' => (int) $targetLoc->setting_id,
                'destination_is_tax' => $targetIsTax,
                'is_bad' => $isBad,
                'entered_condition' => $serial->enteredCondition,
                'same_text_other_product' => $serial->sameTextOtherProduct,
                'cross_setting' => $serial->crossSetting,
                'label' => $serial->label,
            ];
        }

        $omissions = [];
        foreach ($classification['omitted'] as $serial) {
            /** @var \Modules\Adjustment\DTOs\SerialClassification $serial */
            $sourceLocationId = (int) $serial->sourceLocationId;
            $sourceIsBad = $serial->sourceCondition === 'bad';
            $sourceIsTax = (bool) $serial->sourceIsTax;

            $bucketKey = $sourceLocationId . ':' . ($sourceIsBad ? 'bad' : 'good') . ':' . ($sourceIsTax ? 'tax' : 'non_tax');
            $bucketDebits[$bucketKey] = ($bucketDebits[$bucketKey] ?? 0) + 1;

            $omissions[] = [
                'serial_id' => $serial->sourceSerialId,
                'serial_number' => $serial->serialNumber,
                'source_location_id' => $sourceLocationId,
                'is_bad' => $sourceIsBad,
                'is_tax' => $sourceIsTax,
                'label' => $serial->label,
            ];
        }

        return [
            'type' => 'serialized',
            'product_id' => (int) $product->id,
            'entries' => $entries,
            'omissions' => $omissions,
            'bucket_debits' => $bucketDebits,
            'conflicts' => $conflicts,
            'warnings' => $classification['warnings'],
        ];
    }

    private function validateBucketAvailability(Product $product, array $plan, Collection $stocksByProductAndLocation): array
    {
        $shortfalls = [];

        foreach ($plan['bucket_debits'] as $bucketKey => $needed) {
            [$locationId, $conditionKey, $taxKey] = explode(':', $bucketKey);
            $stock = $stocksByProductAndLocation->get($product->id . ':' . $locationId, collect())->first();
            $available = $this->bucketValue($stock, $conditionKey === 'bad', $taxKey === 'tax');
            if ($available < $needed) {
                $shortfalls[] = sprintf(
                    'Produk %s: stok %s/%s di lokasi #%s tidak mencukupi untuk memproses nomor seri (tersedia %d, dibutuhkan %d).',
                    $product->product_name,
                    $conditionKey === 'bad' ? 'rusak' : 'baik',
                    $taxKey === 'tax' ? 'kena pajak' : 'tidak kena pajak',
                    $locationId,
                    $available,
                    $needed
                );
            }
        }

        return $shortfalls;
    }

    private function applySerializedPlan(
        Product $product,
        Collection $selectedLocations,
        array $plan,
        Adjustment $adjustment,
        User $actor,
        array $applicableTaxesBySettingId,
        Collection $stocksByProductAndLocation,
        Collection $lockedSerialsById,
        Collection $lockedLocationsById,
        array &$stockNotificationChecks,
    ): array {
        $touchedStocks = [];

        $touchStock = function (int $locationId) use (&$touchedStocks, $product, $stocksByProductAndLocation, $lockedLocationsById) {
            if (isset($touchedStocks[$locationId])) {
                return $touchedStocks[$locationId]['stock'];
            }
            $stock = $stocksByProductAndLocation->get($product->id . ':' . $locationId, collect())->first();
            if (!$stock) {
                $lockedLocation = $lockedLocationsById->get($locationId);
                if (!$lockedLocation) {
                    throw ValidationException::withMessages([
                        'count_draft' => ["Lokasi #{$locationId} tidak memiliki data lokasi terkunci yang sah untuk memproses stok."],
                    ]);
                }
                $stock = ProductStock::create([
                    'product_id' => $product->id,
                    'location_id' => $locationId,
                    'quantity' => 0, 'quantity_non_tax' => 0, 'quantity_tax' => 0,
                    'broken_quantity' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0,
                ]);
                $stock = ProductStock::where('id', $stock->id)->lockForUpdate()->first();
                $stock->setRelation('location', $lockedLocation);
            }
            $stock->setRelation('product', $product);
            $touchedStocks[$locationId] = [
                'stock' => $stock,
                'before_total' => (float) $stock->quantity_tax + (float) $stock->quantity_non_tax
                    + (float) $stock->broken_quantity_tax + (float) $stock->broken_quantity_non_tax,
                'before_broken' => (float) $stock->broken_quantity_tax + (float) $stock->broken_quantity_non_tax,
                'before_good' => (float) $stock->quantity_tax + (float) $stock->quantity_non_tax,
                'before_bad' => (float) $stock->broken_quantity_tax + (float) $stock->broken_quantity_non_tax,
            ];
            return $stock;
        };

        foreach ($selectedLocations as $loc) {
            $touchStock((int) $loc->id);
        }

        $appliedEntries = [];
        $appliedOmissions = [];
        $newSerialCount = 0;

        foreach ($plan['entries'] as $entry) {
            $destLocId = (int) $entry['destination_location_id'];
            $destLoc = $lockedLocationsById->get($destLocId);
            $destIsPkp = (bool) ($destLoc?->setting?->is_pkp ?? false);
            $destTaxId = $destIsPkp ? ($applicableTaxesBySettingId[$destLoc->setting_id] ?? null) : null;

            if ($entry['action'] === 'create') {
                $destStock = $touchStock($destLocId);
                $this->incrementBucket($destStock, $entry['is_bad'], $destIsPkp, 1);

                $serial = ProductSerialNumber::create([
                    'product_id' => $product->id,
                    'location_id' => $destLocId,
                    'serial_number' => $entry['serial_number'],
                    'tax_id' => $destTaxId,
                    'status' => ProductSerialNumber::STATUS_ACTIVE,
                    'is_broken' => $entry['is_bad'],
                ]);

                SerialNumberHistoryService::record(
                    $serial->id,
                    SerialNumberHistory::EVENT_STATUS_CHANGED,
                    $destLocId,
                    $adjustment,
                    "Stock opname disetujui ({$adjustment->reference}): nomor seri baru terdaftar."
                );

                $newSerialCount++;

                $appliedEntries[] = [
                    'serial_id' => (int) $serial->id,
                    'serial_number' => $entry['serial_number'],
                    'action' => 'created',
                    'source_location_id' => null,
                    'source_condition' => null,
                    'source_is_tax' => null,
                    'destination_location_id' => $destLocId,
                    'destination_location_name' => $entry['destination_location_name'] ?? $destLoc?->name,
                    'applied_condition' => $entry['is_bad'] ? 'bad' : 'good',
                    'applied_is_tax' => $destIsPkp,
                    'same_text_other_product' => $entry['same_text_other_product'] ?? false,
                    'label' => $entry['label'] ?? '',
                ];
                continue;
            }

            $sourceLocationId = (int) $entry['source_location_id'];
            $sourceStock = $touchStock($sourceLocationId);
            $this->incrementBucket($sourceStock, $entry['source_is_bad'], $entry['source_is_tax'], -1);

            $destStock = $sourceLocationId === $destLocId
                ? $sourceStock
                : $touchStock($destLocId);
            $this->incrementBucket($destStock, $entry['is_bad'], $destIsPkp, 1);

            /** @var ProductSerialNumber $serial */
            $serial = $lockedSerialsById->get($entry['serial_id']);
            $serial->update([
                'location_id' => $destLocId,
                'is_broken' => $entry['is_bad'],
                'tax_id' => $destTaxId,
                'status' => ProductSerialNumber::STATUS_ACTIVE,
            ]);

            $isSameLocation = $sourceLocationId === $destLocId;
            if (!$isSameLocation) {
                SerialNumberHistoryService::record(
                    $serial->id,
                    SerialNumberHistory::EVENT_LOCATION_TRANSFER,
                    $destLocId,
                    $adjustment,
                    "Stock opname disetujui ({$adjustment->reference}): pindah lokasi."
                );
            } elseif ($entry['source_is_bad'] !== $entry['is_bad'] || $entry['source_is_tax'] !== $destIsPkp) {
                SerialNumberHistoryService::record(
                    $serial->id,
                    SerialNumberHistory::EVENT_STATUS_CHANGED,
                    $destLocId,
                    $adjustment,
                    "Stock opname disetujui ({$adjustment->reference}): kondisi/status pajak diperbarui."
                );
            }

            $appliedEntries[] = [
                'serial_id' => (int) $serial->id,
                'serial_number' => $entry['serial_number'],
                'action' => $isSameLocation ? 'retained' : 'moved',
                'source_location_id' => $sourceLocationId,
                'source_location_name' => $entry['source_location_name'] ?? null,
                'source_condition' => $entry['source_is_bad'] ? 'bad' : 'good',
                'source_is_tax' => $entry['source_is_tax'],
                'cross_setting' => $entry['cross_setting'] ?? false,
                'destination_location_id' => $destLocId,
                'destination_location_name' => $entry['destination_location_name'] ?? $destLoc?->name,
                'applied_condition' => $entry['is_bad'] ? 'bad' : 'good',
                'applied_is_tax' => $destIsPkp,
                'same_text_other_product' => $entry['same_text_other_product'] ?? false,
                'label' => $entry['label'] ?? '',
            ];
        }

        foreach ($plan['omissions'] as $omission) {
            $sourceLocId = (int) $omission['source_location_id'];
            $stock = $touchStock($sourceLocId);
            $this->incrementBucket($stock, $omission['is_bad'], $omission['is_tax'], -1);

            /** @var ProductSerialNumber $serial */
            $serial = $lockedSerialsById->get($omission['serial_id']);
            $serial->update([
                'status' => ProductSerialNumber::STATUS_MISSING,
            ]);

            SerialNumberHistoryService::record(
                $serial->id,
                SerialNumberHistory::EVENT_STOCK_OPNAME_MISSING,
                $sourceLocId,
                $adjustment,
                "Stock opname disetujui ({$adjustment->reference}): tidak ditemukan saat penghitungan fisik."
            );

            $appliedOmissions[] = [
                'serial_id' => (int) $serial->id,
                'serial_number' => $omission['serial_number'],
                'action' => 'missing',
                'previous_location_id' => $sourceLocId,
                'previous_condition' => $omission['is_bad'] ? 'bad' : 'good',
                'previous_is_tax' => $omission['is_tax'],
                'disposition' => 'status_set_to_missing_location_retained_as_provenance',
                'label' => $omission['label'] ?? '',
            ];
        }

        $previousProductQuantity = (float) $product->product_quantity;
        $previousProductBroken = (float) ($product->broken_quantity ?? 0);
        $netProductDelta = 0.0;
        $netBrokenDelta = 0.0;
        $stockDeltas = [];

        foreach ($touchedStocks as $locationId => $data) {
            $stock = $data['stock'];
            $stock->broken_quantity = (float) $stock->broken_quantity_tax + (float) $stock->broken_quantity_non_tax;
            $stock->quantity = (float) $stock->quantity_tax + (float) $stock->quantity_non_tax + $stock->broken_quantity;
            $stock->save();

            $afterTotal = (float) $stock->quantity;
            $beforeTotal = $data['before_total'];
            $netProductDelta += $afterTotal - $beforeTotal;
            $netBrokenDelta += (float) $stock->broken_quantity - $data['before_broken'];

            $stockDeltas[$locationId] = ['stock' => $stock, 'before_total' => $beforeTotal, 'after_total' => $afterTotal];

            $stockNotificationChecks["location:{$stock->id}"] = [
                'scope' => 'location', 'stock' => $stock,
                'before' => $beforeTotal, 'after' => $afterTotal,
            ];
        }

        $product->product_quantity = max(0, $previousProductQuantity + $netProductDelta);
        $product->broken_quantity = max(0, $previousProductBroken + $netBrokenDelta);
        $product->save();
        $afterProductQuantity = (float) $product->product_quantity;

        $locationsEvidence = [];
        foreach ($stockDeltas as $locationId => $data) {
            $stock = $data['stock'];
            $delta = $data['after_total'] - $data['before_total'];

            $lockedLocation = $lockedLocationsById->get($locationId);
            if (!$lockedLocation) {
                throw ValidationException::withMessages([
                    'count_draft' => ["Lokasi #{$locationId} tidak memiliki data lokasi terkunci yang sah untuk mencatat transaksi."],
                ]);
            }
            $transactionSettingId = $lockedLocation->setting_id;

            Transaction::create([
                'product_id' => $product->id,
                'setting_id' => $transactionSettingId,
                'type' => 'ADJ',
                'quantity' => $delta,
                'current_quantity' => (float) $stock->quantity,
                'broken_quantity' => (float) $stock->broken_quantity,
                'previous_quantity' => $previousProductQuantity,
                'previous_quantity_at_location' => $data['before_total'],
                'after_quantity' => $afterProductQuantity,
                'after_quantity_at_location' => $data['after_total'],
                'quantity_tax' => (float) $stock->quantity_tax,
                'quantity_non_tax' => (float) $stock->quantity_non_tax,
                'broken_quantity_tax' => (float) $stock->broken_quantity_tax,
                'broken_quantity_non_tax' => (float) $stock->broken_quantity_non_tax,
                'location_id' => (int) $locationId,
                'user_id' => $actor->id,
                'reason' => "Stock opname disetujui ({$adjustment->reference})",
            ]);

            $locationsEvidence[] = [
                'location_id' => (int) $locationId,
                'before_total' => $data['before_total'],
                'after_total' => $data['after_total'],
                'quantity_tax' => (float) $stock->quantity_tax,
                'quantity_non_tax' => (float) $stock->quantity_non_tax,
                'broken_quantity_tax' => (float) $stock->broken_quantity_tax,
                'broken_quantity_non_tax' => (float) $stock->broken_quantity_non_tax,
            ];
        }

        $stockNotificationChecks["global:{$product->id}"] = [
            'scope' => 'global', 'product' => $product,
            'before' => $previousProductQuantity, 'after' => $afterProductQuantity,
        ];

        $primaryLoc = $selectedLocations->first();
        $primaryBefore = $touchedStocks[$primaryLoc->id] ?? null;
        $primaryAfter = $stockDeltas[$primaryLoc->id]['stock'] ?? null;
        $beforeDestGood = (float) ($primaryBefore['before_good'] ?? 0);
        $beforeDestBad = (float) ($primaryBefore['before_bad'] ?? 0);
        $appliedDestGood = $primaryAfter
            ? (float) $primaryAfter->quantity_tax + (float) $primaryAfter->quantity_non_tax
            : $beforeDestGood;
        $appliedDestBad = $primaryAfter
            ? (float) $primaryAfter->broken_quantity_tax + (float) $primaryAfter->broken_quantity_non_tax
            : $beforeDestBad;

        $enteredGood = collect($plan['entries'])->filter(fn (array $e) => !$e['is_bad'])->count();
        $enteredBad = collect($plan['entries'])->filter(fn (array $e) => $e['is_bad'])->count();

        return [
            'product_id' => (int) $product->id,
            'product_name' => (string) $product->product_name,
            'product_code' => (string) $product->product_code,
            'base_unit' => (string) ($product->baseUnit?->unit_name ?? $product->baseUnit?->name ?? ''),
            'is_serialized' => true,
            'before' => [
                'good' => $beforeDestGood,
                'bad' => $beforeDestBad,
                'product_quantity' => $previousProductQuantity,
                'broken_quantity' => $previousProductBroken,
            ],
            'entered' => [
                'good' => $enteredGood,
                'bad' => $enteredBad,
                'serial_count' => count($plan['entries']),
            ],
            'applied' => [
                'good' => $appliedDestGood,
                'bad' => $appliedDestBad,
                'new_serial_count' => $newSerialCount,
                'net_delta' => $netProductDelta,
                'broken_delta' => $netBrokenDelta,
                'product_quantity' => (float) $product->product_quantity,
                'broken_quantity' => (float) $product->broken_quantity,
            ],
            'net_delta' => $netProductDelta,
            'broken_delta' => $netBrokenDelta,
            'locations' => $locationsEvidence,
            'serials' => $appliedEntries,
            'omitted_serials' => $appliedOmissions,
            'warnings' => $plan['warnings'],
        ];
    }

    private function bucketValue(?ProductStock $stock, bool $isBad, bool $isTax): int
    {
        if (!$stock) {
            return 0;
        }
        if ($isBad) {
            return (int) round((float) ($isTax ? $stock->broken_quantity_tax : $stock->broken_quantity_non_tax));
        }
        return (int) round((float) ($isTax ? $stock->quantity_tax : $stock->quantity_non_tax));
    }

    private function incrementBucket(ProductStock $stock, bool $isBad, bool $isTax, int $delta): void
    {
        if ($isBad && $isTax) {
            $stock->broken_quantity_tax = max(0, (int) round((float) $stock->broken_quantity_tax) + $delta);
        } elseif ($isBad && !$isTax) {
            $stock->broken_quantity_non_tax = max(0, (int) round((float) $stock->broken_quantity_non_tax) + $delta);
        } elseif (!$isBad && $isTax) {
            $stock->quantity_tax = max(0, (int) round((float) $stock->quantity_tax) + $delta);
        } else {
            $stock->quantity_non_tax = max(0, (int) round((float) $stock->quantity_non_tax) + $delta);
        }
    }
}
