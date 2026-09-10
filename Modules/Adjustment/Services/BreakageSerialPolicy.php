<?php

namespace Modules\Adjustment\Services;

use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;

/**
 * Strict breakage serial eligibility policy (design.md "Resolve scans with a
 * strict breakage policy" / "Preserve physical totals and serial identity").
 *
 * Unlike Stock Opname's serial classifier -- which legitimately handles
 * moves, reclassification, and new-serial creation -- breakage NEVER moves,
 * reclassifies, or creates a serial. It only asks: is this an existing,
 * canonical-sellable (scopeSellable) serial for THIS product at THIS
 * location, whose current tax_id classification agrees with the location's
 * current PKP setting? Anything else is a conflict; it is never silently
 * corrected or normalized.
 */
class BreakageSerialPolicy
{
    /**
     * Classify a list of candidate serial IDs against one product/location.
     *
     * @param int[] $serialIds
     * @return array{
     *   eligible: array, // each: [serial_id, serial_number, tax_id, is_tax]
     *   serial_conflicts: array, // each: [serial_id|null, serial_number|null, reason]
     *   conflicts: string[], // product-level conflict messages (duplicates, PKP mismatch, etc.)
     * }
     */
    public function classify(Product $product, Location $location, bool $isPkp, array $serialIds): array
    {
        $rawSerialIds = array_map('intval', $serialIds);
        $hasDuplicates = count($rawSerialIds) !== count(array_unique($rawSerialIds));
        $serialIds = array_values(array_unique($rawSerialIds));

        $eligible = [];
        $serialConflicts = [];
        $conflicts = [];

        if ($hasDuplicates) {
            $conflicts[] = sprintf('Produk %s: nomor seri duplikat terdeteksi.', $product->product_name);
        }

        if (empty($serialIds)) {
            $conflicts[] = sprintf('Produk %s: nomor seri wajib dipilih untuk produk bertipe serial.', $product->product_name);

            return [
                'eligible' => $eligible,
                'serial_conflicts' => $serialConflicts,
                'conflicts' => $conflicts,
            ];
        }

        $serials = ProductSerialNumber::whereIn('id', $serialIds)->get()->keyBy('id');

        foreach ($serialIds as $serialId) {
            /** @var ProductSerialNumber|null $serial */
            $serial = $serials->get($serialId);

            if (!$serial) {
                $serialConflicts[] = [
                    'serial_id' => $serialId,
                    'serial_number' => null,
                    'reason' => 'Nomor seri tidak ditemukan.',
                ];
                continue;
            }

            if ((int) $serial->product_id !== (int) $product->id) {
                $serialConflicts[] = [
                    'serial_id' => $serial->id,
                    'serial_number' => $serial->serial_number,
                    'reason' => sprintf('Nomor seri %s bukan milik produk %s.', $serial->serial_number, $product->product_name),
                ];
                continue;
            }

            if ((int) $serial->location_id !== (int) $location->id) {
                $serialConflicts[] = [
                    'serial_id' => $serial->id,
                    'serial_number' => $serial->serial_number,
                    'reason' => sprintf('Nomor seri %s berada di lokasi lain, bukan lokasi terpilih.', $serial->serial_number),
                ];
                continue;
            }

            if (!$serial->isSellable()) {
                $serialConflicts[] = [
                    'serial_id' => $serial->id,
                    'serial_number' => $serial->serial_number,
                    'reason' => sprintf('Nomor seri %s tidak tersedia (terkirim, dalam proses retur, sudah rusak, atau tidak aktif).', $serial->serial_number),
                ];
                continue;
            }

            $serialIsTax = $serial->tax_id !== null;
            if ($serialIsTax !== $isPkp) {
                $serialConflicts[] = [
                    'serial_id' => $serial->id,
                    'serial_number' => $serial->serial_number,
                    'reason' => sprintf(
                        'Nomor seri %s memiliki klasifikasi pajak yang tidak sesuai dengan pengaturan PKP lokasi ini.',
                        $serial->serial_number
                    ),
                ];
                continue;
            }

            $eligible[] = [
                'serial_id' => (int) $serial->id,
                'serial_number' => $serial->serial_number,
                'tax_id' => $serial->tax_id,
                'is_tax' => $serialIsTax,
            ];
        }

        if (!empty($serialConflicts)) {
            foreach ($serialConflicts as $sc) {
                $conflicts[] = $sc['reason'];
            }
        }

        return [
            'eligible' => $eligible,
            'serial_conflicts' => $serialConflicts,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * Single-serial eligibility check used by the entry-time scan resolver:
     * true only if the serial is an existing sellable serial for this exact
     * product and location, with a tax classification consistent with the
     * location's current PKP setting.
     */
    public function isEligibleForScan(ProductSerialNumber $serial, Product $product, Location $location, bool $isPkp): bool
    {
        if ((int) $serial->product_id !== (int) $product->id) {
            return false;
        }

        if ((int) $serial->location_id !== (int) $location->id) {
            return false;
        }

        if (!$serial->isSellable()) {
            return false;
        }

        return ($serial->tax_id !== null) === $isPkp;
    }
}
