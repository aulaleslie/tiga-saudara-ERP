<?php

namespace Modules\Pos\Services;

use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;

class PosCheckoutSplitPlannerService
{
    public function __construct(
        private readonly PosNonStockSourceResolverService $nonStockSourceResolver = new PosNonStockSourceResolverService()
    ) {
    }

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
                // Non-stock content has no allocation record; ownership comes from the
                // first configured POS sales-location source.
                $lineChunks = $this->resolveNonStockLineChunks($settingId, $line);
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
            $isBundledLine = ($bundleId > 0 && $bundleItems !== []);
            $posOwnerIsPkp = $this->sourceIsPkp($settingId);
            $childParts = [];
            $totalChildAllocationsMinor = 0;

            if ($isBundledLine) {
                foreach ($bundleItems as $j => $bi) {
                    $allocPrice = $this->resolveComponentAllocationAmount($settingId, $bi);
                    $itemAllocMinor = $this->toMinor($allocPrice * (int) ($bi['quantity'] ?? 1) * $qty);
                    $totalChildAllocationsMinor += $itemAllocMinor;
                }

                $parentResidualMinor = $lineSubtotalMinor - $totalChildAllocationsMinor;
                if ($parentResidualMinor < 0) {
                    $productName = $line['product_name'] ?? ('Produk #' . $line['product_id']);
                    throw new PosCheckoutValidationException(
                        'BUNDLE_RESIDUAL_NEGATIVE',
                        "Harga paket '{$productName}' tidak mencukupi untuk menutupi alokasi komponen. (Residual: " . ($parentResidualMinor / 100) . ")"
                    );
                }

                foreach ($bundleItems as $j => $bi) {
                    $itemQty = $qty * (int) ($bi['quantity'] ?? 1);
                    $allocPrice = $this->resolveComponentAllocationAmount($settingId, $bi);
                    $itemAllocMinor = $this->toMinor($allocPrice * (int) ($bi['quantity'] ?? 1) * $qty);

                    $childKey = "{$lineIndex}_C_{$j}";
                    $childAllocations = $allocations[$childKey] ?? null;
                    $isBiStockManaged = (bool) ($bi['stock_managed'] ?? true);
                    if ($isBiStockManaged) {
                        if (! is_array($childAllocations) || empty($childAllocations)) {
                            throw new PosCheckoutValidationException(
                                'STOCK_UNAVAILABLE',
                                "Alokasi stok tidak ditemukan untuk komponen paket #{$childKey}."
                            );
                        }

                        $childParts[$childKey] = [
                            'allocations' => $childAllocations,
                            'total_minor' => $itemAllocMinor,
                            'total_qty' => $itemQty,
                        ];
                    } else {
                        // Stockless component - owned by the first configured POS sales-location source.
                        $componentLabel = (string) ($bi['product_name'] ?? ('Produk #' . ($bi['product_id'] ?? '')));
                        $nonStockSource = $this->requireNonStockSource($settingId, $componentLabel);

                        $sourceSettingId = $nonStockSource['setting_id'];
                        $sourceLocationId = $nonStockSource['location_id'];
                        $sourceIsPkp = $this->sourceIsPkp($sourceSettingId);

                        $taxRequired = $sourceIsPkp;
                        $candidateTaxId = $line['tax_id'] ?? null;

                        [$effectiveTaxId, $taxName, $taxRate] = $this->resolveEffectiveTax(
                            $taxRequired,
                            (int) $candidateTaxId
                        );

                        $taxBucket = $effectiveTaxId > 0 ? 'TAX:' . $effectiveTaxId : 'NON_TAX';
                        $splitKey = $this->buildSplitKey($sourceSettingId, $sourceLocationId, $taxBucket);

                        $childParts[$childKey] = [
                            'is_stockless' => true,
                            'source_is_pkp' => $sourceIsPkp,
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
            } else {
                $parentResidualMinor = $lineSubtotalMinor;
            }

            // Distribute parent residual and child allocations across their chunks/groups
            $parentResidualShares = $this->allocateMinorByQuantity($lineChunks, $parentResidualMinor, $qty);
            $lineDiscountShares = $this->allocateMinorByQuantity($lineChunks, $lineDiscountMinor, $qty);
            $billDiscountShares = $this->allocateMinorByQuantity($lineChunks, $billDiscountMinor, $qty);

            // Group everything by splitKey
            $lineRevenueByGroup = []; // splitKey -> { subtotalMinor, discountMinor, billDiscountMinor, parentQty, taxInfo, ... }

            // 1. Assign parent shares
            foreach ($lineChunks as $chunkIndex => $chunk) {
                $sourceSettingId = (int) $chunk['source_setting_id'];
                $sourceLocationId = (int) $chunk['source_location_id'];
                $sourceIsPkp = (bool) ($chunk['source_is_pkp'] ?? $this->sourceIsPkp($sourceSettingId));

                if ($isBundledLine) {
                    // Bundled revenue is taxable ONLY when this group is owned by the POS transaction owner AND that owner is PKP
                    $taxRequired = ($sourceSettingId === $settingId) && $posOwnerIsPkp;
                    $candidateTaxId = (int) ($line['tax_id'] ?? 0);
                    [$effectiveTaxId, $taxName, $taxRate] = $this->resolveEffectiveTax($taxRequired, $candidateTaxId);
                    $taxBucket = $effectiveTaxId > 0 ? 'TAX:' . $effectiveTaxId : 'NON_TAX';
                    $splitKey = $this->buildSplitKey($sourceSettingId, $sourceLocationId, $taxBucket);

                    $chunk['split_key'] = $splitKey;
                    $chunk['tax_bucket'] = $taxBucket;
                    $chunk['effective_tax_id'] = $effectiveTaxId;
                    $chunk['tax_name'] = $taxName;
                    $chunk['tax_rate'] = $taxRate;
                    // Preserve resolver's physical tax_bucket_used if present
                } else {
                    $splitKey = (string) $chunk['split_key'];
                }

                if (! isset($lineRevenueByGroup[$splitKey])) {
                    $lineRevenueByGroup[$splitKey] = $this->initLineGroup($chunk);
                }

                $lineRevenueByGroup[$splitKey]['subtotal_minor'] += $parentResidualShares[$chunkIndex];
                $lineRevenueByGroup[$splitKey]['parent_residual_minor'] += $parentResidualShares[$chunkIndex];
                $lineRevenueByGroup[$splitKey]['discount_minor'] += $lineDiscountShares[$chunkIndex];
                $lineRevenueByGroup[$splitKey]['bill_discount_minor'] += $billDiscountShares[$chunkIndex];
                $lineRevenueByGroup[$splitKey]['parent_qty'] += (int) $chunk['allocated_qty'];
                $lineRevenueByGroup[$splitKey]['parent_chunks'][] = $chunk;
            }

            // 2. Assign child shares
            foreach ($childParts as $childKey => $part) {
                if (isset($part['is_stockless'])) {
                    $sourceSettingId = (int) $part['source_setting_id'];
                    $sourceLocationId = (int) $part['source_location_id'];
                    $sourceIsPkp = (bool) ($part['source_is_pkp'] ?? $this->sourceIsPkp($sourceSettingId));
                    $taxRequired = ($sourceSettingId === $settingId) && $posOwnerIsPkp;
                    $candidateTaxId = (int) ($line['tax_id'] ?? 0);
                    [$effectiveTaxId, $taxName, $taxRate] = $this->resolveEffectiveTax($taxRequired, $candidateTaxId);
                    $taxBucket = $effectiveTaxId > 0 ? 'TAX:' . $effectiveTaxId : 'NON_TAX';
                    $splitKey = $this->buildSplitKey($sourceSettingId, $sourceLocationId, $taxBucket);

                    $part['split_key'] = $splitKey;
                    $part['tax_bucket'] = $taxBucket;
                    $part['effective_tax_id'] = $effectiveTaxId;
                    $part['tax_name'] = $taxName;
                    $part['tax_rate'] = $taxRate;

                    if (! isset($lineRevenueByGroup[$splitKey])) {
                        $lineRevenueByGroup[$splitKey] = $this->initLineGroup($part);
                    }

                    $lineRevenueByGroup[$splitKey]['subtotal_minor'] += $part['total_minor'];
                    $lineRevenueByGroup[$splitKey]['child_allocations'][$childKey][] = [
                        'source_setting_id' => $sourceSettingId,
                        'source_location_id' => $sourceLocationId,
                        'allocated_qty' => $qty * (int) ($bundleItems[explode('_C_', $childKey)[1]]['quantity'] ?? 1),
                        'allocated_minor' => $part['total_minor'],
                        'tax_bucket_used' => (bool) ($effectiveTaxId > 0),
                        // Audit-only: this component never enters stock allocation or movement.
                        'is_non_stock_audit' => true,
                        'tax_policy_snapshot' => [
                            'source_is_pkp' => (bool) ($part['source_is_pkp'] ?? false),
                            'tax_id' => $effectiveTaxId,
                            'tax_name' => $taxName,
                            'tax_rate' => $taxRate,
                        ]
                    ];
                } else {
                    $childShares = $this->allocateMinorByQuantity($part['allocations'], $part['total_minor'], $part['total_qty']);
                    foreach ($part['allocations'] as $chunkIndex => $chunk) {
                        $sourceLocationId = (int) ($chunk['source_location_id'] ?? 0);
                        $suppliedSettingId = isset($chunk['source_setting_id']) ? (int) $chunk['source_setting_id'] : null;
                        $sourceSettingId = $this->resolveSourceSettingId($settingId, $sourceLocationId, $suppliedSettingId);
                        $sourceIsPkp = (bool) ($chunk['tax_policy_snapshot']['source_is_pkp'] ?? $this->sourceIsPkp($sourceSettingId));

                        $taxRequired = ($sourceSettingId === $settingId) && $posOwnerIsPkp;
                        $candidateTaxId = (int) ($line['tax_id'] ?? 0);

                        [$effectiveTaxId, $taxName, $taxRate] = $this->resolveEffectiveTax($taxRequired, $candidateTaxId);

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
                            'tax_bucket_used' => (bool) ($chunk['tax_bucket_used'] ?? false),
                            'tax_policy_snapshot' => [
                                'source_is_pkp' => (bool) ($chunk['tax_policy_snapshot']['source_is_pkp'] ?? $this->sourceIsPkp($sourceSettingId)),
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
                
                $finalGroupLineQty = $rev['parent_qty'];
                $groupLine['qty'] = $finalGroupLineQty;
                $parentResidualMinorForGroup = $rev['parent_residual_minor'];
                $groupLine['unit_price'] = $finalGroupLineQty > 0 
                    ? $this->fromMinor((int) round($parentResidualMinorForGroup / $finalGroupLineQty))
                    : 0;

                // If this group didn't fulfill any parent stock, mark it as non-stock-managed 
                // and non-serial-tracked so it skips validation and movement for the parent.
                if ($rev['parent_qty'] <= 0) {
                    $groupLine['stock_managed'] = false;
                    $groupLine['serial_number_required'] = false;
                    // This owner group fulfils only a component, so the parent contributes
                    // no quantity here. This is a planner-authored skip, not a claim that
                    // the product is non-stock-managed, and must not be posted as audit
                    // evidence nor rejected as a classification conflict.
                    $groupLine['parent_not_fulfilled_by_group'] = true;
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
                        'is_non_stock_audit' => (bool) ($pc['is_non_stock_audit'] ?? false),
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
            ->keyBy(fn ($row) => ProductSerialNumber::normalize((string) $row->serial_number));

        $chunks = [];

        foreach ($assignedSerials as $serialNumber) {
            $record = $serialRows->get(ProductSerialNumber::normalize((string) $serialNumber));
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
            $taxRequired = $sourceIsPkp;

            [$effectiveTaxId, $taxName, $taxRate] = $this->resolveEffectiveTax(
                $taxRequired,
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
            $chunks[$splitKey]['serial_numbers'][] = (string) $record->serial_number;
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

            $suppliedSettingId = isset($allocation['source_setting_id']) ? (int) $allocation['source_setting_id'] : null;
            $sourceSettingId = $this->resolveSourceSettingId($settingId, $sourceLocationId, $suppliedSettingId);

            $snapshot = is_array($allocation['tax_policy_snapshot'] ?? null)
                ? $allocation['tax_policy_snapshot']
                : [];

            $sourceIsPkp = array_key_exists('source_is_pkp', $snapshot)
                ? (bool) $snapshot['source_is_pkp']
                : $this->sourceIsPkp($sourceSettingId);

            // The selling owner's PKP status alone determines customer-tax applicability.
            // Which physical stock bucket was consumed (tax_bucket_used) must never
            // independently make a non-PKP source's allocation taxable.
            $taxRequired = $sourceIsPkp;
            $lineTaxId = (int) ($line['tax_id'] ?? 0);
            $candidateTaxId = $lineTaxId > 0 ? $lineTaxId : (int) ($snapshot['tax_id'] ?? 0);

            [$effectiveTaxId, $taxName, $taxRate] = $this->resolveEffectiveTax($taxRequired, $candidateTaxId);

            if ($effectiveTaxId === null && ! $taxRequired) {
                $taxName = null;
                $taxRate = 0.0;
            } elseif ($taxName === null && isset($snapshot['tax_name'])) {
                $taxName = $snapshot['tax_name'] !== null ? (string) $snapshot['tax_name'] : null;
            }

            if ($taxRequired && $taxRate <= 0 && isset($snapshot['tax_rate'])) {
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
            'stock_managed' => (bool) ($line['stock_managed'] ?? true),
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
            'bundle_item_serials' => is_array($line['bundle_item_serials'] ?? null) ? $line['bundle_item_serials'] : [],
        ];
    }

    /**
     * Resolve the ownership chunk for a non-stock-managed parent line.
     *
     * Ownership is the first enabled entry of the configured POS sales-location order,
     * never the terminal setting and never filtered by PKP status. The resolved source
     * owner's tax policy then determines the split tax bucket.
     *
     * @param  array<string, mixed>  $line
     * @return array<int, array<string, mixed>>
     */
    private function resolveNonStockLineChunks(int $settingId, array $line): array
    {
        $productLabel = (string) (($line['product_name'] ?? null) ?: ('Produk #' . ($line['product_id'] ?? '')));
        $source = $this->requireNonStockSource($settingId, $productLabel);

        $sourceSettingId = $source['setting_id'];
        $sourceLocationId = $source['location_id'];
        $sourceIsPkp = $this->sourceIsPkp($sourceSettingId);

        [$effectiveTaxId, $taxName, $taxRate] = $this->resolveEffectiveTax(
            $sourceIsPkp,
            (int) ($line['tax_id'] ?? 0)
        );

        $taxBucket = $effectiveTaxId > 0 ? 'TAX:' . $effectiveTaxId : 'NON_TAX';

        return [[
            'split_key' => $this->buildSplitKey($sourceSettingId, $sourceLocationId, $taxBucket),
            'source_setting_id' => $sourceSettingId,
            'source_location_id' => $sourceLocationId,
            'tax_bucket' => $taxBucket,
            'source_is_pkp' => $sourceIsPkp,
            'effective_tax_id' => $effectiveTaxId,
            'tax_name' => $taxName,
            'tax_rate' => $taxRate,
            'allocated_qty' => (int) ($line['qty'] ?? 0),
            'serial_numbers' => [],
            'tax_bucket_used' => $effectiveTaxId > 0,
            // Audit-only: this parent never enters stock allocation or movement.
            'is_non_stock_audit' => true,
        ]];
    }

    private function resolveSourceSettingId(int $fallbackSettingId, int $locationId, ?int $suppliedSettingId = null): int
    {
        $location = $this->locationById($locationId);

        if (! $location) {
            throw new PosCheckoutValidationException(
                'STOCK_UNAVAILABLE',
                "Lokasi sumber #{$locationId} tidak ditemukan."
            );
        }

        $locationSettingId = (int) $location->setting_id;

        if ($suppliedSettingId !== null && $suppliedSettingId > 0 && $suppliedSettingId !== $locationSettingId) {
            throw new PosCheckoutValidationException(
                'STOCK_UNAVAILABLE',
                "Ketidakcocokan kepemilikan: setting #{$suppliedSettingId} tidak sesuai dengan setting #{$locationSettingId} pada lokasi #{$locationId}."
            );
        }

        return $locationSettingId > 0 ? $locationSettingId : $fallbackSettingId;
    }

    private function sourceIsPkp(int $settingId): bool
    {
        return (bool) ($this->settingById($settingId)?->is_pkp ?? false);
    }

    /**
     * @return array{0:int|null,1:string|null,2:float}
     */
    private function resolveEffectiveTax(bool $taxRequired, int $candidateTaxId): array
    {
        if (! $taxRequired) {
            return [null, null, 0.0];
        }

        $effectiveTaxId = $candidateTaxId > 0 ? $candidateTaxId : null;
        $tax = $effectiveTaxId ? $this->taxById($effectiveTaxId) : null;

        if (! $tax) {
            $tax = $this->fallbackTax();
            $effectiveTaxId = $tax ? (int) $tax->id : null;
        }

        if (! $tax) {
            throw new PosCheckoutValidationException(
                'TAX_POLICY_UNRESOLVED',
                'Checkout requires a fallback tax, but no fallback tax is configured.'
            );
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
            'parent_residual_minor' => 0,
            'discount_minor' => 0,
            'bill_discount_minor' => 0,
            'parent_qty' => 0,
            'parent_chunks' => [],
            'child_allocations' => [],
        ];
    }

    private function resolveComponentAllocationAmount(int $settingId, array $item): float
    {
        return (float) ($item['informational_item_price'] ?? 0);
    }

    /**
     * Resolve the configured first POS sales-location source for non-stock content.
     *
     * @return array{setting_id:int, location_id:int}
     */
    private function requireNonStockSource(int $settingId, string $productLabel): array
    {
        $source = $this->nonStockSourceResolver->resolve($settingId);

        if ($source === null) {
            throw new PosCheckoutValidationException(
                'NON_STOCK_SOURCE_UNCONFIGURED',
                "Gagal menentukan sumber kepemilikan untuk produk non-stok '{$productLabel}'. Tidak ada lokasi penjualan POS aktif yang dikonfigurasi."
            );
        }

        return $source;
    }
}
