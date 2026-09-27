<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;

class ReturnedSerialNumberResolver
{
    /**
     * Resolve returned serials for a given purchase context and map them to received note details.
     *
     * @param int $purchaseId
     * @param Collection $receivedDetailIds
     * @return Collection Collection of ProductSerialNumber (unique)
     */
    public function resolveForPurchase(int $purchaseId, Collection $receivedDetailIds): Collection
    {
        $receivedSerialIds = collect();
        if ($receivedDetailIds->isNotEmpty()) {
            $receivedSerialIds = DB::table('received_note_detail_serial_numbers')
                ->whereIn('received_note_detail_id', $receivedDetailIds)
                ->pluck('product_serial_number_id')
                ->unique();
        }

        if ($receivedSerialIds->isEmpty() || $purchaseId <= 0) {
            return collect();
        }

        // 1) History-based returned serial detection (backward compatible)
        $returnedSerialsByHistory = ProductSerialNumber::query()
            ->with([
                'histories.reference' => function ($morphTo) {
                    $morphTo->morphWith([\Modules\Purchase\Entities\ReceivedNoteDetail::class => ['purchaseDetail']]);
                }, 
                'receivedNoteDetails.purchaseDetail', 
                'receivedNoteDetail.purchaseDetail'
            ])
            ->whereIn('id', $receivedSerialIds)
            ->whereIn('id', function ($query) use ($purchaseId) {
                $query->select('product_serial_number_id')
                    ->from('serial_number_histories')
                    ->where('event_type', SerialNumberHistory::EVENT_PURCHASE_RETURNED)
                    ->where(function ($q) use ($purchaseId) {
                        $q->where(function ($q1) use ($purchaseId) {
                            $q1->where('reference_type', PurchaseReturn::class)
                                ->whereIn('reference_id', function ($sub) use ($purchaseId) {
                                    $sub->select('purchase_return_id')
                                        ->from('purchase_return_details')
                                        ->where('po_id', $purchaseId);
                                });
                        })
                        ->orWhere(function ($q2) use ($purchaseId) {
                            $q2->where('reference_type', PurchaseReturnItemSettlement::class)
                                ->whereIn('reference_id', function ($sub) use ($purchaseId) {
                                    $sub->select('id')
                                        ->from('purchase_return_item_settlements')
                                        ->whereIn('purchase_return_detail_id', function ($sub2) use ($purchaseId) {
                                            $sub2->select('id')
                                                ->from('purchase_return_details')
                                                ->where('po_id', $purchaseId);
                                        });
                                });
                        });
                    });
            })
            ->get();

        // 2) Fallback detection for records without PURCHASE_RETURNED history:
        $fallbackSerialIds = PurchaseReturnItemSettlement::query()
            ->where('target_purchase_id', $purchaseId)
            ->whereRaw('UPPER(method) = ?', ['MODIFY_PURCHASE'])
            ->whereRaw('UPPER(status) = ?', [PurchaseReturnItemSettlement::STATUS_APPROVED])
            ->whereNotNull('product_serial_number_id')
            ->pluck('product_serial_number_id')
            ->unique()
            ->values();

        $returnedSerialsByState = collect();
        if ($fallbackSerialIds->isNotEmpty()) {
            $returnedSerialsByState = ProductSerialNumber::query()
                ->with([
                    'histories.reference' => function ($morphTo) {
                        $morphTo->morphWith([\Modules\Purchase\Entities\ReceivedNoteDetail::class => ['purchaseDetail']]);
                    }, 
                    'receivedNoteDetails.purchaseDetail', 
                    'receivedNoteDetail.purchaseDetail'
                ])
                ->whereIn('id', $fallbackSerialIds)
                ->whereIn('id', $receivedSerialIds)
                ->get();
        }

        return $returnedSerialsByHistory
            ->concat($returnedSerialsByState)
            ->unique('id')
            ->reject(function ($serial) use ($purchaseId) {
                 return strtoupper($serial->status) === ProductSerialNumber::STATUS_ACTIVE
                     && $serial->resolveCurrentPurchaseId() === $purchaseId;
            })
            ->values();
    }

    /**
     * Map unified resolved serials to each detail row.
     */
    public function mapToDetails(Collection $receivedNoteDetails, Collection $returnedSerials): void
    {
        if ($returnedSerials->isEmpty()) {
            foreach ($receivedNoteDetails as $detail) {
                $detail->returnedSerialNumbers = collect([]);
            }
            return;
        }

        $detailIds = $receivedNoteDetails->pluck('id');

        $histories = SerialNumberHistory::query()
            ->whereIn('product_serial_number_id', $returnedSerials->pluck('id'))
            ->where('event_type', SerialNumberHistory::EVENT_RECEIVED)
            ->where('reference_type', \Modules\Purchase\Entities\ReceivedNoteDetail::class)
            ->get()
            ->groupBy('reference_id');

        $pivotLinks = DB::table('received_note_detail_serial_numbers')
            ->whereIn('received_note_detail_id', $detailIds)
            ->whereIn('product_serial_number_id', $returnedSerials->pluck('id'))
            ->get()
            ->groupBy('received_note_detail_id');

        foreach ($receivedNoteDetails as $detail) {
            $returnedFromHistory = $histories->get($detail->id)
                ? $returnedSerials->whereIn('id', $histories->get($detail->id)->pluck('product_serial_number_id'))
                : collect([]);

            $returnedFromPivot = $pivotLinks->get($detail->id)
                ? $returnedSerials->whereIn('id', $pivotLinks->get($detail->id)->pluck('product_serial_number_id'))
                : collect([]);

            $detail->returnedSerialNumbers = $returnedFromHistory
                ->concat($returnedFromPivot)
                ->unique('id')
                ->values();
        }
    }
}
