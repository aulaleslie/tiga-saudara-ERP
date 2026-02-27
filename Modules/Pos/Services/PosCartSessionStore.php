<?php

namespace Modules\Pos\Services;

class PosCartSessionStore
{
    /**
     * @return array{
     *     setting_id:int,
     *     session_id:int,
     *     lines:array<int|string, array<string, mixed>>,
     *     bill_discount_type:string,
     *     bill_discount_value:float
     * }
     */
    public function getCart(int $settingId, int $sessionId): array
    {
        $key = $this->key($settingId, $sessionId);
        $stored = session()->get($key);

        if (! is_array($stored)) {
            return $this->emptyCart($settingId, $sessionId);
        }

        return [
            'setting_id' => $settingId,
            'session_id' => $sessionId,
            'lines' => is_array($stored['lines'] ?? null) ? $stored['lines'] : [],
            'bill_discount_type' => strtolower((string) ($stored['bill_discount_type'] ?? 'fixed')) === 'percentage'
                ? 'percentage'
                : 'fixed',
            'bill_discount_value' => (float) ($stored['bill_discount_value'] ?? 0),
        ];
    }

    /**
     * @param  array{
     *     setting_id?:int,
     *     session_id?:int,
     *     lines?:array<int|string, array<string, mixed>>,
     *     bill_discount_type?:string,
     *     bill_discount_value?:float|int|string
     * }  $cart
     */
    public function putCart(int $settingId, int $sessionId, array $cart): void
    {
        session()->put($this->key($settingId, $sessionId), [
            'setting_id' => $settingId,
            'session_id' => $sessionId,
            'lines' => is_array($cart['lines'] ?? null) ? $cart['lines'] : [],
            'bill_discount_type' => strtolower((string) ($cart['bill_discount_type'] ?? 'fixed')) === 'percentage'
                ? 'percentage'
                : 'fixed',
            'bill_discount_value' => (float) ($cart['bill_discount_value'] ?? 0),
        ]);
    }

    public function clearCart(int $settingId, int $sessionId): void
    {
        session()->forget($this->key($settingId, $sessionId));
    }

    /**
     * @return array{
     *     setting_id:int,
     *     session_id:int,
     *     lines:array<int|string, array<string, mixed>>,
     *     bill_discount_type:string,
     *     bill_discount_value:float
     * }
     */
    public function emptyCart(int $settingId, int $sessionId): array
    {
        return [
            'setting_id' => $settingId,
            'session_id' => $sessionId,
            'lines' => [],
            'bill_discount_type' => 'fixed',
            'bill_discount_value' => 0.0,
        ];
    }

    private function key(int $settingId, int $sessionId): string
    {
        return 'pos.cart.setting.' . $settingId . '.session.' . $sessionId;
    }
}
