<?php

namespace Modules\Pos\Services;

use Modules\People\Entities\Customer;
use Modules\Setting\Entities\Setting;

class PosCheckoutCustomerResolverService
{
    /**
     * @return array{
     *     selected_customer_id:int|null,
     *     selected_customer:array<string, mixed>|null,
     *     default_customer_id:int|null,
     *     default_customer:array<string, mixed>|null,
     *     resolved_customer_id:int|null,
     *     resolution_source:string,
     *     resolution_error:array{code:string,message:string}|null
     * }
     */
    public function resolve(int $settingId, ?int $selectedCustomerId): array
    {
        /** @var Setting|null $setting */
        $setting = Setting::query()
            ->select(['id', 'pos_walk_in_customer_id'])
            ->find($settingId);

        $defaultCustomerId = $setting ? (int) ($setting->pos_walk_in_customer_id ?? 0) : 0;
        $defaultCustomerId = $defaultCustomerId > 0 ? $defaultCustomerId : null;

        $selectedCustomer = null;
        if ($selectedCustomerId !== null && $selectedCustomerId > 0) {
            $selectedCustomer = Customer::query()
                ->where('setting_id', $settingId)
                ->whereKey($selectedCustomerId)
                ->first(['id', 'customer_name', 'contact_name', 'customer_phone']);
        }

        $defaultCustomer = null;
        if ($defaultCustomerId !== null) {
            $defaultCustomer = Customer::query()
                ->where('setting_id', $settingId)
                ->whereKey($defaultCustomerId)
                ->first(['id', 'customer_name', 'contact_name', 'customer_phone']);
        }

        if ($selectedCustomer) {
            $mappedSelected = $this->mapCustomer($selectedCustomer);

            return [
                'selected_customer_id' => $mappedSelected['id'],
                'selected_customer' => $mappedSelected,
                'default_customer_id' => $defaultCustomerId,
                'default_customer' => $defaultCustomer ? $this->mapCustomer($defaultCustomer) : null,
                'resolved_customer_id' => $mappedSelected['id'],
                'resolution_source' => 'selected',
                'resolution_error' => null,
            ];
        }

        if ($defaultCustomer) {
            $mappedDefault = $this->mapCustomer($defaultCustomer);

            return [
                'selected_customer_id' => null,
                'selected_customer' => null,
                'default_customer_id' => $defaultCustomerId,
                'default_customer' => $mappedDefault,
                'resolved_customer_id' => $mappedDefault['id'],
                'resolution_source' => 'default',
                'resolution_error' => null,
            ];
        }

        $error = $defaultCustomerId === null
            ? [
                'code' => 'WALK_IN_CUSTOMER_NOT_CONFIGURED',
                'message' => 'Default walk-in customer mapping is not configured for this business.',
            ]
            : [
                'code' => 'WALK_IN_CUSTOMER_INVALID',
                'message' => 'Configured walk-in customer is invalid for this business.',
            ];

        return [
            'selected_customer_id' => null,
            'selected_customer' => null,
            'default_customer_id' => $defaultCustomerId,
            'default_customer' => null,
            'resolved_customer_id' => null,
            'resolution_source' => 'unresolved',
            'resolution_error' => $error,
        ];
    }

    /**
     * @return array{id:int,customer_name:string,contact_name:string|null,customer_phone:string|null,display_name:string}
     */
    private function mapCustomer(Customer $customer): array
    {
        $displayName = $customer->contact_name
            ? $customer->contact_name . ' - ' . $customer->customer_name
            : $customer->customer_name;

        return [
            'id' => (int) $customer->id,
            'customer_name' => (string) ($customer->customer_name ?? ''),
            'contact_name' => $customer->contact_name !== null ? (string) $customer->contact_name : null,
            'customer_phone' => $customer->customer_phone !== null ? (string) $customer->customer_phone : null,
            'display_name' => $displayName,
        ];
    }
}
