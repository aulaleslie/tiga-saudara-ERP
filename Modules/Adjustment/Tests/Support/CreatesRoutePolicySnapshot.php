<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Support;

use App\Models\User;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Tax;

trait CreatesRoutePolicySnapshot
{
    protected function createRoutePolicySnapshot(
        Transfer $transfer,
        Location $origin,
        Location $destination,
        User $approver,
        int $transferRevision = 1,
        string $classification = TransferRoutePolicy::CLASSIFICATION_PRESERVE,
        bool $mandatoryReturn = false,
        ?Tax $resolvedTax = null
    ): TransferRoutePolicy {
        $origin->loadMissing('setting');
        $destination->loadMissing('setting');

        return TransferRoutePolicy::create([
            'transfer_id'                => $transfer->id,
            'transfer_revision'          => $transferRevision,
            'origin_location_id'         => $origin->id,
            'destination_location_id'    => $destination->id,
            'origin_setting_id'          => $origin->setting_id,
            'destination_setting_id'     => $destination->setting_id,
            'origin_is_pkp'              => (bool) $origin->setting?->is_pkp,
            'destination_is_pkp'         => (bool) $destination->setting?->is_pkp,
            'same_business'              => (int) $origin->setting_id === (int) $destination->setting_id,
            'stock_condition'            => $transfer->stock_condition,
            'destination_classification' => $classification,
            'mandatory_return'           => $mandatoryReturn,
            'resolved_tax_id'            => $resolvedTax?->id,
            'resolved_tax_name'          => $resolvedTax?->name,
            'resolved_tax_rate'          => $resolvedTax?->value,
            'tax_resolver_provenance'    => $resolvedTax ? TransferRoutePolicy::PROVENANCE_DEFAULT : null,
            'approved_by'                => $approver->id,
            'approved_at'                => now(),
        ]);
    }
}
