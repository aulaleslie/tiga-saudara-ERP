<?php

namespace Modules\Adjustment\Services;

use Modules\Adjustment\Entities\Transfer;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class TransferWorkflowEligibilityService
{
    /**
     * Determine if a transfer or origin/destination route is eligible for workflow version 2.
     *
     * Rules:
     * - Same business (origin setting_id == destination setting_id): ELIGIBLE
     * - Cross business where both businesses are non-PKP: ELIGIBLE
     * - Any route involving a PKP business (origin or destination is_pkp == true): INELIGIBLE (Version 1)
     */
    public function isEligibleForV2(Transfer|Location|int $origin, Location|int|null $destination = null): bool
    {
        if ($origin instanceof Transfer) {
            $origin->loadMissing(['originLocation.setting', 'destinationLocation.setting']);
            $originLoc = $origin->originLocation;
            $destLoc = $origin->destinationLocation;
        } else {
            $originLoc = $origin instanceof Location ? $origin : Location::with('setting')->find($origin);
            $destLoc = $destination instanceof Location ? $destination : ($destination ? Location::with('setting')->find($destination) : null);
        }

        if (!$originLoc) {
            return false;
        }

        // If destination is not yet selected (draft stage), we check origin business
        $originSetting = $originLoc->setting ?? Setting::find($originLoc->setting_id);
        if (!$originSetting) {
            return false;
        }

        if (!$destLoc) {
            // Without destination, if origin is PKP, it's definitely not eligible for v2 non-PKP cross-transfer,
            // but if same-business is chosen later it might be. However, standard check requires knowing destination or defaults to origin PKP status.
            return !(bool) $originSetting->is_pkp;
        }

        $destSetting = $destLoc->setting ?? Setting::find($destLoc->setting_id);
        if (!$destSetting) {
            return false;
        }

        // Same business route
        if ((int) $originSetting->id === (int) $destSetting->id) {
            return true;
        }

        // Cross business: eligible ONLY if both are non-PKP
        $originIsPkp = (bool) $originSetting->is_pkp;
        $destIsPkp = (bool) $destSetting->is_pkp;

        return !$originIsPkp && !$destIsPkp;
    }

    /**
     * Resolve the initial workflow version for a new transfer.
     */
    public function resolveWorkflowVersion(Location|int $origin, Location|int|null $destination = null): int
    {
        return $this->isEligibleForV2($origin, $destination) ? 2 : 1;
    }

    /**
     * Assert that a transfer is eligible for workflow version 2. Throws RuntimeException if not.
     */
    public function validateV2Eligibility(Transfer $transfer): void
    {
        if ((int) $transfer->workflow_version !== 2) {
            throw new \RuntimeException("This operation is only supported for workflow version 2 transfers.");
        }

        if (!$this->isEligibleForV2($transfer)) {
            throw new \RuntimeException("This transfer route is not eligible for workflow version 2.");
        }
    }
}
