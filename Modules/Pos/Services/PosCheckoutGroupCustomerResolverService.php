<?php

namespace Modules\Pos\Services;

use Modules\People\Entities\Customer;
use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;
use Modules\Setting\Entities\Setting;

class PosCheckoutGroupCustomerResolverService
{
    /**
     * @return array{customer_id:int,resolution_source:string}
     */
    public function resolve(
        int $terminalSettingId,
        int $sourceSettingId,
        ?int $selectedCustomerId
    ): array {
        if ($sourceSettingId <= 0) {
            throw $this->unresolvedException(
                $terminalSettingId,
                $sourceSettingId,
                $selectedCustomerId,
                null
            );
        }

        $selectedId = $selectedCustomerId !== null && $selectedCustomerId > 0
            ? $selectedCustomerId
            : null;

        if ($selectedId !== null) {
            $selectedCustomer = Customer::query()
                ->whereKey($selectedId)
                ->first(['id']);

            if ($selectedCustomer) {
                return [
                    'customer_id' => (int) $selectedCustomer->id,
                    'resolution_source' => 'selected',
                ];
            }
        }

        $walkInCustomerId = (int) (Setting::query()
            ->whereKey($sourceSettingId)
            ->value('pos_walk_in_customer_id') ?? 0);

        if ($walkInCustomerId > 0) {
            $walkInCustomer = Customer::query()
                ->whereKey($walkInCustomerId)
                ->first(['id']);

            if ($walkInCustomer) {
                return [
                    'customer_id' => (int) $walkInCustomer->id,
                    'resolution_source' => 'walk_in',
                ];
            }
        }

        throw $this->unresolvedException(
            $terminalSettingId,
            $sourceSettingId,
            $selectedCustomerId,
            $walkInCustomerId > 0 ? $walkInCustomerId : null
        );
    }

    private function unresolvedException(
        int $terminalSettingId,
        int $sourceSettingId,
        ?int $selectedCustomerId,
        ?int $sourceWalkInCustomerId
    ): PosCheckoutValidationException {
        return new PosCheckoutValidationException(
            'CUSTOMER_UNRESOLVED',
            'Customer could not be resolved for split checkout source setting.',
            [
                'reason_code' => 'SOURCE_CUSTOMER_UNRESOLVED',
                'source_setting_id' => $sourceSettingId,
                'terminal_setting_id' => $terminalSettingId,
                'selected_customer_id' => $selectedCustomerId !== null && $selectedCustomerId > 0
                    ? $selectedCustomerId
                    : null,
                'source_walk_in_customer_id' => $sourceWalkInCustomerId !== null && $sourceWalkInCustomerId > 0
                    ? $sourceWalkInCustomerId
                    : null,
            ]
        );
    }
}
