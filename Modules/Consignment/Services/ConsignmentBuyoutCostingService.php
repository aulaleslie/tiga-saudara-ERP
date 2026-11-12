<?php

namespace Modules\Consignment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutLine;
use Modules\Consignment\Entities\ConsignmentInventoryCoordination;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;

/**
 * Carrying-value REPLACEMENT per inventory-owner setting/product (not a second weighted receipt):
 *   V_after = Q*A - q*A + C ;  A_after = round(V_after / Q, 2)
 * Q = locked total physical quantity across the setting's locations (unchanged by acquisition), A = current
 * shared average, q = acquired unsold quantity, C = accepted inventory cost of those slices (all unsold slices
 * of one product in one buyout aggregated first). Sold-only documents are a no-op. Last-purchase price and
 * historical Sales/POS HPP are never touched.
 */
class ConsignmentBuyoutCostingService
{
    /**
     * Pure replacement formula. Throws on invalid inputs (never clamps or seeds a missing average).
     *
     * @return array{average_before: float, average_after: float, value_before: float, value_after: float, rounding_residual: float}
     */
    public static function replace(float $totalQuantity, ?float $average, float $acquiredQuantity, float $acquiredCost): array
    {
        $Q = ConsignmentBuyoutCapacityService::milli($totalQuantity);
        $q = ConsignmentBuyoutCapacityService::milli($acquiredQuantity);
        if ($average === null || ! is_finite($average) || $average < 0) {
            throw new \DomainException('Missing or invalid carrying average: reconcile it before the buyout.');
        }
        if (! is_finite($acquiredCost) || $acquiredCost < 0) {
            throw new \DomainException('Acquired inventory cost must be finite and non-negative.');
        }
        if ($Q <= 0) {
            throw new \DomainException('Total physical quantity must be positive to replace the carrying value.');
        }
        if ($q <= 0 || $q > $Q) {
            throw new \DomainException('Acquired quantity must be greater than zero and not exceed the physical quantity.');
        }

        $valueBefore = round($totalQuantity * $average, 4);
        $valueAfter = round($valueBefore - $acquiredQuantity * $average + $acquiredCost, 4);
        $exact = $valueAfter / $totalQuantity;
        $after = round($exact, 2);

        return [
            'average_before' => round($average, 2),
            'average_after' => $after,
            'value_before' => $valueBefore,
            'value_after' => $valueAfter,
            'rounding_residual' => round($exact - $after, 4),
        ];
    }

    /**
     * Plan the per-product replacement for an approved buyout under the caller's transaction and coordination
     * locks (reads Q and A with row locks). Does not write.
     *
     * @return array<int, array<string, mixed>> product_id => result (or blocker)
     */
    public function plan(ConsignmentBuyout $buyout, array $slices): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Costing plans must be computed inside the posting transaction.');
        }
        // Grouped by INVENTORY OWNER and product: a remote acquisition replaces the holding business's carrying value
        // only; the header (billing) business gets no stock or average change for it. Invoice discounts were already
        // allocated once across all slices by pricing, so each owner receives only its own slices' cost.
        $groups = [];
        $owners = ConsignmentBuyoutLine::where('consignment_buyout_id', $buyout->id)->get()->mapWithKeys(fn ($l) => [(int) $l->id => $l->inventoryOwnerSettingId()]);
        foreach ($slices as $s) {
            if ($s['line_type'] !== ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT) {
                continue; // sold slices change no inventory and no cost
            }
            $owner = (int) ($owners[(int) ($s['line_id'] ?? 0)] ?? $buyout->setting_id);
            $p = (int) $s['product_id'];
            $key = $owner === (int) $buyout->setting_id ? $p : $owner . ':' . $p;   // header-owned keys stay product ids
            $groups[$key]['owner'] = $owner;
            $groups[$key]['product'] = $p;
            $groups[$key]['q'] = ($groups[$key]['q'] ?? 0) + ConsignmentBuyoutCapacityService::milli($s['quantity']);
            $groups[$key]['c'] = ($groups[$key]['c'] ?? 0.0) + (float) $s['inventory_cost'];
        }

        $out = [];
        foreach ($groups as $key => $agg) {
            [$owner, $productId] = [$agg['owner'], $agg['product']];
            ConsignmentInventoryCoordination::lockFor($owner, $productId);
            $Q = (float) ProductStock::where('product_id', $productId)
                ->whereIn('location_id', DB::table('locations')->where('setting_id', $owner)->select('id'))
                ->lockForUpdate()->sum('quantity');
            $price = ProductPrice::where('product_id', $productId)->where('setting_id', $owner)->lockForUpdate()->first();
            $A = $price?->average_purchase_price;

            try {
                $out[$key] = self::replace($Q, $A === null ? null : (float) $A, ConsignmentBuyoutCapacityService::fromMilli($agg['q']), round($agg['c'], 2))
                    + ['product_id' => $productId, 'setting_id' => $owner, 'total_quantity' => $Q, 'acquired_quantity' => ConsignmentBuyoutCapacityService::fromMilli($agg['q']),
                        'acquired_cost_total' => round($agg['c'], 2), 'blocker' => null];
            } catch (\DomainException $e) {
                $out[$key] = ['product_id' => $productId, 'setting_id' => $owner, 'blocker' => $e->getMessage(), 'total_quantity' => $Q];
            }
        }

        return $out;
    }
}
