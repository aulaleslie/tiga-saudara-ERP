<?php

namespace Modules\Pos\Services;

class PosCartTotalsCalculator
{
    /**
     * Marks a line whose stored authoritative total must be consumed as-is
     * rather than recalculated: a draft row loaded from the database that has
     * not been touched by a pricing interaction in this session.
     *
     * Cleared by quantity, discount, tax, packing, bundle, customer-tier, and
     * automatic-price changes, so those recalculate under the business's
     * current rounding increment. Set only by server-side cart operations.
     */
    public const LINE_CLEAN_FLAG = 'line_pricing_clean';

    /**
     * Fingerprint of the pricing inputs behind a clean row's stored total.
     *
     * A cached total is reused only while this still matches the line's current
     * inputs. That way a mutation path that forgets to dirty the row fails safe
     * — the fingerprint no longer matches and the row is recalculated — rather
     * than silently serving a stale total.
     */
    public const LINE_PRICING_FINGERPRINT = 'line_pricing_fingerprint';

    /**
     * Fingerprint the inputs that determine a row's automatic price.
     *
     * @param  array<string, mixed>  $line
     */
    public static function pricingFingerprint(array $line): string
    {
        return hash('sha256', json_encode([
            'product_id' => (int) ($line['product_id'] ?? 0),
            'qty' => (int) ($line['qty'] ?? 0),
            'unit_price' => round((float) ($line['unit_price'] ?? 0), 2),
            'discount_type' => (string) ($line['line_discount_type'] ?? 'fixed'),
            'discount_value' => round((float) ($line['line_discount_value'] ?? 0), 2),
            'tax_id' => $line['tax_id'] ?? null,
            'tax_rate' => round((float) ($line['tax_rate'] ?? 0), 4),
            'price_source' => (string) ($line['price_source'] ?? 'BASE'),
            'conversion_id' => $line['conversion_id'] ?? null,
            'bundle_id' => $line['bundle_id'] ?? null,
            'bundle_price' => $line['bundle_price'] ?? null,
            'pricing_basis' => $line['pricing_basis'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array{type?: string, value?: float|int|string|null}  $billDiscount
     * @return array{
     *     lines: array<int, array<string, mixed>>,
     *     totals: array{
     *         subtotal: float,
     *         line_discount_total: float,
     *         bill_discount_total: float,
     *         discount_total: float,
     *         tax_total: float,
     *         grand_total: float
     *     }
     * }
     */
    public function calculate(array $lines, array $billDiscount, bool $isPkp, ?int $settingId = null): array
    {
        $settingIncrement = (float) ($settingId ? (\Modules\Setting\Entities\Setting::query()->whereKey($settingId)->value('row_total_rounding_increment') ?? 0.00) : 0.00);

        $normalizedLines = array_map(function (array $line) use ($settingIncrement): array {
            $lineId = (int) ($line['line_id'] ?? 0);
            $qty = max(0, (int) ($line['qty'] ?? 0));
            $unitPriceCents = $this->toMinor((float) ($line['unit_price'] ?? 0));
            $priceSource = (string) ($line['price_source'] ?? 'BASE');
            $discountType = $this->normalizeDiscountType((string) ($line['line_discount_type'] ?? 'fixed'));
            $discountValue = (float) ($line['line_discount_value'] ?? 0);

            // A stored fingerprint must still match the row's current pricing
            // inputs. A mutation that failed to dirty the row therefore falls
            // back to recalculation rather than serving a stale total.
            //
            // An ABSENT fingerprint is deliberately treated as matching, not as
            // a mismatch: drafts saved before this change carry none, and they
            // are trusted persisted state. Failing them safe would reprice every
            // historical draft on its first load after deploy, which is what the
            // loaded-document stability requirement forbids. Legacy rows keep
            // their stored total until an eligible interaction dirties them.
            $storedFingerprint = $line[self::LINE_PRICING_FINGERPRINT] ?? null;
            $fingerprintMatches = $storedFingerprint === null
                || hash_equals((string) $storedFingerprint, self::pricingFingerprint($line));

            $isCleanLoadedLine = ! empty($line[self::LINE_CLEAN_FLAG])
                && isset($line['line_total_minor'])
                && $fingerprintMatches
                && ! $this->hasCanonicalOverrideMetadata($line);

            if ($isCleanLoadedLine) {
                // A loaded draft row untouched by any pricing interaction. Its
                // stored total already reflects the increment in force when the
                // row was calculated, so recalculating here would silently
                // reprice an unedited draft after the business changed its
                // configuration. Bundle and owner fragments then allocate from
                // this same authoritative amount.
                $lineNetBeforeBillCents = (int) $line['line_total_minor'];

                if (isset($line['line_gross_minor'], $line['line_discount_minor'])) {
                    // Increment rounding is not reversible, so the gross and
                    // discount behind this net are consumed as persisted. Deriving
                    // them backwards from a rounded net would drift line_gross,
                    // percentage-derived discount amounts, receipt presentation,
                    // and posted discount values.
                    $lineGrossCents = (int) $line['line_gross_minor'];
                    $lineDiscountCents = (int) $line['line_discount_minor'];
                } else {
                    // Legacy rows persisted before the trio was stored.
                    $lineDiscountCents = $this->reverseLineDiscountCents($discountType, $discountValue, $lineNetBeforeBillCents);
                    $lineGrossCents = $lineNetBeforeBillCents + $lineDiscountCents;
                }
            } elseif ($this->hasCanonicalOverrideMetadata($line)) {
                // A row override already computed these through the canonical
                // arithmetic authority. Re-deriving them here is what previously
                // produced two disagreeing sets of numbers, so they are consumed
                // as persisted rather than reconstructed.
                $lineGrossCents = (int) $line['line_gross_minor'];
                $lineDiscountCents = (int) $line['line_discount_minor'];
                $lineNetBeforeBillCents = (int) $line['line_net_minor'];
            } elseif ($priceSource === 'LINE_TOTAL_OVERRIDE' && isset($line['line_total_minor'])) {
                // Explicit minor-unit contract takes precedence.
                $lineNetBeforeBillCents = (int) $line['line_total_minor'];
                $lineDiscountCents = $this->reverseLineDiscountCents($discountType, $discountValue, $lineNetBeforeBillCents);
                $lineGrossCents = $lineNetBeforeBillCents + $lineDiscountCents;
            } elseif ($priceSource === 'LINE_TOTAL_OVERRIDE' && isset($line['line_total'])) {
                // Legacy fallback: rows persisted before the explicit field
                // existed carry the net in the ambiguous `line_total`.
                $lineNetBeforeBillCents = (int) $line['line_total'];
                $lineDiscountCents = $this->reverseLineDiscountCents($discountType, $discountValue, $lineNetBeforeBillCents);
                $lineGrossCents = $lineNetBeforeBillCents + $lineDiscountCents;
            } elseif ($priceSource === 'PACKED' && isset($line['line_total'])) {
                $lineGrossCents = (int) $line['line_total'];
                $lineDiscountCents = $this->lineDiscountCents($discountType, $discountValue, $lineGrossCents);
                $rawNetCents = max(0, $lineGrossCents - $lineDiscountCents);

                if ($settingIncrement > 0) {
                    $rawNet = $this->fromMinor($rawNetCents);
                    $roundedNet = \App\Support\RowTotalRoundingCalculator::round($rawNet, $settingIncrement);
                    $lineNetBeforeBillCents = $this->toMinor($roundedNet);
                } else {
                    $lineNetBeforeBillCents = $rawNetCents;
                }
            } else {
                $lineGrossCents = $qty * $unitPriceCents;
                $lineDiscountCents = $this->lineDiscountCents($discountType, $discountValue, $lineGrossCents);
                $rawNetCents = max(0, $lineGrossCents - $lineDiscountCents);

                // Round automatic row total when setting increment > 0
                if ($settingIncrement > 0) {
                    $rawNet = $this->fromMinor($rawNetCents);
                    $roundedNet = \App\Support\RowTotalRoundingCalculator::round($rawNet, $settingIncrement);
                    $lineNetBeforeBillCents = $this->toMinor($roundedNet);
                } else {
                    $lineNetBeforeBillCents = $rawNetCents;
                }
            }

            return array_merge($line, [
                'line_id' => $lineId,
                'qty' => $qty,
                'unit_price' => $this->fromMinor($unitPriceCents),
                'line_discount_type' => $discountType,
                'line_discount_value' => $discountValue,
                '_line_gross_minor' => $lineGrossCents,
                '_line_discount_minor' => $lineDiscountCents,
                '_line_net_before_bill_minor' => $lineNetBeforeBillCents,
            ]);
        }, $lines);

        usort($normalizedLines, function (array $left, array $right): int {
            return (int) $left['line_id'] <=> (int) $right['line_id'];
        });

        $billDiscountType = $this->normalizeDiscountType((string) ($billDiscount['type'] ?? 'fixed'));
        $billDiscountValue = (float) ($billDiscount['value'] ?? 0);
        $billDiscountBaseCents = array_sum(array_map(
            fn (array $line): int => (int) $line['_line_net_before_bill_minor'],
            $normalizedLines
        ));

        $billDiscountCents = $this->billDiscountCents(
            $billDiscountType,
            $billDiscountValue,
            $billDiscountBaseCents
        );

        $allocatedBillDiscounts = $this->prorateBillDiscount($normalizedLines, $billDiscountCents, $billDiscountBaseCents);

        $lineDiscountTotalCents = 0;
        $billDiscountTotalCents = 0;
        $subtotalCents = 0;
        $taxTotalCents = 0;
        $resultLines = [];

        foreach ($normalizedLines as $line) {
            $lineId = (int) $line['line_id'];
            $lineGrossCents = (int) $line['_line_gross_minor'];
            $lineDiscountCents = (int) $line['_line_discount_minor'];
            $lineNetBeforeBillCents = (int) $line['_line_net_before_bill_minor'];
            $billShareCents = (int) ($allocatedBillDiscounts[$lineId] ?? 0);
            $lineSubtotalCents = max(0, $lineNetBeforeBillCents - $billShareCents);
            $lineTaxCents = $this->lineTaxCents(
                $lineSubtotalCents,
                $line['tax_id'] ?? null,
                (float) ($line['tax_rate'] ?? 0),
                $isPkp
            );
            $lineTotalCents = $lineSubtotalCents;

            $lineDiscountTotalCents += $lineDiscountCents;
            $billDiscountTotalCents += $billShareCents;
            $subtotalCents += $lineSubtotalCents;
            $taxTotalCents += $lineTaxCents;

            $resultLines[] = array_merge($line, [
                'line_gross' => $this->fromMinor($lineGrossCents),
                'line_discount_amount' => $this->fromMinor($lineDiscountCents),
                'line_net_before_bill' => $this->fromMinor($lineNetBeforeBillCents),
                'bill_discount_amount' => $this->fromMinor($billShareCents),
                'line_subtotal' => $this->fromMinor($lineSubtotalCents),
                'line_tax_total' => $this->fromMinor($lineTaxCents),
                'line_total' => $this->fromMinor($lineTotalCents),
                // The row's authoritative pre-bill-discount net in minor units.
                // Persisted with the draft so a later reload consumes it rather
                // than recalculating under a since-changed rounding increment.
                'line_authoritative_net_minor' => $lineNetBeforeBillCents,
            ]);
        }

        $discountTotalCents = $lineDiscountTotalCents + $billDiscountTotalCents;
        $grandTotalCents = $subtotalCents;

        $resultLines = array_map(function (array $line): array {
            unset(
                $line['_line_gross_minor'],
                $line['_line_discount_minor'],
                $line['_line_net_before_bill_minor']
            );

            // Add serial_status field
            $isSerialRequired = (bool) ($line['serial_number_required'] ?? false);
            $assignedCount = count((array) ($line['assigned_serials'] ?? []));
            $qty = (int) ($line['qty'] ?? 0);
            $parentSerialOk = ! $isSerialRequired || ($assignedCount === $qty);

            $componentsSerialOk = true;
            if (! empty($line['bundle_items']) && is_array($line['bundle_items'])) {
                foreach ($line['bundle_items'] as $bItem) {
                    $cSerialRequired = (bool) ($bItem['serial_number_required'] ?? false);
                    if ($cSerialRequired) {
                        $bItemId = (int) ($bItem['bundle_item_id'] ?? 0);
                        $cQtyPerBundle = (float) ($bItem['quantity_per_bundle'] ?? ($bItem['quantity'] ?? 1));
                        $cRequiredQty = (int) round($qty * $cQtyPerBundle);
                        $cAssignedCount = count((array) ($line['bundle_item_serials'][$bItemId] ?? ($bItem['assigned_serials'] ?? [])));
                        if ($cAssignedCount < $cRequiredQty) {
                            $componentsSerialOk = false;
                            break;
                        }
                    }
                }
            }

            $serialStatus = ($parentSerialOk && $componentsSerialOk) ? 'ok' : 'incomplete';

            return array_merge($line, [
                'serial_status' => $serialStatus,
            ]);
        }, $resultLines);

        return [
            'lines' => $resultLines,
            'totals' => [
                'subtotal' => $this->fromMinor($subtotalCents),
                'line_discount_total' => $this->fromMinor($lineDiscountTotalCents),
                'bill_discount_total' => $this->fromMinor($billDiscountTotalCents),
                'discount_total' => $this->fromMinor($discountTotalCents),
                'tax_total' => $this->fromMinor($taxTotalCents),
                'grand_total' => $this->fromMinor($grandTotalCents),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, int>
     */
    private function prorateBillDiscount(array $lines, int $billDiscountCents, int $baseCents): array
    {
        $allocations = [];

        if ($billDiscountCents <= 0 || $baseCents <= 0 || $lines === []) {
            foreach ($lines as $line) {
                $allocations[(int) $line['line_id']] = 0;
            }

            return $allocations;
        }

        $fractionalRows = [];
        $allocated = 0;

        foreach ($lines as $line) {
            $lineId = (int) $line['line_id'];
            $lineBase = max(0, (int) $line['_line_net_before_bill_minor']);
            $numerator = $lineBase * $billDiscountCents;
            $floorShare = intdiv($numerator, $baseCents);
            $remainder = $numerator % $baseCents;

            $allocations[$lineId] = $floorShare;
            $allocated += $floorShare;

            $fractionalRows[] = [
                'line_id' => $lineId,
                'remainder' => $remainder,
            ];
        }

        $remaining = max(0, $billDiscountCents - $allocated);

        usort($fractionalRows, function (array $left, array $right): int {
            if ((int) $left['remainder'] === (int) $right['remainder']) {
                return (int) $left['line_id'] <=> (int) $right['line_id'];
            }

            return (int) $right['remainder'] <=> (int) $left['remainder'];
        });

        for ($index = 0; $index < $remaining; $index++) {
            $row = $fractionalRows[$index % count($fractionalRows)];
            $allocations[(int) $row['line_id']]++;
        }

        return $allocations;
    }

    /**
     * Whether a line carries the canonical derived metadata written by
     * PosRowOverrideArithmetic.
     *
     * @param  array<string, mixed>  $line
     */
    private function hasCanonicalOverrideMetadata(array $line): bool
    {
        $source = (string) ($line['price_source'] ?? 'BASE');

        if ($source !== 'LINE_TOTAL_OVERRIDE' && $source !== 'LINE_UNIT_PRICE_OVERRIDE') {
            return false;
        }

        return isset($line['line_gross_minor'], $line['line_discount_minor'], $line['line_net_minor']);
    }

    private function normalizeDiscountType(string $type): string
    {
        return strtolower($type) === 'percentage' ? 'percentage' : 'fixed';
    }

    private function reverseLineDiscountCents(string $type, float $value, int $lineNetCents): int
    {
        if ($lineNetCents <= 0) {
            return 0;
        }

        if ($type === 'percentage') {
            $percentage = max(0.0, min(100.0, $value));
            if ($percentage >= 100.0) {
                return 0;
            }
            $grossMinor = (int) round(($lineNetCents * 100.0) / (100.0 - $percentage), 0, PHP_ROUND_HALF_UP);

            return max(0, $grossMinor - $lineNetCents);
        }

        $fixedCents = max(0, $this->toMinor($value));

        return $fixedCents;
    }

    private function lineDiscountCents(string $type, float $value, int $lineGrossCents): int
    {
        if ($lineGrossCents <= 0) {
            return 0;
        }

        if ($type === 'percentage') {
            $percentage = max(0.0, min(100.0, $value));

            return min(
                $lineGrossCents,
                (int) round(($lineGrossCents * $percentage) / 100, 0, PHP_ROUND_HALF_UP)
            );
        }

        $fixedCents = max(0, $this->toMinor($value));

        return min($lineGrossCents, $fixedCents);
    }

    private function billDiscountCents(string $type, float $value, int $baseCents): int
    {
        if ($baseCents <= 0) {
            return 0;
        }

        if ($type === 'percentage') {
            $percentage = max(0.0, min(100.0, $value));

            return min(
                $baseCents,
                (int) round(($baseCents * $percentage) / 100, 0, PHP_ROUND_HALF_UP)
            );
        }

        return min($baseCents, max(0, $this->toMinor($value)));
    }

    private function lineTaxCents(int $lineSubtotalCents, mixed $taxId, float $taxRate, bool $isPkp): int
    {
        if (! $isPkp || $lineSubtotalCents <= 0) {
            return 0;
        }

        $taxIdValue = (int) ($taxId ?? 0);
        $rateBasisPoints = (int) round(max(0.0, $taxRate) * 100, 0, PHP_ROUND_HALF_UP);

        if ($taxIdValue <= 0 || $rateBasisPoints <= 0) {
            return 0;
        }

        // POS prices are treated as gross; extract tax with whole-rupiah rounding.
        $grossAmount = $lineSubtotalCents / 100;
        $taxAmount = (int) round(
            ($grossAmount * $rateBasisPoints) / (10000 + $rateBasisPoints),
            0,
            PHP_ROUND_HALF_UP
        );

        return $taxAmount * 100;
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
