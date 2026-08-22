<?php

namespace App\Services\Sequence;

enum DocumentType: string
{
    case PURCHASE = 'purchase';
    case SALE = 'sale';

    public function defaultPrefix(): string
    {
        return match ($this) {
            self::PURCHASE => 'PR',
            self::SALE => 'SL',
        };
    }
}
