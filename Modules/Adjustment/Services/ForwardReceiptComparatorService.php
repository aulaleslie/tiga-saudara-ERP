<?php

namespace Modules\Adjustment\Services;

use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use RuntimeException;

class ForwardReceiptComparatorService
{
    /**
     * Require the exact approved route-policy snapshot for the transfer's
     * current revision at the time the movement was created, rejecting a
     * missing, stale, or inconsistent policy before any mutation occurs.
     */
    public function requirePolicy(Transfer $transfer, TransferMovement $receiptMovement): TransferRoutePolicy
    {
        $policy = TransferRoutePolicy::where('transfer_id', $transfer->id)
            ->where('transfer_revision', $receiptMovement->transfer_revision)
            ->lockForUpdate()
            ->first();

        if (!$policy) {
            throw new RuntimeException("No approved route-policy snapshot exists for transfer revision {$receiptMovement->transfer_revision}.");
        }

        if ((int) $policy->origin_location_id !== (int) $receiptMovement->origin_location_id ||
            (int) $policy->destination_location_id !== (int) $receiptMovement->destination_location_id ||
            $policy->stock_condition !== $receiptMovement->stock_condition) {
            throw new RuntimeException("Route-policy snapshot does not match the receipt movement's route identity.");
        }

        return $policy;
    }

    /**
     * Compare submitted forward receipt movement against the approved source forward dispatch movement.
     *
     * @param TransferMovement $receiptMovement (Type: FORWARD_RECEIPT)
     * @param TransferMovement|null $sourceDispatchMovement (Type: FORWARD_DISPATCH, Status: APPROVED)
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
    public function compare(TransferMovement $receiptMovement, ?TransferMovement $sourceDispatchMovement = null): array
    {
        if ($sourceDispatchMovement === null) {
            $receiptMovement->loadMissing('sourceMovement.lines.product', 'sourceMovement.lines.serials');
            $sourceDispatchMovement = $receiptMovement->sourceMovement;
        }

        if (!$sourceDispatchMovement) {
            throw new RuntimeException("Source forward dispatch movement is missing.");
        }

        if ($sourceDispatchMovement->type !== TransferMovement::TYPE_FORWARD_DISPATCH || $sourceDispatchMovement->status !== TransferMovement::STATUS_APPROVED) {
            throw new RuntimeException("Source movement must be an APPROVED FORWARD_DISPATCH.");
        }

        $sourceDispatchMovement->loadMissing(['lines.product', 'lines.serials']);
        $receiptMovement->loadMissing(['lines.product', 'lines.serials']);

        $expectedProducts = [];
        foreach ($sourceDispatchMovement->lines as $line) {
            $prodId = (int) $line->product_id;
            $rawQty = (string) $line->quantity;
            $qtyStr = bcadd($rawQty, '0', 4);

            $serials = [];
            $serialIdsByText = [];
            foreach ($line->serials as $s) {
                $normalized = TransferMovementSerial::normalize((string) $s->serial_number);
                if ($normalized !== '') {
                    $serials[] = $normalized;
                    if ($s->product_serial_number_id) {
                        $serialIdsByText[$normalized] = (int) $s->product_serial_number_id;
                    }
                }
            }
            sort($serials);

            $expectedProducts[$prodId] = [
                'product'             => $line->product,
                'expected_quantity'   => $qtyStr,
                'expected_serials'    => $serials,
                'expected_serial_ids' => $serialIdsByText,
            ];
        }

        $countedProducts = [];
        foreach ($receiptMovement->lines as $line) {
            $prodId = (int) $line->product_id;
            $rawQty = (string) $line->quantity;
            $qtyStr = bcadd($rawQty, '0', 4);

            $serials = [];
            $serialIdsByText = [];
            foreach ($line->serials as $s) {
                $normalized = TransferMovementSerial::normalize((string) $s->serial_number);
                if ($normalized !== '') {
                    $serials[] = $normalized;
                    if ($s->product_serial_number_id) {
                        $serialIdsByText[$normalized] = (int) $s->product_serial_number_id;
                    }
                }
            }
            sort($serials);

            $countedProducts[$prodId] = [
                'product'            => $line->product,
                'counted_quantity'   => $qtyStr,
                'counted_serials'    => $serials,
                'counted_serial_ids' => $serialIdsByText,
                'count_confirmed'    => (bool) $line->count_confirmed,
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
            $expectedSerialIds = $expected ? ($expected['expected_serial_ids'] ?? []) : [];
            $countedSerialIds = $counted ? ($counted['counted_serial_ids'] ?? []) : [];

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
                    
                    $hasIdMismatch = false;
                    foreach ($countedSerials as $sn) {
                        $cId = $countedSerialIds[$sn] ?? null;
                        $eId = $expectedSerialIds[$sn] ?? null;
                        if ($cId === null || $eId === null || $cId !== $eId) {
                            $hasIdMismatch = true;
                            break;
                        }
                    }

                    if (!empty($missingSerials) || !empty($unexpectedSerials) || count($expectedSerials) !== $expCount || count($countedSerials) !== $cntCount || $hasIdMismatch) {
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

        // Special case: If document has 0 expected products and 0 counted products, but has empty_count_confirmed
        if (empty($expectedProducts) && empty($countedProducts)) {
            $matches = true;
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
