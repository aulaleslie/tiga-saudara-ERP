<?php

namespace Modules\Adjustment\Services;

use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Adjustment\Services\TransferStockVisibility;

class ForwardDispatchProjectionService
{
    public function __construct(
        private ForwardDispatchComparatorService $comparator,
    ) {
    }

    /**
     * Build preparation projection for a movement draft according to permissions.
     *
     * @param Transfer $transfer
     * @param TransferMovement $movement
     * @param bool $canViewSystemStock
     * @return array
     */
    public function getPreparationProjection(Transfer $transfer, TransferMovement $movement, bool $canViewSystemStock): array
    {
        $transfer->loadMissing(['products.product', 'originLocation', 'destinationLocation']);
        $movement->loadMissing(['lines.product', 'lines.serials']);

        $lines = [];
        foreach ($movement->lines as $line) {
            $product = $line->product;
            $isSerialized = (bool) ($product?->serial_number_required ?? false);

            $serials = [];
            foreach ($line->serials as $ser) {
                $serials[] = [
                    'id'            => (int) $ser->product_serial_number_id,
                    'serial_number' => (string) $ser->serial_number,
                ];
            }

            $lineData = [
                'line_id'         => (int) $line->id,
                'product_id'      => (int) $line->product_id,
                'product_name'    => (string) ($product?->product_name ?? ''),
                'product_code'    => (string) ($product?->product_code ?? ''),
                'is_serialized'   => $isSerialized,
                'quantity'        => (string) $line->quantity,
                'count_confirmed' => (bool) $line->count_confirmed,
                'serials'         => $serials,
            ];

            // If privileged, add requested and stock info
            if ($canViewSystemStock) {
                $matchingTransferProduct = $transfer->products->firstWhere('product_id', $line->product_id);
                $lineData['requested_quantity'] = $matchingTransferProduct ? (string) $matchingTransferProduct->quantity : '0.0000';
            }

            $lines[] = $lineData;
        }

        $projection = [
            'transfer_id'             => (int) $transfer->id,
            'movement_id'             => (int) $movement->id,
            'revision'                => (int) $movement->revision,
            'lock_version'            => (int) $movement->lock_version,
            'status'                  => (string) $movement->status,
            'stock_condition'         => (string) $movement->stock_condition,
            'origin_location_name'    => (string) ($transfer->originLocation?->name ?? ''),
            'destination_location_name' => (string) ($transfer->destinationLocation?->name ?? ''),
            'lines'                   => $lines,
        ];

        return $projection;
    }

    /**
     * Build approval projection for a pending forward dispatch movement according to permissions.
     *
     * @param Transfer $transfer
     * @param TransferMovement $movement
     * @param bool $canViewSystemStock
     * @return array
     */
    public function getApprovalProjection(Transfer $transfer, TransferMovement $movement, bool $canViewSystemStock): array
    {
        $comparison = $this->comparator->compare($transfer, $movement);

        if (!$canViewSystemStock) {
            return [
                'movement_id'     => (int) $movement->id,
                'revision'        => (int) $movement->revision,
                'status'          => (string) $movement->status,
                'stock_condition' => (string) $movement->stock_condition,
                'matches'         => (bool) $comparison['matches'],
                'message'         => $comparison['matches']
                    ? 'Hasil perhitungan fisik sesuai dengan permintaan transfer.'
                    : 'Terdapat ketidaksesuaian antara perhitungan fisik dan permintaan transfer.',
            ];
        }

        return [
            'movement_id'     => (int) $movement->id,
            'revision'        => (int) $movement->revision,
            'status'          => (string) $movement->status,
            'stock_condition' => (string) $movement->stock_condition,
            'matches'         => (bool) $comparison['matches'],
            'summary'         => $comparison['summary'],
            'details'         => $comparison['details'],
        ];
    }
}
