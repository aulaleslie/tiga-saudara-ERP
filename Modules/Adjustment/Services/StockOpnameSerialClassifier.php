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
        Location $destinationLocation,
        bool $isPkp,
        Collection $eligibleLocationIds,
        Collection $matchingSerialsByText,
        Collection $destinationSerialsByProduct,
        Collection $activeClaimSerialIds,
        Collection $allocatedSerialIds,
    ): array {
        if (!$product->serial_number_required) {
            return ['entered' => [], 'omitted' => [], 'warnings' => [], 'conflicts' => [], 'newSerialCount' => 0];
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
            if ($this->isUnavailableForCounting($existingSerial)) {
                // Not currently available at this location for counting
                // purposes; omission is expected, not a discrepancy. This
                // includes MISSING: a serial already flagged missing by a
                // prior stock opname is not re-flagged as a fresh omission.
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

        return [
            'entered' => $entered,
            'omitted' => $omitted,
            'warnings' => $warnings,
            'conflicts' => $conflicts,
            'newSerialCount' => $newSerialCount,
        ];
    }

    /**
     * A destination serial in this state is not an omission discrepancy: it
     * is already known to be unavailable for ordinary counting/movement
     * (sold, mid-return, or already flagged missing by an earlier opname).
     */
    public function isUnavailableForCounting(ProductSerialNumber $serial): bool
    {
        return in_array($serial->status, [
            ProductSerialNumber::STATUS_SOLD,
            ProductSerialNumber::STATUS_RETURN_IN_PROCESS,
            ProductSerialNumber::STATUS_MISSING,
        ], true);
    }

    /**
     * Return a Bahasa Indonesia conflict reason if this serial's current
     * authoritative state is unsafe to move/create/reclassify/remove, or
     * null if it is safe to proceed. Purely in-memory against bulk-loaded
     * eligible-location/claim/allocation sets — issues no query itself.
     */
    public function unsafeSerialConflictReason(
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
        if ($serial->status === ProductSerialNumber::STATUS_MISSING) {
            // A serial already flagged missing by a prior approved opname is
            // never silently reusable by entering its text again: its
            // retained location_id is provenance only, not a live movable
            // position, and re-establishing it requires an explicit separate
            // recovery workflow rather than an ordinary count/move/reclassify.
            return 'Nomor seri berstatus HILANG pada hitungan sebelumnya dan memerlukan alur pemulihan tersendiri sebelum dapat diproses lagi.';
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
