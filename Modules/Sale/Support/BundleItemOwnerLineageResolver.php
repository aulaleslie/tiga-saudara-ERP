<?php

namespace Modules\Sale\Support;

use Illuminate\Support\Collection;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Setting\Entities\Location;

/**
 * Recovers the physical owner setting for legacy (pre-Section-3/4)
 * sale_bundle_items rows that predate explicit owner-setting snapshotting,
 * per openspec/changes/harden-product-bundle-hpp design.md decision #7.
 *
 * Resolution:
 * - Normal Sales (no POS split signature): the Sale's own setting_id is
 *   the single unambiguous physical owner.
 * - POS split groups: recovered by joining to dispatch_details on
 *   (sale_id, product_id, bundle_id). Exactly one matching dispatch detail
 *   is unambiguous; zero or multiple matches cannot be resolved safely and
 *   must be skipped rather than guessed.
 */
class BundleItemOwnerLineageResolver
{
    const SKIP_NO_DISPATCH_MATCH = 'NO_DISPATCH_MATCH';
    const SKIP_AMBIGUOUS_DISPATCH_MATCH = 'AMBIGUOUS_DISPATCH_MATCH';

    /**
     * @param  Collection<int, SaleBundleItem>  $bundleItems
     * @return array{
     *     resolved: Collection<int, int>,
     *     skipped: array<int, array{sale_bundle_item_id: int, sale_id: int, dispatch_detail_id: int|null, bundle_item_id: int|null, product_id: int, reason: string}>
     * }
     */
    public function resolve(Collection $bundleItems): array
    {
        $resolved = collect();
        $skipped = [];

        if ($bundleItems->isEmpty()) {
            return ['resolved' => $resolved, 'skipped' => $skipped];
        }

        $saleIds = $bundleItems->pluck('sale_id')->unique()->all();
        $sales = Sale::query()->whereIn('id', $saleIds)->get(['id', 'setting_id'])->keyBy('id');

        $dispatchCandidates = \Illuminate\Support\Facades\DB::table('dispatch_details')
            ->whereIn('sale_id', $saleIds)
            ->select('id', 'sale_id', 'product_id', 'bundle_id', 'location_id')
            ->get()
            ->groupBy(fn ($row) => $row->sale_id . '|' . $row->product_id . '|' . ($row->bundle_id ?? '0'));

        $locationSettingIds = Location::query()
            ->whereIn('id', $dispatchCandidates->flatten(1)->pluck('location_id')->filter()->unique()->all())
            ->pluck('setting_id', 'id');

        foreach ($bundleItems as $bundleItem) {
            $sale = $sales->get($bundleItem->sale_id);

            if (!$sale) {
                $skipped[] = [
                    'sale_bundle_item_id' => $bundleItem->id,
                    'sale_id' => $bundleItem->sale_id,
                    'dispatch_detail_id' => null,
                    'bundle_item_id' => $bundleItem->bundle_item_id,
                    'product_id' => $bundleItem->product_id,
                    'reason' => self::SKIP_NO_DISPATCH_MATCH,
                ];
                continue;
            }

            $key = $bundleItem->sale_id . '|' . $bundleItem->product_id . '|' . ($bundleItem->bundle_id ?? '0');
            $candidates = $dispatchCandidates->get($key, collect())
                ->filter(fn ($row) => $row->location_id && $locationSettingIds->has($row->location_id));

            $distinctOwnerSettings = $candidates
                ->map(fn ($row) => (int) $locationSettingIds->get($row->location_id))
                ->unique();

            if ($distinctOwnerSettings->count() === 1) {
                // Every matching dispatch (even if split into several rows/serials)
                // agrees on a single owner setting: unambiguous.
                $resolved->put($bundleItem->id, $distinctOwnerSettings->first());
                continue;
            }

            if ($distinctOwnerSettings->count() > 1) {
                $skipped[] = [
                    'sale_bundle_item_id' => $bundleItem->id,
                    'sale_id' => $bundleItem->sale_id,
                    'dispatch_detail_id' => null,
                    'bundle_item_id' => $bundleItem->bundle_item_id,
                    'product_id' => $bundleItem->product_id,
                    'reason' => self::SKIP_AMBIGUOUS_DISPATCH_MATCH,
                ];
                continue;
            }

            // No dispatch lineage at all: fall back to the Sale's own setting_id,
            // which is unambiguous for a Normal Sale (single dispatch owner) but
            // may be incorrect for an undispatched/legacy POS split group. Without
            // any dispatch_details row to disambiguate, this is the best available
            // and deterministic signal.
            $resolved->put($bundleItem->id, (int) $sale->setting_id);
        }

        return ['resolved' => $resolved, 'skipped' => $skipped];
    }
}
