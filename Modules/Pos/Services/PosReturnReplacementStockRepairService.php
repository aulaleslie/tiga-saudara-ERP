<?php

namespace Modules\Pos\Services;

use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;

/**
 * Evidence-gated discovery and guarded repair of historical stock-bucket drift
 * caused by POS Return replacement dispatch decrementing only aggregate
 * `product_stocks.quantity` without the owner's PKP/non-PKP bucket.
 *
 * Discovery is read-only and starts from immutable lineage (completed managed
 * POS Return replacement lines -> Sale Return details -> replacement dispatch
 * details -> outbound DISPATCH_RETURN transactions). A generic aggregate/bucket
 * or stock/serial mismatch is never on its own a repair trigger.
 */
class PosReturnReplacementStockRepairService
{
    public const DEFECT_VERSION = 'pos-return-replacement-bucket-v1';

    /** Marks a corrective ledger row and carries its repair identity. */
    public const REPAIR_REASON_PREFIX = 'REPAIR:' . self::DEFECT_VERSION . ':';

    public const CLASSIFICATION_REPAIRABLE = 'repairable';
    public const CLASSIFICATION_ALREADY_REPAIRED = 'already_repaired';
    public const CLASSIFICATION_AMBIGUOUS = 'ambiguous';
    public const CLASSIFICATION_CONFLICT = 'conflict';

    public function __construct(
        protected PosReturnReplacementStockMutator $mutator
    ) {
    }

    /**
     * Read-only candidate discovery. Performs no writes.
     *
     * @param  array{product_id?: int|null, location_id?: int|null}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function discover(array $filters = []): array
    {
        $contributions = $this->collectFaultyContributions($filters);

        $grouped = [];

        foreach ($contributions as $contribution) {
            $key = $contribution['product_id'] . ':' . $contribution['location_id'];

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'product_id' => $contribution['product_id'],
                    'location_id' => $contribution['location_id'],
                    'setting_id' => $contribution['setting_id'],
                    'bucket' => $contribution['bucket'],
                    'contributions' => [],
                ];
            }

            $grouped[$key]['contributions'][] = $contribution;
        }

        $candidates = [];

        foreach ($grouped as $group) {
            $candidates[] = $this->buildCandidate($group);
        }

        usort($candidates, function (array $left, array $right): int {
            return [$left['product_id'], $left['location_id']] <=> [$right['product_id'], $right['location_id']];
        });

        return $candidates;
    }

    /**
     * Guarded apply. Re-runs discovery, locks each stock row, revalidates the
     * evidence and exact current values, and mutates inside a per-row
     * transaction. Candidates whose data moved produce a conflict result and
     * are never overwritten.
     *
     * @param  array{product_id?: int|null, location_id?: int|null}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function apply(array $filters = [], ?int $actorId = null): array
    {
        $results = [];

        foreach ($this->discover($filters) as $candidate) {
            if ($candidate['classification'] !== self::CLASSIFICATION_REPAIRABLE) {
                $results[] = $candidate;
                continue;
            }

            $results[] = $this->applyCandidate($candidate, $actorId);
        }

        return $results;
    }

    /**
     * Reconstruct every missed owner-bucket decrement from immutable lineage.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function collectFaultyContributions(array $filters): array
    {
        $rows = DB::table('dispatch_details as dd')
            ->join('pos_return_lines as prl', 'prl.id', '=', 'dd.pos_return_line_id')
            ->join('pos_returns as pr', 'pr.id', '=', 'prl.pos_return_id')
            ->join('sale_return_details as srd', 'srd.pos_return_line_id', '=', 'prl.id')
            ->join('sale_returns as sr', 'sr.id', '=', 'srd.sale_return_id')
            ->join('locations as loc', 'loc.id', '=', 'dd.location_id')
            ->join('settings as st', 'st.id', '=', 'loc.setting_id')
            ->where('prl.resolution', PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT)
            ->where('prl.stock_behavior', PosReturnLine::STOCK_BEHAVIOR_MANAGED)
            ->where('dd.is_inventory_managed', true)
            ->whereNotNull('dd.location_id')
            ->when(! empty($filters['product_id']), fn ($q) => $q->where('dd.product_id', (int) $filters['product_id']))
            ->when(! empty($filters['location_id']), fn ($q) => $q->where('dd.location_id', (int) $filters['location_id']))
            ->select([
                'dd.id as dispatch_detail_id',
                'dd.dispatch_id',
                'dd.product_id',
                'dd.location_id',
                'dd.dispatched_quantity',
                'dd.serial_numbers',
                'prl.id as pos_return_line_id',
                'prl.returned_serial_id',
                'prl.replacement_serial_id',
                'pr.id as pos_return_id',
                'pr.status as pos_return_status',
                'srd.id as sale_return_detail_id',
                'sr.id as sale_return_id',
                'sr.reference as sale_return_reference',
                'loc.setting_id as owner_setting_id',
                'st.is_pkp as owner_is_pkp',
            ])
            ->get();

        $contributions = [];

        foreach ($rows as $row) {
            $quantity = (int) $row->dispatched_quantity;

            if ($quantity <= 0) {
                continue;
            }

            // The outbound ledger row is the proof the stock actually left.
            $transaction = $this->correlateDispatchTransaction($row, $quantity);

            if (! $transaction) {
                // No conclusive outbound proof: never infer a correction.
                continue;
            }

            $bucket = ((bool) $row->owner_is_pkp)
                ? PosReturnReplacementStockMutator::BUCKET_TAX
                : PosReturnReplacementStockMutator::BUCKET_NON_TAX;

            $contributions[] = [
                'product_id' => (int) $row->product_id,
                'location_id' => (int) $row->location_id,
                'setting_id' => (int) $row->owner_setting_id,
                'bucket' => $bucket,
                'quantity' => $quantity,
                'transaction_id' => (int) $transaction->id,
                'repair_key' => $this->repairKey((int) $transaction->id),
                'pos_return_id' => (int) $row->pos_return_id,
                'sale_return_id' => (int) $row->sale_return_id,
                'sale_return_detail_id' => (int) $row->sale_return_detail_id,
                'dispatch_id' => (int) $row->dispatch_id,
                'dispatch_detail_id' => (int) $row->dispatch_detail_id,
                'returned_serial_id' => $row->returned_serial_id ? (int) $row->returned_serial_id : null,
                'replacement_serial_id' => $row->replacement_serial_id ? (int) $row->replacement_serial_id : null,
            ];
        }

        return $contributions;
    }

    /**
     * Correlate a replacement dispatch with its outbound ledger row.
     *
     * Matching is exact, never a substring search: the two historical code
     * paths emitted one of two known reason strings, so we reconstruct those
     * verbatim and additionally pin product, location, type and quantity. A
     * reason like "... #84" can otherwise match "#840" or "#184".
     */
    protected function correlateDispatchTransaction(object $row, int $quantity): ?Transaction
    {
        // Historical reason formats, reproduced exactly as each path wrote them.
        // BaseModel upper-cases persisted text, so compare in upper case.
        $candidateReasons = [
            'Dispatch replacement for Sale Return #' . $row->sale_return_id,
            'Cross-owner replacement dispatch for POS Return #' . $row->sale_return_reference,
        ];

        $candidateReasons = array_map(
            fn (string $reason) => mb_strtoupper($reason, 'UTF-8'),
            $candidateReasons
        );

        $matches = Transaction::query()
            ->where('type', 'DISPATCH_RETURN')
            ->where('product_id', (int) $row->product_id)
            ->where('location_id', (int) $row->location_id)
            ->where('setting_id', (int) $row->owner_setting_id)
            ->where('quantity', -$quantity)
            ->whereIn(DB::raw('UPPER(reason)'), $candidateReasons)
            ->orderBy('id')
            ->get();

        // Ambiguity is never resolved by guessing: if the evidence does not
        // point at exactly one ledger row, the contribution is not conclusive.
        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    protected function buildCandidate(array $group): array
    {
        $productId = (int) $group['product_id'];
        $locationId = (int) $group['location_id'];
        $bucket = $group['bucket'];

        $alreadyRepaired = $this->settledRepairKeys($productId, $locationId);

        $pending = array_values(array_filter(
            $group['contributions'],
            fn (array $c) => ! in_array($c['repair_key'], $alreadyRepaired, true)
        ));

        $delta = array_sum(array_column($pending, 'quantity'));

        $stock = ProductStock::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->first();

        $product = Product::query()->find($productId);

        $current = $this->snapshotBuckets($stock);

        $candidate = [
            'product_id' => $productId,
            'product_name' => $product?->product_name,
            'location_id' => $locationId,
            'setting_id' => (int) $group['setting_id'],
            'bucket' => $bucket,
            'current' => $current,
            'expected' => $current,
            'bucket_delta' => 0,
            'aggregate_delta' => 0,
            'global_quantity' => (int) ($product->product_quantity ?? 0),
            'sellable_serial_count' => $this->sellableSerialCount($productId, $locationId),
            'contributions' => $group['contributions'],
            'pending_contributions' => $pending,
            'pos_return_ids' => array_values(array_unique(array_column($group['contributions'], 'pos_return_id'))),
            'sale_return_ids' => array_values(array_unique(array_column($group['contributions'], 'sale_return_id'))),
            'dispatch_detail_ids' => array_values(array_unique(array_column($group['contributions'], 'dispatch_detail_id'))),
            'transaction_ids' => array_values(array_unique(array_column($group['contributions'], 'transaction_id'))),
            'returned_serial_ids' => array_values(array_filter(array_column($group['contributions'], 'returned_serial_id'))),
            'replacement_serial_ids' => array_values(array_filter(array_column($group['contributions'], 'replacement_serial_id'))),
            'classification' => self::CLASSIFICATION_AMBIGUOUS,
            'reason' => null,
        ];

        if (! $stock || ! $product) {
            $candidate['reason'] = 'Baris stok atau produk tidak ditemukan.';

            return $candidate;
        }

        if ($pending === []) {
            $candidate['classification'] = self::CLASSIFICATION_ALREADY_REPAIRED;
            $candidate['reason'] = 'Semua kontribusi sudah diperbaiki sebelumnya.';

            return $candidate;
        }

        // The ledger cannot tell a faulty dispatch from a fixed one: both write
        // a positive bucket quantity on the DISPATCH_RETURN row. So the proven
        // lineage only bounds how much drift this defect could explain; the
        // size of the correction is measured from the row's physical truth.
        //
        // These are serialized products, so the sellable serial count is that
        // truth: the good-stock bucket must equal it. Bucket-vs-aggregate drift
        // alone is not enough, because a later receipt recomputes the aggregate
        // from the buckets and so promotes earlier bucket drift into the
        // aggregate, hiding it (POS Return 84 then 86 on product 182).
        if (! (bool) $product->serial_number_required) {
            $candidate['reason'] = 'Produk non-serial: tidak ada bukti fisik kanonik untuk menentukan koreksi.';

            return $candidate;
        }

        $brokenTotal = (int) $current['broken_quantity_tax'] + (int) $current['broken_quantity_non_tax'];

        // The owner's good bucket must hold exactly the sellable serials, and
        // the aggregate must hold those plus the broken buckets.
        $targetBucket = $candidate['sellable_serial_count'];
        $targetAggregate = $targetBucket + $brokenTotal;

        $drift = (int) $current[$bucket] - $targetBucket;

        if ($drift <= 0) {
            $candidate['reason'] = 'Bucket sudah sesuai dengan jumlah serial sellable; tidak ada yang diperbaiki.';

            return $candidate;
        }

        if ($drift > $delta) {
            $candidate['reason'] = sprintf(
                'Selisih %d melebihi %d yang dapat dibuktikan oleh lineage replacement; kemungkinan ada penyebab lain.',
                $drift,
                $delta
            );

            return $candidate;
        }

        // The other good bucket must already be consistent, or something other
        // than this defect is also in play.
        $otherBucket = $bucket === PosReturnReplacementStockMutator::BUCKET_TAX
            ? PosReturnReplacementStockMutator::BUCKET_NON_TAX
            : PosReturnReplacementStockMutator::BUCKET_TAX;

        if ((int) $current[$otherBucket] !== 0) {
            $candidate['reason'] = sprintf(
                'Bucket %s berisi %d; kepemilikan campuran tidak dapat dikoreksi secara deterministik.',
                $otherBucket,
                (int) $current[$otherBucket]
            );

            return $candidate;
        }

        $delta = $drift;

        $expected = $current;
        $expected[$bucket] = $targetBucket;
        $expected['quantity'] = $expected['quantity_tax']
            + $expected['quantity_non_tax']
            + $expected['broken_quantity_tax']
            + $expected['broken_quantity_non_tax'];
        $expected['broken_quantity'] = $expected['broken_quantity_tax'] + $expected['broken_quantity_non_tax'];

        if ($expected['quantity'] !== $targetAggregate) {
            $candidate['reason'] = 'Agregat terkoreksi tidak konsisten dengan bukti fisik.';

            return $candidate;
        }

        $candidate['expected'] = $expected;
        $candidate['bucket_delta'] = -$delta;
        $candidate['aggregate_delta'] = $expected['quantity'] - (int) $current['quantity'];
        $candidate['classification'] = self::CLASSIFICATION_REPAIRABLE;

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    protected function applyCandidate(array $candidate, ?int $actorId): array
    {
        return DB::transaction(function () use ($candidate, $actorId) {
            $stock = ProductStock::query()
                ->where('product_id', $candidate['product_id'])
                ->where('location_id', $candidate['location_id'])
                ->lockForUpdate()
                ->first();

            $product = Product::query()->whereKey($candidate['product_id'])->lockForUpdate()->first();

            if (! $stock || ! $product) {
                return $this->conflict($candidate, 'Baris stok atau produk hilang sebelum apply.');
            }

            // Exact current-value precondition: anything that moved since
            // discovery aborts this candidate without overwriting newer data.
            $locked = $this->snapshotBuckets($stock);

            if ($locked !== $candidate['current']) {
                return $this->conflict($candidate, 'Nilai stok berubah setelah dry-run.');
            }

            if ((int) $product->product_quantity !== (int) $candidate['global_quantity']) {
                return $this->conflict($candidate, 'Kuantitas global produk berubah setelah dry-run.');
            }

            // Re-read the repair identities under the lock so a concurrent
            // apply cannot double-correct the same contribution.
            $settled = $this->settledRepairKeys($candidate['product_id'], $candidate['location_id']);

            $pending = array_values(array_filter(
                $candidate['pending_contributions'],
                fn (array $c) => ! in_array($c['repair_key'], $settled, true)
            ));

            if ($pending === []) {
                $result = $candidate;
                $result['classification'] = self::CLASSIFICATION_ALREADY_REPAIRED;
                $result['reason'] = 'Semua kontribusi sudah diperbaiki sebelumnya.';

                return $result;
            }

            // Use the drift-derived plan validated at discovery, not the raw
            // contribution count (see buildCandidate).
            $delta = -$candidate['bucket_delta'];
            $bucket = $candidate['bucket'];

            if ($delta <= 0) {
                return $this->conflict($candidate, 'Rencana koreksi tidak valid.');
            }

            if ((int) $stock->{$bucket} < $delta) {
                return $this->conflict($candidate, 'Bucket pemilik tidak lagi mencukupi untuk koreksi.');
            }

            $bucketBefore = (int) $stock->{$bucket};
            $aggregateBefore = (int) $stock->quantity;
            $globalBefore = (int) $product->product_quantity;

            $stock->{$bucket} = $bucketBefore - $delta;
            $stock->broken_quantity = (int) ($stock->broken_quantity_non_tax ?? 0)
                + (int) ($stock->broken_quantity_tax ?? 0);
            $stock->quantity = (int) ($stock->quantity_non_tax ?? 0)
                + (int) ($stock->quantity_tax ?? 0)
                + (int) ($stock->broken_quantity_non_tax ?? 0)
                + (int) ($stock->broken_quantity_tax ?? 0);
            $stock->save();

            $aggregateAfter = (int) $stock->quantity;
            $globalAfter = $globalBefore + ($aggregateAfter - $aggregateBefore);

            $product->product_quantity = $globalAfter;
            $product->save();

            $isTaxBucket = $bucket === PosReturnReplacementStockMutator::BUCKET_TAX;

            $corrective = Transaction::create([
                'product_id' => $candidate['product_id'],
                'setting_id' => $candidate['setting_id'],
                'quantity' => $aggregateAfter - $aggregateBefore,
                'current_quantity' => $globalAfter,
                'broken_quantity' => (int) ($stock->broken_quantity ?? 0),
                'location_id' => $candidate['location_id'],
                'user_id' => $actorId,
                // Carries the repair identity: this row IS the audit evidence
                // and the idempotency guard.
                'reason' => $this->correctiveReason(array_column($pending, 'repair_key')),
                'type' => 'DISPATCH_RETURN',
                'previous_quantity' => $globalBefore,
                'after_quantity' => $globalAfter,
                'previous_quantity_at_location' => $aggregateBefore,
                'after_quantity_at_location' => $aggregateAfter,
                'quantity_non_tax' => $isTaxBucket ? 0 : -$delta,
                'quantity_tax' => $isTaxBucket ? -$delta : 0,
                'broken_quantity_non_tax' => (int) ($stock->broken_quantity_non_tax ?? 0),
                'broken_quantity_tax' => (int) ($stock->broken_quantity_tax ?? 0),
            ]);


            $result = $candidate;
            $result['classification'] = self::CLASSIFICATION_REPAIRABLE;
            $result['applied'] = true;
            $result['corrective_transaction_id'] = (int) $corrective->id;
            $result['current'] = $this->snapshotBuckets($stock->fresh());

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    protected function conflict(array $candidate, string $reason): array
    {
        $candidate['classification'] = self::CLASSIFICATION_CONFLICT;
        $candidate['applied'] = false;
        $candidate['reason'] = $reason;

        return $candidate;
    }

    /**
     * @return array<string, int>
     */
    protected function snapshotBuckets(?ProductStock $stock): array
    {
        return [
            'quantity' => (int) ($stock->quantity ?? 0),
            'quantity_tax' => (int) ($stock->quantity_tax ?? 0),
            'quantity_non_tax' => (int) ($stock->quantity_non_tax ?? 0),
            'broken_quantity' => (int) ($stock->broken_quantity ?? 0),
            'broken_quantity_tax' => (int) ($stock->broken_quantity_tax ?? 0),
            'broken_quantity_non_tax' => (int) ($stock->broken_quantity_non_tax ?? 0),
        ];
    }

    protected function sellableSerialCount(int $productId, int $locationId): int
    {
        return (int) \Modules\Product\Entities\ProductSerialNumber::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->sellable()
            ->count();
    }

    protected function repairKey(int $transactionId): string
    {
        return self::DEFECT_VERSION . ':txn:' . $transactionId;
    }

    /**
     * The corrective ledger row's reason doubles as the persisted repair
     * identity: it names the defect version and every faulty transaction it
     * settles, so a second apply can detect its own prior work without any
     * schema expansion.
     */
    protected function correctiveReason(array $repairKeys): string
    {
        sort($repairKeys);

        return self::REPAIR_REASON_PREFIX . implode('|', $repairKeys);
    }

    /**
     * Repair keys already settled by a previous corrective ledger row.
     *
     * @return array<int, string>
     */
    protected function settledRepairKeys(int $productId, int $locationId): array
    {
        $reasons = Transaction::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('reason', 'like', mb_strtoupper(self::REPAIR_REASON_PREFIX, 'UTF-8') . '%')
            ->pluck('reason');

        $settled = [];

        foreach ($reasons as $reason) {
            $payload = mb_substr((string) $reason, mb_strlen(self::REPAIR_REASON_PREFIX));

            foreach (explode('|', $payload) as $key) {
                $key = trim($key);

                if ($key !== '') {
                    // Persisted text is upper-cased by BaseModel; compare in
                    // the same case as the generated keys.
                    $settled[] = mb_strtolower($key, 'UTF-8');
                }
            }
        }

        return array_values(array_unique($settled));
    }
}
