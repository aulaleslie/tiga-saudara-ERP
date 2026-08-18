<?php

namespace App\Constants;

class PaymentStatus
{
    const PAID = 'Paid';
    const PARTIAL = 'Partial';
    const UNPAID = 'Unpaid';

    const LABELS = [
        self::PAID => 'Lunas',
        self::PARTIAL => 'Sebagian',
        self::UNPAID => 'Belum Lunas',
    ];

    public static function label(?string $status): string
    {
        if ($status === null || $status === '') {
            return '';
        }

        foreach (self::LABELS as $key => $label) {
            if (strcasecmp($key, $status) === 0) {
                return $label;
            }
        }

        return $status;
    }

    public static function matches(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        return strcasecmp($a, $b) === 0;
    }
}
