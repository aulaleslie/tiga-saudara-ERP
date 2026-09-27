<?php

namespace Modules\Pos\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosTransaction;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;

class PosSettlementProjectionService
{
    public const PAYMENT_STATUS_PAID = 'Paid';
    public const PAYMENT_STATUS_PARTIAL = 'Partial';
    public const PAYMENT_STATUS_UNPAID = 'Unpaid';

    public const PAYMENT_STATUS_LABELS = [
        self::PAYMENT_STATUS_PAID => 'Lunas',
        self::PAYMENT_STATUS_PARTIAL => 'Dibayar Sebagian',
        self::PAYMENT_STATUS_UNPAID => 'Belum Dibayar',
    ];

    protected PosReachableSalesResolver $resolver;

    public function __construct(?PosReachableSalesResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new PosReachableSalesResolver();
    }

    /**
     * Eager-load spec for reachable Sales (split + inline) with active-settlement data,
     * for use with PosTransaction::with(...) or PosTransaction query ->with(...).
     *
     * The 'sale' relation on PosCheckoutSale/PosCheckout is constrained with
     * withArchived() so an archived reachable Sale is still loaded (never silently
     * dropped by ArchivingScope) — PosReachableSalesResolver and the eligibility
     * checks in PosSettlementProjectionService::project() depend on seeing it to
     * flag the transaction as not payable, rather than treating it as unmapped.
     * Reusing this eager-loaded relation lets the resolver and projection avoid an
     * additional query per POS transaction when iterating a list.
     *
     * @return array<string, \Closure>
     */
    public static function reachableSalesEagerLoad(): array
    {
        $saleConstraint = function ($query) {
            $query->withArchived()->with(['salePayments.creditApplications', 'tenantSetting']);
        };

        return [
            'completedCheckout.checkoutSales.sale' => $saleConstraint,
            'completedCheckout.sale' => $saleConstraint,
        ];
    }

    /**
     * Resolve reachable sales.
     */
    public function resolveSales(PosTransaction|PosCheckout $record): Collection
    {
        return $this->resolver->resolveSales($record);
    }

    /**
     * Run diagnostics on mapping.
     */
    public function diagnose(PosTransaction|PosCheckout $record): array
    {
        return $this->resolver->resolveWithDiagnostics($record);
    }

    /**
     * Project settlement for a single POS transaction.
     *
     * @param PosTransaction $transaction
     * @return array{
     *     total_amount: float,
     *     effective_paid: float,
     *     live_due: float,
     *     payment_status: string,
     *     payment_status_label: string,
     *     effective_due_date: ?string,
     *     is_overdue: bool,
     *     overdue_amount: float,
     *     has_recent_payment_30d: bool,
     *     is_payable: bool,
     *     is_valid: bool,
     *     anomalies: array<string>,
     *     sales: Collection<int, Sale>
     * }
     */
    public function project(PosTransaction $transaction): array
    {
        $diagnostics = $this->resolver->resolveWithDiagnostics($transaction);
        $sales = $diagnostics['sales'];
        $isValid = $diagnostics['is_valid'] && $sales->isNotEmpty();
        $anomalies = $diagnostics['anomalies'];

        if (!$isValid || $sales->isEmpty()) {
            $checkout = $transaction->completedCheckout;
            $totalAmount = $checkout ? (float) $checkout->grand_total : 0.0;

            return [
                'total_amount' => $totalAmount,
                'effective_paid' => 0.0,
                'live_due' => $totalAmount,
                'payment_status' => self::PAYMENT_STATUS_UNPAID,
                'payment_status_label' => self::PAYMENT_STATUS_LABELS[self::PAYMENT_STATUS_UNPAID],
                'effective_due_date' => null,
                'is_overdue' => false,
                'overdue_amount' => 0.0,
                'has_recent_payment_30d' => false,
                'is_payable' => false,
                'is_valid' => false,
                'anomalies' => $anomalies,
                'sales' => $sales,
            ];
        }

        $totalAmount = 0.0;
        $effectivePaid = 0.0;
        $liveDue = 0.0;
        $overdueAmount = 0.0;
        $earliestOutstandingDueDate = null;
        $hasRecentPayment30d = false;

        $today = Carbon::today()->toDateString();
        $thirtyDaysAgo = Carbon::today()->subDays(30)->toDateString();

        foreach ($sales as $sale) {
            // Use the eager-loaded salePayments (and nested creditApplications) relation
            // when available to avoid an additional query per Sale for both the
            // effective-paid amount and the recent-payment check. Callers are expected to
            // eager-load via self::reachableSalesEagerLoad() for this fast path to apply;
            // otherwise this falls back to the query-per-Sale accessor.
            $paymentsRelationLoaded = $sale->relationLoaded('salePayments');
            $activePayments = $paymentsRelationLoaded
                ? $sale->salePayments->where('status', SalePayment::STATUS_ACTIVE)
                : null;

            $creditApplicationsLoadable = $paymentsRelationLoaded
                && $activePayments->every(fn ($payment) => $payment->relationLoaded('creditApplications'));

            $saleTotal = (float) $sale->total_amount;

            if ($paymentsRelationLoaded && $creditApplicationsLoadable) {
                $monetarySum = (float) $activePayments->sum('amount');
                $creditSum = (float) $activePayments->sum(fn ($payment) => $payment->creditApplications->sum('amount'));
                $salePaid = round($monetarySum + $creditSum, 2);
            } else {
                $salePaid = (float) $sale->getEffectivePaidAmount();
            }

            $saleDue = max(0.0, round($saleTotal - $salePaid, 2));

            $totalAmount += $saleTotal;
            $effectivePaid += $salePaid;
            $liveDue += $saleDue;

            if ($saleDue > 0) {
                $dueDate = $sale->due_date ? Carbon::parse($sale->due_date)->toDateString() : null;
                if ($dueDate) {
                    if ($earliestOutstandingDueDate === null || $dueDate < $earliestOutstandingDueDate) {
                        $earliestOutstandingDueDate = $dueDate;
                    }
                    if ($dueDate < $today) {
                        $overdueAmount += $saleDue;
                    }
                }
            }

            // Check recent 30-day active payments on child sale
            if ($activePayments !== null) {
                $recentActivePayment = $activePayments->contains(function ($payment) use ($thirtyDaysAgo, $today) {
                    $paymentDate = $payment->getRawOriginal('date') ?? $payment->date;
                    $paymentDate = Carbon::parse($paymentDate)->toDateString();
                    return $paymentDate >= $thirtyDaysAgo && $paymentDate <= $today;
                });
            } else {
                $recentActivePayment = SalePayment::where('sale_id', $sale->id)
                    ->where('status', SalePayment::STATUS_ACTIVE)
                    ->whereDate('date', '>=', $thirtyDaysAgo)
                    ->whereDate('date', '<=', $today)
                    ->exists();
            }

            if ($recentActivePayment) {
                $hasRecentPayment30d = true;
            }
        }

        $totalAmount = round($totalAmount, 2);
        $effectivePaid = round($effectivePaid, 2);
        $liveDue = round(max(0.0, $liveDue), 2);
        $overdueAmount = round($overdueAmount, 2);

        // Determine payment status
        if ($liveDue <= 0.0) {
            $paymentStatus = self::PAYMENT_STATUS_PAID;
        } elseif ($effectivePaid > 0.0) {
            $paymentStatus = self::PAYMENT_STATUS_PARTIAL;
        } else {
            $paymentStatus = self::PAYMENT_STATUS_UNPAID;
        }

        $isOverdue = ($liveDue > 0.0 && $earliestOutstandingDueDate !== null && $earliestOutstandingDueDate < $today);

        // Every reachable Sale must remain eligible for new payments (not archived, not in a
        // terminal/ineligible lifecycle status such as REJECTED or fully RETURNED). A POS
        // transaction whose Sales have drifted out of eligibility since checkout must not be
        // payable, even if its aggregate live due is still positive.
        $allSalesEligible = $sales->every(function (Sale $sale) {
            return $sale->archived_at === null && in_array($sale->status, [
                Sale::STATUS_APPROVED,
                Sale::STATUS_DISPATCHED_PARTIALLY,
                Sale::STATUS_DISPATCHED,
                Sale::STATUS_RETURNED_PARTIALLY,
            ], true);
        });

        $isPayable = (
            $transaction->status === PosTransaction::STATUS_COMPLETED
            && $liveDue > 0.0
            && $isValid
            && $allSalesEligible
        );

        // Recent payment is only counted for currently fully paid transactions
        $qualifiesRecent30d = ($paymentStatus === self::PAYMENT_STATUS_PAID && $hasRecentPayment30d);

        return [
            'total_amount' => $totalAmount,
            'effective_paid' => $effectivePaid,
            'live_due' => $liveDue,
            'payment_status' => $paymentStatus,
            'payment_status_label' => self::PAYMENT_STATUS_LABELS[$paymentStatus] ?? $paymentStatus,
            'effective_due_date' => $earliestOutstandingDueDate,
            'is_overdue' => $isOverdue,
            'overdue_amount' => $overdueAmount,
            'has_recent_payment_30d' => $qualifiesRecent30d,
            'is_payable' => $isPayable,
            'is_valid' => $isValid,
            'anomalies' => $anomalies,
            'sales' => $sales,
        ];
    }

    /**
     * Build base eligible query for the global POS payment register.
     *
     * Requires:
     * - PosTransaction status = COMPLETED
     * - Completed checkout exists and status = POSTED
     * - At least one reachable Sale actually exists (via pos_checkout_sales.sale_id or
     *   pos_checkouts.sale_id resolving to a real Sale row, archived Sales included, not
     *   merely a non-null foreign key) via pos_checkout_sales or pos_checkouts.sale_id)
     */
    public function baseEligibleQuery(): Builder
    {
        return PosTransaction::query()
            ->where('pos_transactions.status', PosTransaction::STATUS_COMPLETED)
            ->whereHas('completedCheckout', function (Builder $q) {
                $q->where('status', PosCheckout::STATUS_POSTED)
                  ->where(function (Builder $sub) {
                      $sub->whereHas('checkoutSales', function (Builder $cs) {
                          $cs->whereHas('sale', function (Builder $sq) {
                              $sq->withArchived();
                          });
                      })->orWhereHas('sale', function (Builder $sq) {
                          $sq->withArchived();
                      });
                  });
            });
    }

    /**
     * Compute summary cards totals and counts for global POS payments.
     */
    public function calculateSummaryCards(
        ?int $customerId = null,
        array $businessFilters = [],
        ?string $transactionDateFrom = null,
        ?string $transactionDateTo = null,
        ?string $dueDateFrom = null,
        ?string $dueDateTo = null
    ): array {
        $query = $this->baseEligibleQuery()->with(array_merge(
            self::reachableSalesEagerLoad(),
            ['customer']
        ));

        if (!empty($customerId)) {
            $query->where('pos_transactions.customer_id', $customerId);
        }

        if (!empty($businessFilters)) {
            $query->whereIn('pos_transactions.setting_id', $businessFilters);
        }

        if (!empty($transactionDateFrom)) {
            $query->whereDate('pos_transactions.created_at', '>=', $transactionDateFrom);
        }
        if (!empty($transactionDateTo)) {
            $query->whereDate('pos_transactions.created_at', '<=', $transactionDateTo);
        }

        $transactions = $query->get();

        $outstandingCount = 0;
        $outstandingTotal = 0.0;

        $overdueCount = 0;
        $overdueTotal = 0.0;

        $paid30dCount = 0;
        $paid30dTotal = 0.0;

        foreach ($transactions as $trx) {
            $projection = $this->project($trx);

            // Apply due date filters if specified
            if (!empty($dueDateFrom) && ($projection['effective_due_date'] === null || $projection['effective_due_date'] < $dueDateFrom)) {
                continue;
            }
            if (!empty($dueDateTo) && ($projection['effective_due_date'] === null || $projection['effective_due_date'] > $dueDateTo)) {
                continue;
            }

            if ($projection['live_due'] > 0) {
                $outstandingCount++;
                $outstandingTotal += $projection['live_due'];
            }

            if ($projection['is_overdue']) {
                $overdueCount++;
                $overdueTotal += $projection['overdue_amount'] > 0 ? $projection['overdue_amount'] : $projection['live_due'];
            }

            if ($projection['has_recent_payment_30d']) {
                $paid30dCount++;
                $paid30dTotal += $projection['effective_paid'];
            }
        }

        return [
            'outstanding' => [
                'count' => $outstandingCount,
                'total' => round($outstandingTotal, 2),
            ],
            'overdue' => [
                'count' => $overdueCount,
                'total' => round($overdueTotal, 2),
            ],
            'paid_within_30_days' => [
                'count' => $paid30dCount,
                'total' => round($paid30dTotal, 2),
            ],
        ];
    }
}
