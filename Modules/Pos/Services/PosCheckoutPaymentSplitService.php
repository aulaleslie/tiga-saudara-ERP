<?php

namespace Modules\Pos\Services;

class PosCheckoutPaymentSplitService
{
    /**
     * @param  array<int, array{split_key:string,grand_total:float|int|string}>  $groups
     * @return array<string, float>
     */
    public function allocate(array $groups, float $totalAmount): array
    {
        if ($groups === []) {
            return [];
        }

        usort($groups, static function (array $left, array $right): int {
            return strcmp((string) $left['split_key'], (string) $right['split_key']);
        });

        $totalMinor = max(0, $this->toMinor($totalAmount));
        $weightTotalMinor = 0;
        $weights = [];

        foreach ($groups as $group) {
            $splitKey = (string) ($group['split_key'] ?? '');
            $groupTotalMinor = max(0, $this->toMinor((float) ($group['grand_total'] ?? 0)));
            $weights[$splitKey] = $groupTotalMinor;
            $weightTotalMinor += $groupTotalMinor;
        }

        if ($weightTotalMinor <= 0 || $totalMinor <= 0) {
            $zero = [];
            foreach ($groups as $group) {
                $zero[(string) $group['split_key']] = 0.0;
            }

            return $zero;
        }

        $allocationsMinor = [];
        $fractionalRows = [];
        $allocated = 0;

        foreach ($groups as $index => $group) {
            $splitKey = (string) $group['split_key'];
            $weightMinor = $weights[$splitKey];

            $numerator = $totalMinor * $weightMinor;
            $floorShare = intdiv($numerator, $weightTotalMinor);
            $remainder = $numerator % $weightTotalMinor;

            $allocationsMinor[$splitKey] = $floorShare;
            $allocated += $floorShare;

            $fractionalRows[] = [
                'split_key' => $splitKey,
                'remainder' => $remainder,
                'index' => $index,
            ];
        }

        $remaining = max(0, $totalMinor - $allocated);
        usort($fractionalRows, static function (array $left, array $right): int {
            if ((int) $left['remainder'] === (int) $right['remainder']) {
                return (int) $left['index'] <=> (int) $right['index'];
            }

            return (int) $right['remainder'] <=> (int) $left['remainder'];
        });

        $rowCount = count($fractionalRows);
        for ($index = 0; $index < $remaining && $rowCount > 0; $index++) {
            $row = $fractionalRows[$index % $rowCount];
            $allocationsMinor[(string) $row['split_key']]++;
        }

        $allocations = [];
        foreach ($groups as $group) {
            $splitKey = (string) $group['split_key'];
            $allocations[$splitKey] = $this->fromMinor((int) ($allocationsMinor[$splitKey] ?? 0));
        }

        return $allocations;
    }

    private function toMinor(float $value): int
    {
        return (int) round($value * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function fromMinor(int $value): float
    {
        return round($value / 100, 2);
    }
}
