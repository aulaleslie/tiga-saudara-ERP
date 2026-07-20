<?php

namespace Modules\Pos\Services;

use Modules\People\Entities\Customer;

class PosCustomerSearchService
{
    /**
     * @return array{
     *     query:string,
     *     results:array<int, array<string, mixed>>,
     *     meta:array{limit:int,result_count:int}
     * }
     */
    public function search(string $query, int $limit = 10): array
    {
        $normalizedQuery = trim($query);
        $safeLimit = max(1, min(20, $limit));

        if ($normalizedQuery === '') {
            return [
                'query' => $query,
                'results' => [],
                'meta' => [
                    'limit' => $safeLimit,
                    'result_count' => 0,
                ],
            ];
        }

        $rows = Customer::query()
            ->where(function ($queryBuilder) use ($normalizedQuery) {
                $queryBuilder->where('customer_name', 'like', '%' . $normalizedQuery . '%')
                    ->orWhere('contact_name', 'like', '%' . $normalizedQuery . '%')
                    ->orWhere('customer_phone', 'like', '%' . $normalizedQuery . '%');
            })
            ->orderBy('customer_name')
            ->orderBy('contact_name')
            ->orderBy('id')
            ->limit($safeLimit)
            ->get(['id', 'customer_name', 'contact_name', 'customer_phone']);

        $results = $rows->map(function (Customer $customer) {
            return [
                'id' => (int) $customer->id,
                'customer_name' => (string) ($customer->customer_name ?? ''),
                'contact_name' => $customer->contact_name !== null ? (string) $customer->contact_name : null,
                'customer_phone' => $customer->customer_phone !== null ? (string) $customer->customer_phone : null,
                'display_name' => $customer->canonical_name,
            ];
        })->values();

        return [
            'query' => $query,
            'results' => $results->all(),
            'meta' => [
                'limit' => $safeLimit,
                'result_count' => $results->count(),
            ],
        ];
    }
}
