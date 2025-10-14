<?php

namespace App\Support\SalesReturn;

use Illuminate\Support\Collection;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\SalesReturn\Entities\SaleReturnDetail;

class SaleReturnEligibilityService
{
    /**
     * Sales that are eligible for returns must already be dispatched.
     */
    public const ELIGIBLE_STATUSES = [
        Sale::STATUS_DISPATCHED,
        Sale::STATUS_DISPATCHED_PARTIALLY,
    ];

    /**
     * Determine if the given sale is eligible for the return workflow.
     */
    public function isSaleEligible(Sale $sale): bool
    {
        return in_array($sale->status, self::ELIGIBLE_STATUSES, true);
    }

    /**
     * Build the rows that represent returnable dispatch lines for the sale.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function buildReturnableRows(Sale $sale, ?int $excludeSaleReturnId = null): Collection
    {
        $sale->loadMissing([
            'saleDetails' => function ($query) {
                $query->with('bundleItems');
            },
            'saleDetails.product',
        ]);

        $dispatchDetails = DispatchDetail::query()
            ->with(['product:id,product_name,product_code,serial_number_required', 'location:id,name'])
            ->where('sale_id', $sale->id)
            ->get();

        if ($dispatchDetails->isEmpty()) {
            return collect();
        }

        $dispatchIds = $dispatchDetails->pluck('id');

        $returnDetails = SaleReturnDetail::query()
            ->with('saleReturn:id,approval_status')
            ->whereIn('dispatch_detail_id', $dispatchIds)
            ->when($excludeSaleReturnId, function ($query) use ($excludeSaleReturnId) {
                $query->where('sale_return_id', '!=', $excludeSaleReturnId);
            })
            ->get(['sale_return_id', 'dispatch_detail_id', 'quantity', 'serial_number_ids']);

        $validReturnDetails = $returnDetails->filter(function (SaleReturnDetail $detail) {
            $status = strtolower(optional($detail->saleReturn)->approval_status ?? '');

            return $status !== 'rejected';
        });

        $returnedQuantities = $validReturnDetails
            ->groupBy('dispatch_detail_id')
            ->map(fn ($group) => (int) $group->sum('quantity'));

        $serialsReturned = $validReturnDetails
            ->groupBy('dispatch_detail_id')
            ->map(fn ($group) => $group->sum(function (SaleReturnDetail $detail) {
                return collect($detail->serial_number_ids ?? [])->count();
            }));

        $serialCounts = ProductSerialNumber::query()
            ->selectRaw('dispatch_detail_id, COUNT(*) as serial_count')
            ->whereIn('dispatch_detail_id', $dispatchIds)
            ->groupBy('dispatch_detail_id')
            ->pluck('serial_count', 'dispatch_detail_id');

        $saleDetailsByProduct = $sale->saleDetails->groupBy('product_id');

        $bundleItems = $sale->saleDetails
            ->flatMap(function (SaleDetails $detail) {
                return $detail->bundleItems->map(function (SaleBundleItem $item) use ($detail) {
                    return [
                        'product_id' => $item->product_id,
                        'bundle_sale_detail_id' => $detail->id,
                        'bundle_name' => $detail->product_name ?? $item->name,
                        'bundle_product_code' => $detail->product_code ?? null,
                        'bundle_quantity' => (int) $detail->quantity,
                        'component_quantity' => (int) $item->quantity,
                    ];
                });
            })
            ->filter(fn ($item) => ! empty($item['product_id']))
            ->groupBy('product_id');

        return $dispatchDetails
            ->map(function (DispatchDetail $detail) use ($saleDetailsByProduct, $returnedQuantities, $serialCounts, $serialsReturned, $bundleItems) {
                $dispatched = (int) ($detail->dispatched_quantity ?? 0);
                $returned = (int) ($returnedQuantities->get($detail->id) ?? 0);
                $available = max($dispatched - $returned, 0);

                if ($available <= 0) {
                    return null;
                }

                /** @var \Modules\Sale\Entities\SaleDetails|null $saleDetail */
                $saleDetail = optional($saleDetailsByProduct->get($detail->product_id))->first();

                $unitPrice = $saleDetail
                    ? (float) ($saleDetail->unit_price ?? $saleDetail->price ?? 0)
                    : 0.0;

                $serialTotal = (int) ($serialCounts->get($detail->id) ?? 0);
                $serialReturnedCount = (int) ($serialsReturned->get($detail->id) ?? 0);
                $serialAvailable = max(min($serialTotal - $serialReturnedCount, $available), 0);

                $bundleContext = optional($bundleItems->get($detail->product_id))
                    ->map(function ($bundle) {
                        return [
                            'bundle_sale_detail_id' => $bundle['bundle_sale_detail_id'],
                            'bundle_name' => $bundle['bundle_name'],
                            'bundle_product_code' => $bundle['bundle_product_code'],
                            'bundle_quantity' => $bundle['bundle_quantity'],
                            'component_quantity' => $bundle['component_quantity'],
                        ];
                    })
                    ->values()
                    ->all() ?? [];

                return [
                    'dispatch_detail_id' => $detail->id,
                    'sale_detail_id' => $saleDetail->id ?? null,
                    'product_id' => $detail->product_id,
                    'product_name' => optional($detail->product)->product_name
                        ?? optional($saleDetail?->product)->product_name
                        ?? '-',
                    'product_code' => optional($detail->product)->product_code
                        ?? optional($saleDetail?->product)->product_code,
                    'serial_number_required' => (bool) (optional($detail->product)->serial_number_required ?? false),
                    'serial_numbers' => [],
                    'serial_total' => $serialTotal,
                    'serial_returned' => $serialReturnedCount,
                    'serial_available' => $serialAvailable,
                    'quantity' => 0,
                    'available_quantity' => $available,
                    'dispatched_quantity' => $dispatched,
                    'returned_quantity' => $returned,
                    'unit_price' => $unitPrice,
                    'total' => 0,
                    'location_id' => $detail->location_id,
                    'location_name' => optional($detail->location)->name,
                    'tax_id' => $detail->tax_id ?? null,
                    'bundle_context' => $bundleContext,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Summarise eligibility data for the sale, including returnable rows and meta information.
     */
    public function summariseSale(Sale $sale, ?int $excludeSaleReturnId = null): array
    {
        $rows = $this->buildReturnableRows($sale, $excludeSaleReturnId);

        $bundleLineCount = $rows->filter(function (array $row) {
            return ! empty($row['bundle_context']);
        })->count();

        return [
            'rows' => $rows,
            'returnable_lines' => $rows->count(),
            'total_available_quantity' => (int) $rows->sum('available_quantity'),
            'requires_serials' => $rows->contains(fn ($row) => ! empty($row['serial_number_required'])),
            'bundle_lines' => $bundleLineCount,
        ];
    }
}

