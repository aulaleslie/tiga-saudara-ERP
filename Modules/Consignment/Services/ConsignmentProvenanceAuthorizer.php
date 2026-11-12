<?php

namespace Modules\Consignment\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Narrow cross-business authority for receipt provenance (track-transferred-consignment-provenance 1.4).
 *
 * Access to the original receipt business never implies access to a custody/owner business.
 * Remote acquisition needs explicit membership of every affected holding business plus the
 * delegated consignments.buyouts.acquire_remote ability. No global foreign-business access is granted.
 */
class ConsignmentProvenanceAuthorizer
{
    public function canAccessBusiness(User $actor, int $settingId): bool
    {
        return $actor->hasRole('Super Admin') || $actor->settings()->where('settings.id', $settingId)->exists();
    }

    /** Reconcile/attribute/map evidence for receipts of the original business. */
    public function authorizeOriginReconciliation(User $actor, int $originalSettingId): void
    {
        if (! $this->canAccessBusiness($actor, $originalSettingId)) {
            throw new AuthorizationException('No access to the original receipt business.');
        }
        if (Gate::forUser($actor)->denies('consignments.provenance.reconcile')) {
            throw new AuthorizationException('Missing permission consignments.provenance.reconcile.');
        }
    }

    /**
     * Acquire unsold supplier stock held by custody businesses on behalf of the original business.
     *
     * @param  int[]  $custodySettingIds
     */
    public function authorizeAcquisition(User $actor, int $originalSettingId, array $custodySettingIds): void
    {
        if (! $this->canAccessBusiness($actor, $originalSettingId)) {
            throw new AuthorizationException('No access to the original receipt business.');
        }
        if (Gate::forUser($actor)->denies('consignments.buyouts.acquire_unsold')) {
            throw new AuthorizationException('Missing permission consignments.buyouts.acquire_unsold.');
        }
        $remote = array_values(array_diff(array_unique(array_map('intval', $custodySettingIds)), [$originalSettingId]));
        if ($remote === []) {
            return;
        }
        if (Gate::forUser($actor)->denies('consignments.buyouts.acquire_remote')) {
            throw new AuthorizationException('Missing delegated permission consignments.buyouts.acquire_remote.');
        }
        foreach ($remote as $settingId) {
            if (! $this->canAccessBusiness($actor, $settingId)) {
                throw new AuthorizationException("No delegated access to custody business #{$settingId}.");
            }
        }
    }
}
