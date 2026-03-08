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
     *     resolved_customer_id:int|null,
     *     resolution_source:string,
     *     resolution_error:array{code:string,message:string}|null
     * }
     */
    public function resolve(int $settingId, ?int $selectedCustomerId): array
    {
        // No customer selected - return non-fatal unresolved payload
        if ($selectedCustomerId === null || $selectedCustomerId < 1) {
            return [
                'selected_customer_id' => null,
                'selected_customer' => null,
                'resolved_customer_id' => null,
                'resolution_source' => 'none',
                'resolution_error' => null,
            ];
        }

        $selectedCustomer = Customer::query()
            ->where('setting_id', $settingId)
            ->whereKey($selectedCustomerId)
            ->first(['id', 'customer_name', 'contact_name', 'customer_phone']);

        if (! $selectedCustomer) {
            throw new \DomainException('Selected customer is not valid for active setting.');
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
