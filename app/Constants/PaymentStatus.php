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

    /**
     * Normalize any historical casing ('PAID', 'paid', 'Paid') to the canonical constant.
     *
     * Payment status has been written both uppercase and mixed-case by different writers
     * over time, so persisted values cannot be assumed to share one casing.
     */
    public static function normalize(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return $status;
        }

        foreach (array_keys(self::LABELS) as $canonical) {
            if (strcasecmp($canonical, $status) === 0) {
                return $canonical;
            }
        }

        return $status;
    }

    /**
     * Every stored spelling of a status, for case-insensitive SQL comparison.
     *
     * @return array<int, string>
     */
    public static function variants(string $status): array
    {
        $canonical = self::normalize($status);

        return array_values(array_unique([
            $canonical,
            strtoupper($canonical),
            strtolower($canonical),
        ]));
    }

    /**
     * All stored spellings for a set of statuses.
     *
     * @param array<int, string> $statuses
     * @return array<int, string>
     */
    public static function variantsFor(array $statuses): array
    {
        $all = [];
        foreach ($statuses as $status) {
            $all = array_merge($all, self::variants($status));
        }

        return array_values(array_unique($all));
    }
}
