<?php

namespace Modules\Pos\Services;

use App\Support\SalesLocationResolver;
use Modules\Setting\Entities\Location;

/**
 * Resolves the ownership source for non-stock-managed POS content.
 *
 * Non-stock content never enters stock allocation, so it has no allocation-derived
 * owner. Its financial and audit ownership comes from the first enabled entry of the
 * terminal setting's ordered POS sales-location configuration
 * (`setting_sale_locations`), and the owner setting is that location's business.
 */
class PosNonStockSourceResolverService
{
    /** @var array<int, array{setting_id:int, location_id:int}|null> */
    private array $cache = [];

    /**
     * @return array{setting_id:int, location_id:int}|null
     */
    public function resolve(int $terminalSettingId): ?array
    {
        if ($terminalSettingId <= 0) {
            return null;
        }

        if (array_key_exists($terminalSettingId, $this->cache)) {
            return $this->cache[$terminalSettingId];
        }

        $locationId = (int) (SalesLocationResolver::resolveLocationIds($terminalSettingId)->first() ?? 0);

        if ($locationId <= 0) {
            return $this->cache[$terminalSettingId] = null;
        }

        $location = Location::query()->find($locationId);
        $settingId = (int) ($location?->setting_id ?? 0);

        // A configured entry pointing at a location that no longer resolves to an owning
        // business is an unusable source. Never substitute the terminal setting: that is
        // exactly the silent misattribution this resolver exists to prevent.
        if ($settingId <= 0) {
            return $this->cache[$terminalSettingId] = null;
        }

        return $this->cache[$terminalSettingId] = [
            'setting_id' => $settingId,
            'location_id' => $locationId,
        ];
    }
}
