<?php

namespace App\Support\SalesReturn\SerialRegistration;

use Modules\Product\Entities\ProductSerialNumber;

/**
 * Normalization and validation of proposed (not yet registered) serials, using
 * ProductSerialNumber conventions and its product-scoped uniqueness.
 */
class PendingSerialValidator
{
    public const MAX_LENGTH = 255;

    public const ERROR_EMPTY = 'empty';
    public const ERROR_TOO_LONG = 'too_long';
    public const ERROR_DUPLICATE = 'duplicate';
    public const ERROR_ALREADY_REGISTERED = 'already_registered';

    public function normalize(string $value): string
    {
        return ProductSerialNumber::normalize($value);
    }

    /**
     * @param  array<int,array{product_id:int|string,serial:string,key?:string|int}>  $proposals
     * @return array<string|int,string> map of proposal key (or index) => error code; empty when valid
     */
    public function validate(array $proposals, bool $checkRegistered = true): array
    {
        $errors = [];
        $seen = [];
        $normalized = [];

        foreach ($proposals as $i => $proposal) {
            $key = $proposal['key'] ?? $i;
            $text = $this->normalize((string) ($proposal['serial'] ?? ''));
            $productId = (int) $proposal['product_id'];

            if ($text === '') {
                $errors[$key] = self::ERROR_EMPTY;
                continue;
            }

            if (mb_strlen($text, 'UTF-8') > self::MAX_LENGTH) {
                $errors[$key] = self::ERROR_TOO_LONG;
                continue;
            }

            $scope = $productId.'|'.$text;
            if (isset($seen[$scope])) {
                $errors[$key] = self::ERROR_DUPLICATE;
                continue;
            }

            $seen[$scope] = true;
            $normalized[$key] = [$productId, $text];
        }

        if ($checkRegistered && $normalized !== []) {
            foreach (collect($normalized)->groupBy(fn ($pair) => $pair[0]) as $productId => $pairs) {
                $existing = ProductSerialNumber::query()
                    ->where('product_id', (int) $productId)
                    ->whereIn('serial_number', $pairs->pluck(1)->all())
                    ->pluck('serial_number')
                    ->all();

                foreach ($normalized as $key => [$pid, $text]) {
                    if ($pid === (int) $productId && in_array($text, $existing, true)) {
                        $errors[$key] = self::ERROR_ALREADY_REGISTERED;
                    }
                }
            }
        }

        return $errors;
    }
}
