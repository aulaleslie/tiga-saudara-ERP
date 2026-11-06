<?php

namespace App\Services\Sequence;

enum DocumentType: string
{
    case PURCHASE = 'purchase';
    case SALE = 'sale';
    case SALE_RETURN = 'sale_return';

    public function defaultPrefix(): string
    {
        return match ($this) {
            self::PURCHASE => 'PR',
            self::SALE => 'SL',
            self::SALE_RETURN => 'SLRN',
        };
    }
}
