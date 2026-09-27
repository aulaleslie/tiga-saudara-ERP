<?php

namespace Modules\Sale\Services;

use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;

/**
 * Resolves a stock-managed product's live unit HPP from a physical stock owner's
 * positive average purchase price, falling back deterministically to nearby
 * same-PKP then opposite-PKP settings. See openspec/changes/harden-product-bundle-hpp
 * design.md decision #2 for the ordering rules this class implements.
 */
class AverageCostResolver
{
    const SOURCE_OWNER_AVERAGE_PRICE = 'OWNER_AVERAGE_PRICE';
    const SOURCE_NEARBY_SAME_PKP_AVERAGE_PRICE = 'NEARBY_SAME_PKP_AVERAGE_PRICE';
    const SOURCE_NEARBY_OPPOSITE_PKP_AVERAGE_PRICE = 'NEARBY_OPPOSITE_PKP_AVERAGE_PRICE';
    const SOURCE_MISSING_AVERAGE_PRICE = 'MISSING_AVERAGE_PRICE';

    /**
     * Resolve unit cost for a product against the given owner setting.
     *
     * @return array{
     *     unit_cost: float,
     *     source: string,
     *     setting_id: int|null,
     *     setting_is_pkp: bool|null,
     *     is_missing: bool,
     * }
     */
    public function resolve(Product $product, ?int $ownerSettingId): array
    {
        $ownerPrice = $this->positivePrice($product, $ownerSettingId);

        if ($ownerPrice !== null) {
            $owner = $ownerSettingId ? Setting::find($ownerSettingId) : null;

            return [
                'unit_cost' => $ownerPrice,
                'source' => self::SOURCE_OWNER_AVERAGE_PRICE,
                'setting_id' => $ownerSettingId,
                'setting_is_pkp' => $owner?->is_pkp,
                'is_missing' => false,
            ];
        }

        $ownerIsPkp = $ownerSettingId ? (bool) Setting::find($ownerSettingId)?->is_pkp : false;

        foreach ($this->fallbackSettingsInOrder($ownerSettingId, $ownerIsPkp) as $candidate) {
            $price = $this->positivePrice($product, $candidate['id']);

            if ($price !== null) {
                return [
                    'unit_cost' => $price,
                    'source' => $candidate['is_pkp'] === $ownerIsPkp
                        ? self::SOURCE_NEARBY_SAME_PKP_AVERAGE_PRICE
                        : self::SOURCE_NEARBY_OPPOSITE_PKP_AVERAGE_PRICE,
                    'setting_id' => $candidate['id'],
                    'setting_is_pkp' => $candidate['is_pkp'],
                    'is_missing' => false,
                ];
            }
        }

        return [
            'unit_cost' => 0.0,
            'source' => self::SOURCE_MISSING_AVERAGE_PRICE,
            'setting_id' => null,
            'setting_is_pkp' => null,
            'is_missing' => true,
        ];
    }

    /**
     * Strictly positive, finite average purchase price for the product at the given setting.
     */
    protected function positivePrice(Product $product, ?int $settingId): ?float
    {
        if (!$settingId) {
            return null;
        }

        $raw = $product->averagePurchasePrice($settingId);

        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;

        if (!is_finite($value) || $value <= 0) {
            return null;
        }

        return $value;
    }

    /**
     * Deterministic ordered candidate settings for fallback, excluding the owner itself:
     * 1. Settings reachable via the owner's enabled sale-location assignments, ordered by
     *    earliest enabled position, same-PKP before opposite-PKP, setting ID tie-break.
     * 2. All remaining settings not yet considered, same-PKP first then opposite-PKP,
     *    each ordered by setting ID.
     *
     * @return array<int, array{id: int, is_pkp: bool}>
     */
    protected function fallbackSettingsInOrder(?int $ownerSettingId, bool $ownerIsPkp): array
    {
        $nearby = $this->nearbySettingsByPosition($ownerSettingId);

        $seen = collect($nearby)->pluck('id')->all();
        if ($ownerSettingId) {
            $seen[] = $ownerSettingId;
        }

        $remaining = Setting::query()
            ->whereNotIn('id', $seen)
            ->orderBy('id')
            ->get(['id', 'is_pkp'])
            ->map(fn (Setting $s) => ['id' => $s->id, 'is_pkp' => (bool) $s->is_pkp])
            ->all();

        $ordered = array_merge($nearby, $remaining);

        $samePkp = array_values(array_filter($ordered, fn ($c) => $c['is_pkp'] === $ownerIsPkp));
        $oppositePkp = array_values(array_filter($ordered, fn ($c) => $c['is_pkp'] !== $ownerIsPkp));

        return array_merge($samePkp, $oppositePkp);
    }

    /**
     * Settings reachable through the owner's enabled sale-location assignments, ordered by
     * each assignment's earliest enabled position, with setting-ID tie breaks. Multiple
     * locations owned by the same setting collapse to that setting's earliest position.
     *
     * @return array<int, array{id: int, is_pkp: bool}>
     */
    protected function nearbySettingsByPosition(?int $ownerSettingId): array
    {
        if (!$ownerSettingId) {
            return [];
        }

        $rows = SettingSaleLocation::query()
            ->join('locations', 'setting_sale_locations.location_id', '=', 'locations.id')
            ->where('setting_sale_locations.setting_id', $ownerSettingId)
            ->where('setting_sale_locations.is_enabled', true)
            ->whereNotNull('setting_sale_locations.position')
            ->where('locations.setting_id', '!=', $ownerSettingId)
            ->orderBy('setting_sale_locations.position')
            ->orderBy('locations.setting_id')
            ->get(['locations.setting_id as nearby_setting_id', 'setting_sale_locations.position']);

        $earliestPositionBySetting = [];
        foreach ($rows as $row) {
            $settingId = (int) $row->nearby_setting_id;
            if (!array_key_exists($settingId, $earliestPositionBySetting)) {
                $earliestPositionBySetting[$settingId] = (float) $row->position;
            }
        }

        if (empty($earliestPositionBySetting)) {
            return [];
        }

        $settingIds = array_keys($earliestPositionBySetting);

        $settings = Setting::query()
            ->whereIn('id', $settingIds)
            ->get(['id', 'is_pkp'])
            ->keyBy('id');

        $candidates = [];
        foreach ($settingIds as $settingId) {
            $setting = $settings->get($settingId);
            if (!$setting) {
                continue;
            }
            $candidates[] = [
                'id' => $settingId,
                'is_pkp' => (bool) $setting->is_pkp,
                'position' => $earliestPositionBySetting[$settingId],
            ];
        }

        usort($candidates, function ($a, $b) {
            return $a['position'] <=> $b['position'] ?: $a['id'] <=> $b['id'];
        });

        return array_map(fn ($c) => ['id' => $c['id'], 'is_pkp' => $c['is_pkp']], $candidates);
    }
}
