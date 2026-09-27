<?php

namespace Modules\Pos\Services;

use Modules\People\Entities\Customer;

class PosCheckoutCustomerResolverService
{
    /**
     * @return array{
     *     selected_customer_id:int|null,
     *     selected_customer:array<string, mixed>|null,
     *     resolved_customer_id:int|null,
     *     resolution_source:string,
     *     resolution_error:array{code:string,message:string}|null
     * }
     */
    public function resolve(int $settingId, ?int $selectedCustomerId): array
    {
        // 1. Explicitly selected customer
        if ($selectedCustomerId !== null && $selectedCustomerId > 0) {
            $selectedCustomer = Customer::query()
                ->whereKey($selectedCustomerId)
                ->first(['id', 'customer_name', 'contact_name', 'customer_phone']);

            if (! $selectedCustomer) {
                throw new \DomainException('Selected customer is not valid.');
            }

            $mappedSelected = $this->mapCustomer($selectedCustomer);

            return [
                'selected_customer_id' => $mappedSelected['id'],
                'selected_customer' => $mappedSelected,
                'resolved_customer_id' => $mappedSelected['id'],
                'resolution_source' => 'selected',
                'resolution_error' => null,
            ];
        }

        // 2. Default walk-in customer configured for setting
        if ($settingId > 0) {
            $walkInCustomerId = (int) (\Modules\Setting\Entities\Setting::query()
                ->whereKey($settingId)
                ->value('pos_walk_in_customer_id') ?? 0);

            if ($walkInCustomerId > 0) {
                $walkInCustomer = Customer::query()
                    ->whereKey($walkInCustomerId)
                    ->first(['id', 'customer_name', 'contact_name', 'customer_phone']);

                if ($walkInCustomer) {
                    $mappedWalkIn = $this->mapCustomer($walkInCustomer);

                    return [
                        'selected_customer_id' => null,
                        'selected_customer' => $mappedWalkIn,
                        'resolved_customer_id' => $mappedWalkIn['id'],
                        'resolution_source' => 'walk_in',
                        'resolution_error' => null,
                    ];
                }
            }
        }

        // 3. No customer selected and no walk-in default configured
        return [
            'selected_customer_id' => null,
            'selected_customer' => null,
            'resolved_customer_id' => null,
            'resolution_source' => 'none',
            'resolution_error' => null,
        ];
    }

    /**
     * @return array{id:int,customer_name:string,contact_name:string|null,customer_phone:string|null,display_name:string}
     */
    private function mapCustomer(Customer $customer): array
    {
        $displayName = $customer->canonical_name;

        return [
            'id' => (int) $customer->id,
            'customer_name' => (string) ($customer->customer_name ?? ''),
            'contact_name' => $customer->contact_name !== null ? (string) $customer->contact_name : null,
            'customer_phone' => $customer->customer_phone !== null ? (string) $customer->customer_phone : null,
            'display_name' => $displayName,
        ];
    }
}
