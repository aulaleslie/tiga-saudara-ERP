<?php

namespace Modules\Pos\Services;

use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class PosReturnReplacementOwnerResolver
{
    public function resolveById(int $replacementSerialId): array
    {
        $serial = ProductSerialNumber::query()
            ->with(['location.setting'])
            ->find($replacementSerialId);

        return $this->resolve($serial);
    }

    public function resolve(?ProductSerialNumber $serial): array
    {
        if (! $serial) {
            return [
                'serial' => null,
                'location' => null,
                'location_id' => null,
                'owner_setting' => null,
                'owner_setting_id' => null,
            ];
        }

        $serial->loadMissing('location.setting');

        /** @var Location|null $location */
        $location = $serial->location;
        /** @var Setting|null $ownerSetting */
        $ownerSetting = $location?->setting;

        return [
            'serial' => $serial,
            'location' => $location,
            'location_id' => $location?->id,
            'owner_setting' => $ownerSetting,
            'owner_setting_id' => $ownerSetting?->id,
        ];
    }
}