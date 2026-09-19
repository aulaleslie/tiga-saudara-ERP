<?php

namespace Modules\Adjustment\Services;

use Illuminate\Support\Collection;
use Modules\Adjustment\DTOs\SerialClassification;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;

/**
 * Single pure (no queries, no mutation) serial classification engine shared
 * by StockOpnameReconciliationService (preview and locked-preview recompute)
 * and StockOpnameApprovalService (locked approval planning), so a serial can
 * never be classified one way for display and another way for posting.
 *
 * Every method here operates purely against already bulk-loaded/locked
 * in-memory collections passed in by the caller.
 */
class StockOpnameSerialClassifier
{
    /**
     * Classify every entered serial for one product plus every destination
     * serial omitted from the entered set.
     *
     * Supports both multi-location pools (Collection of Locations) and
     * single-location documents (single Location model).
     *
     * @param Collection<int, Location>|Location $destinationLocation
     * @param Collection $movementEligibleLocationIds Any existing non-consignment location ID
     * @param Collection $matchingSerialsByText Keyed by normalized serial_number
     * @param Collection $destinationSerialsByProduct Keyed by product_id
     * @param Collection $activeClaimSerialIds
     * @param Collection $allocatedSerialIds
     * @param Collection|null $activeTransferClaimSerialIds
     * @param Collection|null $locationStocks Keyed by "{$locationId}_{$productId}"
     * @return array{
     *   entered: SerialClassification[],
     *   omitted: SerialClassification[],
     *   warnings: string[],
     *   conflicts: string[],
     *   newSerialCount: int,
     * }
     */
    public function classify(
        Product $product,
        array $row,
        Collection|Location $destinationLocation,
        bool $isPkp,
        Collection $movementEligibleLocationIds,
        Collection $matchingSerialsByText,
        Collection $destinationSerialsByProduct,
        Collection $activeClaimSerialIds,
        Collection $allocatedSerialIds,
        ?Collection $activeTransferClaimSerialIds = null,
        ?Collection $locationStocks = null,
    ): array {
        if (!$product->serial_number_required) {
            return ['entered' => [], 'omitted' => [], 'warnings' => [], 'conflicts' => [], 'newSerialCount' => 0];
        }

        $selectedLocations = $destinationLocation instanceof Collection
            ? $destinationLocation
            : collect([$destinationLocation]);

        $selectedLocationIds = $selectedLocations->pluck('id')->map(fn ($id) => (int) $id)->all();
        $selectedLocationsById = $selectedLocations->keyBy('id');
        $locationStocks ??= collect();

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

            if (isset($enteredTexts[$text])) {
                $conflicts[] = sprintf(
                    "Nomor seri '%s' untuk produk %s dimasukkan lebih dari sekali.",
                    $text,
                    $product->product_name
                );
                continue;
            }
            $enteredTexts[$text] = true;

            $enteredCondition = strtolower((string) ($serialRow['condition'] ?? 'good'));

            // Re-resolve authoritatively by product ID + normalized text, never
            // trusting client-supplied or saved source fields.
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
                // New serial: assign to deterministic surplus destination
                $targetLoc = $this->determineSurplusDestination($selectedLocations, $locationStocks, (int) $product->id, $enteredCondition);
                $targetIsTax = (bool) ($targetLoc->setting?->is_pkp ?? false);

                $entered[] = new SerialClassification(
                    serialNumber: $text,
                    status: SerialClassification::STATUS_NEW,
                    sourceSerialId: null,
                    sourceLocationId: null,
                    sourceLocationName: null,
                    sourceCondition: null,
                    sourceIsTax: null,
                    enteredCondition: $enteredCondition,
                    destinationIsTax: $targetIsTax,
                    sameTextOtherProduct: (bool) $otherProductMatch,
                    label: sprintf('Baru: akan didaftarkan di %s.', $targetLoc->name),
                    statuses: [SerialClassification::STATUS_NEW],
                );
                $newSerialCount++;
                continue;
            }

            $sourceLocationId = $ownProductMatch->location_id ? (int) $ownProductMatch->location_id : null;

            $conflictReason = $this->unsafeSerialConflictReason(
                $ownProductMatch,
                $movementEligibleLocationIds,
                $activeClaimSerialIds,
                $allocatedSerialIds,
                $activeTransferClaimSerialIds
            );

            // Determine if serial is already in the selected pool
            $isAlreadyInPool = $sourceLocationId !== null && in_array($sourceLocationId, $selectedLocationIds, true);

            if ($isAlreadyInPool) {
                // Serial is retained at its current selected location
                $targetLoc = $selectedLocationsById->get($sourceLocationId) ?? $ownProductMatch->location;
            } else {
                // Serial moves from outside the pool into the deterministic surplus destination
                $targetLoc = $this->determineSurplusDestination($selectedLocations, $locationStocks, (int) $product->id, $enteredCondition);
            }

            $targetIsTax = (bool) ($targetLoc?->setting?->is_pkp ?? false);

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
                    destinationIsTax: $targetIsTax,
                    conflictReason: $conflictReason,
                    label: 'Konflik: tidak dapat diproses secara aman — ' . $conflictReason,
                    statuses: [SerialClassification::STATUS_CONFLICTING],
                );
                continue;
            }

            $sourceCondition = $ownProductMatch->is_broken ? 'bad' : 'good';
            $sourceIsTax = $ownProductMatch->tax_id !== null;

            $isSameLocation = $sourceLocationId === (int) $targetLoc->id;
            $conditionChanged = $sourceCondition !== $enteredCondition;
            $taxChanged = $sourceIsTax !== $targetIsTax;

            $crossSetting = !$isSameLocation
                && $ownProductMatch->location !== null
                && (int) $ownProductMatch->location->setting_id !== (int) $targetLoc->setting_id;

            $statuses = [];
            $labelParts = [];

            if (!$isSameLocation) {
                $statuses[] = SerialClassification::STATUS_MOVED;
                $labelParts[] = sprintf(
                    'Pindah dari %s ke %s.',
                    $ownProductMatch->location?->name ?? "lokasi #{$sourceLocationId}",
                    $targetLoc->name
                );
                if ($crossSetting) {
                    $labelParts[] = 'Perpindahan lintas pengaturan (lokasi asal berbeda pengaturan dari lokasi tujuan).';
                }
            }

            if ($conditionChanged) {
                $statuses[] = SerialClassification::STATUS_CONDITION_CHANGED;
                $labelParts[] = sprintf('Kondisi berubah dari %s menjadi %s.', $sourceCondition, $enteredCondition);
            }

            if ($taxChanged) {
                $statuses[] = SerialClassification::STATUS_TAX_CHANGED;
                $labelParts[] = $targetIsTax
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
                destinationIsTax: $targetIsTax,
                label: implode(' ', $labelParts),
                statuses: $statuses,
                crossSetting: $crossSetting,
            );
        }

        // Selected pool serials omitted from the entered complete set.
        $omitted = [];
        $destinationSerials = $destinationSerialsByProduct->get($product->id, collect());
        foreach ($destinationSerials as $existingSerial) {
            if (isset($enteredTexts[$existingSerial->serial_number])) {
                continue;
            }
            if ($this->isUnavailableForCounting($existingSerial, $activeTransferClaimSerialIds)) {
                continue;
            }

            $locName = $existingSerial->location?->name ?? ('lokasi #' . $existingSerial->location_id);

            $omitted[] = new SerialClassification(
                serialNumber: $existingSerial->serial_number,
                status: SerialClassification::STATUS_OMITTED,
                sourceSerialId: (int) $existingSerial->id,
                sourceLocationId: (int) $existingSerial->location_id,
                sourceLocationName: $locName,
                sourceCondition: $existingSerial->is_broken ? 'bad' : 'good',
                sourceIsTax: $existingSerial->tax_id !== null,
                enteredCondition: null,
                destinationIsTax: null,
                label: sprintf('Terdaftar di %s tetapi tidak disertakan dalam hitungan.', $locName),
                statuses: [SerialClassification::STATUS_OMITTED],
            );
        }

        if (!empty($omitted)) {
            $warnings[] = sprintf(
                '%s: %d nomor seri terdaftar di lokasi terpilih tidak disertakan dalam hitungan.',
                $product->product_name,
                count($omitted)
            );
        }

        return [
            'entered' => $entered,
            'omitted' => $omitted,
            'warnings' => $warnings,
            'conflicts' => $conflicts,
            'newSerialCount' => $newSerialCount,
        ];
    }

    /**
     * Deterministically determine surplus destination for new or outside serials.
     * Order: (is_pkp ASC, relevant condition stock ASC, location_id ASC).
     */
    public function determineSurplusDestination(
        Collection $selectedLocations,
        Collection $locationStocks,
        int $productId,
        string $condition = 'good'
    ): Location {
        $sorted = $selectedLocations->all();
        usort($sorted, function (Location $a, Location $b) use ($locationStocks, $productId, $condition) {
            $pkpA = (int) (bool) ($a->setting?->is_pkp ?? false);
            $pkpB = (int) (bool) ($b->setting?->is_pkp ?? false);
            if ($pkpA !== $pkpB) {
                return $pkpA <=> $pkpB;
            }

            $stockA = $locationStocks->get("{$a->id}_{$productId}");
            $stockB = $locationStocks->get("{$b->id}_{$productId}");

            $valA = $condition === 'bad'
                ? ($stockA ? (int) round((float) $stockA->broken_quantity) : 0)
                : ($stockA ? (int) round((float) $stockA->quantity_tax) + (int) round((float) $stockA->quantity_non_tax) : 0);
            $valB = $condition === 'bad'
                ? ($stockB ? (int) round((float) $stockB->broken_quantity) : 0)
                : ($stockB ? (int) round((float) $stockB->quantity_tax) + (int) round((float) $stockB->quantity_non_tax) : 0);

            if ($valA !== $valB) {
                return $valA <=> $valB;
            }

            return ((int) $a->id) <=> ((int) $b->id);
        });

        return $sorted[0];
    }

    /**
     * A destination serial in this state is not an omission discrepancy: it
     * is already known to be unavailable for ordinary counting/movement
     * (sold, mid-return, in-transit transfer, or already flagged missing by an earlier opname).
     */
    public function isUnavailableForCounting(ProductSerialNumber $serial, ?Collection $activeTransferClaimSerialIds = null): bool
    {
        if (in_array($serial->status, [
            ProductSerialNumber::STATUS_SOLD,
            ProductSerialNumber::STATUS_RETURN_IN_PROCESS,
            ProductSerialNumber::STATUS_MISSING,
        ], true)) {
            return true;
        }

        if ($activeTransferClaimSerialIds !== null && $activeTransferClaimSerialIds->contains($serial->id)) {
            return true;
        }

        return false;
    }

    /**
     * Return a Bahasa Indonesia conflict reason if this serial's current
     * authoritative state is unsafe to move/create/reclassify/remove, or
     * null if it is safe to proceed. Purely in-memory against bulk-loaded
     * eligible-location/claim/allocation sets — issues no query itself.
     */
    public function unsafeSerialConflictReason(
        ProductSerialNumber $serial,
        Collection $movementEligibleLocationIds,
        Collection $activeClaimSerialIds,
        Collection $allocatedSerialIds,
        ?Collection $activeTransferClaimSerialIds = null,
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
        if ($serial->status === ProductSerialNumber::STATUS_MISSING) {
            return 'Nomor seri berstatus HILANG pada hitungan sebelumnya dan memerlukan alur pemulihan tersendiri sebelum dapat diproses lagi.';
        }
        if ($activeClaimSerialIds->contains($serial->id)) {
            return 'Nomor seri memiliki klaim konsinyasi aktif.';
        }
        if ($allocatedSerialIds->contains($serial->id)) {
            return 'Nomor seri memiliki alokasi konsinyasi aktif.';
        }
        if ($activeTransferClaimSerialIds !== null && $activeTransferClaimSerialIds->contains($serial->id)) {
            return 'Nomor seri sedang dalam proses transfer stok (in transit).';
        }

        if ($serial->location_id !== null && !$movementEligibleLocationIds->contains((int) $serial->location_id)) {
            return 'Nomor seri berada di lokasi konsinyasi atau lokasi yang tidak valid untuk perpindahan stok.';
        }

        return null;
    }
}
