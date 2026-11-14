<?php

namespace Modules\Pos\Services;

use App\Support\ConsistentReadSnapshot;
use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosTransaction;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;

class PosGlobalPaymentReadService
{
    public const FALLBACK_BATCH_SIZE = 200;

    protected ?PosSettlementProjectionService $projectionService = null;

    public function __construct()
    {
    }

    protected function resolveProjectionService(?PosSettlementProjectionService $projectionService = null): PosSettlementProjectionService
    {
        return $projectionService ?? ($this->projectionService ??= new PosSettlementProjectionService());
    }

    /**
     * Classify candidate POS transactions into disjoint, exhaustive ordinary and
     * exceptional sets while evaluating base search and relational filters once.
     *
     * @return array{
     *     ordered_ids: array<int, int>,
     *     ordinary: array<int, array{id: int, status: string, sale_ids: array<int, int>}>,
     *     exceptional_ids: array<int, int>,
     *     sale_meta: array<int, array{customer_id: ?int, status: ?string, archived_at: mixed, due_date: mixed, total_amount: float}>
     * }
     */
    public function classifyCandidates(
        Builder $baseQuery,
        string $sortField = 'created_at',
        string $sortDirection = 'desc',
        ?ConnectionInterface $connection = null
    ): array {
        $conn = $connection ?? $baseQuery->getConnection();

        return ConsistentReadSnapshot::run(
            fn (ConnectionInterface $snapshotConn) => $this->classifyCandidatesInSnapshot(
                $baseQuery,
                $sortField,
                $sortDirection,
                $snapshotConn
            ),
            $conn
        );
    }

    /**
     * Compute global POS summary card counts and totals using staged settlement reads.
     *
     * @param  array<int>  $businessFilters
     * @return array{
     *     outstanding: array{count: int, total: float},
     *     overdue: array{count: int, total: float},
     *     paid_within_30_days: array{count: int, total: float}
     * }
     */
    public function calculateSummaryCards(
        ?int $customerId = null,
        array $businessFilters = [],
        ?string $transactionDateFrom = null,
        ?string $transactionDateTo = null,
        ?string $dueDateFrom = null,
        ?string $dueDateTo = null,
        ?PosSettlementProjectionService $projectionService = null
    ): array {
        $service = $this->resolveProjectionService($projectionService);
        $query = $service->baseEligibleQuery();

        if (! empty($customerId)) {
            $query->where('pos_transactions.customer_id', $customerId);
        }

        if (! empty($businessFilters)) {
            $query->whereIn('pos_transactions.setting_id', $businessFilters);
        }

        if (! empty($transactionDateFrom)) {
            $query->whereDate('pos_transactions.created_at', '>=', $transactionDateFrom);
        }

        if (! empty($transactionDateTo)) {
            $query->whereDate('pos_transactions.created_at', '<=', $transactionDateTo);
        }

        $conn = $query->getConnection();

        return ConsistentReadSnapshot::run(function (ConnectionInterface $snapshotConn) use (
            $query,
            $dueDateFrom,
            $dueDateTo,
            $service
        ) {
            $classified = $this->classifyCandidatesInSnapshot($query, 'id', 'asc', $snapshotConn);
            $projectionsById = $this->projectAll($classified, $snapshotConn, $service);

            $outstandingCount = 0;
            $outstandingTotal = 0.0;
            $overdueCount = 0;
            $overdueTotal = 0.0;
            $paid30dCount = 0;
            $paid30dTotal = 0.0;

            foreach ($classified['ordered_ids'] as $trxId) {
                $projection = $projectionsById[$trxId] ?? null;
                if ($projection === null) {
                    continue;
                }

                if (! empty($dueDateFrom) && ($projection['effective_due_date'] === null || $projection['effective_due_date'] < $dueDateFrom)) {
                    continue;
                }
                if (! empty($dueDateTo) && ($projection['effective_due_date'] === null || $projection['effective_due_date'] > $dueDateTo)) {
                    continue;
                }

                if ($projection['live_due'] > 0) {
                    $outstandingCount++;
                    $outstandingTotal += $projection['live_due'];
                }

                if ($projection['is_overdue']) {
                    $overdueCount++;
                    $overdueTotal += $projection['overdue_amount'] > 0
                        ? $projection['overdue_amount']
                        : $projection['live_due'];
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
        }, $conn);
    }

    /**
     * Evaluate projection-derived filters via staged reads, combine ordinary and
     * fallback matches in canonical database sort order, paginate, and hydrate
     * presentation relations for visible rows only.
     *
     * @param  callable(array): bool  $projectionPredicate
     * @return array{paginator: LengthAwarePaginator, rowProjections: array<int, array>}
     */
    public function paginateFilteredTransactions(
        Builder $baseQuery,
        callable $projectionPredicate,
        string $sortField = 'created_at',
        string $sortDirection = 'desc',
        int $perPage = 10,
        int $page = 1,
        string $pageName = 'page',
        ?PosSettlementProjectionService $projectionService = null
    ): array {
        $service = $this->resolveProjectionService($projectionService);
        $conn = $baseQuery->getConnection();
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        return ConsistentReadSnapshot::run(function (ConnectionInterface $snapshotConn) use (
            $baseQuery,
            $projectionPredicate,
            $sortField,
            $sortDirection,
            $perPage,
            $page,
            $pageName,
            $service
        ) {
            $classified = $this->classifyCandidatesInSnapshot($baseQuery, $sortField, $sortDirection, $snapshotConn);
            $projectionsById = $this->projectAll($classified, $snapshotConn, $service);

            $matchingIds = [];
            foreach ($classified['ordered_ids'] as $trxId) {
                $proj = $projectionsById[$trxId] ?? null;
                if ($proj !== null && $projectionPredicate($proj)) {
                    $matchingIds[] = $trxId;
                }
            }

            $total = count($matchingIds);
            $offset = ($page - 1) * $perPage;
            $pageIds = array_slice($matchingIds, $offset, $perPage);

            $orderedItems = new EloquentCollection();
            $rowProjections = [];

            if (! empty($pageIds)) {
                $loadedById = PosTransaction::query()
                    ->with([
                        'setting',
                        'customer',
                        'creator',
                        'completedCheckout.terminal',
                        'completedCheckout.cashier',
                    ])
                    ->whereIn('id', $pageIds)
                    ->get()
                    ->keyBy('id');

                foreach ($pageIds as $trxId) {
                    if ($loadedById->has($trxId)) {
                        $trx = $loadedById->get($trxId);
                        $proj = $projectionsById[$trxId];
                        $trx->projection = $proj;
                        $rowProjections[$trxId] = $proj;
                        $orderedItems->push($trx);
                    }
                }
            }

            $paginator = new LengthAwarePaginator(
                $orderedItems,
                $total,
                $perPage,
                $page,
                [
                    'path' => Paginator::resolveCurrentPath(),
                    'pageName' => $pageName,
                ]
            );

            return [
                'paginator' => $paginator,
                'rowProjections' => $rowProjections,
            ];
        }, $conn);
    }

    /**
     * Stage 1 inside an active read snapshot.
     *
     * @return array{
     *     ordered_ids: array<int, int>,
     *     ordinary: array<int, array{id: int, status: string, sale_ids: array<int, int>}>,
     *     exceptional_ids: array<int, int>,
     *     sale_meta: array<int, array{customer_id: ?int, status: ?string, archived_at: mixed, due_date: mixed, total_amount: float}>
     * }
     */
    protected function classifyCandidatesInSnapshot(
        Builder $baseQuery,
        string $sortField,
        string $sortDirection,
        ConnectionInterface $conn
    ): array {
        $allowedSortFields = ['created_at', 'code', 'id'];
        $field = in_array($sortField, $allowedSortFields, true)
            ? "pos_transactions.{$sortField}"
            : 'pos_transactions.created_at';
        $direction = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        $candidateQuery = (clone $baseQuery)
            ->setEagerLoads([])
            ->reorder()
            ->leftJoin('pos_checkouts as pc', 'pc.id', '=', 'pos_transactions.completed_checkout_id')
            ->select([
                'pos_transactions.id',
                'pos_transactions.status',
                'pos_transactions.completed_checkout_id',
                'pc.id as checkout_id',
                'pc.pos_transaction_id as checkout_pos_transaction_id',
                'pc.status as checkout_status',
                'pc.customer_id as checkout_customer_id',
            ])
            ->orderBy($field, $direction);

        if ($field !== 'pos_transactions.id') {
            $candidateQuery->orderBy('pos_transactions.id', $direction);
        }

        $candidateRows = $candidateQuery->get();
        if ($candidateRows->isEmpty()) {
            return [
                'ordered_ids' => [],
                'ordinary' => [],
                'exceptional_ids' => [],
                'sale_meta' => [],
            ];
        }

        // Load active completed non-reversed return associations once to avoid per-row correlated scans
        $returnCheckoutSet = [];
        $returnTrxSet = [];
        $activeReturns = $conn->table('pos_returns')
            ->select(['pos_checkout_id', 'pos_transaction_id'])
            ->where('status', PosReturn::STATUS_COMPLETED)
            ->where('is_reversed', false)
            ->get();

        foreach ($activeReturns as $ret) {
            if ($ret->pos_checkout_id !== null) {
                $returnCheckoutSet[(int) $ret->pos_checkout_id] = true;
            }
            if ($ret->pos_transaction_id !== null) {
                $returnTrxSet[(int) $ret->pos_transaction_id] = true;
            }
        }

        $checkoutIds = [];
        foreach ($candidateRows as $row) {
            if ($row->checkout_id !== null) {
                $checkoutIds[(int) $row->checkout_id] = true;
            }
        }

        $splitsByCheckout = [];
        $saleMeta = [];
        $chunkSize = $this->chunkSizeForConnection($conn);

        foreach (array_chunk(array_keys($checkoutIds), $chunkSize) as $chunkCheckoutIds) {
            $splitRows = $conn->table('pos_checkout_sales as pcs')
                ->leftJoin('sales as s', 's.id', '=', 'pcs.sale_id')
                ->select([
                    'pcs.pos_checkout_id',
                    'pcs.sale_id',
                    'pcs.split_key',
                    's.id as existing_sale_id',
                    's.customer_id as sale_customer_id',
                    's.status as sale_status',
                    's.archived_at as sale_archived_at',
                    's.due_date as sale_due_date',
                    's.total_amount as sale_total_amount',
                ])
                ->whereIn('pcs.pos_checkout_id', $chunkCheckoutIds)
                ->get();

            foreach ($splitRows as $sp) {
                $cid = (int) $sp->pos_checkout_id;
                $splitsByCheckout[$cid][] = $sp;

                if ($sp->existing_sale_id !== null) {
                    $sid = (int) $sp->existing_sale_id;
                    $saleMeta[$sid] = [
                        'customer_id' => $sp->sale_customer_id !== null ? (int) $sp->sale_customer_id : null,
                        'status' => $sp->sale_status,
                        'archived_at' => $sp->sale_archived_at,
                        'due_date' => $sp->sale_due_date,
                        'total_amount' => (float) ($sp->sale_total_amount ?? 0.0),
                    ];
                }
            }
        }

        $orderedIds = [];
        $ordinary = [];
        $exceptionalIds = [];

        foreach ($candidateRows as $row) {
            $trxId = (int) $row->id;
            $orderedIds[] = $trxId;

            if ($this->isOrdinaryCandidate($row, $splitsByCheckout, $saleMeta, $returnCheckoutSet, $returnTrxSet, $splitSaleIds)) {
                $ordinary[$trxId] = [
                    'id' => $trxId,
                    'status' => (string) $row->status,
                    'sale_ids' => $splitSaleIds,
                ];
            } else {
                $exceptionalIds[] = $trxId;
            }
        }

        return [
            'ordered_ids' => $orderedIds,
            'ordinary' => $ordinary,
            'exceptional_ids' => $exceptionalIds,
            'sale_meta' => $saleMeta,
        ];
    }

    /**
     * Conservative check ensuring every edge case (inline-only, active return,
     * checkout/trx mismatch, null/duplicate/case-colliding split key, missing/zero/duplicate
     * sale ID, customer mismatch) safely delegates to the Stage 3 resolver oracle.
     *
     * @param  array<int, array<int, object>>  $splitsByCheckout
     * @param  array<int, array<string, mixed>>  $saleMeta
     * @param  array<int, bool>  $returnCheckoutSet
     * @param  array<int, bool>  $returnTrxSet
     * @param  array<int, int>|null  $splitSaleIds
     */
    protected function isOrdinaryCandidate(
        object $row,
        array $splitsByCheckout,
        array $saleMeta,
        array $returnCheckoutSet,
        array $returnTrxSet,
        ?array &$splitSaleIds = null
    ): bool {
        $splitSaleIds = [];
        $trxId = (int) $row->id;

        if ((string) $row->status !== PosTransaction::STATUS_COMPLETED) {
            return false;
        }

        if ($row->checkout_id === null || (string) $row->checkout_status !== PosCheckout::STATUS_POSTED) {
            return false;
        }

        $checkoutId = (int) $row->checkout_id;
        if ($row->checkout_pos_transaction_id === null || (int) $row->checkout_pos_transaction_id !== $trxId) {
            return false;
        }

        if (isset($returnCheckoutSet[$checkoutId]) || isset($returnTrxSet[$trxId])) {
            return false;
        }

        $splits = $splitsByCheckout[$checkoutId] ?? [];
        if (empty($splits)) {
            // Inline-only mapping routes conservatively through Stage 3
            return false;
        }

        $checkoutCustomerId = $row->checkout_customer_id !== null ? (int) $row->checkout_customer_id : 0;
        $seenSaleIds = [];
        $seenExactKeys = [];
        $seenNormKeys = [];

        foreach ($splits as $sp) {
            $rawSaleId = $sp->sale_id;
            if ($rawSaleId === null || (int) $rawSaleId <= 0) {
                return false;
            }

            $sid = (int) $rawSaleId;
            if (! isset($saleMeta[$sid])) {
                return false;
            }

            if (isset($seenSaleIds[$sid])) {
                return false;
            }
            $seenSaleIds[$sid] = true;

            $splitKey = $sp->split_key;
            if ($splitKey === null || trim((string) $splitKey) === '') {
                return false;
            }
            $splitKeyStr = (string) $splitKey;
            if (isset($seenExactKeys[$splitKeyStr])) {
                return false;
            }
            $seenExactKeys[$splitKeyStr] = true;

            // Route case/whitespace split-key variants conservatively so MySQL CI collation cannot drift
            $normKey = strtolower(trim($splitKeyStr));
            if (isset($seenNormKeys[$normKey])) {
                return false;
            }
            $seenNormKeys[$normKey] = true;

            if ($checkoutCustomerId !== 0 && (int) ($saleMeta[$sid]['customer_id'] ?? 0) !== $checkoutCustomerId) {
                return false;
            }

            $splitSaleIds[] = $sid;
        }

        return true;
    }

    /**
     * Execute Stage 2 (bounded ordinary ledger aggregation) and Stage 3 (chunked
     * fallback projection) and combine scalar projections keyed by transaction ID.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function projectAll(
        array $classified,
        ConnectionInterface $conn,
        PosSettlementProjectionService $projectionService
    ): array {
        $projectionsById = [];

        if (! empty($classified['ordinary'])) {
            $projectionsById = $this->projectOrdinaryBatch(
                $classified['ordinary'],
                $classified['sale_meta'],
                $conn
            );
        }

        if (! empty($classified['exceptional_ids'])) {
            foreach (array_chunk($classified['exceptional_ids'], self::FALLBACK_BATCH_SIZE) as $chunkIds) {
                $batchTrxs = PosTransaction::query()
                    ->with(PosSettlementProjectionService::reachableSalesEagerLoad())
                    ->whereIn('id', $chunkIds)
                    ->get();

                $replacementContext = $projectionService->prepareReplacementSales($batchTrxs);

                foreach ($batchTrxs as $trx) {
                    $proj = $projectionService->project($trx, $replacementContext);
                    unset($proj['sales']);
                    $projectionsById[(int) $trx->id] = $proj;
                }

                unset($replacementContext, $batchTrxs);
            }
        }

        return $projectionsById;
    }

    /**
     * Stage 2: Bounded Sale-ledger SQL aggregation (one grouped query per ledger
     * source so fanout cannot multiply totals) + per-transaction scalar projection.
     *
     * @param  array<int, array{id: int, status: string, sale_ids: array<int, int>}>  $ordinaryByTxId
     * @param  array<int, array<string, mixed>>  $saleMeta
     * @return array<int, array<string, mixed>>
     */
    protected function projectOrdinaryBatch(
        array $ordinaryByTxId,
        array $saleMeta,
        ConnectionInterface $conn
    ): array {
        $ordinarySaleIds = [];
        foreach ($ordinaryByTxId as $entry) {
            foreach ($entry['sale_ids'] as $sid) {
                $ordinarySaleIds[$sid] = true;
            }
        }

        $today = Carbon::today()->toDateString();
        $thirtyDaysAgo = Carbon::today()->subDays(30)->toDateString();

        $paySumBySale = [];
        $recentPayBySale = [];
        $creditSumBySale = [];
        $inSumBySale = [];
        $recentInBySale = [];
        $outSumBySale = [];

        $chunkSize = $this->chunkSizeForConnection($conn);

        foreach (array_chunk(array_keys($ordinarySaleIds), $chunkSize) as $saleChunk) {
            $payRows = $conn->table('sale_payments')
                ->selectRaw(
                    'sale_id, SUM(amount) AS monetary_sum, MAX(CASE WHEN DATE(date) >= ? AND DATE(date) <= ? THEN 1 ELSE 0 END) AS has_recent',
                    [$thirtyDaysAgo, $today]
                )
                ->where('status', SalePayment::STATUS_ACTIVE)
                ->whereIn('sale_id', $saleChunk)
                ->groupBy('sale_id')
                ->get();

            foreach ($payRows as $r) {
                $sid = (int) $r->sale_id;
                $paySumBySale[$sid] = (float) ($r->monetary_sum ?? 0.0);
                if ((int) ($r->has_recent ?? 0) === 1) {
                    $recentPayBySale[$sid] = true;
                }
            }

            $creditRows = $conn->table('sale_payments as sp')
                ->join('sale_payment_credit_applications as spca', 'spca.sale_payment_id', '=', 'sp.id')
                ->selectRaw('sp.sale_id, SUM(spca.amount) AS credit_sum')
                ->where('sp.status', SalePayment::STATUS_ACTIVE)
                ->whereIn('sp.sale_id', $saleChunk)
                ->groupBy('sp.sale_id')
                ->get();

            foreach ($creditRows as $r) {
                $creditSumBySale[(int) $r->sale_id] = (float) ($r->credit_sum ?? 0.0);
            }

            $inRows = $conn->table('pos_owner_payment_transfers as popt')
                ->leftJoin('sale_payments as sp_orig', 'sp_orig.id', '=', 'popt.original_sale_payment_id')
                ->selectRaw(
                    'popt.replacement_sale_id AS sale_id, SUM(popt.amount) AS incoming_sum, MAX(CASE WHEN popt.original_sale_payment_id IS NOT NULL AND sp_orig.status = ? AND DATE(sp_orig.date) >= ? AND DATE(sp_orig.date) <= ? THEN 1 ELSE 0 END) AS has_recent_in',
                    [SalePayment::STATUS_ACTIVE, $thirtyDaysAgo, $today]
                )
                ->where(function ($q) {
                    $q->whereNull('popt.original_sale_payment_id')
                        ->orWhere('sp_orig.status', SalePayment::STATUS_ACTIVE);
                })
                ->whereIn('popt.replacement_sale_id', $saleChunk)
                ->groupBy('popt.replacement_sale_id')
                ->get();

            foreach ($inRows as $r) {
                $sid = (int) $r->sale_id;
                $inSumBySale[$sid] = (float) ($r->incoming_sum ?? 0.0);
                if ((int) ($r->has_recent_in ?? 0) === 1) {
                    $recentInBySale[$sid] = true;
                }
            }

            $outRows = $conn->table('pos_owner_payment_transfers as popt')
                ->leftJoin('sale_payments as sp_orig', 'sp_orig.id', '=', 'popt.original_sale_payment_id')
                ->selectRaw('popt.source_sale_id AS sale_id, SUM(popt.amount) AS outgoing_sum')
                ->where(function ($q) {
                    $q->whereNull('popt.original_sale_payment_id')
                        ->orWhere('sp_orig.status', SalePayment::STATUS_ACTIVE);
                })
                ->whereIn('popt.source_sale_id', $saleChunk)
                ->groupBy('popt.source_sale_id')
                ->get();

            foreach ($outRows as $r) {
                $outSumBySale[(int) $r->sale_id] = (float) ($r->outgoing_sum ?? 0.0);
            }
        }

        // Compute per-Sale effective settlement scalars once
        $saleComputed = [];
        foreach (array_keys($ordinarySaleIds) as $sid) {
            $meta = $saleMeta[$sid];
            $saleTotal = (float) $meta['total_amount'];
            $monetarySum = $paySumBySale[$sid] ?? 0.0;
            $creditSum = $creditSumBySale[$sid] ?? 0.0;
            $incomingSum = $inSumBySale[$sid] ?? 0.0;
            $outgoingSum = $outSumBySale[$sid] ?? 0.0;

            $salePaid = max(0.0, round($monetarySum + $creditSum + $incomingSum - $outgoingSum, 2));
            $saleDue = max(0.0, round($saleTotal - $salePaid, 2));

            $dueDate = ($meta['due_date'] !== null && $meta['due_date'] !== '')
                ? Carbon::parse($meta['due_date'])->toDateString()
                : null;

            $isEligible = ($meta['archived_at'] === null && in_array($meta['status'], PosChildSaleEligibilityService::ELIGIBLE_LIFECYCLE_STATUSES, true))
                || ($meta['status'] === Sale::STATUS_RETURNED && round($saleTotal, 2) == 0.0 && round($saleDue, 2) == 0.0);

            $saleComputed[$sid] = [
                'total' => $saleTotal,
                'paid' => $salePaid,
                'due' => $saleDue,
                'due_date' => $dueDate,
                'has_recent' => isset($recentPayBySale[$sid]) || isset($recentInBySale[$sid]),
                'is_eligible' => $isEligible,
            ];
        }

        $projections = [];

        foreach ($ordinaryByTxId as $trxId => $entry) {
            $totalAmount = 0.0;
            $effectivePaid = 0.0;
            $liveDue = 0.0;
            $overdueAmount = 0.0;
            $earliestOutstandingDueDate = null;
            $hasRecentPayment30d = false;
            $allSalesEligible = true;

            foreach ($entry['sale_ids'] as $sid) {
                $sc = $saleComputed[$sid];
                $totalAmount += $sc['total'];
                $effectivePaid += $sc['paid'];
                $liveDue += $sc['due'];

                if ($sc['due'] > 0 && $sc['due_date'] !== null) {
                    if ($earliestOutstandingDueDate === null || $sc['due_date'] < $earliestOutstandingDueDate) {
                        $earliestOutstandingDueDate = $sc['due_date'];
                    }
                    if ($sc['due_date'] < $today) {
                        $overdueAmount += $sc['due'];
                    }
                }

                if ($sc['has_recent']) {
                    $hasRecentPayment30d = true;
                }

                if (! $sc['is_eligible']) {
                    $allSalesEligible = false;
                }
            }

            $totalAmount = round($totalAmount, 2);
            $effectivePaid = round($effectivePaid, 2);
            $liveDue = round(max(0.0, $liveDue), 2);
            $overdueAmount = round($overdueAmount, 2);

            if ($liveDue <= 0.0) {
                $paymentStatus = PosSettlementProjectionService::PAYMENT_STATUS_PAID;
            } elseif ($effectivePaid > 0.0) {
                $paymentStatus = PosSettlementProjectionService::PAYMENT_STATUS_PARTIAL;
            } else {
                $paymentStatus = PosSettlementProjectionService::PAYMENT_STATUS_UNPAID;
            }

            $isOverdue = ($liveDue > 0.0 && $earliestOutstandingDueDate !== null && $earliestOutstandingDueDate < $today);
            $isPayable = ($entry['status'] === PosTransaction::STATUS_COMPLETED && $liveDue > 0.0 && $allSalesEligible);
            $qualifiesRecent30d = ($paymentStatus === PosSettlementProjectionService::PAYMENT_STATUS_PAID && $hasRecentPayment30d);

            $projections[$trxId] = [
                'total_amount' => $totalAmount,
                'effective_paid' => $effectivePaid,
                'live_due' => $liveDue,
                'payment_status' => $paymentStatus,
                'payment_status_label' => PosSettlementProjectionService::PAYMENT_STATUS_LABELS[$paymentStatus] ?? $paymentStatus,
                'effective_due_date' => $earliestOutstandingDueDate,
                'is_overdue' => $isOverdue,
                'overdue_amount' => $overdueAmount,
                'has_recent_payment_30d' => $qualifiesRecent30d,
                'is_payable' => $isPayable,
                'is_valid' => true,
                'anomalies' => [],
            ];
        }

        return $projections;
    }

    protected function chunkSizeForConnection(ConnectionInterface $conn): int
    {
        return method_exists($conn, 'getDriverName') && $conn->getDriverName() === 'mysql'
            ? 2000
            : 400;
    }
}
