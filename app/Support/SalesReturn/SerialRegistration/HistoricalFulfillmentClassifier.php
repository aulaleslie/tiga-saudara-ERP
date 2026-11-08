<?php

namespace App\Support\SalesReturn\SerialRegistration;

use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\DispatchDetail;
use Modules\SalesReturn\Entities\ReturnSerialRegistration;

/**
 * Shared classifier for standard and POS sources (ordinary and bundle-component).
 *
 * Precedence of evidence:
 *  1. Serials registered by returns (return_serial_registrations.registered_serial_id) are
 *     return-created operational lineage and are removed from original-fulfillment evidence.
 *  2. Any remaining recorded serial ID that cannot be resolved to a ProductSerialNumber of the
 *     source product => INCONSISTENT (blocks the unknown-serial path).
 *  3. Explicit serialized indicator with no resolvable serial => INCONSISTENT.
 *  4. Remaining resolved serials => RECORDED_SERIAL.
 *  5. No serial evidence and no serialized indicator => QUANTITY_ONLY (legacy compatibility
 *     default; it does not claim to prove the historical catalog flag).
 */
class HistoricalFulfillmentClassifier
{
    /**
     * @param  string  $sourceKey  stable identity of the source, e.g. "dispatch_detail:12" or "pos_line:5:component:9"
     * @param  array<int,int|string|null>  $recordedSerialIds  raw serial IDs persisted on the source
     * @param  bool  $serializedIndicator  explicit evidence of serialized fulfillment (tracking snapshot, etc.)
     * @param  array<int,int>  $returnCreatedSerialIds  serial IDs known to be created by return registration
     */
    public function classify(
        string $sourceKey,
        int $productId,
        array $recordedSerialIds,
        bool $serializedIndicator = false,
        array $returnCreatedSerialIds = [],
        int|string|float $quantity = 0,
    ): SourceClassification {
        $ids = collect($recordedSerialIds)
            ->filter(fn ($id) => $id !== null && $id !== '' && is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $registered = collect($returnCreatedSerialIds)->map(fn ($id) => (int) $id)->all();
        $original = $ids->reject(fn (int $id) => in_array($id, $registered, true))->values();

        $resolved = $original->isEmpty()
            ? collect()
            : ProductSerialNumber::query()
                ->where('product_id', $productId)
                ->whereIn('id', $original->all())
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values();

        $fingerprint = $this->fingerprint($sourceKey, $productId, $original->all(), $serializedIndicator, $quantity);

        if ($original->isNotEmpty() && $resolved->count() !== $original->count()) {
            return new SourceClassification(
                SourceClassification::INCONSISTENT,
                SourceClassification::BASIS_UNRESOLVED_SERIAL_IDS,
                $resolved->all(),
                $fingerprint,
            );
        }

        if ($resolved->isNotEmpty()) {
            return new SourceClassification(
                SourceClassification::RECORDED_SERIAL,
                SourceClassification::BASIS_RECORDED_SERIALS,
                $resolved->all(),
                $fingerprint,
            );
        }

        if ($serializedIndicator) {
            return new SourceClassification(
                SourceClassification::INCONSISTENT,
                SourceClassification::BASIS_SERIALIZED_INDICATOR_WITHOUT_SERIALS,
                [],
                $fingerprint,
            );
        }

        return new SourceClassification(
            SourceClassification::QUANTITY_ONLY,
            SourceClassification::BASIS_NO_SERIAL_EVIDENCE,
            [],
            $fingerprint,
        );
    }

    /**
     * Fingerprint of ORIGINAL fulfillment evidence only. Return registrations are excluded, so
     * expected lifecycle changes do not alter it while external edits to the source do.
     *
     * @param  list<int>  $originalSerialIds
     */
    public function fingerprint(string $sourceKey, int $productId, array $originalSerialIds, bool $serializedIndicator, int|string|float $quantity): string
    {
        sort($originalSerialIds);

        return hash('sha256', json_encode([
            'source' => $sourceKey,
            'product' => $productId,
            'serials' => array_values($originalSerialIds),
            'serialized' => $serializedIndicator,
            'qty' => number_format((float) $quantity, 4, '.', ''),
        ]));
    }

    /**
     * Serial IDs that were created by return registration (never original fulfillment evidence).
     *
     * @param  array<int,int>|null  $candidateIds  restrict the lookup, or null for all
     * @return array<int,int>
     */
    public function returnCreatedSerialIds(?array $candidateIds = null): array
    {
        $query = ReturnSerialRegistration::query()->whereNotNull('registered_serial_id');
        if ($candidateIds !== null) {
            if ($candidateIds === []) {
                return [];
            }
            $query->whereIn('registered_serial_id', $candidateIds);
        }

        return $query->pluck('registered_serial_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Return-created serials whose registration originated from THIS dispatch line. A serial registered from
     * another source (e.g. later resold through a new transaction) is that transaction's own fulfillment evidence.
     *
     * @param  array<int,int>  $candidateIds
     * @return array<int,int>
     */
    public function returnCreatedSerialIdsForSource(array $candidateIds, int $dispatchDetailId): array
    {
        if ($candidateIds === []) {
            return [];
        }

        return ReturnSerialRegistration::query()
            ->whereNotNull('registered_serial_id')
            ->whereIn('registered_serial_id', array_map('intval', $candidateIds))
            ->where(function ($q) use ($dispatchDetailId) {
                $q->whereIn('sale_return_detail_id', \Modules\SalesReturn\Entities\SaleReturnDetail::query()
                    ->where('dispatch_detail_id', $dispatchDetailId)->select('id'))
                    ->orWhereIn('pos_return_line_id', \Modules\Pos\Entities\PosReturnLine::query()
                        ->where('dispatch_detail_id', $dispatchDetailId)->select('id'));
            })
            ->pluck('registered_serial_id')->map(fn ($id) => (int) $id)->all();
    }

    public static function standardSourceKey(int $dispatchDetailId): string
    {
        return 'dispatch_detail:'.$dispatchDetailId;
    }

    /**
     * Standard workflow source: one approved DispatchDetail. Evidence = serial rows anchored to the
     * dispatch line plus the dispatch line's own persisted serial list (text or IDs).
     */
    public function forDispatchDetail(DispatchDetail $detail, array $extraSerialIds = []): SourceClassification
    {
        $ids = array_map('intval', $extraSerialIds);
        $ids = array_merge($ids, ProductSerialNumber::query()
            ->where('dispatch_detail_id', $detail->id)
            ->pluck('id')->map(fn ($id) => (int) $id)->all());

        $listed = $detail->serial_numbers;
        if (is_string($listed)) {
            $listed = json_decode($listed, true);
        }
        foreach ((array) $listed as $entry) {
            if (is_array($entry)) {
                $entry = $entry['id'] ?? ($entry['serial_number'] ?? null);
            }
            if ($entry === null || $entry === '') {
                continue;
            }
            if (is_numeric($entry)) {
                $ids[] = (int) $entry;
                continue;
            }
            $resolved = ProductSerialNumber::query()
                ->where('product_id', $detail->product_id)
                ->where('serial_number', ProductSerialNumber::normalize((string) $entry))
                ->value('id');
            $ids[] = $resolved ? (int) $resolved : 0;   // 0 never resolves => inconsistent
        }

        $ids = array_values(array_unique($ids));

        return $this->classify(
            self::standardSourceKey((int) $detail->id),
            (int) $detail->product_id,
            $ids,
            false,
            $this->returnCreatedSerialIds(array_filter($ids)),
            $detail->dispatched_quantity ?? 0,
        );
    }
}
