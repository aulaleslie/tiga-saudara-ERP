<?php

namespace Modules\Consignment\Services;

use Illuminate\Support\Collection;
use Modules\Consignment\Entities\ConsignmentProvenanceEvent as Event;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;

/**
 * Receipt-rooted custody projection (track-transferred-consignment-provenance 1.3).
 *
 * Rebuildable from the approved receiving detail plus append-only physical custody events
 * (receipt root, transfers, held moves) and derived sold/acquired exits;
 * never an authority of its own. Each receipt quantity sits in exactly one disjoint
 * disposition: SELLABLE / HELD / TRANSIT per (location, setting), or the SOLD and
 * ACQUIRED exits. Supplier billing is a financial classification of SOLD and is never
 * subtracted again here.
 *
 * Root: an explicit RECEIPT event (EXTERNAL -> SELLABLE) if one exists; otherwise the
 * approved receipt itself is the implicit direct custody at its receiving location.
 */
class ConsignmentCustodyProjection
{
    public const LOCATED_STATES = [Event::STATE_SELLABLE, Event::STATE_HELD, Event::STATE_TRANSIT];

    /**
     * @return array{
     *   receipt_id:int, original_setting_id:int, received:float,
     *   custody: array<int, array{state:string, location_id:?int, setting_id:?int, quantity:float}>,
     *   sold:float, acquired:float, blockers: string[], conserved: bool
     * }
     */
    /**
     * @param  array<int, array{state:string, location_id:?int, setting_id:?int, quantity:float|string}>  $derivedExits
     *         SOLD/ACQUIRED exits read from their authoritative records (sold attribution, finalized buyouts),
     *         each taken from the located SELLABLE custody at that location. Never also stored as events.
     */
    public function forReceipt(ConsignmentReceivingDetail $detail, ?Collection $events = null, array $derivedExits = []): array
    {
        $detail->loadMissing('receiving');
        $events ??= Event::where('consignment_receiving_detail_id', $detail->id)->orderBy('effective_at')->orderBy('id')->get();

        $received = ConsignmentBuyoutCapacityService::milli($detail->received_base_quantity ?? $detail->quantity_received);
        $balances = [];   // "STATE|loc|setting" => milli
        $exits = [Event::STATE_SOLD => 0, Event::STATE_ACQUIRED => 0];
        $blockers = [];

        if (! $events->contains(fn ($e) => $e->from_state === Event::STATE_EXTERNAL)) {
            $this->add($balances, Event::STATE_SELLABLE, $detail->receiving->location_id, $detail->receiving->setting_id, $received);
        }

        foreach ($events as $e) {
            $q = ConsignmentBuyoutCapacityService::milli($e->base_quantity);
            if ($q <= 0) {
                $blockers[] = "Event #{$e->id} has a non-positive quantity.";
                continue;
            }
            if ($e->from_state !== Event::STATE_EXTERNAL) {
                $this->move($balances, $exits, $e->from_state, $e->from_location_id, $e->from_setting_id, -$q);
            }
            if ($e->to_state !== Event::STATE_EXTERNAL) {
                $this->move($balances, $exits, $e->to_state, $e->to_location_id, $e->to_setting_id, $q);
            }
        }

        foreach ($derivedExits as $x) {
            if (isset($x['blocker'])) {
                $blockers[] = $x['blocker'];
                continue;
            }
            $q = ConsignmentBuyoutCapacityService::milli($x['quantity']);
            if ($q <= 0) {
                continue;
            }
            if ($x['state'] === Event::STATE_HELD) {
                // Returned but not released: back in supplier custody at the return location, not sellable.
                // (The return already left the SOLD exit, which had taken it from the sale location.)
                $this->subtractSellable($balances, $x['from_location_id'] ?? $x['location_id'], $q);
                $this->add($balances, Event::STATE_HELD, $x['location_id'], $x['setting_id'], $q);
                continue;
            }
            $this->subtractSellable($balances, $x['location_id'], $q, $x['setting_id']);
            $this->move($balances, $exits, $x['state'], null, null, $q);
        }

        $custody = [];
        $located = 0;
        foreach ($balances as $key => $m) {
            [$state, $loc, $setting] = explode('|', $key);
            if ($m < 0) {
                $blockers[] = "Unexplained negative {$state} balance at location {$loc}.";
            }
            if ($m === 0) {
                continue;
            }
            $located += $m;
            $custody[] = [
                'state' => $state,
                'location_id' => $loc === '' ? null : (int) $loc,
                'setting_id' => $setting === '' ? null : (int) $setting,
                'quantity' => ConsignmentBuyoutCapacityService::fromMilli($m),
            ];
        }
        foreach ($exits as $state => $m) {
            if ($m < 0) {
                $blockers[] = "Unexplained negative {$state} exit.";
            }
        }

        $conserved = $located + $exits[Event::STATE_SOLD] + $exits[Event::STATE_ACQUIRED] === $received;
        if (! $conserved) {
            $blockers[] = 'Receipt quantity is not conserved across custody, sold and acquired dispositions.';
        }

        return [
            'receipt_id' => (int) $detail->id,
            'original_setting_id' => (int) $detail->receiving->setting_id,
            'received' => ConsignmentBuyoutCapacityService::fromMilli($received),
            'custody' => $custody,
            'sold' => ConsignmentBuyoutCapacityService::fromMilli($exits[Event::STATE_SOLD]),
            'acquired' => ConsignmentBuyoutCapacityService::fromMilli($exits[Event::STATE_ACQUIRED]),
            'blockers' => array_values(array_unique($blockers)),
            'conserved' => $conserved,
        ];
    }

    /** Supplier-owned quantity in one state at one location. */
    public function quantityAt(array $projection, string $state, int $locationId): float
    {
        return (float) collect($projection['custody'])
            ->where('state', $state)->where('location_id', $locationId)->sum('quantity');
    }

    private function move(array &$balances, array &$exits, string $state, $loc, $setting, int $q): void
    {
        if (array_key_exists($state, $exits)) {
            $exits[$state] += $q;

            return;
        }
        if (! in_array($state, self::LOCATED_STATES, true)) {
            throw new \DomainException("Unknown custody state {$state}.");
        }
        $this->add($balances, $state, $loc, $setting, $q);
    }

    /** Take from the SELLABLE balance at a location, whichever setting key it was recorded under. */
    private function subtractSellable(array &$balances, $loc, int $q, $setting = null): void
    {
        foreach (array_keys($balances) as $key) {
            [$state, $l] = explode('|', $key);
            if ($state === Event::STATE_SELLABLE && (string) $l === (string) $loc) {
                $balances[$key] -= $q;

                return;
            }
        }
        $this->add($balances, Event::STATE_SELLABLE, $loc, $setting, -$q);
    }

    private function add(array &$balances, string $state, $loc, $setting, int $q): void
    {
        $key = $state . '|' . $loc . '|' . $setting;
        $balances[$key] = ($balances[$key] ?? 0) + $q;
    }
}
