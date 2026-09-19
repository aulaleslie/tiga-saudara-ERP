<?php

namespace Modules\Adjustment\Services;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentLocation;
use Modules\Setting\Entities\Location;

/**
 * Centralized, read-only resolver that returns the authoritative ordered
 * set of selected locations for any Stock Opname document — whether it
 * uses the multi-location schema version 2 relation or the historical
 * single-location column.
 *
 * Never writes to the database; read-only resolution and validation.
 */
class SelectedLocationPoolResolver
{
    /**
     * Resolve the authoritative ordered Location models for the document.
     *
     * - Schema version 2: reads from adjustment_locations relation.
     * - Schema version 1 / legacy: adapts adjustments.location_id to a
     *   one-location pool without backfilling or rewriting the document.
     *
     * Validates that every resolved location is active, standard (non-
     * consignment), and exists. If any member is ineligible, the entire
     * pool is rejected.
     *
     * @return Collection<int, Location>
     * @throws InvalidArgumentException
     */
    public function resolve(Adjustment $adjustment): Collection
    {
        if ($adjustment->isSchemaVersion2()) {
            return $this->resolveFromRelation($adjustment);
        }

        return $this->resolveFromLegacyColumn($adjustment);
    }

    /**
     * Resolve only the sorted unique integer location IDs.
     *
     * @return array<int>
     * @throws InvalidArgumentException
     */
    public function resolveIds(Adjustment $adjustment): array
    {
        return $this->resolve($adjustment)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------
    //  Schema-specific resolution
    // ------------------------------------------------------------------

    /**
     * Multi-location (v2): load from the adjustment_locations pivot.
     */
    private function resolveFromRelation(Adjustment $adjustment): Collection
    {
        $rows = $adjustment->selectedLocations()->get();

        if ($rows->isEmpty()) {
            throw new InvalidArgumentException(
                'Dokumen stock opname multi-lokasi tidak memiliki lokasi terpilih.'
            );
        }

        $locationIds = $rows->pluck('location_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        // Detect duplicates in relation rows (should not happen with
        // unique constraint, but defend in depth)
        if ($locationIds->count() !== $rows->count()) {
            throw new InvalidArgumentException(
                'Lokasi duplikat ditemukan dalam daftar lokasi terpilih.'
            );
        }

        $locations = Location::with('setting')
            ->whereIn('id', $locationIds->all())
            ->get()
            ->keyBy('id');

        // Preserve the order from the relation (position ASC, id ASC)
        $ordered = $rows->map(function (AdjustmentLocation $row) use ($locations) {
            $loc = $locations->get($row->location_id);

            if (!$loc) {
                throw new InvalidArgumentException(
                    "Lokasi dengan ID {$row->location_id} tidak ditemukan."
                );
            }

            return $loc;
        });

        $this->validatePool($ordered);

        return $ordered;
    }

    /**
     * Historical single-location (v1 / legacy): adapt location_id to a
     * one-element pool without writing anything.
     */
    private function resolveFromLegacyColumn(Adjustment $adjustment): Collection
    {
        $locationId = (int) $adjustment->location_id;

        if ($locationId <= 0) {
            throw new InvalidArgumentException(
                'Dokumen stock opname tidak memiliki lokasi tujuan.'
            );
        }

        $location = Location::with('setting')->find($locationId);

        if (!$location) {
            throw new InvalidArgumentException(
                "Lokasi dengan ID {$locationId} tidak ditemukan."
            );
        }

        $pool = collect([$location]);

        $this->validatePool($pool);

        return $pool;
    }

    // ------------------------------------------------------------------
    //  Shared pool validation
    // ------------------------------------------------------------------

    /**
     * Validate that every location in the pool is active and standard
     * (non-consignment). Failure of any member rejects the whole pool.
     *
     * @param Collection<int, Location> $pool
     * @throws InvalidArgumentException
     */
    private function validatePool(Collection $pool): void
    {
        foreach ($pool as $location) {
            if (!$location->is_active) {
                throw new InvalidArgumentException(
                    "Lokasi '{$location->name}' tidak aktif dan tidak dapat digunakan untuk stock opname."
                );
            }

            if ($location->is_consignment) {
                throw new InvalidArgumentException(
                    "Lokasi '{$location->name}' adalah lokasi konsinyasi dan tidak dapat digunakan untuk stock opname."
                );
            }
        }
    }
}
