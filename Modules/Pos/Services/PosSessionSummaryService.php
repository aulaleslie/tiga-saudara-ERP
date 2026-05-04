<?php

namespace Modules\Pos\Services;

use DomainException;
use Modules\Pos\Entities\PosSession;

class PosSessionSummaryService
{
    public function __construct(private readonly PosSessionExpectedCashCalculator $expectedCashCalculator)
    {
    }

    /**
     * @return array{
     *     session_id:int,
     *     status:string,
     *     cashier_user_id:int,
     *     cashier_name:string,
     *     terminal_id:int,
     *     expected_cash_total:float,
     *     cached_expected_cash_total:float,
     *     threshold_value:float,
     *     threshold_source:string,
     *     is_threshold_breached:bool,
     *     events_count:int,
     *     calculated_at:string,
     *     sales_total:float,
     *     duration:string|null,
     *     terminal_code:string|null,
     *     terminal_name:string|null,
     *     total_transactions_count:int,
     *     total_transactions_amount:float,
     *     transactions:array,
     *     cash_events:array
     * }
     */
    public function getSummary(int $sessionId, int $actorUserId, int $settingId): array
    {
        if ($actorUserId <= 0) {
            throw new DomainException('Actor user is required.');
        }

        $session = PosSession::query()
            ->with(['cashier', 'terminal.policy', 'cashEvents.performer', 'cashEvents.approver'])
            ->where('id', $sessionId)
            ->where('setting_id', $settingId)
            ->first();

        if (! $session) {
            throw new DomainException('POS session not found for current setting.');
        }

        $calculation = $this->expectedCashCalculator->calculate($session->id);

        $thresholdValue = $session->terminal?->policy?->cash_threshold;
        $thresholdSource = 'terminal_policy';

        if ($thresholdValue === null) {
            $thresholdValue = (float) config('pos.default_cash_threshold', 10000000);
            $thresholdSource = 'default_config';
        }

        $expectedCashTotal = round((float) $calculation['expected_cash_total'], 2);
        $threshold = round((float) $thresholdValue, 2);

        // Load transactions based on session type (terminal vs non-terminal)
        $isNonTerminal = $session->terminal_id === null;

        if ($isNonTerminal) {
            // For non-terminal sessions, load PosTransaction records
            $transactionQuery = \Modules\Pos\Entities\PosTransaction::query()
                ->with(['owner'])
                ->where('source_pos_session_id', $sessionId)
                ->orderBy('created_at', 'desc');

            $transactions = (clone $transactionQuery)
                ->limit(50)
                ->get()
                ->map(function ($transaction) {
                    $amount = $transaction->snapshot_totals['grand_total'] ?? 0;
                    return [
                        'id' => (int) $transaction->id,
                        'code' => (string) $transaction->code,
                        'amount' => round((float) $amount, 2),
                        'owner_name' => $transaction->owner?->name ?? 'Unknown',
                        'timestamp' => $transaction->created_at?->toIso8601String(),
                    ];
                })
                ->values()
                ->toArray();

            // Calculate transaction aggregates for non-terminal
            $totalTransactionsCount = (clone $transactionQuery)->count();
            $totalTransactionsAmount = (clone $transactionQuery)
                ->get()
                ->sum(function ($tx) {
                    return $tx->snapshot_totals['grand_total'] ?? 0;
                });
        } else {
            // For terminal sessions, load PosCheckout records (existing logic)
            $checkoutQuery = \Modules\Pos\Entities\PosCheckout::query()
                ->with(['cashier', 'paymentMethod', 'payments.paymentMethod'])
                ->where('pos_session_id', $sessionId)
                ->where('status', \Modules\Pos\Entities\PosCheckout::STATUS_POSTED)
                ->orderBy('finalized_at', 'desc');

            // Compute change-aware cash totals from posted checkout payment rows
            $cashTenderedTotal = \Modules\Pos\Entities\PosCheckoutPayment::query()
                ->join('pos_checkouts', 'pos_checkout_payments.pos_checkout_id', '=', 'pos_checkouts.id')
                ->join('payment_methods', 'pos_checkout_payments.payment_method_id', '=', 'payment_methods.id')
                ->where('pos_checkouts.pos_session_id', $sessionId)
                ->where('pos_checkouts.status', \Modules\Pos\Entities\PosCheckout::STATUS_POSTED)
                ->where('payment_methods.is_cash', true)
                ->sum('pos_checkout_payments.amount_minor_units') / 100;

            $changeTotalRaw = (clone $checkoutQuery)->sum('change_total');


            $transactions = (clone $checkoutQuery)
                ->limit(50)
                ->get()
                ->map(function ($checkout) {
                    $paymentMethods = $checkout->payments->map(fn ($p) => $p->paymentMethod?->name)
                        ->filter()
                        ->unique()
                        ->implode(', ');

                    if ($paymentMethods === '') {
                        $paymentMethods = $checkout->paymentMethod?->name ?? 'Unknown';
                    }

                    return [
                        'id' => (int) $checkout->id,
                        'transaction_id' => $checkout->pos_transaction_id ? (int) $checkout->pos_transaction_id : null,
                        'receipt_number' => (string) $checkout->receipt_number,
                        'amount' => round((float) $checkout->grand_total, 2),
                        'payment_method' => $paymentMethods,
                        'cashier' => $checkout->cashier?->name ?? 'Unknown',
                        'timestamp' => $checkout->finalized_at?->toIso8601String(),
                    ];
                })
                ->values()
                ->toArray();

            // Calculate transaction aggregates (total for this session)
            $totalTransactionsCount = (clone $checkoutQuery)->count();
            $totalTransactionsAmount = (clone $checkoutQuery)->sum('grand_total');
        }

        // Calculate sales total
        $salesTotal = round((float) $totalTransactionsAmount, 2);

        // Terminal-only cash totals (rounded for output)
        $cashTenderedTotalRounded = isset($cashTenderedTotal) ? round((float) $cashTenderedTotal, 2) : null;
        $changeTotalRounded = isset($changeTotalRaw) ? round((float) $changeTotalRaw, 2) : null;
        $netCashSalesTotalRounded = ($cashTenderedTotalRounded !== null && $changeTotalRounded !== null)
            ? round($cashTenderedTotalRounded - $changeTotalRounded, 2)
            : null;

        // Load cash event timeline
        $cashEvents = $session->cashEvents
            ->sortByDesc('occurred_at')
            ->map(function ($event) {
                return [
                    'id' => (int) $event->id,
                    'event_type' => (string) $event->event_type,
                    'amount' => round((float) $event->amount, 2),
                    'direction' => (string) $event->direction,
                    'timestamp' => $event->occurred_at?->toIso8601String(),
                    'performer' => $event->performer?->name ?? 'Unknown',
                    'approver' => $event->approver?->name ?? null,
                    'notes' => $event->notes ?? null,
                ];
            })
            ->values()
            ->toArray();

        return [
            'session_id' => (int) $session->id,
            'status' => (string) $session->status,
            'cashier_user_id' => (int) $session->cashier_user_id,
            'cashier_name' => $session->cashier?->name ?? 'Unknown',
            'terminal_id' => $session->terminal_id,
            'expected_cash_total' => $expectedCashTotal,
            'cached_expected_cash_total' => round((float) $calculation['cached_expected_cash_total'], 2),
            'threshold_value' => $threshold,
            'threshold_source' => $thresholdSource,
            'is_threshold_breached' => $expectedCashTotal > $threshold,
            'events_count' => (int) $calculation['events_count'],
            'calculated_at' => (string) $calculation['calculated_at'],
            'sales_total' => $salesTotal,
            'duration' => $session->duration,
            'terminal_code' => $session->terminal?->code,
            'terminal_name' => $session->terminal?->name,
            'total_transactions_count' => (int) $totalTransactionsCount,
            'total_transactions_amount' => round((float) $totalTransactionsAmount, 2),
            'transactions' => $transactions,
            'cash_events' => $cashEvents,
            'cash_tendered_total' => $cashTenderedTotalRounded,
            'change_total' => $changeTotalRounded,
            'net_cash_sales_total' => $netCashSalesTotalRounded,
        ];
    }
}
