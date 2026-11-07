<?php

namespace App\Support\SalesReturn;

use Illuminate\Support\Collection;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement as Settlement;

/**
 * Coverage rules for STANDARD returns (POS keeps its own active-quantity rules and never uses this).
 *
 * Eligibility first: a return contributes ONLY when it is approved AND physically received
 * (status awaiting settlement / completed AND received_at recorded). Rejected, unreceived or
 * otherwise invalid parents contribute nothing, neither finals nor pending contributors.
 *
 * Occurrences: one per claimed serial, or one per non-serial detail. Evidence must be consistent
 * (quantity == serial cardinality, no duplicate serial ids, serial required for serial products,
 * no settlement for an unclaimed occurrence); anything ambiguous BLOCKS rather than guesses.
 *
 * Finality depends on the method: recognized money methods are final when APPROVED; service methods
 * (repair, cannot process) only when DISPATCHED; unknown/missing methods are never final. Exactly ONE valid final settlement
 * per occurrence, and no competing open one.
 */
class StandardReturnCoverage
{
    private const SERVICE_METHODS = [SaleReturnDetail::METHOD_PRODUCT_REPAIR, SaleReturnDetail::METHOD_UNPROCESSED];

    /** Money/credit methods, final when APPROVED (modify-sale and customer-credit are historical only). */
    private const MONEY_METHODS = [
        SaleReturnDetail::METHOD_CASH_REFUND,
        SaleReturnDetail::METHOD_CUSTOMER_CREDIT,
        SaleReturnDetail::METHOD_MODIFY_SALE,
    ];

    /** Approved and physically received (authoritative receiving evidence = received_at). */
    public static function isEffective(SaleReturn $return): bool
    {
        return strtolower((string) $return->approval_status) === 'approved'
            && in_array(strtoupper((string) $return->status), ['AWAITING SETTLEMENT', 'COMPLETED'], true)
            && ! empty($return->received_at);
    }

    /** Is this settlement final for ITS method? (the status/method combination is what counts) */
    public static function isFinal(Settlement $settlement): bool
    {
        $method = strtoupper((string) $settlement->method);

        // Only RECOGNIZED methods can be final; unknown or missing methods are malformed evidence and
        // stay unresolved (open) rather than counting as settled.
        if (in_array($method, self::SERVICE_METHODS, true)) {
            return $settlement->status === Settlement::STATUS_DISPATCHED;
        }

        return in_array($method, self::MONEY_METHODS, true) && $settlement->status === Settlement::STATUS_APPROVED;
    }

    public static function isOpen(Settlement $settlement): bool
    {
        return ! self::isFinal($settlement) && $settlement->status !== Settlement::STATUS_REJECTED;
    }

    /**
     * Physical occurrences the return must settle, or null when the received evidence is inconsistent.
     *
     * @return array<int, array{detail: SaleReturnDetail, serial: ?int, units: float}>|null
     */
    public static function occurrences(SaleReturnDetail $detail): ?array
    {
        $raw = collect($detail->serial_number_ids ?? [])->map(fn ($id) => (int) $id)->filter()->values();
        $quantity = round((float) $detail->quantity, 3);

        if ($raw->isNotEmpty()) {
            // serial cardinality must equal the received quantity, without duplicates
            if ($raw->count() !== $raw->unique()->count() || abs($quantity - $raw->count()) > 0.0004) {
                return null;
            }

            return $raw->map(fn ($id) => ['detail' => $detail, 'serial' => $id, 'units' => 1.0])->all();
        }

        if ((bool) $detail->product?->serial_number_required) {
            return null;   // a serial product without its claimed serials is ambiguous
        }

        return [['detail' => $detail, 'serial' => null, 'units' => $quantity]];
    }

    /**
     * @return array{complete: bool, expected: int, final: int, missing: array<int, string>}
     */
    public static function returnCompletion(SaleReturn $return): array
    {
        $missing = [];
        if (! self::isEffective($return)) {
            return ['complete' => false, 'expected' => 0, 'final' => 0, 'missing' => ['return is not approved and physically received']];
        }

        $details = SaleReturnDetail::query()->with('product:id,serial_number_required')
            ->where('sale_return_id', $return->id)->where('quantity', '>', 0)->orderBy('id')->get();
        $settlements = Settlement::query()->where('sale_return_id', $return->id)->get();

        $expected = 0;
        $final = 0;
        $knownKeys = [];

        foreach ($details as $detail) {
            $occurrences = self::occurrences($detail);
            if ($occurrences === null) {
                $expected++;
                $missing[] = "detail {$detail->id}: inconsistent received quantity / serial evidence";
                continue;
            }

            foreach ($occurrences as $occurrence) {
                $expected++;
                $key = $detail->id . ':' . ($occurrence['serial'] ?? 0);
                $knownKeys[$key] = true;

                $rows = $settlements->filter(fn ($s) => (int) $s->sale_return_detail_id === (int) $detail->id
                    && ($occurrence['serial'] === null ? $s->product_serial_number_id === null : (int) $s->product_serial_number_id === $occurrence['serial']));
                $finals = $rows->filter(fn ($s) => self::isFinal($s));
                $open = $rows->filter(fn ($s) => self::isOpen($s));

                if ($finals->count() === 1 && $open->isEmpty()) {
                    $final++;
                } else {
                    $missing[] = "detail {$detail->id}" . ($occurrence['serial'] ? " serial {$occurrence['serial']}" : '')
                        . ($finals->count() > 1 ? ': duplicate final settlements' : '');
                }
            }
        }

        // A live settlement for an occurrence nobody received/claimed is contradictory evidence.
        foreach ($settlements as $s) {
            if ($s->status !== Settlement::STATUS_REJECTED && ! isset($knownKeys[$s->sale_return_detail_id . ':' . ($s->product_serial_number_id ?? 0)])) {
                $expected++;
                $missing[] = "settlement {$s->id}: no matching received occurrence";
            }
        }

        return ['complete' => $expected > 0 && $final === $expected, 'expected' => $expected, 'final' => $final, 'missing' => $missing];
    }

    /**
     * Final cash-return units per original dispatch line of a sale, with pending contributors.
     * Only EFFECTIVE (approved + received) parents are considered; each occurrence needs exactly one
     * final cash settlement and no competing open one, otherwise it is pending/ambiguous, not coverage.
     *
     * @return array{lines: array<int, float>, pending: int, ambiguous: float}
     */
    public static function saleCashUnits(int $saleId): array
    {
        $lines = [];
        $ambiguous = 0.0;
        $pending = 0;

        $returns = SaleReturn::withArchived()->where('sale_id', $saleId)->get()->filter(fn ($r) => self::isEffective($r));

        foreach ($returns as $return) {
            $details = SaleReturnDetail::query()->with('product:id,serial_number_required')
                ->where('sale_return_id', $return->id)->where('quantity', '>', 0)->get();
            // ALL methods: a competing repair/other row for the same occurrence must be seen, not filtered away.
            $settlements = Settlement::query()->where('sale_return_id', $return->id)->get();

            $knownKeys = [];
            $inconsistentDetails = [];

            foreach ($details as $detail) {
                $occurrences = self::occurrences($detail);
                $detailRows = $settlements->filter(fn ($s) => (int) $s->sale_return_detail_id === (int) $detail->id);

                if ($occurrences === null) {
                    $inconsistentDetails[(int) $detail->id] = true;
                    // inconsistent quantities/serials: nothing from this detail can prove coverage
                    $ambiguous += $detailRows->contains(fn ($s) => self::isFinal($s)) ? (float) $detail->quantity : 0.0;
                    $pending += $detailRows->filter(fn ($s) => self::isOpen($s))->count();
                    continue;
                }

                foreach ($occurrences as $occurrence) {
                    $knownKeys[$detail->id . ':' . ($occurrence['serial'] ?? 0)] = true;
                    $rows = $detailRows->filter(fn ($s) => $occurrence['serial'] === null
                        ? $s->product_serial_number_id === null
                        : (int) $s->product_serial_number_id === $occurrence['serial']);
                    $finals = $rows->filter(fn ($s) => self::isFinal($s));
                    $open = $rows->filter(fn ($s) => self::isOpen($s));

                    $pending += $open->count();
                    if ($finals->count() > 1) {
                        $ambiguous += $occurrence['units'];   // duplicate finals never count a unit twice
                        continue;
                    }
                    if ($finals->count() === 1) {
                        if (strtoupper((string) $finals->first()->method) !== SaleReturnDetail::METHOD_CASH_REFUND) {
                            continue;   // a final non-cash settlement is not cash coverage (and counts nothing)
                        }
                        if (! $detail->dispatch_detail_id) {
                            $ambiguous += $occurrence['units'];
                            continue;
                        }
                        $lines[(int) $detail->dispatch_detail_id] = ($lines[(int) $detail->dispatch_detail_id] ?? 0.0) + $occurrence['units'];
                    }
                }
            }

            // A live settlement for an occurrence nobody received/claimed is contradictory evidence, exactly as in
            // returnCompletion(): it is an unresolved contributor and must block full coverage.
            foreach ($settlements as $row) {
                if ($row->status === Settlement::STATUS_REJECTED || isset($inconsistentDetails[(int) $row->sale_return_detail_id])) {
                    continue;
                }
                if (! isset($knownKeys[$row->sale_return_detail_id . ':' . ($row->product_serial_number_id ?? 0)])) {
                    $pending++;
                }
            }
        }

        return ['lines' => $lines, 'pending' => $pending, 'ambiguous' => $ambiguous];
    }
}
