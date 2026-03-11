<?php

namespace Modules\Pos\Services;

use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;

class PosCheckoutSplitPlannerService
{
    /** @var array<int, Setting|null> */
    private array $settingsCache = [];

    /** @var array<int, Location|null> */
    private array $locationsCache = [];

    /** @var array<int, Tax|null> */
    private array $taxesCache = [];

    private ?Tax $fallbackTax = null;

    private bool $fallbackTaxResolved = false;

    /**
     * @param  array{
     *     setting_id:int,
     *     cart_snapshot:array<string, mixed>,
     *     allocations:array<int, array<int, array<string, mixed>>>
     * }  $context
     * @return array{
     *     groups:array<int, array{
     *         split_key:string,
     *         source_setting_id:int,
     *         source_location_id:int,
     *         tax_bucket:string,
     *         subtotal:float,
     *         discount_total:float,
     *         tax_total:float,
     *         grand_total:float,
     *         lines:array<int, array<string, mixed>>,
     *         allocations:array<int, array<int, array<string, mixed>>>
     *     }>
     * }
     */
    public function plan(array $context): array
    {
        $settingId = (int) ($context['setting_id'] ?? 0);
        $cartSnapshot = is_array($context['cart_snapshot'] ?? null) ? $context['cart_snapshot'] : [];
        $allocations = is_array($context['allocations'] ?? null) ? $context['allocations'] : [];
        $lines = is_array($cartSnapshot['lines'] ?? null) ? $cartSnapshot['lines'] : [];

        if ($settingId <= 0 || $lines === []) {
            return ['groups' => []];
        }

        $groupMap = [];

        foreach ($lines as $lineIndex => $line) {
            $qty = (int) ($line['qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $lineTaxable = (int) ($line['tax_id'] ?? 0) > 0;
            $lineChunks = (bool) ($line['serial_number_required'] ?? false)
                ? $this->resolveSerialLineChunks($settingId, $line, $lineTaxable)
                : $this->resolveNonSerialLineChunks($settingId, $line, $lineTaxable, $allocations[$lineIndex] ?? []);

            $allocatedQty = array_sum(array_map(
                static fn (array $chunk): int => max(0, (int) ($chunk['allocated_qty'] ?? 0)),
                $lineChunks
            ));

            if ($allocatedQty !== $qty) {
                throw new PosCheckoutValidationException(
                    'STOCK_UNAVAILABLE',
                    'Allocated quantity does not match checkout line quantity for split planning.'
                );
            }

            $lineSubtotalMinor = $this->toMinor((float) ($line['line_subtotal'] ?? 0));
            $lineDiscountMinor = $this->toMinor((float) ($line['line_discount_amount'] ?? 0));
            $billDiscountMinor = $this->toMinor((float) ($line['bill_discount_amount'] ?? 0));

            $lineSubtotalShares = $this->allocateMinorByQuantity($lineChunks, $lineSubtotalMinor, $qty);
            $lineDiscountShares = $this->allocateMinorByQuantity($lineChunks, $lineDiscountMinor, $qty);
            $billDiscountShares = $this->allocateMinorByQuantity($lineChunks, $billDiscountMinor, $qty);

            foreach ($lineChunks as $chunkIndex => $chunk) {
                $splitKey = (string) $chunk['split_key'];

                if (! isset($groupMap[$splitKey])) {
                    $groupMap[$splitKey] = [
                        'split_key' => $splitKey,
                        'source_setting_id' => (int) $chunk['source_setting_id'],
                        'source_location_id' => (int) $chunk['source_location_id'],
                        'tax_bucket' => (string) $chunk['tax_bucket'],
                        '_subtotal_minor' => 0,
                        '_discount_minor' => 0,
                        '_tax_minor' => 0,
                        '_grand_minor' => 0,
                        'lines' => [],
                        'allocations' => [],
                    ];
                }

                $chunkQty = max(0, (int) ($chunk['allocated_qty'] ?? 0));
                $chunkSubtotalMinor = (int) ($lineSubtotalShares[$chunkIndex] ?? 0);
                $chunkLineDiscountMinor = (int) ($lineDiscountShares[$chunkIndex] ?? 0);
                $chunkBillDiscountMinor = (int) ($billDiscountShares[$chunkIndex] ?? 0);
                $chunkTaxMinor = ((int) ($chunk['effective_tax_id'] ?? 0) > 0)
                    ? $this->extractIncludedTaxMinor($chunkSubtotalMinor, (float) ($chunk['tax_rate'] ?? 0))
                    : 0;

                $groupLine = $this->buildGroupLine(
                    line: $line,
                    qty: $chunkQty,
                    taxId: (int) ($chunk['effective_tax_id'] ?? 0) ?: null,
                    taxName: $chunk['tax_name'] ?? null,
                    taxRate: (float) ($chunk['tax_rate'] ?? 0),
                    lineSubtotalMinor: $chunkSubtotalMinor,
                    lineDiscountMinor: $chunkLineDiscountMinor,
                    billDiscountMinor: $chunkBillDiscountMinor,
                    lineTaxMinor: $chunkTaxMinor,
                    serialNumbers: is_array($chunk['serial_numbers'] ?? null) ? $chunk['serial_numbers'] : []
                );

                $groupLineIndex = count($groupMap[$splitKey]['lines']);
                $groupMap[$splitKey]['lines'][] = $groupLine;

                if ((bool) ($line['serial_number_required'] ?? false)) {
                    $groupMap[$splitKey]['allocations'][$groupLineIndex] = [];
                } else {
                    $groupMap[$splitKey]['allocations'][$groupLineIndex] = [[
                        'source_location_id' => (int) $chunk['source_location_id'],
                        'source_setting_id' => (int) $chunk['source_setting_id'],
                        'allocated_qty' => $chunkQty,
                        'tax_bucket_used' => (bool) ($chunk['tax_bucket_used'] ?? false),
                        'tax_policy_snapshot' => [
                            'source_is_pkp' => (bool) ($chunk['source_is_pkp'] ?? false),
                            'tax_id' => (int) ($chunk['effective_tax_id'] ?? 0) > 0 ? (int) $chunk['effective_tax_id'] : null,
                            'tax_name' => $chunk['tax_name'] ?? null,
                            'tax_rate' => (float) ($chunk['tax_rate'] ?? 0),
                        ],
                    ]];
                }

                $groupMap[$splitKey]['_subtotal_minor'] += $chunkSubtotalMinor;
                $groupMap[$splitKey]['_discount_minor'] += $chunkLineDiscountMinor + $chunkBillDiscountMinor;
                $groupMap[$splitKey]['_tax_minor'] += $chunkTaxMinor;
                $groupMap[$splitKey]['_grand_minor'] += $chunkSubtotalMinor;
            }
        }

        $groups = array_values($groupMap);
        usort($groups, static function (array $left, array $right): int {
            return strcmp((string) $left['split_key'], (string) $right['split_key']);
        });

        $normalized = [];
        foreach ($groups as $group) {
            $normalized[] = [
                'split_key' => (string) $group['split_key'],
                'source_setting_id' => (int) $group['source_setting_id'],
                'source_location_id' => (int) $group['source_location_id'],
                'tax_bucket' => (string) $group['tax_bucket'],
                'subtotal' => $this->fromMinor((int) $group['_subtotal_minor']),
                'discount_total' => $this->fromMinor((int) $group['_discount_minor']),
                'tax_total' => $this->fromMinor((int) $group['_tax_minor']),
                'grand_total' => $this->fromMinor((int) $group['_grand_minor']),
                'lines' => array_values($group['lines']),
                'allocations' => $group['allocations'],
            ];
        }

        return [
            'groups' => $normalized,
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<int, array<string, mixed>>
     */
    private function resolveSerialLineChunks(int $settingId, array $line, bool $lineTaxable): array
    {
        $productId = (int) ($line['product_id'] ?? 0);
        $qty = (int) ($line['qty'] ?? 0);
        $assignedSerials = array_values((array) ($line['assigned_serials'] ?? []));

        if ($productId <= 0 || $qty <= 0 || count($assignedSerials) !== $qty) {
            throw new PosCheckoutValidationException(
                'SERIAL_INVALID',
                'Serial tracked line is missing required serial assignments for split planning.'
            );
        }

        $serialRows = ProductSerialNumber::query()
            ->where('product_id', $productId)
            ->whereIn('serial_number', $assignedSerials)
            ->get()
            ->keyBy('serial_number');

        $chunks = [];

        foreach ($assignedSerials as $serialNumber) {
            $record = $serialRows->get($serialNumber);
            if (! $record) {
                throw new PosCheckoutValidationException(
                    'SERIAL_INVALID',
                    "Serial number {$serialNumber} is not valid for split planning."
                );
            }

            $sourceLocationId = (int) $record->location_id;
            $sourceSettingId = $this->resolveSourceSettingId($settingId, $sourceLocationId);
            $sourceIsPkp = $this->sourceIsPkp($sourceSettingId);

            $lineTaxId = (int) ($line['tax_id'] ?? 0);
            $candidateTaxId = (int) ($record->tax_id ?? 0);
            if ($candidateTaxId <= 0 && $lineTaxId > 0) {
                $candidateTaxId = $lineTaxId;
            }

            [$effectiveTaxId, $taxName, $taxRate] = $this->resolveEffectiveTax(
                $lineTaxable,
                $sourceIsPkp,
                $candidateTaxId
            );

            $taxBucket = $effectiveTaxId > 0 ? 'TAX:' . $effectiveTaxId : 'NON_TAX';
            $splitKey = $this->buildSplitKey($sourceSettingId, $sourceLocationId, $taxBucket);

            if (! isset($chunks[$splitKey])) {
                $chunks[$splitKey] = [
                    'split_key' => $splitKey,
                    'source_setting_id' => $sourceSettingId,
                    'source_location_id' => $sourceLocationId,
                    'tax_bucket' => $taxBucket,
                    'source_is_pkp' => $sourceIsPkp,
                    'effective_tax_id' => $effectiveTaxId > 0 ? $effectiveTaxId : null,
                    'tax_name' => $taxName,
                    'tax_rate' => $taxRate,
                    'allocated_qty' => 0,
                    'serial_numbers' => [],
                    'tax_bucket_used' => false,
                ];
            }

            $chunks[$splitKey]['allocated_qty']++;
            $chunks[$splitKey]['serial_numbers'][] = $serialNumber;
        }

        return array_values($chunks);
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<int, array<string, mixed>>  $lineAllocations
     * @return array<int, array<string, mixed>>
     */
    private function resolveNonSerialLineChunks(
        int $settingId,
        array $line,
        bool $lineTaxable,
        array $lineAllocations
    ): array {
        if ($lineAllocations === []) {
            throw new PosCheckoutValidationException(
                'STOCK_UNAVAILABLE',
                'Stock allocations are missing for split planning.'
            );
        }

        $chunks = [];

        foreach ($lineAllocations as $allocation) {
            $sourceLocationId = (int) ($allocation['source_location_id'] ?? 0);
            if ($sourceLocationId <= 0) {
                throw new PosCheckoutValidationException(
                    'STOCK_UNAVAILABLE',
                    'Source location is not valid for split planning.'
                );
            }

            $sourceSettingId = (int) ($allocation['source_setting_id'] ?? 0);
            if ($sourceSettingId <= 0) {
                $sourceSettingId = $this->resolveSourceSettingId($settingId, $sourceLocationId);
            }

            $snapshot = is_array($allocation['tax_policy_snapshot'] ?? null)
                ? $allocation['tax_policy_snapshot']
                : [];

            $sourceIsPkp = array_key_exists('source_is_pkp', $snapshot)
                ? (bool) $snapshot['source_is_pkp']
                : $this->sourceIsPkp($sourceSettingId);

            $lineTaxId = (int) ($line['tax_id'] ?? 0);
            $candidateTaxId = (int) ($snapshot['tax_id'] ?? 0);
            if ($candidateTaxId <= 0 && $lineTaxId > 0) {
                $candidateTaxId = $lineTaxId;
            }

            [$effectiveTaxId, $taxName, $taxRate] = $this->resolveEffectiveTax(
                $lineTaxable,
                $sourceIsPkp,
                $candidateTaxId
            );

            if ($taxName === null && isset($snapshot['tax_name'])) {
                $taxName = $snapshot['tax_name'] !== null ? (string) $snapshot['tax_name'] : null;
            }

            if ($taxRate <= 0 && isset($snapshot['tax_rate'])) {
                $taxRate = (float) $snapshot['tax_rate'];
            }

            $taxBucket = $effectiveTaxId > 0 ? 'TAX:' . $effectiveTaxId : 'NON_TAX';
            $splitKey = $this->buildSplitKey($sourceSettingId, $sourceLocationId, $taxBucket);

            $chunks[] = [
                'split_key' => $splitKey,
                'source_setting_id' => $sourceSettingId,
                'source_location_id' => $sourceLocationId,
                'tax_bucket' => $taxBucket,
                'source_is_pkp' => $sourceIsPkp,
                'effective_tax_id' => $effectiveTaxId > 0 ? $effectiveTaxId : null,
                'tax_name' => $taxName,
                'tax_rate' => $taxRate,
                'allocated_qty' => (int) ($allocation['allocated_qty'] ?? 0),
                'serial_numbers' => [],
                'tax_bucket_used' => (bool) ($allocation['tax_bucket_used'] ?? false),
            ];
        }

        return $chunks;
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<int, string>  $serialNumbers
     * @return array<string, mixed>
     */
    private function buildGroupLine(
        array $line,
        int $qty,
        ?int $taxId,
        ?string $taxName,
        float $taxRate,
        int $lineSubtotalMinor,
        int $lineDiscountMinor,
        int $billDiscountMinor,
        int $lineTaxMinor,
        array $serialNumbers
    ): array {
        return [
            'line_id' => (int) ($line['line_id'] ?? 0),
            'product_id' => (int) ($line['product_id'] ?? 0),
            'product_name' => (string) ($line['product_name'] ?? ''),
            'product_code' => (string) ($line['product_code'] ?? ''),
            'barcode' => isset($line['barcode']) ? (string) $line['barcode'] : null,
            'serial_number_required' => (bool) ($line['serial_number_required'] ?? false),
            'assigned_serials' => $serialNumbers,
            'qty' => $qty,
            'available_qty' => $qty,
            'unit_price' => round((float) ($line['unit_price'] ?? 0), 2),
            'line_discount_type' => (string) ($line['line_discount_type'] ?? 'fixed'),
            'line_discount_value' => round((float) ($line['line_discount_value'] ?? 0), 2),
            'tax_id' => $taxId,
            'tax_name' => $taxName,
            'tax_rate' => round($taxRate, 4),
            'merge_key' => (string) ($line['merge_key'] ?? ''),
            'price_source' => (string) ($line['price_source'] ?? 'BASE'),
            'price_valid' => (bool) ($line['price_valid'] ?? true),
            'price_error' => $line['price_error'] ?? null,
            'conversion_id' => isset($line['conversion_id']) ? (int) $line['conversion_id'] : null,
            'conversion_unit_name' => $line['conversion_unit_name'] ?? null,
            'line_discount_amount' => $this->fromMinor($lineDiscountMinor),
            'bill_discount_amount' => $this->fromMinor($billDiscountMinor),
            'line_subtotal' => $this->fromMinor($lineSubtotalMinor),
            'line_tax_total' => $this->fromMinor($lineTaxMinor),
            'line_total' => $this->fromMinor($lineSubtotalMinor),
        ];
    }

    private function resolveSourceSettingId(int $fallbackSettingId, int $locationId): int
    {
        $location = $this->locationById($locationId);

        return (int) ($location?->setting_id ?? $fallbackSettingId);
    }

    private function sourceIsPkp(int $settingId): bool
    {
        return (bool) ($this->settingById($settingId)?->is_pkp ?? false);
    }

    /**
     * @return array{0:int|null,1:string|null,2:float}
     */
    private function resolveEffectiveTax(bool $lineTaxable, bool $sourceIsPkp, int $candidateTaxId): array
    {
        if (! $lineTaxable || ! $sourceIsPkp) {
            return [null, null, 0.0];
        }

        $effectiveTaxId = $candidateTaxId > 0 ? $candidateTaxId : null;
        $tax = $effectiveTaxId ? $this->taxById($effectiveTaxId) : null;

        if (! $tax) {
            $tax = $this->fallbackTax();
            $effectiveTaxId = $tax ? (int) $tax->id : null;
        }

        if (! $tax) {
            return [null, null, 0.0];
        }

        return [
            (int) $tax->id,
            (string) $tax->name,
            (float) $tax->value,
        ];
    }

    private function fallbackTax(): ?Tax
    {
        if ($this->fallbackTaxResolved) {
            return $this->fallbackTax;
        }

        $this->fallbackTax = Tax::query()
            ->where('is_default', true)
            ->orderByDesc('id')
            ->first();

        if (! $this->fallbackTax) {
            $this->fallbackTax = Tax::query()->orderByDesc('id')->first();
        }

        $this->fallbackTaxResolved = true;

        return $this->fallbackTax;
    }

    private function settingById(int $settingId): ?Setting
    {
        if (! array_key_exists($settingId, $this->settingsCache)) {
            $this->settingsCache[$settingId] = Setting::query()->find($settingId);
        }

        return $this->settingsCache[$settingId];
    }

    private function locationById(int $locationId): ?Location
    {
        if (! array_key_exists($locationId, $this->locationsCache)) {
            $this->locationsCache[$locationId] = Location::query()->find($locationId);
        }

        return $this->locationsCache[$locationId];
    }

    private function taxById(int $taxId): ?Tax
    {
        if (! array_key_exists($taxId, $this->taxesCache)) {
            $this->taxesCache[$taxId] = Tax::query()->find($taxId);
        }

        return $this->taxesCache[$taxId];
    }

    private function buildSplitKey(int $sourceSettingId, int $sourceLocationId, string $taxBucket): string
    {
        return $sourceSettingId . ':' . $sourceLocationId . ':' . $taxBucket;
    }

    private function extractIncludedTaxMinor(int $grossMinor, float $taxRate): int
    {
        if ($grossMinor <= 0) {
            return 0;
        }

        $rateBasisPoints = (int) round(max(0.0, $taxRate) * 100, 0, PHP_ROUND_HALF_UP);
        if ($rateBasisPoints <= 0) {
            return 0;
        }

        $grossAmount = $grossMinor / 100;
        $taxAmount = (int) round(
            ($grossAmount * $rateBasisPoints) / (10000 + $rateBasisPoints),
            0,
            PHP_ROUND_HALF_UP
        );

        return $taxAmount * 100;
    }

    /**
     * @param  array<int, array<string, mixed>>  $allocations
     * @return array<int, int>
     */
    private function allocateMinorByQuantity(array $allocations, int $totalMinor, int $totalQty): array
    {
        $shares = [];
        if ($allocations === [] || $totalQty <= 0 || $totalMinor <= 0) {
            foreach ($allocations as $index => $_allocation) {
                $shares[$index] = 0;
            }

            return $shares;
        }

        $fractionalRows = [];
        $allocated = 0;

        foreach ($allocations as $index => $allocation) {
            $chunkQty = max(0, (int) ($allocation['allocated_qty'] ?? 0));
            $numerator = $totalMinor * $chunkQty;
            $floorShare = intdiv($numerator, $totalQty);
            $remainder = $numerator % $totalQty;

            $shares[$index] = $floorShare;
            $allocated += $floorShare;
            $fractionalRows[] = [
                'index' => $index,
                'remainder' => $remainder,
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
            $shares[(int) $row['index']]++;
        }

        return $shares;
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
