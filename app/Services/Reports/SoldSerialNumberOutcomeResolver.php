<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Sale\Entities\Dispatch;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement;

class SoldSerialNumberOutcomeResolver
{
    /**
     * Resolve physical return receipts and approved replacement chains
     * for a collection of approved dispatch occurrences.
     *
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return Collection<int, array<string, mixed>>
     */
    public function resolve(Collection $candidates): Collection
    {
        if ($candidates->isEmpty()) {
            return collect();
        }

        // Suppress replacement-generated sale roots; they are only edges, never new customer sale roots.
        $roots = $candidates->filter(fn (array $item): bool => ! $this->isReplacementCandidate($item))->values();
        if ($roots->isEmpty()) {
            return collect();
        }

        $saleIds = $roots->pluck('sale_id')->unique()->values()->all();
        $productIds = $roots->pluck('product_id')->unique()->values()->all();
        $detailIds = $roots->pluck('dispatch_detail_id')->unique()->values()->all();

        $serialLookup = $this->loadSerialLookup($productIds, $roots->pluck('normalized_sn')->unique()->values()->all());
        $posReplacementEdges = $this->loadPosReplacementEdges($detailIds, $saleIds, $serialLookup);
        $standardReplacementEdges = $this->loadStandardReplacementEdges($saleIds, $serialLookup);

        // Collect all sale_ids and product_ids including replacement targets for receipt checking.
        $allSaleIds = array_values(array_unique(array_merge(
            $saleIds,
            $posReplacementEdges['target_sale_ids'],
            $standardReplacementEdges['target_sale_ids']
        )));
        $allProductIds = array_values(array_unique(array_merge(
            $productIds,
            $posReplacementEdges['target_product_ids'],
            $standardReplacementEdges['target_product_ids']
        )));

        $receiptEvidence = $this->loadReceiptEvidence($allSaleIds, $allProductIds);

        $resolved = [];

        foreach ($roots as $root) {
            $current = $root;
            $visited = [$this->nodeVisitKey($current) => true];

            while (true) {
                if (! $this->isPhysicallyReceived($current, $receiptEvidence, $serialLookup)) {
                    $resolved[] = [
                        'sale_id' => $root['sale_id'],
                        'sale_setting_id' => $root['sale_setting_id'],
                        'sale_date' => $root['sale_date'],
                        'dispatch_detail_id' => $root['dispatch_detail_id'],
                        'occurrence_index' => $root['occurrence_index'],
                        'product_id' => $current['product_id'],
                        'sn' => $current['sn'],
                        'fallback_product_name' => $current['fallback_product_name'] ?? $root['fallback_product_name'],
                    ];
                    break;
                }

                $next = $this->findReplacementNode(
                    $current,
                    $posReplacementEdges['edges'],
                    $standardReplacementEdges['edges'],
                    $serialLookup
                );

                if ($next === null) {
                    // Physically received with no approved replacement -> omitted.
                    break;
                }

                $visitKey = $this->nodeVisitKey($next);
                if (isset($visited[$visitKey])) {
                    // Cycle protection.
                    break;
                }
                $visited[$visitKey] = true;
                $current = $next;
            }
        }

        return collect($resolved);
    }

    protected function isReplacementCandidate(array $item): bool
    {
        return $item['pos_return_line_id'] !== null
            || $item['replacement_of_dispatch_detail_id'] !== null
            || $item['replacement_returned_serial_id'] !== null;
    }

    protected function nodeVisitKey(array $node): string
    {
        return ($node['dispatch_detail_id'] ?? 0) . '|' . $node['product_id'] . '|' . $node['normalized_sn'];
    }

    /**
     * @return array<string, int> Map of "product_id|NORMALIZED_SN" => serial_id
     */
    protected function loadSerialLookup(array $productIds, array $normalizedSerials): array
    {
        if ($productIds === [] || $normalizedSerials === []) {
            return [];
        }

        $rows = ProductSerialNumber::query()
            ->whereIn('product_id', $productIds)
            ->get(['id', 'product_id', 'serial_number']);

        $lookup = [];
        foreach ($rows as $row) {
            $key = $row->product_id . '|' . strtoupper(trim((string) $row->serial_number));
            $lookup[$key] = (int) $row->id;
        }

        return $lookup;
    }

    /**
     * @return array{
     *   by_detail_serial: array<string, bool>,
     *   by_sale_product_serial: array<string, array<int, string>>
     * }
     */
    protected function loadReceiptEvidence(array $saleIds, array $productIds): array
    {
        $byDetailSerial = [];
        $bySaleProductSerial = [];

        if ($saleIds === []) {
            return [
                'by_detail_serial' => $byDetailSerial,
                'by_sale_product_serial' => $bySaleProductSerial,
            ];
        }

        // 1. Standard return receipt via sale_returns (received_at IS NOT NULL) + return_serial_registrations
        $stdRegs = DB::table('return_serial_registrations as rsr')
            ->join('sale_return_details as srd', 'srd.id', '=', 'rsr.sale_return_detail_id')
            ->join('sale_returns as sr', 'sr.id', '=', 'srd.sale_return_id')
            ->leftJoin('product_serial_numbers as psn', 'psn.id', '=', 'rsr.registered_serial_id')
            ->whereIn('sr.sale_id', $saleIds)
            ->whereNotNull('sr.received_at')
            ->select([
                'sr.sale_id',
                'sr.received_at',
                'srd.product_id',
                'psn.serial_number as reg_sn',
                'rsr.proposed_serial',
            ])
            ->get();

        foreach ($stdRegs as $row) {
            $sn = strtoupper(trim((string) ($row->reg_sn ?: $row->proposed_serial)));
            if ($sn === '') {
                continue;
            }
            $key = $row->sale_id . '|' . $row->product_id . '|' . $sn;
            $bySaleProductSerial[$key][] = (string) $row->received_at;
        }

        // 2. POS return receipt via pos_returns (received_at IS NOT NULL) + return_serial_registrations
        $posRegs = DB::table('return_serial_registrations as rsr')
            ->join('pos_return_lines as prl', 'prl.id', '=', 'rsr.pos_return_line_id')
            ->join('pos_returns as pr', 'pr.id', '=', 'prl.pos_return_id')
            ->join('sale_details as sd', 'sd.id', '=', 'prl.sale_detail_id')
            ->leftJoin('product_serial_numbers as psn', 'psn.id', '=', 'rsr.registered_serial_id')
            ->whereIn('sd.sale_id', $saleIds)
            ->whereNotNull('pr.received_at')
            ->select([
                'sd.sale_id',
                'pr.received_at',
                'prl.product_id',
                'psn.serial_number as reg_sn',
                'rsr.proposed_serial',
            ])
            ->get();

        foreach ($posRegs as $row) {
            $sn = strtoupper(trim((string) ($row->reg_sn ?: $row->proposed_serial)));
            if ($sn === '') {
                continue;
            }
            $key = $row->sale_id . '|' . $row->product_id . '|' . $sn;
            $bySaleProductSerial[$key][] = (string) $row->received_at;
        }

        // 2b. Legacy received returns fallback via sale_return_details.serial_number_ids
        $legacyDetails = DB::table('sale_return_details as srd')
            ->join('sale_returns as sr', 'sr.id', '=', 'srd.sale_return_id')
            ->whereIn('sr.sale_id', $saleIds)
            ->whereNotNull('sr.received_at')
            ->whereNotNull('srd.serial_number_ids')
            ->select(['sr.sale_id', 'sr.received_at', 'srd.serial_number_ids'])
            ->get();

        if ($legacyDetails->isNotEmpty()) {
            $allSerialIds = [];
            $detailSerialMap = [];

            foreach ($legacyDetails as $ld) {
                $ids = json_decode((string) $ld->serial_number_ids, true);
                if (! is_array($ids)) {
                    continue;
                }
                foreach ($ids as $sid) {
                    $sid = (int) $sid;
                    if ($sid > 0) {
                        $allSerialIds[$sid] = true;
                        $detailSerialMap[] = [
                            'sale_id' => (int) $ld->sale_id,
                            'received_at' => (string) $ld->received_at,
                            'serial_id' => $sid,
                        ];
                    }
                }
            }

            if ($allSerialIds !== []) {
                $serialRows = ProductSerialNumber::query()
                    ->whereIn('id', array_keys($allSerialIds))
                    ->get(['id', 'product_id', 'serial_number'])
                    ->keyBy('id');

                foreach ($detailSerialMap as $entry) {
                    $psn = $serialRows->get($entry['serial_id']);
                    if (! $psn) {
                        continue;
                    }
                    $sn = strtoupper(trim((string) $psn->serial_number));
                    if ($sn === '') {
                        continue;
                    }
                    $key = $entry['sale_id'] . '|' . $psn->product_id . '|' . $sn;
                    $bySaleProductSerial[$key][] = $entry['received_at'];
                }
            }
        }

        // 3. Legacy/history SALE_RETURNED events attributed by reference
        if ($productIds !== []) {
            $saleReturnToSale = DB::table('sale_returns')
                ->whereIn('sale_id', $saleIds)
                ->pluck('sale_id', 'id')
                ->all();

            $saleReturnDetailToSale = DB::table('sale_return_details as srd')
                ->join('sale_returns as sr', 'sr.id', '=', 'srd.sale_return_id')
                ->whereIn('sr.sale_id', $saleIds)
                ->pluck('sr.sale_id', 'srd.id')
                ->all();

            $posReturnToSale = DB::table('pos_return_lines as prl')
                ->join('sale_details as sd', 'sd.id', '=', 'prl.sale_detail_id')
                ->whereIn('sd.sale_id', $saleIds)
                ->pluck('sd.sale_id', 'prl.pos_return_id')
                ->all();

            $histories = DB::table('serial_number_histories as snh')
                ->join('product_serial_numbers as psn', 'psn.id', '=', 'snh.product_serial_number_id')
                ->whereIn('psn.product_id', $productIds)
                ->where('snh.event_type', SerialNumberHistory::EVENT_SALE_RETURNED)
                ->whereNotNull('snh.reference_id')
                ->select([
                    'psn.product_id',
                    'psn.serial_number',
                    'snh.reference_type',
                    'snh.reference_id',
                    'snh.created_at',
                ])
                ->get();

            foreach ($histories as $h) {
                $sn = strtoupper(trim((string) $h->serial_number));
                if ($sn === '') {
                    continue;
                }

                $refType = (string) $h->reference_type;
                $refId = (int) $h->reference_id;
                $attributedSaleId = null;

                if (str_ends_with($refType, 'SaleReturnDetail') && isset($saleReturnDetailToSale[$refId])) {
                    $attributedSaleId = (int) $saleReturnDetailToSale[$refId];
                } elseif (str_ends_with($refType, 'SaleReturn') && isset($saleReturnToSale[$refId])) {
                    $attributedSaleId = (int) $saleReturnToSale[$refId];
                } elseif (str_ends_with($refType, 'PosReturn') && isset($posReturnToSale[$refId])) {
                    $attributedSaleId = (int) $posReturnToSale[$refId];
                } elseif (str_ends_with($refType, 'Sale') && in_array($refId, $saleIds, true)) {
                    $attributedSaleId = $refId;
                }

                if ($attributedSaleId !== null) {
                    $key = $attributedSaleId . '|' . $h->product_id . '|' . $sn;
                    $bySaleProductSerial[$key][] = (string) $h->created_at;
                }
            }
        }

        return [
            'by_detail_serial' => $byDetailSerial,
            'by_sale_product_serial' => $bySaleProductSerial,
        ];
    }

    protected function isPhysicallyReceived(array $node, array $receiptEvidence, array $serialLookup): bool
    {
        $key = $node['sale_id'] . '|' . $node['product_id'] . '|' . $node['normalized_sn'];
        $timestamps = $receiptEvidence['by_sale_product_serial'][$key] ?? [];

        if ($timestamps === []) {
            return false;
        }

        $dispatchTime = (string) ($node['dispatch_time'] ?? '');
        if ($dispatchTime === '') {
            return true;
        }

        foreach ($timestamps as $ts) {
            if ($ts === '' || $ts >= $dispatchTime) {
                return true;
            }
        }

        return false;
    }

    protected function loadPosReplacementEdges(array $detailIds, array $saleIds, array $serialLookup): array
    {
        $edges = [];
        $targetSaleIds = [];
        $targetProductIds = [];

        if ($detailIds === []) {
            return compact('edges', 'targetSaleIds', 'targetProductIds');
        }

        $frontier = array_values(array_unique(array_map('intval', $detailIds)));
        $seenDetailIds = array_flip($frontier);

        while ($frontier !== []) {
            $rows = DB::table('dispatch_details as dd')
                ->join('dispatches as d', 'd.id', '=', 'dd.dispatch_id')
                ->join('sales as s', 's.id', '=', 'dd.sale_id')
                ->leftJoin('sale_details as sd', 'sd.id', '=', 'dd.sale_detail_id')
                ->leftJoin('product_serial_numbers as ret_psn', 'ret_psn.id', '=', 'dd.replacement_returned_serial_id')
                ->where('d.status', Dispatch::STATUS_APPROVED)
                ->whereIn('dd.replacement_of_dispatch_detail_id', $frontier)
                ->select([
                    'dd.id as dispatch_detail_id',
                    'dd.sale_id',
                    'dd.product_id',
                    'dd.serial_numbers',
                    'dd.replacement_of_dispatch_detail_id',
                    'dd.replacement_returned_serial_id',
                    'ret_psn.serial_number as returned_sn',
                    'd.approved_at as dispatch_approved_at',
                    'd.created_at as dispatch_created_at',
                    'sd.product_name as fallback_product_name',
                ])
                ->get();

            $nextFrontier = [];

            foreach ($rows as $row) {
                $newDetailId = (int) $row->dispatch_detail_id;
                if (! isset($seenDetailIds[$newDetailId])) {
                    $seenDetailIds[$newDetailId] = true;
                    $nextFrontier[] = $newDetailId;
                }

                $serials = $this->parseSerials($row->serial_numbers);
                if ($serials === []) {
                    continue;
                }

                $targetSaleIds[] = (int) $row->sale_id;
                $targetProductIds[] = (int) $row->product_id;

                $firstSn = $serials[0];
                $targetNode = [
                    'sale_id' => (int) $row->sale_id,
                    'dispatch_detail_id' => $newDetailId,
                    'product_id' => (int) $row->product_id,
                    'sn' => $firstSn,
                    'normalized_sn' => strtoupper(trim($firstSn)),
                    'dispatch_time' => (string) ($row->dispatch_approved_at ?? $row->dispatch_created_at ?? ''),
                    'fallback_product_name' => $row->fallback_product_name ? (string) $row->fallback_product_name : '',
                ];

                if ($row->replacement_of_dispatch_detail_id && $row->returned_sn) {
                    $edgeKey = $row->replacement_of_dispatch_detail_id . '|' . strtoupper(trim((string) $row->returned_sn));
                    $edges[$edgeKey] = $targetNode;
                }
            }

            $frontier = $nextFrontier;
        }

        return [
            'edges' => $edges,
            'target_sale_ids' => array_values(array_unique($targetSaleIds)),
            'target_product_ids' => array_values(array_unique($targetProductIds)),
        ];
    }

    protected function loadStandardReplacementEdges(array $saleIds, array $serialLookup): array
    {
        $edges = [];
        $targetSaleIds = [];
        $targetProductIds = [];

        if ($saleIds === []) {
            return [
                'edges' => $edges,
                'target_sale_ids' => $targetSaleIds,
                'target_product_ids' => $targetProductIds,
            ];
        }

        $rows = DB::table('sale_return_item_settlements as sris')
            ->join('sale_returns as sr', 'sr.id', '=', 'sris.sale_return_id')
            ->leftJoin('sale_return_details as srd', 'srd.id', '=', 'sris.sale_return_detail_id')
            ->leftJoin('product_serial_numbers as ret_psn', 'ret_psn.id', '=', 'sris.product_serial_number_id')
            ->leftJoin('product_serial_numbers as disp_psn', 'disp_psn.id', '=', 'sris.dispatch_serial_number_id')
            ->whereIn('sr.sale_id', $saleIds)
            ->where('sris.status', SaleReturnItemSettlement::STATUS_DISPATCHED)
            ->where('sris.dispatch_kind', 'REPLACEMENT')
            ->where(function ($q): void {
                $q->whereNotNull('sris.dispatched_serial_number')
                    ->orWhereNotNull('sris.dispatch_serial_number_id');
            })
            ->select([
                'sr.sale_id',
                'srd.product_id',
                'ret_psn.serial_number as returned_sn',
                'disp_psn.serial_number as disp_sn',
                'disp_psn.product_id as disp_product_id',
                'sris.dispatched_serial_number',
                'sris.updated_at',
            ])
            ->get();

        foreach ($rows as $row) {
            $returnedSn = strtoupper(trim((string) $row->returned_sn));
            $dispSn = trim((string) ($row->dispatched_serial_number ?: $row->disp_sn));
            if ($returnedSn === '' || $dispSn === '') {
                continue;
            }

            $productId = (int) ($row->disp_product_id ?: $row->product_id);
            $targetSaleIds[] = (int) $row->sale_id;
            $targetProductIds[] = $productId;

            $edgeKey = $row->sale_id . '|' . $productId . '|' . $returnedSn;
            $edges[$edgeKey] = [
                'sale_id' => (int) $row->sale_id,
                'dispatch_detail_id' => 0,
                'product_id' => $productId,
                'sn' => $dispSn,
                'normalized_sn' => strtoupper($dispSn),
                'dispatch_time' => (string) ($row->updated_at ?? ''),
                'fallback_product_name' => '',
            ];
        }

        return [
            'edges' => $edges,
            'target_sale_ids' => array_values(array_unique($targetSaleIds)),
            'target_product_ids' => array_values(array_unique($targetProductIds)),
        ];
    }

    protected function findReplacementNode(
        array $current,
        array $posEdges,
        array $standardEdges,
        array $serialLookup
    ): ?array {
        $detailKey = ($current['dispatch_detail_id'] ?? 0) . '|' . $current['normalized_sn'];
        if (isset($posEdges[$detailKey])) {
            return $posEdges[$detailKey];
        }

        $stdKey = $current['sale_id'] . '|' . $current['product_id'] . '|' . $current['normalized_sn'];
        if (isset($standardEdges[$stdKey])) {
            return $standardEdges[$stdKey];
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function parseSerials(mixed $raw): array
    {
        if (is_array($raw)) {
            $items = $raw;
        } elseif (is_string($raw)) {
            $decoded = json_decode(trim($raw), true);
            $items = is_array($decoded) ? $decoded : (preg_split('/[\r\n,]+/', trim($raw)) ?: []);
        } else {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $result[] = trim((string) $item);
            }
        }

        return $result;
    }
}
