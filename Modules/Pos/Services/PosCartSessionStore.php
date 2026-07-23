<?php

namespace Modules\Pos\Services;

class PosCartSessionStore
{
    /**
     * @return array{
     *     setting_id:int,
     *     session_id:int,
     *     next_line_id:int,
     *     lines:array<int, array<string, mixed>>,
     *     bill_discount_type:string,
     *     bill_discount_value:float,
     *     selected_customer_id:int|null,
     *     selected_customer_tier:string|null,
     *     active_transaction_id:int|null,
     *     staged_payment_token:string|null,
     *     note:string|null
     * }
     */
    public function getCart(int $settingId, int $sessionId): array
    {
        $key = $this->key($settingId, $sessionId);
        $stored = session()->get($key);

        if (! is_array($stored)) {
            return $this->emptyCart($settingId, $sessionId);
        }

        // Normalize old session format (product_id keyed) to new format (line_id keyed)
        $lines = $this->normalizeLineFormat($stored['lines'] ?? []);

        return [
            'setting_id' => $settingId,
            'session_id' => $sessionId,
            'next_line_id' => (int) ($stored['next_line_id'] ?? ($this->computeNextLineId($lines))),
            'lines' => $lines,
            'bill_discount_type' => strtolower((string) ($stored['bill_discount_type'] ?? 'fixed')) === 'percentage'
                ? 'percentage'
                : 'fixed',
            'bill_discount_value' => (float) ($stored['bill_discount_value'] ?? 0),
            'selected_customer_id' => isset($stored['selected_customer_id'])
                ? (int) $stored['selected_customer_id']
                : null,
            'selected_customer_tier' => isset($stored['selected_customer_tier'])
                ? (string) $stored['selected_customer_tier']
                : null,
            'active_transaction_id' => isset($stored['active_transaction_id'])
                ? (int) $stored['active_transaction_id']
                : null,
            'staged_payment_token' => isset($stored['staged_payment_token'])
                ? (string) $stored['staged_payment_token']
                : null,
            'note' => isset($stored['note'])
                ? (string) $stored['note']
                : null,
        ];
    }

    /**
     * @param  array{
     *     setting_id?:int,
     *     session_id?:int,
     *     next_line_id?:int,
     *     lines?:array<int, array<string, mixed>>,
     *     bill_discount_type?:string,
     *     bill_discount_value?:float|int|string,
     *     selected_customer_id?:int|null,
     *     selected_customer_tier?:string|null,
     *     active_transaction_id?:int|null,
     *     staged_payment_token?:string|null,
     *     note?:string|null
     * }  $cart
     */
    public function putCart(int $settingId, int $sessionId, array $cart): void
    {
        session()->put($this->key($settingId, $sessionId), [
            'setting_id' => $settingId,
            'session_id' => $sessionId,
            'next_line_id' => (int) ($cart['next_line_id'] ?? 1),
            'lines' => is_array($cart['lines'] ?? null) ? $cart['lines'] : [],
            'bill_discount_type' => strtolower((string) ($cart['bill_discount_type'] ?? 'fixed')) === 'percentage'
                ? 'percentage'
                : 'fixed',
            'bill_discount_value' => (float) ($cart['bill_discount_value'] ?? 0),
            'selected_customer_id' => isset($cart['selected_customer_id'])
                ? (int) $cart['selected_customer_id']
                : null,
            'selected_customer_tier' => isset($cart['selected_customer_tier'])
                ? (string) $cart['selected_customer_tier']
                : null,
            'active_transaction_id' => isset($cart['active_transaction_id'])
                ? (int) $cart['active_transaction_id']
                : null,
            'staged_payment_token' => isset($cart['staged_payment_token'])
                ? (string) $cart['staged_payment_token']
                : null,
            'note' => isset($cart['note'])
                ? (string) $cart['note']
                : null,
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
     *     next_line_id:int,
     *     lines:array<int, array<string, mixed>>,
     *     bill_discount_type:string,
     *     bill_discount_value:float,
     *     selected_customer_id:int|null,
     *     selected_customer_tier:string|null,
     *     active_transaction_id:int|null,
     *     staged_payment_token:string|null,
     *     note:string|null
     * }
     */
    public function emptyCart(int $settingId, int $sessionId): array
    {
        return [
            'setting_id' => $settingId,
            'session_id' => $sessionId,
            'next_line_id' => 1,
            'lines' => [],
            'bill_discount_type' => 'fixed',
            'bill_discount_value' => 0.0,
            'selected_customer_id' => null,
            'selected_customer_tier' => null,
            'active_transaction_id' => null,
            'staged_payment_token' => null,
            'note' => null,
        ];
    }

    private function key(int $settingId, int $sessionId): string
    {
        return 'pos.cart.setting.' . $settingId . '.session.' . $sessionId;
    }

    /**
     * Normalize old product_id-keyed line format to new line_id-keyed format.
     * This normalizer runs automatically when reading old sessions from storage.
     *
     * @param  array<int|string, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLineFormat(array $lines): array
    {
        if (empty($lines)) {
            return [];
        }

        // If lines are already numeric (line_id keyed), return as-is
        $keys = array_keys($lines);
        if (! empty($keys) && is_int($keys[0])) {
            return $lines;
        }

        // Convert old product_id-keyed format to line_id-keyed format
        $normalized = [];
        $lineId = 1;
        foreach ($lines as $line) {
            if (is_array($line)) {
                $line['line_id'] = $lineId;
                $normalized[$lineId] = $line;
                $lineId++;
            }
        }

        return $normalized;
    }

    /**
     * Compute the next line_id based on existing line IDs.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return int
     */
    private function computeNextLineId(array $lines): int
    {
        if (empty($lines)) {
            return 1;
        }

        return (int) max(array_keys($lines)) + 1;
    }
}
