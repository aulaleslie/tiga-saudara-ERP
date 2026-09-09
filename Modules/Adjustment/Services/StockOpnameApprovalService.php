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
 * Atomic approval poster for the redesigned (normal versioned) Stock Opname
 * workflow (tasks 4.1-4.6). Locks and revalidates the adjustment and every
 * affected stock/serial row inside one database transaction, recomputes
 * reconciliation against the locked authoritative state via the same
 * StockOpnameSerialClassifier used by the reviewer preview (never trusting
 * the caller's earlier preview, and never diverging from what the reviewer
 * was shown), rejects on any conflict or insufficient bucket availability
 * without mutating anything, and otherwise applies exactly the entered
 * non-serialized absolute counts and serial movements/creations/omissions
 * with exact per-location good/bad x tax/non-tax bucket accounting, writes
 * transactions/notifications for every actually affected location, and
 * persists an immutable `approval_result`.
 *
 * Idempotent: an already-APPROVED document is returned unchanged without
 * reposting. Any exception inside the transaction rolls back every mutation.
 */
class StockOpnameApprovalService
{
    public function __construct(
        private StockOpnameSerialClassifier $serialClassifier,
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

            app(AdjustmentOwnershipGuard::class)->assertOwned($locked, $activeSettingId);

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

            $destinationLocationId = (int) $locked->location_id;

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

            // ---- Global lock hierarchy (single order shared by every path
            // through this method, and matching TransferMovementService's
            // stock-before-serial order): ----
            //   1. Adjustment (already locked above)
            //   2. every affected Location, ordered by ID -- the destination
            //      is a member of this SAME ordered lock, never locked
            //      separately or earlier. Two opposite-direction approvals
            //      (A's destination is B's discovered source, and B's
            //      destination is A's discovered source) previously could
            //      deadlock because each transaction locked its own
            //      destination first, then later tried to lock the other's
            //      already-held destination as part of its own sorted
            //      source-location set. Folding the destination into the
            //      same single ascending-ID location query removes that
            //      possibility: whichever transaction reaches the lower
            //      location ID first acquires it, and the other transaction
            //      simply waits its turn on the same ordered lock, exactly
            //      as it would for any other pair of rows in one hierarchy.
            //   3. destination Setting (derived from the locked location map)
            //   4. Product rows, ordered by ID
            //   5. ProductStock rows, ordered by ID
            //   6. ProductSerialNumber rows, ordered by ID
            //
            // Which non-destination Location rows belong in step 2 is not
            // known in advance -- a matched serial's current location_id
            // (its move source) is only readable from the ProductSerialNumber
            // row itself. This is resolved with bounded discovery: an
            // UNLOCKED read of the candidate serials (discoverSerialLocations())
            // first identifies candidate location IDs (never locking anything
            // by itself), the complete location set (destination + every
            // discovered candidate) is then locked ONCE in ascending ID order
            // (lockAffectedLocations()), Setting/Product are locked next
            // (ordinary code in this method, steps 3-4), ProductStock and
            // ProductSerialNumber are locked after that (lockStocksThenSerials(),
            // steps 5-6), and only then is every discovered serial's location
            // revalidated against both the original unlocked discovery read
            // and the locked location map (assertSerialLocationsUnchanged()).
            // If a concurrent mover changed a serial's location in the window
            // between the unlocked discovery read and the location lock, this
            // raises a conflict and rolls back rather than silently
            // proceeding against a location set that might be incomplete --
            // per the requirement that a discovery disagreement not be
            // retried inside this same transaction (retrying here would mean
            // re-running discovery while first-attempt locks are still held,
            // letting the acquired lock set grow in an order not reducible to
            // the single hierarchy above).

            // ---- Step 2 (discovery, unlocked): candidate location IDs ----
            $discovery = $this->discoverSerialLocations(
                destinationLocationId: $destinationLocationId,
                productIds: $productIds,
                enteredSerialTexts: $enteredSerialTexts,
            );

            // ---- Step 2 (lock): complete affected Location set, ascending
            // ID, in ONE query. The destination is a member of this set --
            // never locked separately or earlier. ----
            $lockedLocationsById = $this->lockAffectedLocations(
                destinationLocationId: $destinationLocationId,
                discoveredLocationIds: $discovery['locationIds'],
            );

            $location = $lockedLocationsById->get($destinationLocationId);
            if (!$location) {
                throw ValidationException::withMessages([
                    'location_id' => ['Lokasi tujuan tidak ditemukan.'],
                ]);
            }

            // ---- Step 3: destination Setting, resolved and locked from the
            // already-locked destination Location row -- never from a
            // separate unlocked Location::find(). ----
            $setting = \Modules\Setting\Entities\Setting::where('id', $location->setting_id)->lockForUpdate()->first();
            if (!$setting) {
                throw ValidationException::withMessages([
                    'location_id' => ['Pengaturan pemilik lokasi tujuan tidak ditemukan.'],
                ]);
            }
            $location->setRelation('setting', $setting);

            // ---- Step 4: revalidate destination ownership/consignment
            // against the just-locked destination row, not the possibly-stale
            // check performed before this transaction began
            // (AdjustmentOwnershipGuard::assertOwned above only read
            // $adjustment->location, which is not the locked row). ----
            app(AdjustmentOwnershipGuard::class)->assertLocationOwned($location, $activeSettingId);

            $isPkp = (bool) $setting->is_pkp;

            // Destination PKP requires a real tax record to classify into;
            // reject before any mutation rather than posting an untaxed unit
            // as if it were taxed, or vice versa.
            $applicableTaxId = null;
            if ($isPkp) {
                $applicableTaxId = Tax::where('is_default', true)->value('id')
                    ?? Tax::orderByDesc('created_at')->value('id');
                if ($applicableTaxId === null) {
                    throw ValidationException::withMessages([
                        'tax_id' => ['Lokasi tujuan bersifat Kena Pajak (PKP) tetapi tidak ada data pajak yang tersedia. Tambahkan data pajak sebelum menyetujui.'],
                    ]);
                }
            }

            // ---- Step 5 (Product): locked AFTER the Location/Setting pair
            // and before ProductStock/ProductSerialNumber. No with('product')
            // is used anywhere before this authoritative lock -- every
            // ProductStock/ProductSerialNumber row locked afterward has its
            // `product` relation injected from this SAME locked collection.
            // The product catalogue is global: Product::setting_id never
            // gates approval eligibility. Ownership is enforced entirely
            // through the already-locked destination Location/Setting above
            // and the location-scoped stock/serial handling below -- never by
            // filtering products to the destination setting's own catalogue.
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

            // ---- Steps 6-7: lock ProductStock then ProductSerialNumber,
            // injecting the already-locked Product/Location instances into
            // every returned row's relations rather than re-querying or
            // eager-loading separate unlocked copies. ----
            [
                $matchingSerials,
                $destinationSerials,
                $stocksByProductAndLocation,
            ] = $this->lockStocksThenSerials(
                destinationLocationId: $destinationLocationId,
                productIds: $productIds,
                enteredSerialTexts: $enteredSerialTexts,
                products: $products,
                lockedLocationsById: $lockedLocationsById,
            );

            // ---- Step 8: revalidate every discovered serial's location
            // against the original unlocked discovery read AND the locked
            // location map. No retry runs inside this transaction: a
            // disagreement here throws immediately and rolls back with zero
            // mutations. A caller may retry the entire approve() call (a
            // genuine OUTER retry starting a fresh transaction that releases
            // every lock first) -- this method never re-runs discovery while
            // any lock from this attempt is still held. ----
            $this->assertSerialLocationsUnchanged(
                matchingSerials: $matchingSerials,
                destinationSerials: $destinationSerials,
                discoveredLocationsByText: $discovery['locationsByText'],
                lockedLocationsById: $lockedLocationsById,
            );

            // settingEligibleLocationIds (same-setting, non-consignment) is
            // used only for the all-location total evidence recorded below --
            // an unlocked snapshot is acceptable here since it only feeds an
            // informational sum, never a mutation decision.
            $settingEligibleLocationIds = Location::standard()
                ->where('setting_id', $setting->id)
                ->orderBy('id')
                ->pluck('id');

            // Movement eligibility for LOCKED approval is derived exclusively
            // from the just-locked location map, never from an unlocked
            // pre-transaction snapshot: a source location's is_consignment
            // flag (or its very existence) is only authoritative once locked.
            // A location referenced by a serial but absent from the locked
            // map (deleted, or excluded because discovery/revalidation
            // rejected it) is correctly treated as NOT movement-eligible.
            $movementEligibleLocationIds = $lockedLocationsById
                ->filter(fn (Location $loc) => !$loc->is_consignment)
                ->keys();

            $matchingSerialsByText = $matchingSerials->groupBy('serial_number');
            $destinationSerialsByProduct = $destinationSerials->groupBy('product_id');

            // Reused during mutation (applySerializedPlan) so a moved,
            // retained, or omitted serial's already-locked, already
            // eager-loaded model instance is mutated directly instead of
            // being re-fetched with ProductSerialNumber::find() -- one
            // find() per serial would otherwise add one query per serial,
            // scaling linearly with serial count instead of staying flat.
            $lockedSerialsById = $matchingSerials->concat($destinationSerials)
                ->unique('id')
                ->keyBy('id');

            $candidateSerialIds = $lockedSerialsById->keys();

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

            // `quantity` is already the per-row TOTAL (good + broken); sum it
            // directly for the all-location grand total evidence recorded on
            // approval_result (never SUM(quantity) + SUM(broken_quantity),
            // which double-counts broken units).
            $allLocationTotalsByProduct = ProductStock::whereIn('product_id', $productIds)
                ->whereIn('location_id', $settingEligibleLocationIds)
                ->selectRaw('product_id, SUM(quantity) as grand_total')
                ->groupBy('product_id')
                ->get()
                ->keyBy('product_id');

            // ---- Pass 1: build the full mutation plan and reject on ANY
            // conflict or insufficient-bucket condition before mutating
            // anything ----
            $conflicts = [];
            $plans = [];

            foreach ($rows as $row) {
                $productId = (int) ($row['product_id'] ?? 0);
                $product = $products->get($productId);
                if (!$product) {
                    continue; // already reported above
                }

                $isSerialized = (bool) $product->serial_number_required;

                if ($isSerialized) {
                    $classification = $this->serialClassifier->classify(
                        product: $product,
                        row: $row,
                        destinationLocation: $location,
                        isPkp: $isPkp,
                        movementEligibleLocationIds: $movementEligibleLocationIds,
                        matchingSerialsByText: $matchingSerialsByText,
                        destinationSerialsByProduct: $destinationSerialsByProduct,
                        activeClaimSerialIds: $activeClaimSerialIds,
                        allocatedSerialIds: $allocatedSerialIds,
                    );

                    $plan = $this->planSerializedRow($product, $classification, $location);
                    $conflicts = array_merge($conflicts, $plan['conflicts']);
                    $conflicts = array_merge($conflicts, $this->validateBucketAvailability($product, $plan, $stocksByProductAndLocation));
                    $plans[$productId] = $plan;
                } else {
                    $destinationStock = $stocksByProductAndLocation->get($productId . ':' . $location->id, collect())->first();
                    $plans[$productId] = $this->planNonSerializedRow($product, $row, $destinationStock, $isPkp);
                }
            }

            if (!empty($conflicts)) {
                throw ValidationException::withMessages([
                    'count_draft' => array_values(array_unique($conflicts)),
                ]);
            }

            // ---- Pass 2: apply every planned mutation ----
            $appliedProducts = [];
            $stockNotificationChecks = []; // keyed by "product:location" => [stock, before, after]
            $now = now();

            foreach ($rows as $row) {
                $productId = (int) ($row['product_id'] ?? 0);
                $product = $products->get($productId);
                if (!$product) {
                    continue;
                }

                $plan = $plans[$productId];

                if ($plan['type'] === 'non_serialized') {
                    $destinationStock = $stocksByProductAndLocation->get($productId . ':' . $location->id, collect())->first();
                    $applied = $this->applyNonSerializedPlan($product, $location, $destinationStock, $plan, $locked, $actor, $stockNotificationChecks);
                } else {
                    $applied = $this->applySerializedPlan($product, $location, $plan, $locked, $actor, $applicableTaxId, $stocksByProductAndLocation, $lockedSerialsById, $lockedLocationsById, $stockNotificationChecks);
                }

                $totals = $allLocationTotalsByProduct->get($productId);
                $applied['all_location_current_total_before'] = $totals
                    ? (float) $totals->grand_total
                    : 0.0;

                $appliedProducts[] = $applied;
            }

            // Global-stock notification once per product across every
            // location touched, and location-stock notification for every
            // actually-changed ProductStock row (source and destination).
            foreach ($stockNotificationChecks as $check) {
                if ($check['scope'] === 'location' && (int) $check['before'] !== (int) $check['after']) {
                    app(StockNotificationService::class)->checkLocationStock($check['stock'], $check['before'], $check['after']);
                }
                if ($check['scope'] === 'global' && (int) $check['before'] !== (int) $check['after']) {
                    app(StockNotificationService::class)->checkGlobalStock($check['product'], $check['before'], $check['after']);
                }
            }

            // Every product's classifier-produced warnings (including
            // same-text-other-product) are preserved on the immutable
            // approval_result, not discarded once approval succeeds.
            $approvalWarnings = array_values(array_unique(
                collect($appliedProducts)->flatMap(fn (array $p) => $p['warnings'] ?? [])->all()
            ));

            $approvalResult = [
                'adjustment_id' => (int) $locked->id,
                'location_id' => (int) $location->id,
                'location_name' => (string) $location->name,
                'setting_id' => (int) $setting->id,
                'is_pkp' => $isPkp,
                'applicable_tax_id' => $applicableTaxId,
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

    /**
     * Phase 1 (unlocked discovery): learn every candidate Location ID this
     * approval might need to lock, purely from plain (non-locking) reads.
     * Locks nothing. The destination's own candidate-serial locations cannot
     * be known without reading the ProductSerialNumber rows themselves (a
     * matched serial's current location_id is its move source), so this
     * issues an unlocked scan of both the entered serial texts (wherever
     * they currently are) and the destination (for every entered product's
     * existing serials there, to catch destination omissions) before any
     * lock in this transaction is taken.
     *
     * The returned `locationsByText` (serial id => location id at discovery
     * time) is retained ONLY for later revalidation in
     * assertSerialLocationsUnchanged() -- it is never treated as
     * authoritative for a mutation decision.
     *
     * @return array{locationIds: \Illuminate\Support\Collection, locationsByText: \Illuminate\Support\Collection}
     */
    private function discoverSerialLocations(
        int $destinationLocationId,
        Collection $productIds,
        Collection $enteredSerialTexts,
    ): array {
        $discoveredLocationsByText = collect();
        if ($enteredSerialTexts->isNotEmpty()) {
            $discoveredLocationsByText = ProductSerialNumber::whereIn('serial_number', $enteredSerialTexts)
                ->pluck('location_id', 'id');
        }

        // Serialized-ness is not yet known (Product is not locked yet at
        // this discovery phase), so the destination is scanned for every
        // entered product's serials up front, unlocked, purely to discover
        // which location IDs matter; the actual destination-serial rows are
        // re-queried (and locked) later in lockStocksThenSerials(),
        // restricted to the truly serialized subset once Product is locked.
        $destinationSerialLocationIds = collect();
        if ($productIds->isNotEmpty()) {
            $destinationSerialLocationIds = ProductSerialNumber::where('location_id', $destinationLocationId)
                ->whereIn('product_id', $productIds)
                ->pluck('location_id');
        }

        $discoveredLocationIds = collect([$destinationLocationId])
            ->concat($discoveredLocationsByText->values()->filter())
            ->concat($destinationSerialLocationIds)
            ->unique()
            ->sort()
            ->values();

        return [
            'locationIds' => $discoveredLocationIds,
            'locationsByText' => $discoveredLocationsByText,
        ];
    }

    /**
     * Phase 2 (Location lock): lock the complete affected Location set
     * exactly once, in ascending ID order, in ONE query -- the destination
     * is a member of this SAME ordered lock, never locked separately or
     * earlier. This is what removes the opposite-direction deadlock: two
     * approvals where each one's destination is the other's discovered
     * source now compete for locks in the same global ascending-ID order
     * instead of each eagerly grabbing its own destination first.
     *
     * The returned map is the sole authoritative location map for the rest
     * of approval: destination resolution, movement eligibility, per-serial
     * revalidation, and every Transaction's setting_id are derived
     * exclusively from it, never from the earlier unlocked discovery
     * snapshot (which remains valid for the reviewer preview, informative
     * and can go stale, but never for locked approval) and never from a
     * fresh Location::find() call inside a mutation loop.
     */
    private function lockAffectedLocations(int $destinationLocationId, Collection $discoveredLocationIds): Collection
    {
        return Location::whereIn('id', $discoveredLocationIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Phases 6-7 (ProductStock then ProductSerialNumber locks, in that
     * order -- matching TransferMovementService's stock-before-serial
     * hierarchy). Runs strictly after Location, Setting, and Product are
     * already locked by the caller: every returned ProductStock and
     * ProductSerialNumber row has its `product` and `location` relations
     * injected from the caller's already-locked `$products` and
     * `$lockedLocationsById` collections -- never from with('product') or
     * with('location') eager-loading a separate, unlocked copy, and never
     * from a fresh per-row query.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection, 2: \Illuminate\Support\Collection}
     *   [matchingSerials, destinationSerials, stocksByProductAndLocation]
     */
    private function lockStocksThenSerials(
        int $destinationLocationId,
        Collection $productIds,
        Collection $enteredSerialTexts,
        Collection $products,
        Collection $lockedLocationsById,
    ): array {
        // ---- Lock ProductStock rows (stock before serial) ----
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

        // ---- Lock ProductSerialNumber rows ----
        // No with('product')/with('location') eager-load: every locked
        // serial's relations are injected below from the caller's
        // already-locked $products/$lockedLocationsById collections, so
        // classification never reads an unlocked, separately-fetched copy of
        // either relation.
        $matchingSerials = collect();
        if ($enteredSerialTexts->isNotEmpty()) {
            $matchingSerials = ProductSerialNumber::whereIn('serial_number', $enteredSerialTexts)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }

        $destinationSerials = collect();
        if ($productIds->isNotEmpty()) {
            $destinationSerials = ProductSerialNumber::where('location_id', $destinationLocationId)
                ->whereIn('product_id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }

        $matchingSerials->concat($destinationSerials)
            ->unique('id')
            ->each(function (ProductSerialNumber $s) use ($products, $lockedLocationsById) {
                if ($products->has($s->product_id)) {
                    $s->setRelation('product', $products->get($s->product_id));
                }
                if ($s->location_id !== null && $lockedLocationsById->has($s->location_id)) {
                    $s->setRelation('location', $lockedLocationsById->get($s->location_id));
                }
            });

        return [$matchingSerials, $destinationSerials, $stocksByProductAndLocation];
    }

    /**
     * Phase 8 (revalidation): compare every matched/destination serial's
     * now-locked location_id against both the original unlocked discovery
     * read and the locked location map. No retry runs inside this
     * transaction -- a disagreement here throws immediately and rolls back
     * the whole approval with zero mutations. A caller may retry the entire
     * approve() call (a genuine OUTER retry, starting a fresh transaction
     * that releases every lock first), but this method never re-runs
     * discovery while any lock from this attempt is still held, which is
     * what let the acquired lock set grow out of the single ordered
     * hierarchy in the old implementation.
     */
    private function assertSerialLocationsUnchanged(
        Collection $matchingSerials,
        Collection $destinationSerials,
        Collection $discoveredLocationsByText,
        Collection $lockedLocationsById,
    ): void {
        // Did any matched serial's location change between the unlocked
        // discovery read and the location lock?
        $locationChanged = $matchingSerials->contains(
            fn (ProductSerialNumber $s) => (int) $discoveredLocationsByText->get($s->id) !== (int) $s->location_id
        );

        // Every matched/destination serial's location must still exist in
        // the locked map. (Consignment-source rejection remains a
        // per-serial business conflict raised later by
        // StockOpnameSerialClassifier via movement eligibility, not a
        // conflict here -- a location that IS consignment is a valid,
        // stable, locked fact, not evidence of a race.)
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

    /**
     * Build the mutation plan for one non-serialized entered product: the
     * absolute destination good/bad counts allocated entirely by destination
     * PKP, and the Product-aggregate delta. Purely computation, no mutation.
     */
    private function planNonSerializedRow(Product $product, array $row, ?ProductStock $stock, bool $isPkp): array
    {
        $enteredGood = (int) ($row['good_count'] ?? 0);
        $enteredBad = (int) ($row['bad_count'] ?? 0);

        $currentGoodTax = (int) round((float) ($stock?->quantity_tax ?? 0));
        $currentGoodNonTax = (int) round((float) ($stock?->quantity_non_tax ?? 0));
        $currentBadTax = (int) round((float) ($stock?->broken_quantity_tax ?? 0));
        $currentBadNonTax = (int) round((float) ($stock?->broken_quantity_non_tax ?? 0));
        $currentGood = $currentGoodTax + $currentGoodNonTax;
        $currentBad = $currentBadTax + $currentBadNonTax;

        return [
            'type' => 'non_serialized',
            'product_id' => (int) $product->id,
            'conflicts' => [],
            'current_good' => $currentGood,
            'current_bad' => $currentBad,
            'entered_good' => $enteredGood,
            'entered_bad' => $enteredBad,
            // Destination PKP is authoritative: every resulting unit is
            // assigned wholly to tax or wholly to non-tax buckets.
            'new_good_tax' => $isPkp ? $enteredGood : 0,
            'new_good_non_tax' => $isPkp ? 0 : $enteredGood,
            'new_bad_tax' => $isPkp ? $enteredBad : 0,
            'new_bad_non_tax' => $isPkp ? 0 : $enteredBad,
        ];
    }

    private function applyNonSerializedPlan(
        Product $product,
        Location $location,
        ?ProductStock $stock,
        array $plan,
        Adjustment $adjustment,
        User $actor,
        array &$stockNotificationChecks,
    ): array {
        $stock ??= ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 0,
            'quantity_non_tax' => 0,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);
        // The row may have been created above without a lock; re-lock it so
        // it participates in the same locked-row set as pre-existing stock.
        $stock = ProductStock::where('id', $stock->id)->lockForUpdate()->first();
        $stock->setRelation('product', $product);
        $stock->setRelation('location', $location);

        $previousProductQuantity = (float) $product->product_quantity;
        $previousProductBroken = (float) ($product->broken_quantity ?? 0);
        $previousStockTotal = (float) $stock->quantity_tax + (float) $stock->quantity_non_tax
            + (float) $stock->broken_quantity_tax + (float) $stock->broken_quantity_non_tax;

        $stock->quantity_tax = $plan['new_good_tax'];
        $stock->quantity_non_tax = $plan['new_good_non_tax'];
        $stock->broken_quantity_tax = $plan['new_bad_tax'];
        $stock->broken_quantity_non_tax = $plan['new_bad_non_tax'];
        $stock->broken_quantity = $plan['new_bad_tax'] + $plan['new_bad_non_tax'];
        $stock->quantity = $plan['new_good_tax'] + $plan['new_good_non_tax'] + $stock->broken_quantity;
        $stock->save();

        $enteredTotal = $plan['entered_good'] + $plan['entered_bad'];
        $currentTotal = $plan['current_good'] + $plan['current_bad'];
        $netDelta = $enteredTotal - $currentTotal;
        $brokenDelta = $plan['entered_bad'] - $plan['current_bad'];

        $newProductQuantity = max(0, $previousProductQuantity + $netDelta);
        $product->product_quantity = $newProductQuantity;
        $product->broken_quantity = max(0, $previousProductBroken + $brokenDelta);
        $product->save();

        $stockNotificationChecks["global:{$product->id}"] = [
            'scope' => 'global', 'product' => $product,
            'before' => $previousProductQuantity, 'after' => (float) $product->product_quantity,
        ];
        $stockNotificationChecks["location:{$stock->id}"] = [
            'scope' => 'location', 'stock' => $stock,
            'before' => $previousStockTotal, 'after' => (float) $stock->quantity,
        ];

        Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $location->setting_id,
            'type' => 'ADJ',
            'quantity' => $netDelta,
            'current_quantity' => (float) $stock->quantity,
            'broken_quantity' => (float) $stock->broken_quantity,
            'previous_quantity' => $previousProductQuantity,
            'previous_quantity_at_location' => $previousStockTotal,
            'after_quantity' => (float) $product->product_quantity,
            'after_quantity_at_location' => (float) $stock->quantity,
            'quantity_tax' => (float) $stock->quantity_tax,
            'quantity_non_tax' => (float) $stock->quantity_non_tax,
            'broken_quantity_tax' => (float) $stock->broken_quantity_tax,
            'broken_quantity_non_tax' => (float) $stock->broken_quantity_non_tax,
            'location_id' => $location->id,
            'user_id' => $actor->id,
            'reason' => "Stock opname disetujui ({$adjustment->reference})",
        ]);

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
                'good' => $plan['new_good_tax'] + $plan['new_good_non_tax'],
                'bad' => $plan['new_bad_tax'] + $plan['new_bad_non_tax'],
                'good_tax' => $plan['new_good_tax'],
                'good_non_tax' => $plan['new_good_non_tax'],
                'bad_tax' => $plan['new_bad_tax'],
                'bad_non_tax' => $plan['new_bad_non_tax'],
                'product_quantity' => (float) $product->product_quantity,
            ],
            'net_delta' => $netDelta,
            'locations' => [
                [
                    'location_id' => (int) $location->id,
                    'before_total' => $previousStockTotal,
                    'after_total' => (float) $stock->quantity,
                ],
            ],
            'serials' => [],
            'omitted_serials' => [],
            'warnings' => [],
        ];
    }

    /**
     * Build the mutation plan for one serialized entered product from an
     * already-computed (shared-classifier) result: every entered serial's
     * exact source/destination bucket transition, plus every omission's
     * exact source-bucket decrement. Validates every source bucket has
     * sufficient quantity before any mutation is allowed to proceed; any
     * shortfall is reported as a conflict (never produces negative stock).
     * Purely computation, no mutation.
     */
    private function planSerializedRow(Product $product, array $classification, Location $destinationLocation): array
    {
        $conflicts = $classification['conflicts'];
        $entries = [];

        // Per (location_id, is_broken, is_tax) bucket, how many units this
        // plan intends to remove -- validated against locked stock before
        // any mutation is applied.
        $bucketDebits = [];

        foreach ($classification['entered'] as $serial) {
            /** @var \Modules\Adjustment\DTOs\SerialClassification $serial */
            if ($serial->status === \Modules\Adjustment\DTOs\SerialClassification::STATUS_CONFLICTING) {
                continue; // already reported in $conflicts by the classifier
            }

            $isBad = $serial->enteredCondition === 'bad';

            if ($serial->status === \Modules\Adjustment\DTOs\SerialClassification::STATUS_NEW) {
                $entries[] = [
                    'action' => 'create',
                    'serial_id' => $serial->sourceSerialId,
                    'serial_number' => $serial->serialNumber,
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

            $entries[] = [
                'action' => 'move',
                'serial_id' => $serial->sourceSerialId,
                'serial_number' => $serial->serialNumber,
                'source_location_id' => $sourceLocationId,
                'source_location_name' => $serial->sourceLocationName,
                'source_is_bad' => $sourceIsBad,
                'source_is_tax' => $sourceIsTax,
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
            $sourceIsBad = $serial->sourceCondition === 'bad';
            $sourceIsTax = (bool) $serial->sourceIsTax;

            $bucketKey = $destinationLocation->id . ':' . ($sourceIsBad ? 'bad' : 'good') . ':' . ($sourceIsTax ? 'tax' : 'non_tax');
            $bucketDebits[$bucketKey] = ($bucketDebits[$bucketKey] ?? 0) + 1;

            $omissions[] = [
                'serial_id' => $serial->sourceSerialId,
                'serial_number' => $serial->serialNumber,
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

    /**
     * Validate every bucket a serialized row's plan intends to debit against
     * the already-locked stock rows, returning a Bahasa Indonesia conflict
     * message for each shortfall (empty array if every debit is covered).
     * Run in pass 1, before any mutation, so a shortfall never produces
     * negative stock.
     *
     * @param Collection $stocksByProductAndLocation keyed "productId:locationId" => Collection<ProductStock>
     * @return string[]
     */
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

    /**
     * Apply a serialized row's plan: for every bucket this plan debits,
     * validate the locked stock row actually holds at least that many units
     * (rejecting with a conflict instead of ever producing negative stock),
     * then move/create/omit each serial with exact per-location good/bad x
     * tax/non-tax bucket accounting at both source and destination.
     *
     * @param Collection $stocksByProductAndLocation keyed "productId:locationId" => Collection<ProductStock>, already locked
     * @param Collection $lockedSerialsById keyed by serial id => already-locked, already eager-loaded ProductSerialNumber
     * @param Collection $lockedLocationsById keyed by location id => already-locked Location; the sole authoritative
     *   source for every touched stock row's `location` relation and every Transaction's setting_id in this method.
     *   No Location::find() call and no destination-setting fallback are used: a location referenced by a plan entry
     *   that is somehow absent from this map is a data-integrity conflict, not a value to guess at.
     * @param array $stockNotificationChecks by-ref accumulator of location/global before-after pairs to check once at the end
     */
    private function applySerializedPlan(
        Product $product,
        Location $destinationLocation,
        array $plan,
        Adjustment $adjustment,
        User $actor,
        ?int $applicableTaxId,
        Collection $stocksByProductAndLocation,
        Collection $lockedSerialsById,
        Collection $lockedLocationsById,
        array &$stockNotificationChecks,
    ): array {
        // Bucket availability was already validated (and would have thrown
        // before this method is ever called) in pass 1 via
        // validateBucketAvailability(); this method only mutates.

        // ---- Apply: mutate a get-or-create-and-lock ProductStock helper per (product, location) ----
        $touchedStocks = []; // keyed by location_id => ['stock' => ProductStock, 'before_total' => float]

        $touchStock = function (int $locationId) use (&$touchedStocks, $product, $stocksByProductAndLocation, $lockedLocationsById) {
            if (isset($touchedStocks[$locationId])) {
                return $touchedStocks[$locationId]['stock'];
            }
            $stock = $stocksByProductAndLocation->get($product->id . ':' . $locationId, collect())->first();
            if (!$stock) {
                $lockedLocation = $lockedLocationsById->get($locationId);
                if (!$lockedLocation) {
                    // Every location a plan touches was discovered in
                    // discoverSerialLocations() and locked in
                    // lockAffectedLocations(); reaching here means that
                    // invariant broke -- fail loudly rather than create
                    // a ProductStock row with no authoritative location/
                    // setting to attribute its Transaction evidence to.
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

        $appliedEntries = [];
        $appliedOmissions = [];
        $newSerialCount = 0;
        $isPkp = $applicableTaxId !== null;

        foreach ($plan['entries'] as $entry) {
            if ($entry['action'] === 'create') {
                // Bucket mutations accumulate in memory on the shared
                // $touchStock instance for this location; every touched
                // stock row is persisted exactly once, after every
                // entry/omission in this plan has been applied, in the
                // rollup loop below -- never once per serial.
                $destStock = $touchStock($destinationLocation->id);
                $this->incrementBucket($destStock, $entry['is_bad'], $isPkp, 1);

                $serial = ProductSerialNumber::create([
                    'product_id' => $product->id,
                    'location_id' => $destinationLocation->id,
                    'serial_number' => $entry['serial_number'],
                    'tax_id' => $isPkp ? $applicableTaxId : null,
                    'status' => ProductSerialNumber::STATUS_ACTIVE,
                    'is_broken' => $entry['is_bad'],
                ]);

                SerialNumberHistoryService::record(
                    $serial->id,
                    SerialNumberHistory::EVENT_STATUS_CHANGED,
                    $destinationLocation->id,
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
                    'destination_location_id' => (int) $destinationLocation->id,
                    'applied_condition' => $entry['is_bad'] ? 'bad' : 'good',
                    'applied_is_tax' => $isPkp,
                    'same_text_other_product' => $entry['same_text_other_product'] ?? false,
                    'label' => $entry['label'] ?? '',
                ];
                continue;
            }

            // move: decrement the exact source bucket, increment the exact
            // destination bucket (same ProductStock row when source ==
            // destination, i.e. a same-location condition/tax reclassify).
            // Both mutate the shared in-memory instance; neither saves here
            // (see the "create" branch above for why).
            $sourceStock = $touchStock($entry['source_location_id']);
            $this->incrementBucket($sourceStock, $entry['source_is_bad'], $entry['source_is_tax'], -1);

            $destStock = $entry['source_location_id'] === (int) $destinationLocation->id
                ? $sourceStock
                : $touchStock($destinationLocation->id);
            $this->incrementBucket($destStock, $entry['is_bad'], $isPkp, 1);

            /** @var ProductSerialNumber $serial */
            $serial = $lockedSerialsById->get($entry['serial_id']);
            $serial->update([
                'location_id' => $destinationLocation->id,
                'is_broken' => $entry['is_bad'],
                'tax_id' => $isPkp ? $applicableTaxId : null,
                'status' => ProductSerialNumber::STATUS_ACTIVE,
            ]);

            $isSameLocation = $entry['source_location_id'] === (int) $destinationLocation->id;
            if (!$isSameLocation) {
                SerialNumberHistoryService::record(
                    $serial->id,
                    SerialNumberHistory::EVENT_LOCATION_TRANSFER,
                    $destinationLocation->id,
                    $adjustment,
                    "Stock opname disetujui ({$adjustment->reference}): pindah lokasi."
                );
            } elseif ($entry['source_is_bad'] !== $entry['is_bad'] || $entry['source_is_tax'] !== $isPkp) {
                SerialNumberHistoryService::record(
                    $serial->id,
                    SerialNumberHistory::EVENT_STATUS_CHANGED,
                    $destinationLocation->id,
                    $adjustment,
                    "Stock opname disetujui ({$adjustment->reference}): kondisi/status pajak diperbarui."
                );
            }

            $appliedEntries[] = [
                'serial_id' => (int) $serial->id,
                'serial_number' => $entry['serial_number'],
                'action' => $isSameLocation ? 'retained' : 'moved',
                'source_location_id' => $entry['source_location_id'],
                'source_location_name' => $entry['source_location_name'] ?? null,
                'source_condition' => $entry['source_is_bad'] ? 'bad' : 'good',
                'source_is_tax' => $entry['source_is_tax'],
                'cross_setting' => $entry['cross_setting'] ?? false,
                'destination_location_id' => (int) $destinationLocation->id,
                'applied_condition' => $entry['is_bad'] ? 'bad' : 'good',
                'applied_is_tax' => $isPkp,
                'same_text_other_product' => $entry['same_text_other_product'] ?? false,
                'label' => $entry['label'] ?? '',
            ];
        }

        foreach ($plan['omissions'] as $omission) {
            $stock = $touchStock($destinationLocation->id);
            $this->incrementBucket($stock, $omission['is_bad'], $omission['is_tax'], -1);

            /** @var ProductSerialNumber $serial */
            $serial = $lockedSerialsById->get($omission['serial_id']);

            // `location_id` is a required (NOT NULL) foreign key on this table,
            // so it cannot be cleared. The serial's last known location is left
            // as-is for provenance; STATUS_MISSING alone marks it as no longer
            // physically confirmed there, and it is excluded from future
            // destination-omission candidates and from being re-entered as a
            // move/reclassify target (StockOpnameSerialClassifier treats
            // MISSING as unavailable/unsafe respectively).
            $serial->update([
                'status' => ProductSerialNumber::STATUS_MISSING,
            ]);

            SerialNumberHistoryService::record(
                $serial->id,
                SerialNumberHistory::EVENT_STOCK_OPNAME_MISSING,
                $destinationLocation->id,
                $adjustment,
                "Stock opname disetujui ({$adjustment->reference}): tidak ditemukan saat penghitungan fisik."
            );

            $appliedOmissions[] = [
                'serial_id' => (int) $serial->id,
                'serial_number' => $omission['serial_number'],
                'action' => 'missing',
                'previous_location_id' => (int) $destinationLocation->id,
                'previous_condition' => $omission['is_bad'] ? 'bad' : 'good',
                'previous_is_tax' => $omission['is_tax'],
                'disposition' => 'status_set_to_missing_location_retained_as_provenance',
                'label' => $omission['label'] ?? '',
            ];
        }

        // ---- Persist every touched ProductStock's derived totals first (so
        // the final Product aggregate is known before any Transaction row is
        // written), then record one Transaction per touched location with
        // its correct before/after Product aggregate ----
        $previousProductQuantity = (float) $product->product_quantity;
        $previousProductBroken = (float) ($product->broken_quantity ?? 0);
        $netProductDelta = 0.0;
        $netBrokenDelta = 0.0;
        $stockDeltas = []; // location_id => ['stock' => ProductStock, 'before_total' => float, 'after_total' => float]

        foreach ($touchedStocks as $locationId => $data) {
            $stock = $data['stock'];
            $stock->broken_quantity = (float) $stock->broken_quantity_tax + (float) $stock->broken_quantity_non_tax;
            $stock->quantity = (float) $stock->quantity_tax + (float) $stock->quantity_non_tax + $stock->broken_quantity;
            $stock->save();

            $afterTotal = (float) $stock->quantity;
            $beforeTotal = $data['before_total'];
            $netProductDelta += $afterTotal - $beforeTotal;
            // Exact global broken delta: covers a same-location good<->bad
            // reclassification (source bucket decrement/increment on the same
            // row), a new serial entered as broken, and a cross-location move
            // that changes condition -- every case is captured because it is
            // derived from each touched stock's own before/after broken total,
            // not re-inferred from entry/omission counts separately.
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

            // A same-location reclassify (good<->bad or tax<->non-tax with no
            // net total change) and a genuine zero-delta cross-location move
            // both still get a Transaction row: quantity=0 evidence, not a
            // skipped record, so bucket-only changes remain auditable.
            //
            // setting_id must be this transaction row's OWN location's
            // setting, never unconditionally the destination's: a
            // cross-setting serial move writes two Transaction rows (source
            // and destination) and the source row belongs to the source
            // location's setting, not the destination's. Built exclusively
            // from the locked location map -- no Location::find() fallback
            // and no destination-setting fallback: a location this method is
            // about to write a Transaction for that is somehow missing from
            // the locked map is a data-integrity conflict, not a value to
            // guess at, and must roll back the whole approval rather than
            // silently misattribute the transaction's owning setting.
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

        // Destination-location good/bad, not just the total/broken rollup
        // above: the approved view (both counter and reviewer) needs the
        // exact good vs. bad split at the selected location, derived here
        // from this plan's own before/after touch data rather than ever
        // being re-derived later from current (possibly since-changed) stock.
        $destinationBefore = $touchedStocks[$destinationLocation->id] ?? null;
        $destinationAfter = $stockDeltas[$destinationLocation->id]['stock'] ?? null;
        $beforeDestGood = (float) ($destinationBefore['before_good'] ?? 0);
        $beforeDestBad = (float) ($destinationBefore['before_bad'] ?? 0);
        $appliedDestGood = $destinationAfter
            ? (float) $destinationAfter->quantity_tax + (float) $destinationAfter->quantity_non_tax
            : $beforeDestGood;
        $appliedDestBad = $destinationAfter
            ? (float) $destinationAfter->broken_quantity_tax + (float) $destinationAfter->broken_quantity_non_tax
            : $beforeDestBad;

        // Entered good/bad: every entry actually applied (moved/created,
        // never a conflict — conflicts are excluded from $plan['entries'] by
        // planSerializedRow()) counted by its entered condition, i.e. exactly
        // what the counter physically reported for this product.
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
