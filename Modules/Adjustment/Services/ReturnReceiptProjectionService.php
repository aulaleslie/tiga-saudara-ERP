<?php

namespace Modules\Adjustment\Services;

use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferMovement;
use Modules\Adjustment\Entities\TransferMovementLine;
use Modules\Adjustment\Entities\TransferMovementSerial;
use Modules\Product\Entities\ProductStock;

class ReturnReceiptProjectionService
{
    public function __construct(
        private ReturnReceiptComparatorService $comparator,
    ) {
    }

    /**
     * Build preparation projection for a return receipt movement draft.
     * Note: This projection is UNIVERSALLY BLIND. Regardless of canViewSystemStock,
     * it contains only document metadata, transfer condition, and the operator's entered observations.
     * It NEVER exposes dispatch manifest, expected products/quantities/serials, allocation, custody, reservations, or stock.
     */
    public function getPreparationProjection(Transfer $transfer, TransferMovement $movement, bool $canViewSystemStock = false): array
    {
        $transfer->loadMissing(['originLocation', 'destinationLocation']);
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

            $lines[] = [
                'line_id'         => (int) $line->id,
                'product_id'      => (int) $line->product_id,
                'product_name'    => (string) ($product?->product_name ?? ''),
                'product_code'    => (string) ($product?->product_code ?? ''),
                'is_serialized'   => $isSerialized,
                'quantity'        => (string) $line->quantity,
                'count_confirmed' => (bool) $line->count_confirmed,
                'serials'         => $serials,
            ];
        }

        return [
            'transfer_id'                => (int) $transfer->id,
            'movement_id'                => (int) $movement->id,
            'revision'                   => (int) $movement->revision,
            'lock_version'               => (int) $movement->lock_version,
            'status'                     => (string) $movement->status,
            'stock_condition'            => (string) $movement->stock_condition,
            'origin_location_name'       => (string) ($transfer->destinationLocation?->name ?? ''),
            'destination_location_name'  => (string) ($transfer->originLocation?->name ?? ''),
            'return_batch_id'            => (string) $movement->return_batch_id,
            'empty_count_confirmed'      => (bool) $movement->empty_count_confirmed,
            'empty_count_confirmed_at'   => $movement->empty_count_confirmed_at?->toIso8601String(),
            'lines'                      => $lines,
        ];
    }

    /**
     * Build approval review projection for a pending return receipt movement according to permissions.
     */
    public function getApprovalProjection(Transfer $transfer, TransferMovement $movement, bool $canViewSystemStock): array
    {
        $comparison = $this->comparator->compare($movement);

        if (!$canViewSystemStock) {
            return [
                'movement_id'     => (int) $movement->id,
                'revision'        => (int) $movement->revision,
                'status'          => (string) $movement->status,
                'stock_condition' => (string) $movement->stock_condition,
                'matches'         => (bool) $comparison['matches'],
                'message'         => $comparison['matches']
                    ? 'Hasil penerimaan fisik retur sesuai dengan pengiriman retur.'
                    : 'Terdapat ketidaksesuaian antara penerimaan fisik dan pengiriman retur.',
            ];
        }

        $sourceMovement = $movement->sourceMovement;
        $sourceLines = $sourceMovement ? $sourceMovement->lines()->with('serials')->get() : collect();

        // Include destination (which is origin location for return leg) stock and dispatched bucket provenance for privileged approvers
        $details = $comparison['details'];
        $productIds = array_keys($details);

        $originStocks = ProductStock::whereIn('product_id', $productIds)
            ->where('location_id', $movement->destination_location_id)
            ->get()
            ->keyBy('product_id');

        foreach ($details as $prodId => &$detailRow) {
            $originStock = $originStocks->get($prodId);
            $sourceLine = $sourceLines->firstWhere('product_id', $prodId);

            $detailRow['origin_stock'] = [
                'quantity'         => (int) ($originStock->quantity ?? 0),
                'quantity_tax'     => (int) ($originStock->quantity_tax ?? 0),
                'quantity_non_tax' => (int) ($originStock->quantity_non_tax ?? 0),
                'broken_tax'       => (int) ($originStock->broken_quantity_tax ?? 0),
                'broken_non_tax'   => (int) ($originStock->broken_quantity_non_tax ?? 0),
            ];

            $detailRow['dispatched_provenance'] = [
                'applied_quantity_non_tax'        => (string) ($sourceLine->applied_quantity_non_tax ?? '0.0000'),
                'applied_quantity_tax'            => (string) ($sourceLine->applied_quantity_tax ?? '0.0000'),
                'applied_quantity_broken_non_tax' => (string) ($sourceLine->applied_quantity_broken_non_tax ?? '0.0000'),
                'applied_quantity_broken_tax'     => (string) ($sourceLine->applied_quantity_broken_tax ?? '0.0000'),
            ];
        }
        unset($detailRow);

        return [
            'movement_id'     => (int) $movement->id,
            'revision'        => (int) $movement->revision,
            'status'          => (string) $movement->status,
            'stock_condition' => (string) $movement->stock_condition,
            'matches'         => (bool) $comparison['matches'],
            'summary'         => $comparison['summary'],
            'details'         => $details,
        ];
    }
}
