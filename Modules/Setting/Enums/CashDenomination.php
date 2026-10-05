<?php

declare(strict_types=1);

namespace Modules\Setting\Enums;

enum CashDenomination: int
{
    case Coin100 = 100;
    case Coin200 = 200;
    case Coin500 = 500;
    case Banknote1000 = 1_000;
    case Banknote2000 = 2_000;
    case Banknote5000 = 5_000;
    case Banknote10000 = 10_000;
    case Banknote20000 = 20_000;
    case Banknote50000 = 50_000;
    case Banknote75000 = 75_000;
    case Banknote100000 = 100_000;

    /**
     * @return array<int, int>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $denomination): int => $denomination->value,
            self::cases(),
        );
    }

    public function type(): string
    {
        return match ($this) {
            self::Coin100, self::Coin200, self::Coin500 => 'coin',
            default => 'banknote',
        };
    }

    public function typeLabel(): string
    {
        return $this->type() === 'coin' ? 'Uang Logam' : 'Uang Kertas';
    }

    public function unitLabel(): string
    {
        return $this->type() === 'coin' ? 'keping' : 'lembar';
    }

    public function label(): string
    {
        return 'Rp' . number_format($this->value, 0, ',', '.');
    }
}
