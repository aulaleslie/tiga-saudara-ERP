<?php

namespace Modules\Adjustment\Services;

use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Entities\TransferProduct;

class ForwardDispatchComparatorService
{
    /**
     * Compare submitted forward dispatch movement against the approved transfer request.
     *
     * @param Transfer $transfer
     * @param TransferMovement $movement
     * @return array{
     *     matches: bool,
     *     summary: array{
     *         total_expected_products: int,
     *         total_counted_products: int,
     *         mismatched_products_count: int,
     *         unexpected_products_count: int,
     *         missing_products_count: int,
     *     },
     *     details: array<int, array{
     *         product_id: int,
     *         product_name: string,
     *         product_code: string,
     *         is_serialized: bool,
     *         expected_quantity: string,
     *         counted_quantity: string,
     *         difference_quantity: string,
     *         status: string, // MATCH, SHORTAGE, EXCESS, UNEXPECTED, MISSING, SERIAL_MISMATCH
     *         expected_serials: array<string>,
     *         counted_serials: array<string>,
     *         missing_serials: array<string>,
     *         unexpected_serials: array<string>,
     *     }>
     * }
     */
    public function compare(Transfer $transfer, TransferMovement $movement): array
    {
        $transfer->loadMissing(['products.product']);
        $movement->loadMissing(['lines.product', 'lines.serials']);

        $expectedProducts = [];
        foreach ($transfer->products as $tp) {
            $prodId = (int) $tp->product_id;
            $rawQty = (string) $tp->quantity;
            $qtyStr = bcadd($rawQty, '0', 4);

            $serials = [];
            if (!empty($tp->serial_numbers) && is_array($tp->serial_numbers)) {
                foreach ($tp->serial_numbers as $s) {
                    $sNum = is_array($s) ? ($s['serial_number'] ?? '') : (string) $s;
                    $normalized = TransferMovementSerial::normalize($sNum);
                    if ($normalized !== '') {
                        $serials[] = $normalized;
                    }
                }
            }
            sort($serials);

            $expectedProducts[$prodId] = [
                'product'           => $tp->product,
                'expected_quantity' => $qtyStr,
                'expected_serials'  => $serials,
            ];
        }

        $countedProducts = [];
        foreach ($movement->lines as $line) {
            $prodId = (int) $line->product_id;
            $rawQty = (string) $line->quantity;
            $qtyStr = bcadd($rawQty, '0', 4);

            $serials = [];
            foreach ($line->serials as $s) {
                $normalized = TransferMovementSerial::normalize((string) $s->serial_number);
                if ($normalized !== '') {
                    $serials[] = $normalized;
                }
            }
            sort($serials);

            $countedProducts[$prodId] = [
                'product'          => $line->product,
                'counted_quantity' => $qtyStr,
                'counted_serials'  => $serials,
                'count_confirmed'  => (bool) $line->count_confirmed,
            ];
        }

        $allProductIds = array_unique(array_merge(array_keys($expectedProducts), array_keys($countedProducts)));
        sort($allProductIds);

        $matches = true;
        $details = [];
        $mismatchedCount = 0;
        $unexpectedCount = 0;
        $missingCount = 0;

        foreach ($allProductIds as $prodId) {
            $expected = $expectedProducts[$prodId] ?? null;
            $counted = $countedProducts[$prodId] ?? null;

            $product = $expected['product'] ?? $counted['product'] ?? null;
            $productName = $product ? (string) $product->product_name : "Product #{$prodId}";
            $productCode = $product ? (string) ($product->product_code ?? '') : '';
            $isSerialized = $product ? (bool) ($product->serial_number_required ?? false) : false;

            $expectedQty = $expected ? $expected['expected_quantity'] : '0.0000';
            $countedQty = $counted ? $counted['counted_quantity'] : '0.0000';
            $diffQty = bcsub($countedQty, $expectedQty, 4);

            $expectedSerials = $expected ? $expected['expected_serials'] : [];
            $countedSerials = $counted ? $counted['counted_serials'] : [];

            $missingSerials = array_values(array_diff($expectedSerials, $countedSerials));
            $unexpectedSerials = array_values(array_diff($countedSerials, $expectedSerials));

            $status = 'MATCH';

            if (!$expected) {
                $status = 'UNEXPECTED';
                $unexpectedCount++;
                $matches = false;
            } elseif (!$counted || (bccomp($countedQty, '0.0000', 4) === 0 && !($counted['count_confirmed'] ?? false))) {
                $status = 'MISSING';
                $missingCount++;
                $matches = false;
            } else {
                $comp = bccomp($countedQty, $expectedQty, 4);
                if ($comp < 0) {
                    $status = 'SHORTAGE';
                    $mismatchedCount++;
                    $matches = false;
                } elseif ($comp > 0) {
                    $status = 'EXCESS';
                    $mismatchedCount++;
                    $matches = false;
                } elseif ($isSerialized) {
                    $expCount = (int) round((float) $expectedQty);
                    $cntCount = (int) round((float) $countedQty);
                    if (!empty($missingSerials) || !empty($unexpectedSerials) || count($expectedSerials) !== $expCount || count($countedSerials) !== $cntCount) {
                        $status = 'SERIAL_MISMATCH';
                        $mismatchedCount++;
                        $matches = false;
                    }
                }
            }

            $details[$prodId] = [
                'product_id'          => $prodId,
                'product_name'        => $productName,
                'product_code'        => $productCode,
                'is_serialized'       => $isSerialized,
                'expected_quantity'   => $expectedQty,
                'counted_quantity'    => $countedQty,
                'difference_quantity' => $diffQty,
                'status'              => $status,
                'expected_serials'    => $expectedSerials,
                'counted_serials'     => $countedSerials,
                'missing_serials'     => $missingSerials,
                'unexpected_serials'  => $unexpectedSerials,
            ];
        }

        return [
            'matches' => $matches,
            'summary' => [
                'total_expected_products'   => count($expectedProducts),
                'total_counted_products'    => count($countedProducts),
                'mismatched_products_count' => $mismatchedCount,
                'unexpected_products_count' => $unexpectedCount,
                'missing_products_count'    => $missingCount,
            ],
            'details' => $details,
        ];
    }
}
