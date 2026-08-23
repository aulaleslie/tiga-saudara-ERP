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
            ->active()
            ->join('setting_pos_payment_methods', 'payment_methods.id', '=', 'setting_pos_payment_methods.payment_method_id')
            ->where('setting_pos_payment_methods.setting_id', $settingId)
            ->where('setting_pos_payment_methods.is_enabled', true)
            ->select('payment_methods.*')
            ->orderBy('payment_methods.name');

        if ($query !== null && $query !== '') {
            $builder->where('name', 'like', "%{$query}%");
        }

        return $builder
            ->get(['payment_methods.id', 'payment_methods.name', 'payment_methods.is_cash', 'payment_methods.requires_reference'])
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
