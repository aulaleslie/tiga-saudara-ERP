<?php

namespace Modules\Pos\Services;

use App\Support\SalesLocationResolver;
use Illuminate\Support\Facades\DB;

class PosProductSearchService
{
    public function search(int $settingId, string $query, int $limit = 10): array
    {
        $normalizedQuery = mb_strtolower(trim($query), 'UTF-8');
        $safeLimit = max(1, min(20, $limit));

        if ($normalizedQuery === '') {
            return $this->emptyPayload($query, $safeLimit);
        }

        $allowedLocationIds = SalesLocationResolver::resolveLocationIds($settingId)
            ->filter(fn ($locationId) => (int) $locationId > 0)
            ->map(fn ($locationId) => (int) $locationId)
            ->values()
            ->all();

        if ($allowedLocationIds === []) {
            return $this->emptyPayload($query, $safeLimit);
        }

        $terms = array_values(array_filter(explode(' ', $normalizedQuery)));
        $likeQuery = '%' . $normalizedQuery . '%';
        $availableQtyExpression = $this->availableQtyExpression($allowedLocationIds);

        $productBarcodeExactExpr = 'LOWER(COALESCE(p.barcode, \'\')) = ?';
        $conversionBarcodeExactExpr = 'EXISTS (SELECT 1 FROM product_unit_conversions puc WHERE puc.product_id = p.id AND LOWER(COALESCE(puc.barcode, \'\')) = ?)';
        $barcodeExactExpr = '(' . $productBarcodeExactExpr . ' OR ' . $conversionBarcodeExactExpr . ')';

        $productBarcodePartialExpr = 'LOWER(COALESCE(p.barcode, \'\')) LIKE ?';
        $conversionBarcodePartialExpr = 'EXISTS (SELECT 1 FROM product_unit_conversions puc WHERE puc.product_id = p.id AND LOWER(COALESCE(puc.barcode, \'\')) LIKE ?)';
        $barcodePartialExpr = '(' . $productBarcodePartialExpr . ' OR ' . $conversionBarcodePartialExpr . ')';

        $skuExactExpr = 'LOWER(COALESCE(p.product_code, \'\')) = ?';
        $skuPartialExpr = 'LOWER(COALESCE(p.product_code, \'\')) LIKE ?';
        $namePartialExpr = 'LOWER(COALESCE(p.product_name, \'\')) LIKE ?';

        $rows = DB::table('products as p')
            ->leftJoin('product_prices as pp', function ($join) use ($settingId) {
                $join->on('pp.product_id', '=', 'p.id')
                    ->where('pp.setting_id', '=', $settingId);
            })
            ->where('p.setting_id', $settingId)
            ->where('p.stock_managed', true)
            ->whereRaw($availableQtyExpression . ' > 0')
            ->where(function ($query) use (
                $barcodeExactExpr,
                $skuExactExpr,
                $barcodePartialExpr,
                $skuPartialExpr,
                $namePartialExpr,
                $normalizedQuery,
                $terms
            ) {
                $query->where(function ($q) use ($barcodeExactExpr, $skuExactExpr, $normalizedQuery) {
                    $q->whereRaw($barcodeExactExpr, [$normalizedQuery, $normalizedQuery])
                      ->orWhereRaw($skuExactExpr, [$normalizedQuery]);
                });

                $query->orWhere(function ($multiTermQuery) use ($terms, $barcodePartialExpr, $skuPartialExpr, $namePartialExpr) {
                    foreach ($terms as $term) {
                        $termLike = '%' . $term . '%';
                        $multiTermQuery->where(function ($q) use ($termLike, $barcodePartialExpr, $skuPartialExpr, $namePartialExpr) {
                            $q->whereRaw($barcodePartialExpr, [$termLike, $termLike])
                              ->orWhereRaw($skuPartialExpr, [$termLike])
                              ->orWhereRaw($namePartialExpr, [$termLike]);
                        });
                    }
                });
            })
            ->select([
                'p.id',
                'p.product_name',
                'p.product_code',
                'p.barcode',
                'p.serial_number_required',
            ])
            ->selectRaw($availableQtyExpression . ' as available_qty')
            ->selectRaw('COALESCE(pp.sale_price, p.sale_price, 0) as sale_price')
            ->selectRaw('CASE WHEN EXISTS (SELECT 1 FROM product_bundles pb WHERE pb.parent_product_id = p.id) THEN 1 ELSE 0 END as is_bundle_parent')
            ->selectRaw('CASE WHEN ' . $barcodeExactExpr . ' THEN 1 ELSE 0 END as barcode_exact_match', [$normalizedQuery, $normalizedQuery])
            ->selectRaw('CASE WHEN ' . $skuExactExpr . ' THEN 1 ELSE 0 END as sku_exact_match', [$normalizedQuery])
            ->selectRaw('CASE WHEN ' . $barcodePartialExpr . ' THEN 1 ELSE 0 END as barcode_partial_match', [$likeQuery, $likeQuery])
            ->selectRaw('CASE WHEN ' . $skuPartialExpr . ' THEN 1 ELSE 0 END as sku_partial_match', [$likeQuery])
            ->selectRaw('CASE WHEN ' . $namePartialExpr . ' THEN 1 ELSE 0 END as name_partial_match', [$likeQuery])
            ->orderByDesc('barcode_exact_match')
            ->orderByDesc('sku_exact_match')
            ->orderByDesc('barcode_partial_match')
            ->orderByDesc('sku_partial_match')
            ->orderByDesc('name_partial_match')
            ->orderBy('p.product_name')
            ->orderBy('p.id')
            ->limit($safeLimit)
            ->get();

        $results = $rows->map(function ($row) {
            $matchedBy = 'name_partial';

            if ((int) $row->barcode_exact_match === 1) {
                $matchedBy = 'barcode_exact';
            } elseif ((int) $row->sku_exact_match === 1) {
                $matchedBy = 'sku_exact';
            } elseif ((int) $row->barcode_partial_match === 1) {
                $matchedBy = 'barcode_partial';
            } elseif ((int) $row->sku_partial_match === 1) {
                $matchedBy = 'sku_partial';
            }

            return [
                'id' => (int) $row->id,
                'product_name' => (string) $row->product_name,
                'product_code' => (string) ($row->product_code ?? ''),
                'barcode' => $row->barcode !== null ? (string) $row->barcode : null,
                'available_qty' => (int) $row->available_qty,
                'sale_price' => (float) $row->sale_price,
                'serial_number_required' => (bool) $row->serial_number_required,
                'matched_by' => $matchedBy,
                'is_bundle_parent' => (bool) $row->is_bundle_parent,
            ];
        })->values();

        $autoSelectMatch = $results->firstWhere('matched_by', 'barcode_exact');
        $autoSelectProductId = null;

        if (is_array($autoSelectMatch)) {
            $candidateId = (int) ($autoSelectMatch['id'] ?? 0);
            $autoSelectProductId = $candidateId > 0 ? $candidateId : null;
        }

        return [
            'query' => $query,
            'results' => $results->all(),
            'meta' => [
                'limit' => $safeLimit,
                'result_count' => $results->count(),
                'auto_select_product_id' => $autoSelectProductId,
            ],
        ];
    }

    private function emptyPayload(string $query, int $limit): array
    {
        return [
            'query' => $query,
            'results' => [],
            'meta' => [
                'limit' => $limit,
                'result_count' => 0,
                'auto_select_product_id' => null,
            ],
        ];
    }

    private function availableQtyExpression(array $allowedLocationIds): string
    {
        $ids = implode(',', array_map('intval', $allowedLocationIds));

        return 'COALESCE((SELECT SUM(ps.quantity) FROM product_stocks ps WHERE ps.product_id = p.id AND ps.location_id IN (' . $ids . ')), 0)';
    }
}
