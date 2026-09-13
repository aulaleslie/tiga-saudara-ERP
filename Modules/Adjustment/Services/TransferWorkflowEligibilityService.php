<?php

namespace Modules\Adjustment\Services;

use Modules\Adjustment\Entities\Transfer;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class TransferWorkflowEligibilityService
{
    /**
     * Determine if a transfer or origin/destination route is prospectively
     * eligible for workflow version 2.
     *
     * All five route classes are eligible once a route-policy snapshot can
     * be committed at approval: same-business (any PKP combination),
     * cross-business non-PKP-to-non-PKP, and cross-business routes
     * involving any PKP business. Eligibility here is a prospective,
     * live-settings estimate used at draft/creation time only; the
     * authoritative decision is the immutable snapshot created at approval.
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

        $originSetting = $originLoc->setting ?? Setting::find($originLoc->setting_id);
        if (!$originSetting) {
            return false;
        }

        if (!$destLoc) {
            // Destination not yet chosen (draft stage): every route class is
            // prospectively eligible once destination is known, so estimate optimistically.
            return true;
        }

        $destSetting = $destLoc->setting ?? Setting::find($destLoc->setting_id);
        if (!$destSetting) {
            return false;
        }

        return true;
    }

    /**
     * Resolve the initial workflow version for a new transfer. This is a
     * prospective estimate at draft-creation time; the authoritative
     * workflow version and route policy are committed at approval.
     */
    public function resolveWorkflowVersion(Location|int $origin, Location|int|null $destination = null): int
    {
        return $this->isEligibleForV2($origin, $destination) ? 2 : 1;
    }

    /**
     * Assert that a transfer is eligible for workflow version 2 execution
     * (dispatch/receipt). Requires an immutable route-policy snapshot
     * committed at approval; live settings are never re-evaluated here.
     */
    public function validateV2Eligibility(Transfer $transfer): void
    {
        if ((int) $transfer->workflow_version !== 2) {
            throw new \RuntimeException("This operation is only supported for workflow version 2 transfers.");
        }

        $hasSnapshot = \Modules\Adjustment\Entities\TransferRoutePolicy::where('transfer_id', $transfer->id)->exists();

        if (!$hasSnapshot) {
            throw new \RuntimeException("This transfer has no approved route-policy snapshot and is not eligible for workflow version 2 execution.");
        }
    }
}
