<?php

namespace App\Support\SalesReturn;

use App\Support\SalesReturn\SerialRegistration\PendingSerialValidator;
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
    public function __construct(
        private readonly SaleReturnEligibilityService $eligibility,
        private readonly PendingSerialValidator $pendingValidator = new PendingSerialValidator,
    ) {
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
        $seenPending = [];

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
            // A re-returned return-registered unit re-uses its attributed unit (no extra entitlement).
            $repeatUnits = $this->serialIds($row)
                ->filter(fn ($id) => \App\Support\SalesReturn\SerialRegistration\RegisteredOccurrences::isReReturnable((int) $id, $excludeSaleReturnId))
                ->count();
            $remaining = (int) $source['available_quantity'] - $alreadyClaimed + $repeatUnits;
            if ($quantity > $remaining) {
                $errors["rows.$index.quantity"] = "Jumlah retur melebihi sisa yang dapat dikembalikan ({$remaining}).";
                continue;
            }

            $serialError = $this->validateSerials($row, $source, $quantity, $excludeSaleReturnId);
            if ($serialError) {
                $errors["rows.$index.serial_numbers"] = $serialError;
                continue;
            }

            $pendingTexts = $this->pendingTexts($row);
            $duplicate = false;
            foreach ($pendingTexts as $text) {
                $scope = $source['product_id'].'|'.$text;
                $duplicate = $duplicate || isset($seenPending[$scope]);
                $seenPending[$scope] = true;
            }
            if ($duplicate) {
                $errors["rows.$index.serial_numbers"] = 'Nomor seri baru yang sama tidak boleh diajukan lebih dari sekali.';
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
                // Authoritative classification; the client's eligibility claims are never trusted.
                'source_classification' => $source['source_classification'] ?? null,
                'registration_eligible' => (bool) ($source['registration_eligible'] ?? false),
                'pending_serials' => $pendingTexts,
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

    /** @return list<string> normalized proposed (unregistered) serial texts of a row */
    public function pendingTexts(array $row): array
    {
        return collect($row['serial_numbers'] ?? [])
            ->filter(fn ($serial) => is_array($serial) && empty($serial['id']) && ! empty($serial['pending']) && trim((string) ($serial['serial_number'] ?? '')) !== '')
            ->map(fn ($serial) => $this->pendingValidator->normalize((string) $serial['serial_number']))
            ->values()
            ->all();
    }

    private function validateSerials(array $row, array $source, int $quantity, ?int $excludeSaleReturnId): ?string
    {
        $serialIds = $this->serialIds($row);
        $pending = $this->pendingTexts($row);
        $total = $serialIds->count() + count($pending);

        if (! empty($source['serial_number_required']) && $total !== $quantity) {
            return 'Jumlah nomor seri harus sama dengan kuantitas yang diretur.';
        }

        if ($pending !== []) {
            if (empty($source['registration_eligible'])) {
                return 'Nomor seri baru hanya dapat didaftarkan untuk pengiriman tanpa nomor seri tercatat pada produk yang kini wajib bernomor seri.';
            }
            $errors = $this->pendingValidator->validate(array_map(
                fn ($text, $i) => ['product_id' => $source['product_id'], 'serial' => $text, 'key' => $i], $pending, array_keys($pending)
            ));
            if ($errors !== []) {
                return match (reset($errors)) {
                    PendingSerialValidator::ERROR_ALREADY_REGISTERED => 'Nomor seri sudah terdaftar; gunakan pencarian nomor seri yang ada.',
                    PendingSerialValidator::ERROR_DUPLICATE => 'Nomor seri baru tidak boleh duplikat.',
                    PendingSerialValidator::ERROR_TOO_LONG => 'Nomor seri terlalu panjang.',
                    default => 'Nomor seri tidak valid.',
                };
            }
        }

        if ($total === 0) {
            return null;
        }

        if ($total !== $quantity) {
            return 'Jumlah nomor seri tidak sesuai dengan kuantitas yang diretur.';
        }

        if ($serialIds->isEmpty()) {
            return null;
        }

        $owned = ProductSerialNumber::query()
            ->whereIn('id', $serialIds)
            ->where('dispatch_detail_id', $source['dispatch_detail_id'])
            ->count();
        // A replacement standing in for a return-registered unit belongs to that unit's source line.
        $owned += $serialIds->filter(fn ($id) => ! ProductSerialNumber::query()->whereKey($id)->where('dispatch_detail_id', $source['dispatch_detail_id'])->exists()
            && \App\Support\SalesReturn\SerialRegistration\RegisteredOccurrences::sourceDispatchDetailId((int) $id) === (int) $source['dispatch_detail_id'])->count();

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
                ->intersect($serialIds->reject(fn ($id) => \App\Support\SalesReturn\SerialRegistration\RegisteredOccurrences::isReReturnable((int) $id, $excludeSaleReturnId)))
                ->isNotEmpty());

        return $reserved ? 'Nomor seri sudah diretur pada dokumen lain.' : null;
    }
}
