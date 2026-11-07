<?php

namespace App\Support\SalesReturn;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Sale\Services\SaleNormalizer;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnAdjustment;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement;
use Modules\SalesReturn\Entities\SaleReturnPayment;

/**
 * Financial resolution of STANDARD cash returns (group 6).
 *
 *  value      = attributable charged value of the returned unit(s), from stored source lines:
 *               line net (sub_total, already net of line discount and including line tax) less its
 *               share of the header discount, per unit, bounded by prior committed returns of the
 *               same line (the last unit takes the rounding remainder). Client prices never count.
 *  debt first = debt_reduction = min(value, outstanding debt); cash_refund = value - debt_reduction,
 *               which must fit the verified payments still refundable (never silently truncated).
 *  evidence   = one immutable sale_return_adjustments row per settlement + a cash payout row ONLY
 *               when cash_refund > 0. Anything missing or contradictory blocks financial approval
 *               (SalesReturnFinancialBlockedException); receiving/custody are unaffected.
 *
 * Callers hold the source-sale lock (StandardReturnGuard::lockSettlement/lockForAction).
 * Sale::live_due_amount = total - payments - credits - SUM(debt_reduction).
 */
class StandardFinancialService
{
    public const POLICY_VERSION = 1;
    private const TOLERANCE = 0.011;

    // ------------------------------------------------------------------ resolution

    /**
     * @return array{value: float, units: float, sale: Sale, line: ?SaleDetails, outstanding: float, refundable: float,
     *               fingerprint: string, prior_units: float, serial_id: ?int}
     */
    public function resolve(SaleReturnItemSettlement $settlement): array
    {
        $settlement->loadMissing(['detail', 'saleReturn']);
        $detail = $settlement->detail;
        $return = $settlement->saleReturn;

        $sale = Sale::withArchived()->whereKey($return->sale_id)->lockForUpdate()->first();
        if (! $sale) {
            $this->block('Penjualan sumber tidak ditemukan.');
        }

        $line = $this->sourceLine($detail, $sale);
        $lines = SaleDetails::query()->where('sale_id', $sale->id)->orderBy('id')->get();
        $shipping = (float) $sale->shipping_amount;
        $linesTotal = round((float) $lines->sum('sub_total'), 2);

        // Header discount in the ESTABLISHED representation (SaleNormalizer): percentage mode keeps
        // discount_amount at 0 and discount_percentage > 0; fixed mode keeps discount_amount.
        $percentage = min(100.0, max(0.0, (float) $sale->discount_percentage));
        $discount = $percentage > 0
            ? round($linesTotal * $percentage / 100, 2)
            : min(max(0.0, (float) $sale->discount_amount), $linesTotal);

        // Deterministic allocation: the same largest-remainder proration the sale itself used.
        $shares = app(SaleNormalizer::class)->prorateGlobalDiscount(
            $lines->map(fn ($l) => ['sub_total' => $l->sub_total])->values()->all(),
            $discount
        );
        $lineIndex = $lines->search(fn ($l) => (int) $l->id === (int) $line->id);
        $allocatedDiscount = round(array_sum($shares), 2);

        // The header must be explained by its lines, otherwise the discount/tax allocation is a guess.
        if (abs(($linesTotal - $allocatedDiscount + $shipping) - (float) $sale->total_amount) > max(0.05, 0.01 * $lines->count())) {
            $this->block('Total penjualan sumber tidak sesuai dengan rincian baris (diskon/pajak/ongkir). Alokasi nilai retur tidak dapat ditentukan; periksa data penjualan sumber.');
        }

        $units = $settlement->product_serial_number_id ? 1.0 : round((float) $detail->quantity, 3);
        if ($units <= 0) {
            $this->block('Kuantitas retur tidak valid.');
        }
        $lineQty = (float) $line->quantity;
        if ($lineQty <= 0 || (float) $line->sub_total < 0) {
            $this->block('Baris penjualan sumber tidak memiliki kuantitas/nilai yang valid.');
        }

        // ---- payments, credits, prior effects (all under the source-sale lock)
        $payments = SalePayment::query()->with('globalPosPaymentAllocation')->where('sale_id', $sale->id)->active()->lockForUpdate()->orderBy('id')->get();
        $paidGross = round((float) $payments->sum(fn ($p) => (float) $p->amount), 2)
            + round((float) DB::table('sale_payment_credit_applications as spca')
                ->join('sale_payments as sp', 'spca.sale_payment_id', '=', 'sp.id')
                ->where('sp.sale_id', $sale->id)->where('sp.status', SalePayment::STATUS_ACTIVE)->sum('spca.amount'), 2);
        $paidGross = round($paidGross, 2);

        foreach ($payments as $payment) {
            if ((float) $payment->amount <= 0) {
                $this->block("Pembayaran #{$payment->id} pada penjualan sumber bernilai nol/negatif. Perbaiki bukti pembayaran sebelum menyetujui pengembalian dana.");
            }
            $allocation = $payment->globalPosPaymentAllocation;
            if ($allocation && abs((float) $allocation->amount - (float) $payment->amount) > self::TOLERANCE) {
                $this->block("Pembayaran bersama #{$payment->id} tidak memiliki alokasi yang konsisten untuk penjualan ini. Lengkapi bukti alokasi pembayaran.");
            }
        }

        $adjustments = SaleReturnAdjustment::query()->where('sale_id', $sale->id)->orderBy('id')->get();
        $priorDebt = round((float) $adjustments->sum('debt_reduction'), 2);
        $priorRefund = round((float) $adjustments->sum('cash_refund'), 2);
        $linked = $adjustments->pluck('sale_return_item_settlement_id')->all();

        // Cash already returned on this sale outside the adjustment ledger (earlier returns): refunds, too.
        $legacyRefund = round((float) SaleReturnPayment::query()
            ->whereIn('sale_return_id', DB::table('sale_returns')->where('sale_id', $sale->id)->pluck('id')->all())
            ->where(function ($q) use ($linked) {
                $q->whereNull('sale_return_item_settlement_id');
                if ($linked) {
                    $q->orWhereNotIn('sale_return_item_settlement_id', $linked);
                }
            })
            ->sum('amount'), 2);

        // The header must agree with the evidence we are about to rely on.
        if (abs((float) $sale->paid_amount - ($paidGross - $priorRefund)) > self::TOLERANCE) {
            $this->block('Jumlah dibayar pada header penjualan sumber tidak sama dengan total pembayaran aktif. Rekonsiliasi pembayaran penjualan terlebih dahulu.');
        }

        $outstanding = max(0.0, round((float) $sale->total_amount - $paidGross - $priorDebt, 2));
        $refundable = max(0.0, round($paidGross - $priorRefund - $legacyRefund, 2));

        // ---- attributable value of the returned unit(s)
        $sameLine = $adjustments->where('sale_detail_id', $line->id);
        $priorUnits = round((float) $sameLine->sum('quantity'), 3);
        $priorValue = round((float) $sameLine->sum('committed_value'), 2);
        if ($priorUnits + $units - $lineQty > 0.0004) {
            $this->block('Kuantitas yang dikembalikan melebihi kuantitas pada baris penjualan sumber (termasuk retur sebelumnya).');
        }

        $lineNet = round((float) $line->sub_total - (float) ($shares[$lineIndex] ?? 0), 2);
        if ($lineNet < 0) {
            $this->block('Nilai bersih baris penjualan sumber negatif; alokasi tidak dapat ditentukan.');
        }

        if (abs($priorUnits + $units - $lineQty) <= 0.0004) {
            $value = round($lineNet - $priorValue, 2);          // last unit(s) take the rounding remainder
        } else {
            $value = round($lineNet * $units / $lineQty, 2);
        }
        if ($value < -0.0001 || $priorValue + $value - $lineNet > self::TOLERANCE) {
            $this->block('Nilai retur kumulatif melebihi nilai bersih baris penjualan sumber.');
        }
        $value = max(0.0, $value);

        $fingerprint = hash('sha256', json_encode([
            'sale' => [$sale->id, (string) $sale->total_amount, (string) $sale->discount_amount, (string) $sale->discount_percentage, (string) $sale->shipping_amount, (string) $sale->paid_amount, (string) $sale->due_amount],
            'line' => [$line->id, (string) $line->sub_total, (string) $line->quantity, $linesTotal],
            'units' => [$units, $settlement->product_serial_number_id],
            'payments' => $payments->map(fn ($p) => [$p->id, (string) $p->amount])->all(),
            'credits' => $paidGross,
            'adjustments' => $adjustments->map(fn ($a) => [$a->id, (string) $a->committed_value, (string) $a->debt_reduction, (string) $a->cash_refund])->all(),
            'legacy_refund' => $legacyRefund,
        ]));

        return [
            'value' => $value, 'units' => $units, 'sale' => $sale, 'line' => $line,
            'outstanding' => $outstanding, 'refundable' => $refundable,
            'fingerprint' => $fingerprint, 'prior_units' => $priorUnits,
            'serial_id' => $settlement->product_serial_number_id ? (int) $settlement->product_serial_number_id : null,
        ];
    }

    private function sourceLine(SaleReturnDetail $detail, Sale $sale): SaleDetails
    {
        if (! $detail->sale_detail_id) {
            $this->block('Baris retur tidak terhubung ke baris penjualan sumber. Nilai retur tidak dapat ditentukan.');
        }

        $line = SaleDetails::query()->whereKey($detail->sale_detail_id)->where('sale_id', $sale->id)->first();
        if (! $line) {
            $this->block('Baris penjualan sumber untuk retur ini tidak ditemukan atau berasal dari penjualan lain.');
        }

        return $line;
    }

    // ------------------------------------------------------------------ review (submit time)

    /**
     * Validates the requested nominal against the derived value and returns what to persist as the
     * reviewed evidence. A zero request never becomes a full refund.
     *
     * @return array{committed: float, derived: float, fingerprint: string}
     */
    public function review(SaleReturnItemSettlement $settlement, float $requested): array
    {
        $resolved = $this->resolve($settlement);
        $derived = $resolved['value'];
        $requested = round($requested, 2);

        if ($requested < 0) {
            $this->block('Nominal pengembalian tidak boleh negatif.');
        }
        if ($derived > 0 && $requested <= 0) {
            $this->block('Nominal pengembalian harus lebih dari nol. Nol tidak akan diganti dengan nilai penuh.');
        }
        if ($requested - $derived > self::TOLERANCE) {
            $this->block('Nominal melebihi nilai tagihan yang dapat dikaitkan dengan barang ini (' . number_format($derived, 2, ',', '.') . ').');
        }

        return ['committed' => $derived > 0 ? $requested : 0.0, 'derived' => $derived, 'fingerprint' => $resolved['fingerprint']];
    }

    // ------------------------------------------------------------------ commit (approval time)

    /** Debt first, then positive-only cash payout; evidence, payout and projections in the caller's transaction. */
    public function commit(SaleReturnItemSettlement $settlement): SaleReturnAdjustment
    {
        if (strtoupper((string) $settlement->method) !== SaleReturnDetail::METHOD_CASH_REFUND) {
            throw new \LogicException('Only cash returns have a financial adjustment.');
        }

        if (SaleReturnAdjustment::query()->where('sale_return_item_settlement_id', $settlement->id)->lockForUpdate()->exists()) {
            throw new SalesReturnStateException('Penyelesaian ini sudah memiliki penyesuaian keuangan.');
        }

        $resolved = $this->resolve($settlement);

        if (! $settlement->reviewed_fingerprint || $settlement->reviewed_value === null) {
            $this->block('Item ini belum ditinjau dengan bukti keuangan terbaru. Tolak dan ajukan ulang penyelesaian.');
        }
        // BaseModel upper-cases text columns: compare the hex digest case-insensitively.
        if (! hash_equals(strtolower((string) $settlement->reviewed_fingerprint), $resolved['fingerprint'])) {
            $this->block('Data keuangan sumber berubah sejak item ini ditinjau (pembayaran, retur lain atau nilai penjualan). Tolak dan ajukan ulang agar nilai ditinjau kembali; tidak ada dampak uang yang dicatat.');
        }

        $value = round((float) $settlement->reviewed_value, 2);
        if ($value - $resolved['value'] > self::TOLERANCE) {
            $this->block('Nilai yang ditinjau melebihi nilai tagihan yang dapat dikaitkan.');
        }

        $debt = round(min($value, $resolved['outstanding']), 2);
        $cash = round($value - $debt, 2);
        if ($cash - $resolved['refundable'] > self::TOLERANCE) {
            $this->block('Pengembalian tunai (' . number_format($cash, 2, ',', '.') . ') melebihi pembayaran terverifikasi yang masih dapat dikembalikan (' . number_format($resolved['refundable'], 2, ',', '.') . '). Tidak ada dampak uang yang dicatat.');
        }

        $return = $settlement->saleReturn;
        $payment = null;
        if ($cash > 0) {
            $payment = SaleReturnPayment::create([
                'sale_return_id' => $return->id,
                'amount' => $cash,
                'date' => now()->toDateString(),
                'reference' => 'SRPAY/' . $return->reference,
                'payment_method' => 'Cash Refund',
                'note' => 'Pengembalian tunai per baris',
                // Unique anchor: a replayed approval can never pay out the same row twice.
                'sale_return_item_settlement_id' => $settlement->id,
            ]);
        }

        $adjustment = SaleReturnAdjustment::create([
            'sale_return_item_settlement_id' => $settlement->id,
            'sale_return_id' => $return->id,
            'sale_id' => $resolved['sale']->id,
            'sale_detail_id' => $resolved['line']->id,
            'sale_return_detail_id' => $settlement->sale_return_detail_id,
            'product_serial_number_id' => $settlement->product_serial_number_id,
            'quantity' => $resolved['units'],
            'committed_value' => $value,
            'debt_reduction' => $debt,
            'cash_refund' => $cash,
            'source_fingerprint' => $resolved['fingerprint'],
            'policy_version' => self::POLICY_VERSION,
            'actor_id' => Auth::id(),
            'committed_at' => now(),
            'sale_return_payment_id' => $payment?->id,
        ]);

        // Projections, exactly once: the sale header is rebuilt from evidence (never incremented).
        $resolved['sale']->reconcileFromActivePayments();
        $this->refreshReturnHeader($return);

        $settlement->forceFill(['nominal' => $value])->save();

        return $adjustment;
    }

    /**
     * Return header, kept distinct: paid_amount = cash actually paid out; due_amount = obligation still
     * unsettled (list-price total less list value settled through adjustments and dispatched non-cash
     * lines); payment_status follows that obligation. Debt reduction is settled value, not cash.
     */
    public function refreshReturnHeader(SaleReturn $return): void
    {
        $cash = round((float) SaleReturnPayment::query()->where('sale_return_id', $return->id)->sum('amount'), 2);
        // The return header total is on the LIST-price basis (quantity x unit_price, before the sale's header
        // discount). Settled cash value must be measured on that same basis, otherwise a discounted return can
        // never reach zero due: the adjustment's committed value (net) is what money moved, its list value
        // (adjusted units x the return line's unit price) is what it settles of the header obligation.
        $settledCash = round((float) DB::table('sale_return_adjustments as a')
            ->join('sale_return_details as d', 'd.id', '=', 'a.sale_return_detail_id')
            ->where('a.sale_return_id', $return->id)
            ->selectRaw('COALESCE(SUM(a.quantity * d.unit_price), 0) as listed')
            ->value('listed'), 2);
        $settledGoods = round((float) SaleReturnItemSettlement::query()
            ->where('sale_return_id', $return->id)
            ->where('method', '!=', SaleReturnDetail::METHOD_CASH_REFUND)
            ->where('status', SaleReturnItemSettlement::STATUS_DISPATCHED)
            ->sum('nominal'), 2);
        $settled = round($settledCash + $settledGoods, 2);
        $due = max(0.0, round((float) $return->total_amount - $settled, 2));

        $return->forceFill([
            'paid_amount' => $cash,
            'due_amount' => $due,
            'payment_status' => $due <= 0.009 ? 'Paid' : ($settled > 0 ? 'Partial' : $return->payment_status),
        ])->save();
    }

    /** @return array{value: float, debt: float, cash: float} per-return breakdown for displays */
    public function returnTotals(SaleReturn $return): array
    {
        $rows = SaleReturnAdjustment::query()->where('sale_return_id', $return->id)->get();

        return [
            'value' => round((float) $rows->sum('committed_value'), 2),
            'debt' => round((float) $rows->sum('debt_reduction'), 2),
            'cash' => round((float) $rows->sum('cash_refund'), 2),
        ];
    }

    private function block(string $message): void
    {
        throw new SalesReturnFinancialBlockedException($message);
    }
}
