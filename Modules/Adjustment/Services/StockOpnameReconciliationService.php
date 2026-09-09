<?php

namespace Modules\Adjustment\Services;

use Illuminate\Support\Collection;
use Modules\Adjustment\DTOs\ProductReconciliation;
use Modules\Adjustment\DTOs\ReconciliationResult;
use Modules\Adjustment\DTOs\SerialClassification;
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

        $allLocationTotalsByProduct = ProductStock::whereIn('product_id', $productIds)
            ->whereIn('location_id', $eligibleLocationIds)
            ->selectRaw('product_id, SUM(quantity) as total_good, SUM(broken_quantity) as total_bad')
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
        // serialized products, to detect destination omissions.
        $serializedProductIds = $productsById->filter(fn (Product $p) => (bool) $p->serial_number_required)->keys();
        $destinationSerialsByProduct = collect();
        if ($serializedProductIds->isNotEmpty()) {
            $destinationSerialsByProduct = ProductSerialNumber::where('location_id', $location->id)
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

            $stock = $selectedLocationStocksByProduct->get($productId);
            $currentGood = $stock ? (int) round((float) $stock->quantity) : 0;
            $currentBad = $stock ? (int) round((float) $stock->broken_quantity) : 0;

            $enteredGood = (int) ($row['good_count'] ?? 0);
            $enteredBad = (int) ($row['bad_count'] ?? 0);

            $totals = $allLocationTotalsByProduct->get($productId);
            $allLocationCurrentTotal = $totals ? (float) $totals->total_good + (float) $totals->total_bad : 0.0;

            $enteredTotal = $enteredGood + $enteredBad;
            $currentTotal = $currentGood + $currentBad;

            $exceedsAllLocationTotal = $enteredTotal > $allLocationCurrentTotal;
            $potentialGlobalIncrease = $exceedsAllLocationTotal
                ? $enteredTotal - $allLocationCurrentTotal
                : 0.0;

            [$serialClassifications, $omittedSerials, $serialWarnings, $serialConflicts, $newSerialCount] = $this->classifySerials(
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

    /**
     * Classify every entered serial for one product plus every destination
     * serial omitted from the entered set. Returns
     * [SerialClassification[] $entered, SerialClassification[] $omitted, string[] $warnings, string[] $conflicts, int $newSerialCount].
     * Purely in-memory: every lookup hits an already bulk-loaded collection.
     */
    private function classifySerials(
        Product $product,
        array $row,
        Location $destinationLocation,
        bool $isPkp,
        Collection $eligibleLocationIds,
        Collection $matchingSerialsByText,
        Collection $destinationSerialsByProduct,
        Collection $activeClaimSerialIds,
        Collection $allocatedSerialIds,
    ): array {
        if (!$product->serial_number_required) {
            return [[], [], [], [], 0];
        }

        $entered = [];
        $warnings = [];
        $conflicts = [];
        $enteredTexts = [];
        $newSerialCount = 0;

        foreach ($row['serials'] ?? [] as $serialRow) {
            $text = ProductSerialNumber::normalize((string) ($serialRow['serial_number'] ?? ''));
            if ($text === '') {
                continue;
            }
            $enteredTexts[$text] = true;

            $enteredCondition = strtolower((string) ($serialRow['condition'] ?? 'good'));
            $destinationIsTax = $isPkp;

            // Re-resolve authoritatively by product ID + normalized text, never
            // trusting the client-supplied/saved source fields on the row.
            $candidates = $matchingSerialsByText->get($text, collect());
            $ownProductMatch = $candidates->first(fn (ProductSerialNumber $s) => (int) $s->product_id === (int) $product->id);
            $otherProductMatch = $candidates->first(fn (ProductSerialNumber $s) => (int) $s->product_id !== (int) $product->id);

            if ($otherProductMatch && !$ownProductMatch) {
                $warnings[] = sprintf(
                    "Nomor seri '%s' yang dimasukkan untuk produk %s sudah terdaftar pada produk lain (%s).",
                    $text,
                    $product->product_name,
                    $otherProductMatch->product?->product_name ?? ('ID ' . $otherProductMatch->product_id)
                );
            }

            if (!$ownProductMatch) {
                $entered[] = new SerialClassification(
                    serialNumber: $text,
                    status: SerialClassification::STATUS_NEW,
                    sourceSerialId: null,
                    sourceLocationId: null,
                    sourceLocationName: null,
                    sourceCondition: null,
                    sourceIsTax: null,
                    enteredCondition: $enteredCondition,
                    destinationIsTax: $destinationIsTax,
                    sameTextOtherProduct: (bool) $otherProductMatch,
                    label: 'Baru: akan didaftarkan di lokasi tujuan.',
                    statuses: [SerialClassification::STATUS_NEW],
                );
                $newSerialCount++;
                continue;
            }

            $sourceLocationId = $ownProductMatch->location_id ? (int) $ownProductMatch->location_id : null;

            $conflictReason = $this->unsafeSerialConflictReason(
                $ownProductMatch,
                $eligibleLocationIds,
                $activeClaimSerialIds,
                $allocatedSerialIds
            );
            if ($conflictReason !== null) {
                $conflicts[] = sprintf(
                    "Nomor seri '%s' untuk produk %s tidak dapat diproses: %s",
                    $text,
                    $product->product_name,
                    $conflictReason
                );
                $entered[] = new SerialClassification(
                    serialNumber: $text,
                    status: SerialClassification::STATUS_CONFLICTING,
                    sourceSerialId: (int) $ownProductMatch->id,
                    sourceLocationId: $sourceLocationId,
                    sourceLocationName: $ownProductMatch->location?->name,
                    sourceCondition: $ownProductMatch->is_broken ? 'bad' : 'good',
                    sourceIsTax: $ownProductMatch->tax_id !== null,
                    enteredCondition: $enteredCondition,
                    destinationIsTax: $destinationIsTax,
                    conflictReason: $conflictReason,
                    label: 'Konflik: tidak dapat diproses secara aman — ' . $conflictReason,
                    statuses: [SerialClassification::STATUS_CONFLICTING],
                );
                continue;
            }

            $sourceCondition = $ownProductMatch->is_broken ? 'bad' : 'good';
            $sourceIsTax = $ownProductMatch->tax_id !== null;

            $isSameLocation = $sourceLocationId === (int) $destinationLocation->id;
            $conditionChanged = $sourceCondition !== $enteredCondition;
            $taxChanged = $sourceIsTax !== $destinationIsTax;

            // Composable: a single serial can simultaneously move, change
            // condition, and change tax classification. Every applicable
            // status is reported together rather than picking just one.
            $statuses = [];
            $labelParts = [];

            if (!$isSameLocation) {
                $statuses[] = SerialClassification::STATUS_MOVED;
                $labelParts[] = sprintf(
                    'Pindah dari %s ke %s.',
                    $ownProductMatch->location?->name ?? "lokasi #{$sourceLocationId}",
                    $destinationLocation->name
                );
            }

            if ($conditionChanged) {
                $statuses[] = SerialClassification::STATUS_CONDITION_CHANGED;
                $labelParts[] = sprintf('Kondisi berubah dari %s menjadi %s.', $sourceCondition, $enteredCondition);
            }

            if ($taxChanged) {
                $statuses[] = SerialClassification::STATUS_TAX_CHANGED;
                $labelParts[] = $destinationIsTax
                    ? 'Tidak Kena Pajak → Kena Pajak'
                    : 'Kena Pajak → Tidak Kena Pajak';
            }

            if (empty($statuses)) {
                $statuses[] = SerialClassification::STATUS_RETAINED;
                $labelParts[] = 'Tetap di lokasi saat ini, tidak ada perubahan.';
            }

            $entered[] = new SerialClassification(
                serialNumber: $text,
                status: $statuses[0],
                sourceSerialId: (int) $ownProductMatch->id,
                sourceLocationId: $sourceLocationId,
                sourceLocationName: $ownProductMatch->location?->name,
                sourceCondition: $sourceCondition,
                sourceIsTax: $sourceIsTax,
                enteredCondition: $enteredCondition,
                destinationIsTax: $destinationIsTax,
                label: implode(' ', $labelParts),
                statuses: $statuses,
            );
        }

        // Destination serials omitted from the entered complete set.
        $omitted = [];
        $destinationSerials = $destinationSerialsByProduct->get($product->id, collect());
        foreach ($destinationSerials as $existingSerial) {
            if (isset($enteredTexts[$existingSerial->serial_number])) {
                continue;
            }
            if (in_array($existingSerial->status, [ProductSerialNumber::STATUS_SOLD, ProductSerialNumber::STATUS_RETURN_IN_PROCESS], true)) {
                // Not currently available at this location for counting purposes;
                // omission is expected, not a discrepancy.
                continue;
            }

            $omitted[] = new SerialClassification(
                serialNumber: $existingSerial->serial_number,
                status: SerialClassification::STATUS_OMITTED,
                sourceSerialId: (int) $existingSerial->id,
                sourceLocationId: (int) $existingSerial->location_id,
                sourceLocationName: $destinationLocation->name,
                sourceCondition: $existingSerial->is_broken ? 'bad' : 'good',
                sourceIsTax: $existingSerial->tax_id !== null,
                enteredCondition: null,
                destinationIsTax: null,
                label: 'Terdaftar di lokasi ini tetapi tidak disertakan dalam hitungan.',
                statuses: [SerialClassification::STATUS_OMITTED],
            );
        }

        if (!empty($omitted)) {
            $warnings[] = sprintf(
                '%s: %d nomor seri terdaftar di lokasi ini tidak disertakan dalam hitungan.',
                $product->product_name,
                count($omitted)
            );
        }

        return [$entered, $omitted, $warnings, $conflicts, $newSerialCount];
    }

    /**
     * Return a Bahasa Indonesia conflict reason if this serial's current
     * authoritative state is unsafe to move/create/reclassify/remove, or
     * null if it is safe to proceed. Purely in-memory against bulk-loaded
     * eligible-location/claim/allocation sets — issues no query itself.
     */
    private function unsafeSerialConflictReason(
        ProductSerialNumber $serial,
        Collection $eligibleLocationIds,
        Collection $activeClaimSerialIds,
        Collection $allocatedSerialIds,
    ): ?string {
        if ($serial->dispatch_detail_id !== null) {
            return 'Nomor seri terkait dengan pengiriman aktif.';
        }
        if ($serial->is_in_return_process) {
            return 'Nomor seri sedang dalam proses retur.';
        }
        if ($serial->purchase_return_id !== null) {
            return 'Nomor seri terkait dengan retur pembelian.';
        }
        if (in_array($serial->status, [ProductSerialNumber::STATUS_SOLD, ProductSerialNumber::STATUS_RETURN_IN_PROCESS], true)) {
            return 'Nomor seri berstatus ' . $serial->status . ' dan tidak dapat diproses.';
        }
        if ($activeClaimSerialIds->contains($serial->id)) {
            return 'Nomor seri memiliki klaim konsinyasi aktif.';
        }
        if ($allocatedSerialIds->contains($serial->id)) {
            return 'Nomor seri memiliki alokasi konsinyasi aktif.';
        }

        // A serial currently sitting at a location outside this document's
        // eligible same-owner, non-consignment scope (a different setting,
        // or a consignment location) is never a movable candidate — it is a
        // conflict, not a cross-location move.
        if ($serial->location_id !== null && !$eligibleLocationIds->contains((int) $serial->location_id)) {
            return 'Nomor seri berada di lokasi di luar cakupan pemilik aktif atau merupakan lokasi konsinyasi.';
        }

        return null;
    }
}
