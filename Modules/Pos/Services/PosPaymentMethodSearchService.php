<?php

namespace Modules\Pos\Services;

use Modules\Setting\Entities\PaymentMethod;

class PosPaymentMethodSearchService
{
    /**
     * Search for POS-enabled payment methods.
     *
     * @param  int  $settingId
     * @param  string|null  $query Optional name search query
     * @return array<int, array{id: int, name: string, is_cash: bool, requires_reference: bool}>
     */
    public function search(int $settingId, ?string $query = null): array
    {
        $builder = PaymentMethod::query()
            ->where('setting_id', $settingId)
            ->where('is_available_in_pos', true)
            ->orderBy('name');

        if ($query !== null && $query !== '') {
            $builder->where('name', 'like', "%{$query}%");
        }

        return $builder
            ->get(['id', 'name', 'is_cash', 'requires_reference'])
            ->map(fn (PaymentMethod $method): array => [
                'id' => (int) $method->id,
                'name' => (string) $method->name,
                'is_cash' => (bool) $method->is_cash,
                'requires_reference' => (bool) $method->requires_reference,
            ])
            ->values()
            ->toArray();
    }
}
