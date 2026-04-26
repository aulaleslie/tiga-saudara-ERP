<?php

namespace Modules\Pos\Services;

use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
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
        $childAllocationPools = [];

        foreach ($lines as $lineIndex => $line) {
            $qty = (int) ($line['qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $lineTaxable = (int) ($line['tax_id'] ?? 0) > 0;
            $isStockManaged = (bool) ($line['stock_managed'] ?? true);
            
            // Try new bundle-aware parent key, fallback to legacy index
            $myAllocations = $allocations["{$lineIndex}_P"] ?? ($allocations[$lineIndex] ?? []);

            if (!$isStockManaged) {
                // If not stock managed, it doesn't have an allocation record.
                // We'll place it in the terminal setting group.
                $lineChunks = $this->resolveTerminalLineChunks($settingId, $line, $lineTaxable);
            } else {
                $lineChunks = (bool) ($line['serial_number_required'] ?? false)
                    ? $this->resolveSerialLineChunks($settingId, $line, $lineTaxable)
                    : $this->resolveNonSerialLineChunks($settingId, $line, $lineTaxable, $myAllocations);
            }

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

            // Phase 2: Bundle revenue decomposition
            $bundleId = (int) ($line['bundle_id'] ?? 0);
            $bundleItems = is_array($line['bundle_items'] ?? null) ? $line['bundle_items'] : [];
            $childParts = [];
            $totalChildAllocationsMinor = 0;

            if ($bundleId > 0 && $bundleItems !== []) {
                foreach ($bundleItems as $j => $bi) {
                    $itemQty = $qty * (int) ($bi['quantity'] ?? 1);
                    $allocPrice = $this->resolveComponentAllocationAmount($settingId, $bi);
                    $itemAllocMinor = $this->toMinor($allocPrice * (int) ($bi['quantity'] ?? 1) * $qty);

                    $totalChildAllocationsMinor += $itemAllocMinor;

                    $childKey = "{$lineIndex}_C_{$j}";
                    $childAllocations = $allocations[$childKey] ?? null;

                    if ($bi['stock_managed'] && is_array($childAllocations)) {
                        $childParts[$childKey] = [
                            'allocations' => $childAllocations,
                            'total_minor' => $itemAllocMinor,
                            'total_qty' => $itemQty,
                        ];
                    } else {
                        // Stockless component - allocate to first non-PKP source
                        $nonPkpSource = $this->findFirstNonPkpSource($settingId);
                        if (! $nonPkpSource) {
                            throw new PosCheckoutValidationException(
                                'STOCKLESS_BUNDLE_ALLOCATION_FAILED',
                                "Gagal mengalokasikan pendapatan untuk komponen stokless '{$bi['product_name']}'. Tidak ada lokasi sumber non-PKP yang dikonfigurasi."
                            );
                        }

                        $sourceSettingId = (int) $nonPkpSource->setting_id;
                        $sourceLocationId = (int) $nonPkpSource->location_id;
                        $sourceIsPkp = false;

                        // Resolve tax candidate: parent line tax first, then active/default sale tax
                        $candidateTaxId = $line['tax_id'] ?? null;
                        [$effectiveTaxId, $taxName, $taxRate] = $this->resolveEffectiveTax(
                            $lineTaxable,
                            $sourceIsPkp,
                            (int) $candidateTaxId
                        );

                        $taxBucket = $effectiveTaxId > 0 ? 'TAX:' . $effectiveTaxId : 'NON_TAX';
                        $splitKey = $this->buildSplitKey($sourceSettingId, $sourceLocationId, $taxBucket);

                        $childParts[$childKey] = [
                            'is_stockless' => true,
                            'split_key' => $splitKey,
                            'source_setting_id' => $sourceSettingId,
                            'source_location_id' => $sourceLocationId,
                            'tax_bucket' => $taxBucket,
                            'effective_tax_id' => $effectiveTaxId,
                            'tax_name' => $taxName,
                            'tax_rate' => $taxRate,
                            'total_minor' => $itemAllocMinor,
                        ];
                    }
                }
            }

            $parentResidualMinor = $lineSubtotalMinor - $totalChildAllocationsMinor;
            if ($parentResidualMinor < 0) {
                $productName = $line['product_name'] ?? ('Produk #' . $line['product_id']);
                throw new PosCheckoutValidationException(
                    'BUNDLE_RESIDUAL_NEGATIVE',
                    "Harga paket '{$productName}' tidak mencukupi untuk menutupi alokasi komponen. (Residual: " . ($parentResidualMinor / 100) . ")"
                );
            }

            // Distribute parent residual and child allocations across their chunks/groups
            $parentResidualShares = $this->allocateMinorByQuantity($lineChunks, $parentResidualMinor, $qty);
            $lineDiscountShares = $this->allocateMinorByQuantity($lineChunks, $lineDiscountMinor, $qty);
            $billDiscountShares = $this->allocateMinorByQuantity($lineChunks, $billDiscountMinor, $qty);

            // Group everything by splitKey
            $lineRevenueByGroup = []; // splitKey -> { subtotalMinor, discountMinor, billDiscountMinor, parentQty, taxInfo, ... }

            // 1. Assign parent shares
            foreach ($lineChunks as $chunkIndex => $chunk) {
                $splitKey = (string) $chunk['split_key'];
                if (! isset($lineRevenueByGroup[$splitKey])) {
                    $lineRevenueByGroup[$splitKey] = $this->initLineGroup($chunk);
                }

                $lineRevenueByGroup[$splitKey]['subtotal_minor'] += $parentResidualShares[$chunkIndex];
                $lineRevenueByGroup[$splitKey]['discount_minor'] += $lineDiscountShares[$chunkIndex];
                $lineRevenueByGroup[$splitKey]['bill_discount_minor'] += $billDiscountShares[$chunkIndex];
                $lineRevenueByGroup[$splitKey]['parent_qty'] += (int) $chunk['allocated_qty'];
                $lineRevenueByGroup[$splitKey]['parent_chunks'][] = $chunk;
            }

            // 2. Assign child shares
            foreach ($childParts as $childKey => $part) {
                if (isset($part['is_stockless'])) {
                    $splitKey = $part['split_key'];
                    if (! isset($lineRevenueByGroup[$splitKey])) {
                        $lineRevenueByGroup[$splitKey] = $this->initLineGroup($part);
                    }
                    $lineRevenueByGroup[$splitKey]['subtotal_minor'] += $part['total_minor'];
                } else {
                    $childShares = $this->allocateMinorByQuantity($part['allocations'], $part['total_minor'], $part['total_qty']);
                    foreach ($part['allocations'] as $chunkIndex => $chunk) {
                        $sourceLocationId = (int) ($chunk['source_location_id'] ?? 0);
                        $sourceSettingId = (int) ($chunk['source_setting_id'] ?? $this->resolveSourceSettingId($settingId, $sourceLocationId));
                        $sourceIsPkp = (bool) ($chunk['tax_policy_snapshot']['source_is_pkp'] ?? $this->sourceIsPkp($sourceSettingId));

                        // Bundle allocation tax uses parent/default context, falling back to chunk tax if line is untaxed.
                        $candidateTaxId = (int) ($line['tax_id'] ?? 0);
                        if ($candidateTaxId <= 0 && isset($chunk['tax_policy_snapshot']['tax_id'])) {
                            $candidateTaxId = (int) $chunk['tax_policy_snapshot']['tax_id'];
                        }

                        $effectiveLineTaxable = $lineTaxable || ($candidateTaxId > 0);

                        [$effectiveTaxId, $taxName, $taxRate] = $this->resolveEffectiveTax($effectiveLineTaxable, $sourceIsPkp, $candidateTaxId);

                        $taxBucket = $effectiveTaxId > 0 ? 'TAX:' . $effectiveTaxId : 'NON_TAX';
                        $splitKey = $this->buildSplitKey($sourceSettingId, $sourceLocationId, $taxBucket);

                        if (! isset($lineRevenueByGroup[$splitKey])) {
                            $lineRevenueByGroup[$splitKey] = $this->initLineGroup([
                                'source_setting_id' => $sourceSettingId,
                                'source_location_id' => $sourceLocationId,
                                'tax_bucket' => $taxBucket,
                                'effective_tax_id' => $effectiveTaxId,
                                'tax_name' => $taxName,
                                'tax_rate' => $taxRate,
                            ]);
                        }

                        $lineRevenueByGroup[$splitKey]['subtotal_minor'] += $childShares[$chunkIndex];
                        $lineRevenueByGroup[$splitKey]['child_allocations'][$childKey][] = array_merge($chunk, [
                            'allocated_minor' => $childShares[$chunkIndex],
                            'tax_policy_snapshot' => [
                                'source_is_pkp' => $sourceIsPkp,
                                'tax_id' => $effectiveTaxId,
                                'tax_name' => $taxName,
                                'tax_rate' => $taxRate,
                            ]
                        ]);
                    }
                }
            }

            // 3. Finalize groups for this line and add to groupMap
            foreach ($lineRevenueByGroup as $splitKey => $rev) {
                if (! isset($groupMap[$splitKey])) {
                    $groupMap[$splitKey] = [
                        'split_key' => $splitKey,
                        'source_setting_id' => (int) $rev['source_setting_id'],
                        'source_location_id' => (int) $rev['source_location_id'],
                        'tax_bucket' => (string) $rev['tax_bucket'],
                        '_subtotal_minor' => 0,
                        '_discount_minor' => 0,
                        '_tax_minor' => 0,
                        '_grand_minor' => 0,
                        'lines' => [],
                        'allocations' => [],
                    ];
                }

                $chunkSubtotalMinor = $rev['subtotal_minor'];
                $chunkLineDiscountMinor = $rev['discount_minor'];
                $chunkBillDiscountMinor = $rev['bill_discount_minor'];
                $chunkTaxMinor = ($rev['effective_tax_id'] > 0)
                    ? $this->extractIncludedTaxMinor($chunkSubtotalMinor, (float) $rev['tax_rate'])
                    : 0;

                $revSerials = [];
                foreach ($rev['parent_chunks'] as $pc) {
                    if (isset($pc['serial_numbers']) && is_array($pc['serial_numbers'])) {
                        foreach ($pc['serial_numbers'] as $sn) {
                            $revSerials[] = $sn;
                        }
                    }
                }

                $groupLine = $this->buildGroupLine(
                    line: $line,
                    qty: $qty, // We use full cart qty but owner-specific price to reconcile totals.
                    taxId: $rev['effective_tax_id'] > 0 ? $rev['effective_tax_id'] : null,
                    taxName: $rev['tax_name'],
                    taxRate: (float) $rev['tax_rate'],
                    lineSubtotalMinor: $chunkSubtotalMinor,
                    lineDiscountMinor: $chunkLineDiscountMinor,
                    billDiscountMinor: $chunkBillDiscountMinor,
                    lineTaxMinor: $chunkTaxMinor,
                    serialNumbers: $revSerials
                );

                // Fix: if we have multiple groups for the same line, the qty of 10 might look like 10 in every group.
                // However, split posting creates owner-specific Sales. One owner might own 7/10 parent stock.
                // Usually, we want the sum of quantities to match 10.
                // If it's a bundle, an owner might only own a component but not the parent.
                // We'll follow the pattern: qty reflects the logical count of "parent units" affected by this owner.
                // For parent chunks, use their allocated qty. For purely child-owning groups, use the full parent qty (as they "fulfilled" that component for all bundles).
                // Wait, if an owner owns a component for all 10 bundles, they get 10 * ChildAlloc. Subtotal is correct. Qty 10 is correct.
                // If an owner owns 7/10 parent units, they get 7/10 * Residual. Qty 7 is correct.
                // If an owner owns BOTH 7/10 parent AND 10/10 child, they get (7/10 Residual + 10/10 ChildAlloc). Qty should be?
                // Probably 10, as they are involved in all 10 bundles.
                
                $finalGroupLineQty = $rev['parent_qty'] > 0 ? $rev['parent_qty'] : $qty;
                $groupLine['qty'] = $finalGroupLineQty;
                $groupLine['unit_price'] = $this->fromMinor((int) round($chunkSubtotalMinor / $finalGroupLineQty));

                // If this group didn't fulfill any parent stock, mark it as non-stock-managed 
                // and non-serial-tracked so it skips validation and movement for the parent.
                if ($rev['parent_qty'] <= 0) {
                    $groupLine['stock_managed'] = false;
                    $groupLine['serial_number_required'] = false;
                }

                $groupLineIndex = count($groupMap[$splitKey]['lines']);
                $groupMap[$splitKey]['lines'][] = $groupLine;

                // Build allocations for this group line
                $itemAllocations = [];
                foreach ($rev['parent_chunks'] as $pc) {
                    $itemAllocations[] = [
                        'source_location_id' => (int) $pc['source_location_id'],
                        'source_setting_id' => (int) $pc['source_setting_id'],
                        'allocated_qty' => (int) $pc['allocated_qty'],
                        'tax_bucket_used' => (bool) ($pc['tax_bucket_used'] ?? false),
                        'tax_policy_snapshot' => [
                            'source_is_pkp' => (bool) ($pc['source_is_pkp'] ?? false),
                            'tax_id' => (int) ($pc['effective_tax_id'] ?? 0) > 0 ? (int) $pc['effective_tax_id'] : null,
                            'tax_name' => $pc['tax_name'] ?? null,
                            'tax_rate' => (float) ($pc['tax_rate'] ?? 0),
                        ],
                        'serial_numbers' => is_array($pc['serial_numbers'] ?? null) ? $pc['serial_numbers'] : [],
                    ];
                }
                
                $groupMap[$splitKey]['allocations'][$groupLineIndex] = $itemAllocations;
                $groupMap[$splitKey]['allocations']["{$groupLineIndex}_P"] = $itemAllocations;
                
                foreach ($rev['child_allocations'] as $childKey => $allocs) {
                    $groupMap[$splitKey]['allocations']["{$groupLineIndex}_C_" . explode('_C_', $childKey)[1]] = $allocs;
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
            $serialTaxId = (int) ($record->tax_id ?? 0);
            $candidateTaxId = $lineTaxId > 0 ? $lineTaxId : ($serialTaxId > 0 ? $serialTaxId : 0);
            $serialLineTaxable = $lineTaxable || $serialTaxId > 0;

            [$effectiveTaxId, $taxName, $taxRate] = $this->resolveEffectiveTax(
                $serialLineTaxable,
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
                    'tax_bucket_used' => $effectiveTaxId > 0,
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
            $candidateTaxId = $lineTaxId > 0 ? $lineTaxId : (int) ($snapshot['tax_id'] ?? 0);

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
            'bundle_id' => isset($line['bundle_id']) ? (int) $line['bundle_id'] : null,
            'bundle_items' => is_array($line['bundle_items'] ?? null) ? $line['bundle_items'] : [],
        ];
    }

    /**
     * Resolve a static chunk for terminal setting for non-stock managed items.
     */
    private function resolveTerminalLineChunks(int $settingId, array $line, bool $lineTaxable): array
    {
        $sourceIsPkp = $this->sourceIsPkp($settingId);
        $candidateTaxId = (int) ($line['tax_id'] ?? 0);

        [$effectiveTaxId, $taxName, $taxRate] = $this->resolveEffectiveTax(
            $lineTaxable,
            $sourceIsPkp,
            $candidateTaxId
        );

        $taxBucket = $effectiveTaxId > 0 ? 'TAX:' . $effectiveTaxId : 'NON_TAX';
        
        // Pick any location for this setting to satisfy schema requirements
        $locationId = 0;
        $setting = $this->settingById($settingId);
        if ($setting) {
             $location = \Modules\Setting\Entities\Location::where('setting_id', $settingId)->first();
             $locationId = $location ? (int) $location->id : 0;
        }

        $splitKey = $this->buildSplitKey($settingId, $locationId, $taxBucket);

        return [[
            'split_key' => $splitKey,
            'source_setting_id' => $settingId,
            'source_location_id' => $locationId,
            'tax_bucket' => $taxBucket,
            'source_is_pkp' => $sourceIsPkp,
            'effective_tax_id' => $effectiveTaxId,
            'tax_name' => $taxName,
            'tax_rate' => $taxRate,
            'allocated_qty' => (int) ($line['qty'] ?? 0),
            'serial_numbers' => [],
            'tax_bucket_used' => $effectiveTaxId > 0,
        ]];
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

    /**
     * Consume a required quantity from an allocation pool.
     * 
     * @param array<int, array<string, mixed>> $pool
     * @param int $requiredQty
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function consumeFromPool(array $pool, int $requiredQty): array
    {
        if ($requiredQty <= 0) {
            return [[], $pool];
        }

        $partitioned = [];
        $remainingPool = [];
        $remainingToTake = $requiredQty;

        foreach ($pool as $allocation) {
            if ($remainingToTake <= 0) {
                $remainingPool[] = $allocation;
                continue;
            }

            $currentAllocQty = (int) ($allocation['allocated_qty'] ?? 0);
            if ($currentAllocQty <= 0) {
                continue;
            }

            $take = min($currentAllocQty, $remainingToTake);
            
            $match = $allocation;
            $match['allocated_qty'] = $take;
            $partitioned[] = $match;

            $left = $currentAllocQty - $take;
            if ($left > 0) {
                $rem = $allocation;
                $rem['allocated_qty'] = $left;
                $remainingPool[] = $rem;
            }

            $remainingToTake -= $take;
        }

        return [$partitioned, $remainingPool];
    }

    private function toMinor(float $value): int
    {
        return (int) round($value * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function fromMinor(int $value): float
    {
        return round($value / 100, 2);
    }

    private function initLineGroup(array $chunk): array
    {
        return [
            'source_setting_id' => $chunk['source_setting_id'],
            'source_location_id' => $chunk['source_location_id'],
            'tax_bucket' => $chunk['tax_bucket'],
            'effective_tax_id' => $chunk['effective_tax_id'],
            'tax_name' => $chunk['tax_name'],
            'tax_rate' => $chunk['tax_rate'],
            'subtotal_minor' => 0,
            'discount_minor' => 0,
            'bill_discount_minor' => 0,
            'parent_qty' => 0,
            'parent_chunks' => [],
            'child_allocations' => [],
        ];
    }

    private function resolveComponentAllocationAmount(int $settingId, array $item): float
    {
        // 2.1 Resolve from informational_item_price
        $infoPrice = (float) ($item['informational_item_price'] ?? 0);
        if ($infoPrice > 0) {
            return $infoPrice;
        }

        // 2.2 Fallback to active-setting product sale price
        $productId = (int) $item['product_id'];
        $activePrice = ProductPrice::query()
            ->forProduct($productId)
            ->forSetting($settingId)
            ->value('sale_price');

        return (float) ($activePrice ?? 0);
    }

    private function findFirstNonPkpSource(int $settingId): ?SettingSaleLocation
    {
        $locationIds = SalesLocationResolver::resolveLocationIds($settingId)->all();

        return SettingSaleLocation::query()
            ->where('setting_id', $settingId)
            ->whereIn('location_id', $locationIds)
            ->where('is_enabled', true)
            ->whereHas('setting', fn($q) => $q->where('is_pkp', false))
            ->with('setting:id,is_pkp')
            ->orderBy('position')
            ->first();
    }
}
