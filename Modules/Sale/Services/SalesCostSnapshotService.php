<?php

namespace Modules\Sale\Services;

use Carbon\Carbon;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;

class SalesCostSnapshotService
{
    const SOURCE_NON_STOCK_MANAGED = 'NON_STOCK_MANAGED';
    const SOURCE_MISSING_AVERAGE_PRICE = 'MISSING_AVERAGE_PRICE';
    const SOURCE_CURRENT_AVERAGE_PRICE = 'CURRENT_AVERAGE_PRICE';
    const SOURCE_NOT_FULFILLED_BY_GROUP = 'NOT_FULFILLED_BY_GROUP';

    public function __construct(
        protected AverageCostResolver $resolver
    ) {
    }

    /**
     * Snapshot the cost for a sale detail and, when present, its bundle components.
     * Non-stock managed products (services) receive 0 cost.
     * Stock managed products without a resolvable positive average receive 0 cost
     * with a MISSING_AVERAGE_PRICE warning source.
     *
     * Snapshotting is against the physical owner setting (defaults to the Sale's
     * own setting_id for Normal Sales, where there is a single dispatch owner).
     * Callers that post physically-partitioned groups (POS split posting) must pass
     * the group's own physical owner setting explicitly, since it can differ from
     * the Sale's own setting_id.
     *
     * $parentNotFulfilledByGroup marks a POS split-posting placeholder parent row:
     * the group persisted a SaleDetails row for bookkeeping/revenue purposes but did
     * not physically fulfill the bundle parent itself (only some components). Such a
     * row always receives zero parent cost with NOT_FULFILLED_BY_GROUP, regardless of
     * the product's own stock_managed classification, and never triggers a warning.
     *
     * @return array<int, array{level: string, message: string, product_id: int|null, sale_bundle_item_id: int|null}>
     *     Non-blocking missing-cost warnings for the caller to surface after commit.
     */
    public function snapshotSaleDetailCost(
        SaleDetails $saleDetail,
        ?Carbon $snapshotAt = null,
        ?int $ownerSettingId = null,
        bool $parentNotFulfilledByGroup = false
    ): array {
        $snapshotAt = $snapshotAt ?? now();
        $sale = $saleDetail->sale;
        $ownerSettingId = $ownerSettingId ?? ($sale ? $sale->setting_id : null);

        $warnings = [];

        $this->snapshotDetail($saleDetail, $ownerSettingId, $snapshotAt, $parentNotFulfilledByGroup, $warnings);

        foreach ($saleDetail->bundleItems()->with('product')->get() as $bundleItem) {
            $this->snapshotBundleItem($bundleItem, $ownerSettingId, $snapshotAt, $warnings);
        }

        return $warnings;
    }

    protected function snapshotDetail(SaleDetails $saleDetail, ?int $ownerSettingId, Carbon $snapshotAt, bool $parentNotFulfilledByGroup, array &$warnings): void
    {
        $saleDetail->cost_snapshot_at = $snapshotAt;

        if ($parentNotFulfilledByGroup) {
            $saleDetail->cost_unit_snapshot = 0;
            $saleDetail->cost_total_snapshot = 0;
            $saleDetail->cost_snapshot_source = self::SOURCE_NOT_FULFILLED_BY_GROUP;
            return;
        }

        $product = $saleDetail->product;

        if (!$product || !$product->stock_managed) {
            $saleDetail->cost_unit_snapshot = 0;
            $saleDetail->cost_total_snapshot = 0;
            $saleDetail->cost_snapshot_source = self::SOURCE_NON_STOCK_MANAGED;
            return;
        }

        $result = $this->resolver->resolve($product, $ownerSettingId);

        if ($result['is_missing']) {
            $saleDetail->cost_unit_snapshot = 0;
            $saleDetail->cost_total_snapshot = 0;
            $saleDetail->cost_snapshot_source = self::SOURCE_MISSING_AVERAGE_PRICE;

            $warnings[] = [
                'level' => 'warning',
                'message' => "Missing average purchase price for product #{$product->id} ({$product->product_name}); HPP recorded as 0.",
                'product_id' => $product->id,
                'sale_bundle_item_id' => null,
            ];
            return;
        }

        $qty = (float) $saleDetail->quantity;

        $saleDetail->cost_unit_snapshot = round($result['unit_cost'], 6);
        $saleDetail->cost_total_snapshot = round($result['unit_cost'] * $qty, 2);
        $saleDetail->cost_snapshot_source = self::SOURCE_CURRENT_AVERAGE_PRICE;
    }

    protected function snapshotBundleItem(SaleBundleItem $bundleItem, ?int $ownerSettingId, Carbon $snapshotAt, array &$warnings): void
    {
        $bundleItem->cost_snapshot_at = $snapshotAt;

        $product = $bundleItem->product;

        if (!$product || !$product->stock_managed) {
            $bundleItem->cost_unit_snapshot = 0;
            $bundleItem->cost_total_snapshot = 0;
            $bundleItem->cost_snapshot_source = self::SOURCE_NON_STOCK_MANAGED;
            $bundleItem->cost_snapshot_setting_id = null;
            $bundleItem->cost_snapshot_setting_is_pkp = null;
            $bundleItem->save();
            return;
        }

        $result = $this->resolver->resolve($product, $ownerSettingId);

        if ($result['is_missing']) {
            $bundleItem->cost_unit_snapshot = 0;
            $bundleItem->cost_total_snapshot = 0;
            $bundleItem->cost_snapshot_source = self::SOURCE_MISSING_AVERAGE_PRICE;
            $bundleItem->cost_snapshot_setting_id = null;
            $bundleItem->cost_snapshot_setting_is_pkp = null;
            $bundleItem->save();

            $warnings[] = [
                'level' => 'warning',
                'message' => "Missing average purchase price for bundle component product #{$product->id} ({$product->product_name}); HPP recorded as 0.",
                'product_id' => $product->id,
                'sale_bundle_item_id' => $bundleItem->id,
            ];
            return;
        }

        $qty = (float) $bundleItem->quantity;

        $bundleItem->cost_unit_snapshot = round($result['unit_cost'], 6);
        $bundleItem->cost_total_snapshot = round($result['unit_cost'] * $qty, 2);
        $bundleItem->cost_snapshot_source = self::SOURCE_CURRENT_AVERAGE_PRICE;
        $bundleItem->cost_snapshot_setting_id = $result['setting_id'];
        $bundleItem->cost_snapshot_setting_is_pkp = $result['setting_is_pkp'];
        $bundleItem->save();
    }
}
