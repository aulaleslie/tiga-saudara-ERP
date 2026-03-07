<?php

namespace Modules\Pos\Services;

use DateTime;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;

class PosReconciliationService
{
    /**
     * @return array<int, array>
     */
    public function getSessionReconciliation(int $settingId, string $dateFrom, string $dateTo): array
    {
        $sessions = PosSession::query()
            ->with(['cashier:id,name', 'terminal:id,name'])
            ->where('setting_id', $settingId)
            ->where('status', PosSession::STATUS_CLOSED)
            ->whereDate('opened_at', '>=', $dateFrom)
            ->whereDate('opened_at', '<=', $dateTo)
            ->orderBy('opened_at', 'desc')
            ->get();

        $sessionIds = $sessions->pluck('id')->toArray();

        // 1. Get POS Checkout Totals
        $checkoutTotals = PosCheckout::query()
            ->leftJoin('payment_methods', 'pos_checkouts.payment_method_id', '=', 'payment_methods.id')
            ->select([
                'pos_checkouts.pos_session_id',
                DB::raw('SUM(pos_checkouts.grand_total) as pos_checkout_total'),
                DB::raw("SUM(CASE WHEN payment_methods.is_cash = 1 THEN pos_checkouts.grand_total WHEN payment_methods.id IS NULL AND pos_checkouts.payment_method_code = 'cash' THEN pos_checkouts.grand_total ELSE 0 END) as pos_cash_sales_total"),
                DB::raw("SUM(CASE WHEN payment_methods.is_cash = 0 THEN pos_checkouts.grand_total WHEN payment_methods.id IS NULL AND pos_checkouts.payment_method_code != 'cash' THEN pos_checkouts.grand_total ELSE 0 END) as pos_non_cash_sales_total")
            ])
            ->whereIn('pos_checkouts.pos_session_id', $sessionIds)
            ->where('pos_checkouts.status', PosCheckout::STATUS_POSTED)
            ->groupBy('pos_checkouts.pos_session_id')
            ->get()
            ->keyBy('pos_session_id');

        // 2. Get Safe Drop Totals
        $safeDropTotals = PosSessionCashEvent::query()
            ->select([
                'pos_session_id',
                DB::raw('SUM(amount) as safe_drop_total')
            ])
            ->whereIn('pos_session_id', $sessionIds)
            ->where('event_type', PosSessionCashEvent::EVENT_SAFE_DROP_OUT)
            ->groupBy('pos_session_id')
            ->get()
            ->keyBy('pos_session_id');

        // 3. Get Posted Sales Totals (using sale_id from checkouts)
        $salesQuery = DB::table('pos_checkouts')
            ->join('sales', 'pos_checkouts.sale_id', '=', 'sales.id')
            ->select([
                'pos_checkouts.pos_session_id',
                DB::raw('SUM(sales.total_amount) as posted_sales_total')
            ])
            ->whereIn('pos_checkouts.pos_session_id', $sessionIds)
            ->where('pos_checkouts.status', PosCheckout::STATUS_POSTED)
            ->groupBy('pos_checkouts.pos_session_id')
            ->get()
            ->keyBy('pos_session_id');

        // 4. Get Posted Payments Totals (using sale_payment_id from checkouts)
        $paymentsQuery = DB::table('pos_checkouts')
            ->join('sale_payments', 'pos_checkouts.sale_payment_id', '=', 'sale_payments.id')
            ->select([
                'pos_checkouts.pos_session_id',
                DB::raw('SUM(sale_payments.amount) as posted_payments_total')
            ])
            ->whereIn('pos_checkouts.pos_session_id', $sessionIds)
            ->where('pos_checkouts.status', PosCheckout::STATUS_POSTED)
            ->groupBy('pos_checkouts.pos_session_id')
            ->get()
            ->keyBy('pos_session_id');

        $result = [];

        foreach ($sessions as $session) {
            $cTotals = $checkoutTotals->get($session->id);
            $sdTotals = $safeDropTotals->get($session->id);
            $sTotals = $salesQuery->get($session->id);
            $pTotals = $paymentsQuery->get($session->id);

            $posCheckoutTotal = $cTotals ? (float) $cTotals->pos_checkout_total : 0.0;
            $posCashSalesTotal = $cTotals ? (float) $cTotals->pos_cash_sales_total : 0.0;
            $posNonCashSalesTotal = $cTotals ? (float) $cTotals->pos_non_cash_sales_total : 0.0;
            $safeDropTotal = $sdTotals ? (float) $sdTotals->safe_drop_total : 0.0;
            
            $postedSalesTotal = $sTotals ? (float) $sTotals->posted_sales_total : 0.0;
            $postedPaymentsTotal = $pTotals ? (float) $pTotals->posted_payments_total : 0.0;

            $hasMismatch = false;
            $mismatchDetails = [];

            if (round($posCheckoutTotal, 2) !== round($postedSalesTotal, 2)) {
                $hasMismatch = true;
                $mismatchDetails[] = "POS totals do not match posted sales (POS: {$posCheckoutTotal}, Sales: {$postedSalesTotal})";
            }

            if (round($posCheckoutTotal, 2) !== round($postedPaymentsTotal, 2)) {
                $hasMismatch = true;
                $mismatchDetails[] = "POS totals do not match posted payments (POS: {$posCheckoutTotal}, Payments: {$postedPaymentsTotal})";
            }

            $result[] = [
                'session_id' => $session->id,
                'cashier_name' => $session->cashier ? $session->cashier->name : 'Unknown',
                'terminal_name' => $session->terminal ? $session->terminal->name : 'Unknown',
                'opened_at' => $session->opened_at ? $session->opened_at->toISOString() : null,
                'closed_at' => $session->closed_at ? $session->closed_at->toISOString() : null,
                'opening_float' => round((float) $session->opening_float_total, 2),
                'expected_cash' => round((float) $session->expected_cash_total, 2),
                'counted_cash' => round((float) $session->counted_cash_total, 2),
                'variance' => round((float) $session->variance_total, 2),
                'pos_checkout_total' => round($posCheckoutTotal, 2),
                'pos_cash_sales_total' => round($posCashSalesTotal, 2),
                'pos_non_cash_sales_total' => round($posNonCashSalesTotal, 2),
                'posted_sales_total' => round($postedSalesTotal, 2),
                'posted_payments_total' => round($postedPaymentsTotal, 2),
                'safe_drop_total' => round($safeDropTotal, 2),
                'has_mismatch' => $hasMismatch,
                'mismatch_details' => $hasMismatch ? implode('; ', $mismatchDetails) : null,
            ];
        }

        return $result;
    }
}
