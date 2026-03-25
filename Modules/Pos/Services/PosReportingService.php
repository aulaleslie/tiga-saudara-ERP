<?php

namespace Modules\Pos\Services;

use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSupervisorApproval;

class PosReportingService
{
    /**
     * @return array<int, array{date:string, transactions_count:int, subtotal:float, discount_total:float, tax_total:float, grand_total:float, cash_total:float, non_cash_total:float}>
     * Task 5.4: Updated to aggregate cash/non-cash from payment entries for multi-payment support
     */
    public function getDailySalesSummary(int $settingId, string $dateFrom, string $dateTo): array
    {
        $query = PosCheckout::query()
            ->select([
                DB::raw('DATE(finalized_at) as date'),
                DB::raw('COUNT(pos_checkouts.id) as transactions_count'),
                DB::raw('SUM(subtotal) as subtotal'),
                DB::raw('SUM(discount_total) as discount_total'),
                DB::raw('SUM(tax_total) as tax_total'),
                DB::raw('SUM(grand_total) as grand_total'),
                // Task 5.4: Use payment entries for cash/non-cash calculation
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
                    ), 0)) as cash_total
                SQL),
                DB::raw(<<<'SQL'
                    SUM(COALESCE((
                        SELECT SUM(pcp.amount_minor_units / 100)
                        FROM pos_checkout_payments AS pcp
                        INNER JOIN payment_methods AS pm ON pcp.payment_method_id = pm.id
                        WHERE pcp.pos_checkout_id = pos_checkouts.id AND pm.is_cash = 0
                    ), 0)) as non_cash_total
                SQL),
            ])
            ->where('pos_checkouts.setting_id', $settingId)
            ->where('pos_checkouts.status', PosCheckout::STATUS_POSTED)
            ->whereDate('pos_checkouts.finalized_at', '>=', $dateFrom)
            ->whereDate('pos_checkouts.finalized_at', '<=', $dateTo)
            ->groupBy(DB::raw('DATE(pos_checkouts.finalized_at)'))
            ->orderBy('date', 'desc')
            ->get();

        return $query->map(function ($row) {
            return [
                'date' => $row->date,
                'transactions_count' => (int) $row->transactions_count,
                'subtotal' => round((float) $row->subtotal, 2),
                'discount_total' => round((float) $row->discount_total, 2),
                'tax_total' => round((float) $row->tax_total, 2),
                'grand_total' => round((float) $row->grand_total, 2),
                'cash_total' => round((float) $row->cash_total, 2),
                'non_cash_total' => round((float) $row->non_cash_total, 2),
            ];
        })->toArray();
    }

    /**
     * @return array<int, array{cashier_name:string, transactions_count:int, grand_total:float, cash_total:float, non_cash_total:float, average_basket:float}>
     * Task 5.4: Use payment entries for cash/non-cash aggregation
     */
    public function getCashierSummary(int $settingId, string $dateFrom, string $dateTo): array
    {
        $query = PosCheckout::query()
            ->with('cashier:id,name')
            ->select([
                'pos_checkouts.cashier_user_id',
                DB::raw('COUNT(pos_checkouts.id) as transactions_count'),
                DB::raw('SUM(pos_checkouts.grand_total) as grand_total'),
                // Task 5.4: Aggregate cash/non-cash from payment entries
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
                    ), 0)) as cash_total
                SQL),
                DB::raw(<<<'SQL'
                    SUM(COALESCE((
                        SELECT SUM(pcp.amount_minor_units / 100)
                        FROM pos_checkout_payments AS pcp
                        INNER JOIN payment_methods AS pm ON pcp.payment_method_id = pm.id
                        WHERE pcp.pos_checkout_id = pos_checkouts.id AND pm.is_cash = 0
                    ), 0)) as non_cash_total
                SQL),
            ])
            ->where('pos_checkouts.setting_id', $settingId)
            ->where('pos_checkouts.status', PosCheckout::STATUS_POSTED)
            ->whereDate('pos_checkouts.finalized_at', '>=', $dateFrom)
            ->whereDate('pos_checkouts.finalized_at', '<=', $dateTo)
            ->groupBy('pos_checkouts.cashier_user_id')
            ->orderBy('grand_total', 'desc')
            ->get();

        return $query->map(function ($row) {
            $grandTotal = (float) $row->grand_total;
            $count = (int) $row->transactions_count;
            return [
                'cashier_name' => $row->cashier ? $row->cashier->name : 'Unknown',
                'transactions_count' => $count,
                'grand_total' => round($grandTotal, 2),
                'cash_total' => round((float) $row->cash_total, 2),
                'non_cash_total' => round((float) $row->non_cash_total, 2),
                'average_basket' => $count > 0 ? round($grandTotal / $count, 2) : 0,
            ];
        })->toArray();
    }

    /**
     * @return array<int, array{payment_method_label:string, transactions_count:int, grand_total:float}>
     * Task 5.4: Aggregate payment methods from pos_checkout_payments for multi-payment support.
     * This means a single checkout can contribute to multiple payment methods if it uses multiple payment types.
     */
    public function getPaymentMethodSummary(int $settingId, string $dateFrom, string $dateTo): array
    {
        // Task 5.4: Query from payment_entries which may have multiple rows per checkout
        $query = DB::table('pos_checkout_payments')
            ->join('pos_checkouts', 'pos_checkout_payments.pos_checkout_id', '=', 'pos_checkouts.id')
            ->join('payment_methods', 'pos_checkout_payments.payment_method_id', '=', 'payment_methods.id')
            ->select([
                DB::raw("payment_methods.name as payment_method_label"),
                DB::raw('COUNT(DISTINCT pos_checkouts.id) as transactions_count'),
                DB::raw('SUM(pos_checkout_payments.amount_minor_units / 100) as grand_total'),
            ])
            ->where('pos_checkouts.setting_id', $settingId)
            ->where('pos_checkouts.status', PosCheckout::STATUS_POSTED)
            ->whereDate('pos_checkouts.finalized_at', '>=', $dateFrom)
            ->whereDate('pos_checkouts.finalized_at', '<=', $dateTo)
            ->groupBy('payment_methods.id', 'payment_methods.name')
            ->orderBy('grand_total', 'desc')
            ->get();

        return $query->map(function ($row) {
            return [
                'payment_method_label' => $row->payment_method_label,
                'transactions_count' => (int) $row->transactions_count,
                'grand_total' => round((float) $row->grand_total, 2),
            ];
        })->toArray();
    }

    /**
     * @return array<int, array{product_code:string, product_name:string, quantity_sold:float, gross_revenue:float}>
     */
    public function getItemSalesSummary(int $settingId, string $dateFrom, string $dateTo): array
    {
        $query = DB::table('pos_checkouts')
            ->join('sales', 'pos_checkouts.sale_id', '=', 'sales.id')
            ->join('sale_details', 'sales.id', '=', 'sale_details.sale_id')
            ->select([
                'sale_details.product_code',
                'sale_details.product_name',
                DB::raw('SUM(sale_details.quantity) as quantity_sold'),
                DB::raw('SUM(sale_details.sub_total) as gross_revenue'),
            ])
            ->where('pos_checkouts.setting_id', $settingId)
            ->where('pos_checkouts.status', PosCheckout::STATUS_POSTED)
            ->whereDate('pos_checkouts.finalized_at', '>=', $dateFrom)
            ->whereDate('pos_checkouts.finalized_at', '<=', $dateTo)
            ->groupBy('sale_details.product_id', 'sale_details.product_code', 'sale_details.product_name')
            ->orderBy('gross_revenue', 'desc')
            ->get();

        return $query->map(function ($row) {
            return [
                'product_code' => $row->product_code,
                'product_name' => $row->product_name,
                'quantity_sold' => round((float) $row->quantity_sold, 2), // Some ERPs support decimal units, safe cast to float
                'gross_revenue' => round((float) $row->gross_revenue, 2),
            ];
        })->toArray();
    }

    /**
     * @return array<int, array{action_type:string, approval_result:string, requester_name:string, approver_name:string, reason:string|null, occurred_at:string}>
     */
    public function getSupervisorApprovalLog(int $settingId, string $dateFrom, string $dateTo): array
    {
        $query = PosSupervisorApproval::query()
            ->with(['requester:id,name', 'approver:id,name'])
            ->where('setting_id', $settingId)
            ->whereDate('occurred_at', '>=', $dateFrom)
            ->whereDate('occurred_at', '<=', $dateTo)
            ->orderBy('occurred_at', 'desc')
            ->get();

        return $query->map(function ($row) {
            return [
                'action_type' => $row->action_type,
                'approval_result' => $row->approval_result,
                'requester_name' => $row->requester ? $row->requester->name : 'Unknown',
                'approver_name' => $row->approver ? $row->approver->name : 'Unknown',
                'reason' => $row->reason,
                'occurred_at' => $row->occurred_at->toISOString(),
            ];
        })->toArray();
    }
}
