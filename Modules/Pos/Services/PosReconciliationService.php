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
        // Task 5.5: Use payment entries for cash/non-cash source of truth for multi-payment support
        $checkoutTotals = PosCheckout::query()
            ->select([
                'pos_checkouts.pos_session_id',
                DB::raw('SUM(pos_checkouts.grand_total) as pos_checkout_total'),
                // Task 5.5: Aggregate cash/non-cash from payment entries
                // Deduct change_total from cash_total to show actual cash received
                DB::raw(<<<'SQL'
                    SUM(COALESCE((
                        SELECT SUM(pcp.amount_minor_units / 100)
                        FROM pos_checkout_payments AS pcp
                        INNER JOIN payment_methods AS pm ON pcp.payment_method_id = pm.id
                        WHERE pcp.pos_checkout_id = pos_checkouts.id AND pm.is_cash = 1
                    ), 0)) -
                    SUM(COALESCE((
                        SELECT pc.change_total
                        FROM pos_checkouts pc
                        WHERE pc.id = pos_checkouts.id
                        LIMIT 1
                    ), 0)) as pos_cash_sales_total
                SQL),
                DB::raw(<<<'SQL'
                    SUM(COALESCE((
                        SELECT SUM(pcp.amount_minor_units / 100)
                        FROM pos_checkout_payments AS pcp
                        INNER JOIN payment_methods AS pm ON pcp.payment_method_id = pm.id
                        WHERE pcp.pos_checkout_id = pos_checkouts.id AND pm.is_cash = 0
                    ), 0)) as pos_non_cash_sales_total
                SQL),
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

        // 3. Get Posted Sales Totals (split-aware path via pos_checkout_sales)
        $mappedSalesTotals = DB::table('pos_checkouts')
            ->join('pos_checkout_sales', 'pos_checkouts.id', '=', 'pos_checkout_sales.pos_checkout_id')
            ->leftJoin('sales', 'pos_checkout_sales.sale_id', '=', 'sales.id')
            ->select([
                'pos_checkouts.pos_session_id',
                DB::raw('SUM(COALESCE(sales.total_amount, pos_checkout_sales.grand_total)) as posted_sales_total')
            ])
            ->whereIn('pos_checkouts.pos_session_id', $sessionIds)
            ->where('pos_checkouts.status', PosCheckout::STATUS_POSTED)
            ->groupBy('pos_checkouts.pos_session_id')
            ->get()
            ->keyBy('pos_session_id');

        // Legacy fallback for pre-split rows without mapping records.
        $legacySalesTotals = DB::table('pos_checkouts')
            ->leftJoin('pos_checkout_sales', 'pos_checkouts.id', '=', 'pos_checkout_sales.pos_checkout_id')
            ->join('sales', 'pos_checkouts.sale_id', '=', 'sales.id')
            ->select([
                'pos_checkouts.pos_session_id',
                DB::raw('SUM(sales.total_amount) as posted_sales_total')
            ])
            ->whereIn('pos_checkouts.pos_session_id', $sessionIds)
            ->where('pos_checkouts.status', PosCheckout::STATUS_POSTED)
            ->whereNull('pos_checkout_sales.id')
            ->groupBy('pos_checkouts.pos_session_id')
            ->get()
            ->keyBy('pos_session_id');

        // 4. Get Posted Payments Totals (split-aware path via pos_checkout_sales)
        $mappedPaymentsTotals = DB::table('pos_checkouts')
            ->join('pos_checkout_sales', 'pos_checkouts.id', '=', 'pos_checkout_sales.pos_checkout_id')
            ->leftJoin('sale_payments', 'pos_checkout_sales.sale_payment_id', '=', 'sale_payments.id')
            ->select([
                'pos_checkouts.pos_session_id',
                DB::raw('SUM(COALESCE(sale_payments.amount, pos_checkout_sales.paid_total)) as posted_payments_total')
            ])
            ->whereIn('pos_checkouts.pos_session_id', $sessionIds)
            ->where('pos_checkouts.status', PosCheckout::STATUS_POSTED)
            ->groupBy('pos_checkouts.pos_session_id')
            ->get()
            ->keyBy('pos_session_id');

        $legacyPaymentsTotals = DB::table('pos_checkouts')
            ->leftJoin('pos_checkout_sales', 'pos_checkouts.id', '=', 'pos_checkout_sales.pos_checkout_id')
            ->join('sale_payments', 'pos_checkouts.sale_payment_id', '=', 'sale_payments.id')
            ->select([
                'pos_checkouts.pos_session_id',
                DB::raw('SUM(sale_payments.amount) as posted_payments_total')
            ])
            ->whereIn('pos_checkouts.pos_session_id', $sessionIds)
            ->where('pos_checkouts.status', PosCheckout::STATUS_POSTED)
            ->whereNull('pos_checkout_sales.id')
            ->groupBy('pos_checkouts.pos_session_id')
            ->get()
            ->keyBy('pos_session_id');

        $result = [];

        foreach ($sessions as $session) {
            $cTotals = $checkoutTotals->get($session->id);
            $sdTotals = $safeDropTotals->get($session->id);
            $mappedSales = $mappedSalesTotals->get($session->id);
            $legacySales = $legacySalesTotals->get($session->id);
            $mappedPayments = $mappedPaymentsTotals->get($session->id);
            $legacyPayments = $legacyPaymentsTotals->get($session->id);

            $posCheckoutTotal = $cTotals ? (float) $cTotals->pos_checkout_total : 0.0;
            $posCashSalesTotal = $cTotals ? (float) $cTotals->pos_cash_sales_total : 0.0;
            $posNonCashSalesTotal = $cTotals ? (float) $cTotals->pos_non_cash_sales_total : 0.0;
            $safeDropTotal = $sdTotals ? (float) $sdTotals->safe_drop_total : 0.0;
            
            $postedSalesTotal = ($mappedSales ? (float) $mappedSales->posted_sales_total : 0.0)
                + ($legacySales ? (float) $legacySales->posted_sales_total : 0.0);
            $postedPaymentsTotal = ($mappedPayments ? (float) $mappedPayments->posted_payments_total : 0.0)
                + ($legacyPayments ? (float) $legacyPayments->posted_payments_total : 0.0);

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
