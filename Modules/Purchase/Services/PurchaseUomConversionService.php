<?php

namespace Modules\Purchase\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Purchase\Services\DTOs\PurchaseUomConversionResult;
use Modules\Setting\Entities\Unit;

class PurchaseUomConversionService
{
    /**
     * Convert and validate entered Purchase line quantity and price against authoritative product/conversion data.
     * Uses Brick\Math\BigDecimal for exact fixed-scale decimal arithmetic.
     *
     * @throws InvalidArgumentException
     */
    public function convert(
        Product $product,
        float|string $enteredQuantity,
        float|string|null $enteredUnitPrice = null,
        ?int $conversionId = null,
        ?int $unitId = null,
        array $snapshotData = []
    ): PurchaseUomConversionResult {
        // Ensure conversions & units are accessible
        $product->loadMissing(['unit', 'baseUnit', 'conversions.unit', 'conversions.baseUnit']);

        $baseUnitId = (int) ($product->base_unit_id ?? $product->unit_id);
        $baseUnitName = $product->baseUnit?->name ?? $product->unit?->name ?? 'UNIT';

        $matchedConversion = null;
        $selectedUnitId = null;
        $selectedUnitName = $baseUnitName;
        $factorBd = BigDecimal::of('1');

        $hasValidSnapshotFactor = isset($snapshotData['conversion_factor']) && is_numeric($snapshotData['conversion_factor']) && (float) $snapshotData['conversion_factor'] > 0;
        $isUnchangedHistorical = (bool) ($snapshotData['is_unchanged_historical'] ?? false);

        if ($conversionId !== null) {
            /** @var ProductUnitConversion|null $matchedConversion */
            $matchedConversion = $product->conversions->firstWhere('id', $conversionId);

            if (!$matchedConversion || (int) $matchedConversion->product_id !== (int) $product->id) {
                if ($hasValidSnapshotFactor && $isUnchangedHistorical) {
                    $factorBd = BigDecimal::of((string) $snapshotData['conversion_factor']);
                    $selectedUnitId = $unitId ?? (int) ($snapshotData['purchase_unit_id'] ?? $baseUnitId);
                    $selectedUnitName = (string) ($snapshotData['unit_name'] ?? Unit::find($selectedUnitId)?->name ?? 'UNIT');
                    $baseUnitName = (string) ($snapshotData['base_unit_name'] ?? $baseUnitName);
                } else {
                    throw new InvalidArgumentException("Conversion #{$conversionId} does not belong to product #{$product->id}.");
                }
            } else {
                $convBaseUnitId = (int) ($matchedConversion->base_unit_id ?? $baseUnitId);
                if ($convBaseUnitId !== $baseUnitId) {
                    throw new InvalidArgumentException("Conversion #{$conversionId} base unit does not match product base unit.");
                }

                if ($matchedConversion->unit && !$matchedConversion->unit->is_active && ! $isUnchangedHistorical) {
                    throw new InvalidArgumentException("Conversion unit #{$matchedConversion->unit_id} is inactive.");
                }

                if ($isUnchangedHistorical && $hasValidSnapshotFactor) {
                    $factorBd = BigDecimal::of((string) $snapshotData['conversion_factor']);
                } else {
                    $factorBd = BigDecimal::of((string) $matchedConversion->conversion_factor);
                }

                if ($factorBd->compareTo(BigDecimal::of('1')) <= 0) {
                    throw new InvalidArgumentException("Conversion factor must be greater than 1 for new purchase activity.");
                }

                $selectedUnitId = (int) $matchedConversion->unit_id;
                if ($unitId !== null && (int) $unitId !== $selectedUnitId) {
                    throw new InvalidArgumentException("Unit ID #{$unitId} does not match conversion #{$conversionId} unit ID #{$selectedUnitId}.");
                }

                $selectedUnitName = (string) ($snapshotData['unit_name'] ?? $matchedConversion->unit?->name ?? Unit::find($selectedUnitId)?->name ?? 'UNIT');
            }
        } elseif ($unitId !== null && (int) $unitId !== $baseUnitId) {
            /** @var ProductUnitConversion|null $matchedConversion */
            $matchedConversion = $product->conversions->firstWhere('unit_id', $unitId);

            if (!$matchedConversion) {
                if ($hasValidSnapshotFactor && $isUnchangedHistorical) {
                    $factorBd = BigDecimal::of((string) $snapshotData['conversion_factor']);
                    $selectedUnitId = (int) $unitId;
                    $selectedUnitName = (string) ($snapshotData['unit_name'] ?? Unit::find($selectedUnitId)?->name ?? 'UNIT');
                    $baseUnitName = (string) ($snapshotData['base_unit_name'] ?? $baseUnitName);
                } else {
                    throw new InvalidArgumentException("Unit #{$unitId} does not belong to product #{$product->id} or its conversions.");
                }
            } else {
                $convBaseUnitId = (int) ($matchedConversion->base_unit_id ?? $baseUnitId);
                if ($convBaseUnitId !== $baseUnitId) {
                    throw new InvalidArgumentException("Conversion base unit does not match product base unit.");
                }

                if ($matchedConversion->unit && !$matchedConversion->unit->is_active && ! $isUnchangedHistorical) {
                    throw new InvalidArgumentException("Conversion unit #{$unitId} is inactive.");
                }

                if ($isUnchangedHistorical && $hasValidSnapshotFactor) {
                    $factorBd = BigDecimal::of((string) $snapshotData['conversion_factor']);
                } else {
                    $factorBd = BigDecimal::of((string) $matchedConversion->conversion_factor);
                }

                if ($factorBd->compareTo(BigDecimal::of('1')) <= 0) {
                    throw new InvalidArgumentException("Conversion factor must be greater than 1 for new purchase activity.");
                }

                $selectedUnitId = (int) $matchedConversion->unit_id;
                $selectedUnitName = (string) ($snapshotData['unit_name'] ?? $matchedConversion->unit?->name ?? Unit::find($selectedUnitId)?->name ?? 'UNIT');
            }
        } else {
            $selectedUnitId = $baseUnitId;
            $selectedUnitName = $baseUnitName;
            $factorBd = BigDecimal::of('1');
        }

        $isSerialized = (bool) ($product->serial_number_required ?? false);

        if ($isSerialized && $matchedConversion !== null) {
            if ($factorBd->stripTrailingZeros()->getScale() > 0) {
                throw new InvalidArgumentException("Serialized products require integer conversion factors.");
            }
        }

        try {
            $qtyBd = BigDecimal::of((string) $enteredQuantity);
        } catch (\Exception $e) {
            throw new InvalidArgumentException("Invalid quantity format.");
        }

        if ($qtyBd->compareTo(BigDecimal::of('0')) <= 0) {
            throw new InvalidArgumentException("Entered quantity must be greater than zero.");
        }

        // Validate entered quantity scale (max 3 decimal places)
        if ($qtyBd->stripTrailingZeros()->getScale() > 3) {
            throw new InvalidArgumentException("Entered quantity cannot exceed 3 decimal places.");
        }

        $canonicalQtyBd = $qtyBd->multipliedBy($factorBd);

        // Validate canonical quantity scale (max 3 decimal places)
        if ($canonicalQtyBd->stripTrailingZeros()->getScale() > 3) {
            throw new InvalidArgumentException("Entered quantity yields unsupported canonical quantity precision.");
        }

        if ($isSerialized) {
            if ($canonicalQtyBd->stripTrailingZeros()->getScale() > 0) {
                throw new InvalidArgumentException("Serialized product quantities must resolve to whole base units.");
            }
        }

        $enteredPriceFloat = $enteredUnitPrice !== null ? (float) $enteredUnitPrice : null;
        $normalizedPriceFloat = null;

        if ($enteredPriceFloat !== null) {
            $storedEnteredPrice = isset($snapshotData['entered_unit_price']) ? (float) $snapshotData['entered_unit_price'] : null;
            if ($isUnchangedHistorical && isset($snapshotData['unit_price']) && $storedEnteredPrice !== null && abs($storedEnteredPrice - $enteredPriceFloat) < 0.0001) {
                $normalizedPriceFloat = (float) $snapshotData['unit_price'];
            } else {
                $enteredPriceBd = BigDecimal::of((string) $enteredPriceFloat);
                // High-precision division (up to 6 decimal places, HALF_UP)
                $normalizedPriceBd = $enteredPriceBd->dividedBy($factorBd, 6, RoundingMode::HALF_UP);
                $normalizedPriceFloat = $normalizedPriceBd->toFloat();
            }
        }

        return new PurchaseUomConversionResult(
            purchaseUnitId: $selectedUnitId,
            productUnitConversionId: $matchedConversion?->id,
            enteredQuantity: $qtyBd->toFloat(),
            canonicalQuantity: $canonicalQtyBd->toFloat(),
            enteredUnitPrice: $enteredPriceFloat,
            normalizedUnitPrice: $normalizedPriceFloat,
            conversionFactor: $factorBd->toFloat(),
            unitName: $selectedUnitName,
            baseUnitName: $baseUnitName
        );
    }
}
