<?php

namespace App\Constants;

class CustomerTier
{
    const WHOLESALER = 'WHOLESALER';
    const RESELLER = 'RESELLER';

    const LABELS = [
        self::WHOLESALER => 'Grosir',
        self::RESELLER => 'Reseller',
    ];

    public static function options(): array
    {
        return [
            '' => '-- Pelanggan Normal --',
            self::WHOLESALER => self::LABELS[self::WHOLESALER],
            self::RESELLER => self::LABELS[self::RESELLER],
        ];
    }
}