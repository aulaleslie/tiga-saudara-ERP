<?php

namespace Modules\Adjustment\Services;

use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use RuntimeException;

class TransferRoutePolicyResolver
{
    /**
     * Resolve the destination classification and mandatory-return decision
     * for the agreed same/cross-business PKP route combinations.
     *
     * @return array{classification: string, mandatory_return: bool}
     */
    public function resolveClassification(bool $sameBusiness, bool $originIsPkp, bool $destinationIsPkp): array
    {
        if ($sameBusiness) {
            return [
                'classification'   => TransferRoutePolicy::CLASSIFICATION_PRESERVE,
                'mandatory_return' => false,
            ];
        }

        return [
            'classification'   => $destinationIsPkp ? TransferRoutePolicy::CLASSIFICATION_TAX : TransferRoutePolicy::CLASSIFICATION_NON_TAX,
            'mandatory_return' => true,
        ];
    }

    /**
     * Deterministically resolve the destination's applicable tax for a
     * TAX-classified route: the configured default, else the lowest-ID
     * applicable active tax. Throws if no applicable tax exists.
     *
     * @return array{tax_id: int, tax_name: string, tax_rate: string, provenance: string}
     */
    public function resolveDestinationTax(): array
    {
        $default = Tax::eligible()->where('is_default', true)->lockForUpdate()->first();

        if ($default) {
            return [
                'tax_id'     => $default->id,
                'tax_name'   => $default->name,
                'tax_rate'   => (string) $default->value,
                'provenance' => TransferRoutePolicy::PROVENANCE_DEFAULT,
            ];
        }

        $fallback = Tax::eligible()->orderBy('id')->lockForUpdate()->first();

        if (!$fallback) {
            throw new RuntimeException('No applicable tax is configured for the destination business.');
        }

        return [
            'tax_id'     => $fallback->id,
            'tax_name'   => $fallback->name,
            'tax_rate'   => (string) $fallback->value,
            'provenance' => TransferRoutePolicy::PROVENANCE_FALLBACK,
        ];
    }
}
