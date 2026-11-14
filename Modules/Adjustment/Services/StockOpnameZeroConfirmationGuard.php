<?php

namespace Modules\Adjustment\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Product\Entities\ProductStock;

/**
 * Authoritative physical-zero safeguard shared by Stock Opname submission
 * and locked approval: an entered good + bad total of zero may only remove
 * positive selected-pool stock when the row carries a valid explicit
 * physical-zero confirmation for its unchanged count and location scope.
 *
 * Messages never include system-stock values.
 */
class StockOpnameZeroConfirmationGuard
{
    public const STAGE_SUBMISSION = 'submission';
    public const STAGE_APPROVAL = 'approval';

    /**
     * Pure check: return the product-specific messages for rows that would
     * zero positive stock without valid confirmation.
     *
     * @param array<int, array> $rows Persisted count-draft rows
     * @param array<int> $selectedLocationIds
     * @param callable(int $productId): int $poolTotal Current selected-pool good + bad stock
     * @return string[]
     */
    public function missingConfirmations(array $rows, array $selectedLocationIds, callable $poolTotal, string $stage = self::STAGE_SUBMISSION): array
    {
        $messages = [];

        foreach ($rows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $enteredSerials = collect((array) ($row['serials'] ?? []))
                ->filter(fn ($s) => trim((string) ($s['serial_number'] ?? '')) !== '')
                ->count();
            $enteredTotal = (int) ($row['good_count'] ?? 0) + (int) ($row['bad_count'] ?? 0);
            if ($enteredTotal !== 0 || $enteredSerials > 0) {
                continue;
            }

            if ($poolTotal($productId) <= 0) {
                continue;
            }

            if (CountDraftService::hasValidZeroConfirmation($row, $selectedLocationIds)) {
                continue;
            }

            $name = (string) ($row['product_name'] ?? ('ID ' . $productId));
            $messages[] = $stage === self::STAGE_APPROVAL
                ? "Produk {$name}: hasil hitung fisik 0 belum dikonfirmasi dengan \"Saya sudah menghitung: stok fisik kosong\". Tolak dokumen ini agar hitungan dapat dikoreksi atau dikonfirmasi, lalu ajukan kembali."
                : "Produk {$name}: hasil hitung fisik 0 harus dikonfirmasi dengan mencentang \"Saya sudah menghitung: stok fisik kosong\" sebelum diajukan, atau perbaiki hitungannya.";
        }

        return $messages;
    }

    /**
     * Submission-time check against current stock of the document's selected pool.
     *
     * @throws ValidationException
     */
    public function assertForSubmission(Adjustment $adjustment): void
    {
        $rows = (array) ($adjustment->count_draft['rows'] ?? []);
        $locationIds = app(SelectedLocationPoolResolver::class)->resolveIds($adjustment);
        $productIds = collect($rows)->map(fn ($r) => (int) ($r['product_id'] ?? 0))->filter()->unique()->values();

        $totals = collect();
        if ($productIds->isNotEmpty() && !empty($locationIds)) {
            $totals = ProductStock::whereIn('product_id', $productIds)
                ->whereIn('location_id', $locationIds)
                ->get()
                ->groupBy('product_id')
                ->map(fn (Collection $stocks) => (int) $stocks->sum(fn (ProductStock $s) => self::stockTotal($s)));
        }

        $messages = $this->missingConfirmations($rows, $locationIds, fn (int $pid) => (int) $totals->get($pid, 0));

        if (!empty($messages)) {
            throw ValidationException::withMessages(['count_draft' => $messages]);
        }
    }

    public static function stockTotal(?ProductStock $stock): int
    {
        if (!$stock) {
            return 0;
        }

        return (int) round((float) $stock->quantity_tax) + (int) round((float) $stock->quantity_non_tax)
            + (int) round((float) $stock->broken_quantity_tax) + (int) round((float) $stock->broken_quantity_non_tax);
    }
}
