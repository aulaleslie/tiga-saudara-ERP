<?php

namespace App\Support\SalesReturn;

use Illuminate\Validation\ValidationException;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturnDetail;

/**
 * Turns client-submitted rows into authoritative claims. Called inside the transaction,
 * after the source sale is locked, so remaining entitlement cannot be raced.
 *
 * Nothing the client sends about lineage, price, location or entitlement is trusted:
 *  - the dispatch line must still be returnable for THIS sale (stale/forged claims fail),
 *  - product must match the dispatch line,
 *  - quantity cannot exceed the remaining entitlement computed now,
 *  - serials must belong to that dispatch line and not be reserved by another return,
 *  - price/location/tax/sale-detail come from the source, and the total is recomputed.
 */
class StandardReturnClaimService
{
    public function __construct(private readonly SaleReturnEligibilityService $eligibility)
    {
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows: array<int, array<string, mixed>>, total: float}
     */
    public function authorize(Sale $sale, array $rows, ?int $excludeSaleReturnId = null): array
    {
        $authoritative = $this->eligibility->buildReturnableRows($sale->fresh(), $excludeSaleReturnId)
            ->keyBy('dispatch_detail_id');

        $errors = [];
        $normalized = [];
        // Entitlement and serials are consumed ACROSS the whole request: two rows for the same
        // dispatch line share one remaining quantity, and a serial can appear only once.
        $claimedByLine = [];
        $seenSerials = [];

        foreach ($rows as $index => $row) {
            $quantity = (int) ($row['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $dispatchDetailId = (int) ($row['dispatch_detail_id'] ?? 0);
            $source = $authoritative->get($dispatchDetailId);

            if (! $source) {
                $errors["rows.$index.dispatch_detail_id"] = 'Baris pengiriman tidak lagi dapat diretur untuk penjualan ini (data sudah berubah atau tidak valid).';
                continue;
            }

            if ((int) ($row['product_id'] ?? 0) !== (int) $source['product_id']) {
                $errors["rows.$index.product_id"] = 'Produk tidak sesuai dengan baris pengiriman sumber.';
                continue;
            }

            $alreadyClaimed = $claimedByLine[$dispatchDetailId] ?? 0;
            $remaining = (int) $source['available_quantity'] - $alreadyClaimed;
            if ($quantity > $remaining) {
                $errors["rows.$index.quantity"] = "Jumlah retur melebihi sisa yang dapat dikembalikan ({$remaining}).";
                continue;
            }

            $serialError = $this->validateSerials($row, $source, $quantity, $excludeSaleReturnId);
            if ($serialError) {
                $errors["rows.$index.serial_numbers"] = $serialError;
                continue;
            }

            $rowSerialIds = $this->serialIds($row);
            if ($rowSerialIds->intersect(array_keys($seenSerials))->isNotEmpty()) {
                $errors["rows.$index.serial_numbers"] = 'Nomor seri yang sama tidak boleh diretur lebih dari sekali dalam satu permintaan.';
                continue;
            }
            foreach ($rowSerialIds as $serialId) {
                $seenSerials[$serialId] = true;
            }
            $claimedByLine[$dispatchDetailId] = $alreadyClaimed + $quantity;

            $unitPrice = (float) $source['unit_price'];
            $normalized[] = array_merge($row, [
                'sale_detail_id' => $source['sale_detail_id'],
                'product_id' => $source['product_id'],
                'product_name' => $source['product_name'],
                'product_code' => $source['product_code'],
                'location_id' => $source['location_id'],
                'tax_id' => $source['tax_id'],
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'total' => round($quantity * $unitPrice, 2),
            ]);
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        $total = round(collect($normalized)->sum('total'), 2);
        if (empty($normalized) || $total <= 0) {
            throw ValidationException::withMessages(['rows' => 'Nilai retur harus lebih dari 0.']);
        }

        return ['rows' => $normalized, 'total' => $total];
    }

    /** @return \Illuminate\Support\Collection<int, int> distinct selected serial ids (duplicates inside one row collapse) */
    private function serialIds(array $row)
    {
        $raw = collect($row['serial_numbers'] ?? [])
            ->map(fn ($serial) => is_array($serial) ? ($serial['id'] ?? null) : null)
            ->filter()
            ->map(fn ($id) => (int) $id);

        return $raw->unique()->values();
    }

    private function validateSerials(array $row, array $source, int $quantity, ?int $excludeSaleReturnId): ?string
    {
        $serialIds = $this->serialIds($row);

        if (! empty($source['serial_number_required']) && $serialIds->count() !== $quantity) {
            return 'Jumlah nomor seri harus sama dengan kuantitas yang diretur.';
        }

        if ($serialIds->isEmpty()) {
            return null;
        }

        if ($serialIds->count() !== $quantity) {
            return 'Jumlah nomor seri tidak sesuai dengan kuantitas yang diretur.';
        }

        $owned = ProductSerialNumber::query()
            ->whereIn('id', $serialIds)
            ->where('dispatch_detail_id', $source['dispatch_detail_id'])
            ->count();

        if ($owned !== $serialIds->count()) {
            return 'Nomor seri tidak berasal dari pengiriman terkait.';
        }

        // Portable (no JSON operators): narrow by dispatch line in SQL, match serial ids in PHP.
        $reserved = SaleReturnDetail::query()
            ->where('dispatch_detail_id', $source['dispatch_detail_id'])
            ->when($excludeSaleReturnId, fn ($q) => $q->where('sale_return_id', '!=', $excludeSaleReturnId))
            ->whereHas('saleReturn', fn ($q) => $q->whereRaw('LOWER(approval_status) != ?', ['rejected']))
            ->get(['serial_number_ids'])
            ->contains(fn ($detail) => collect($detail->serial_number_ids ?? [])
                ->map(fn ($id) => (int) $id)
                ->intersect($serialIds)
                ->isNotEmpty());

        return $reserved ? 'Nomor seri sudah diretur pada dokumen lain.' : null;
    }
}
