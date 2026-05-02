<?php

namespace Modules\Pos\Services;

class PosReturnLifecycleService
{
    /**
     * Approve a POS return.
     *
     * @param int $posReturnId
     * @return void
     */
    public function approve(int $posReturnId): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId) {
            $posReturn = \Modules\Pos\Entities\PosReturn::query()
                ->whereKey($posReturnId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($posReturn->status !== \Modules\Pos\Entities\PosReturn::STATUS_PENDING_APPROVAL) {
                throw new \Exception("Only pending approval returns can be approved.");
            }

            $actorId = \Illuminate\Support\Facades\Auth::id();
            $approvedAt = now();

            $posReturn->update([
                'status' => \Modules\Pos\Entities\PosReturn::STATUS_APPROVED,
                'approval_status' => \Modules\Pos\Entities\PosReturn::APPROVAL_STATUS_APPROVED,
                'approved_by' => $actorId,
                'approved_at' => $approvedAt,
            ]);

            // Sync linked Sales Returns
            foreach ($posReturn->saleReturns as $saleReturn) {
                $saleReturn->update([
                    'status' => 'AWAITING RECEIVING',
                    'approval_status' => 'APPROVED',
                    'approved_by' => $actorId,
                    'approved_at' => $approvedAt,
                    'rejected_by' => null,
                    'rejected_at' => null,
                    'rejection_reason' => null,
                ]);
            }
        });
    }

    /**
     * Receive a POS return.
     *
     * @param int $posReturnId
     * @return void
     */
    public function receive(int $posReturnId): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId) {
            $posReturn = \Modules\Pos\Entities\PosReturn::with(['saleReturns.saleReturnDetails', 'lines'])->findOrFail($posReturnId);

            if ($posReturn->status !== \Modules\Pos\Entities\PosReturn::STATUS_APPROVED) {
                throw new \Exception("Only approved returns can be received.");
            }

            foreach ($posReturn->saleReturns as $saleReturn) {
                $this->processSaleReturnReceiving($saleReturn);
            }

            // FR-008: Authoritative Reduction of dispatch quantity
            foreach ($posReturn->lines as $line) {
                if ($line->dispatch_detail_id) {
                    $dispatchDetail = \Modules\Sale\Entities\DispatchDetail::find($line->dispatch_detail_id);
                    if ($dispatchDetail) {
                        $dispatchDetail->decrement('dispatched_quantity', $line->quantity);
                    }
                }
            }

            $nextStatus = $posReturn->return_option === \Modules\Pos\Entities\PosReturn::OPTION_CASH_RETURN
                ? \Modules\Pos\Entities\PosReturn::STATUS_AWAITING_SETTLEMENT
                : \Modules\Pos\Entities\PosReturn::STATUS_AWAITING_DISPATCH;

            $posReturn->update([
                'status' => $nextStatus,
                'received_by' => \Illuminate\Support\Facades\Auth::id(),
                'received_at' => now(),
            ]);
        });
    }

    /**
     * Process receiving for a single SaleReturn.
     *
     * @param SaleReturn $saleReturn
     * @return void
     */
    protected function processSaleReturnReceiving(\Modules\SalesReturn\Entities\SaleReturn $saleReturn): void
    {
        $receivedAt = now();
        $actorId = \Illuminate\Support\Facades\Auth::id();

        $details = \Modules\SalesReturn\Entities\SaleReturnDetail::with('dispatchDetail')
            ->where('sale_return_id', $saleReturn->id)
            ->get();

        foreach ($details as $detail) {
            $quantity = (int) ($detail->quantity ?? 0);
            if ($quantity <= 0) {
                continue;
            }


            // Skip inventory mutation if stockless
            if ($detail->stock_behavior === \Modules\Pos\Entities\PosReturnLine::STOCK_BEHAVIOR_STOCKLESS) {
                continue;
            }

            $dispatchDetail = $detail->dispatchDetail;
            if (!$dispatchDetail) {
                continue;
            }

            $locationId = $detail->location_id
                ?? $saleReturn->location_id
                ?? $dispatchDetail->location_id;

            if (!$locationId) {
                throw new \RuntimeException('Lokasi penerimaan retur tidak dapat ditentukan.');
            }

            $product = \Modules\Product\Entities\Product::findOrFail($detail->product_id);

            $productStock = \Modules\Product\Entities\ProductStock::where('product_id', $detail->product_id)
                ->where('location_id', $locationId)
                ->first();

            if (!$productStock) {
                $productStock = \Modules\Product\Entities\ProductStock::create([
                    'product_id' => $detail->product_id,
                    'location_id' => $locationId,
                    'quantity' => 0,
                    'quantity_tax' => 0,
                    'quantity_non_tax' => 0,
                    'broken_quantity_non_tax' => 0,
                    'broken_quantity_tax' => 0,
                    'broken_quantity' => 0,
                    'tax_id' => $dispatchDetail->tax_id,
                ]);
            }

            $previousProductQuantity = (int) ($product->product_quantity ?? 0);
            $previousQuantityAtLocation = (int) ($productStock->quantity ?? 0);
            $taxId = $dispatchDetail->tax_id;

            if ($taxId) {
                $productStock->quantity_tax = (int) ($productStock->quantity_tax ?? 0) + $quantity;
            } else {
                $productStock->quantity_non_tax = (int) ($productStock->quantity_non_tax ?? 0) + $quantity;
            }

            $productStock->quantity = (int) ($productStock->quantity_non_tax ?? 0)
                + (int) ($productStock->quantity_tax ?? 0)
                + (int) ($productStock->broken_quantity_non_tax ?? 0)
                + (int) ($productStock->broken_quantity_tax ?? 0);
            
            $productStock->save();

            $product->product_quantity = (int) $product->product_quantity + $quantity;
            $product->save();

            \Illuminate\Support\Facades\DB::table('transactions')->insert([
                'product_id' => $product->id,
                'setting_id' => (int) ($saleReturn->setting_id ?? \Illuminate\Support\Facades\Auth::user()->setting_id),
                'type' => $taxId ? 'SALE_RETURN_GOOD_TAX' : 'SALE_RETURN_GOOD_NON_TAX',
                'quantity' => $quantity,
                'current_quantity' => (int) $product->product_quantity,
                'location_id' => $locationId,
                'user_id' => $actorId,
                'reason' => 'PENERIMAAN RETUR PENJUALAN (POS): ' . $saleReturn->reference,
                'previous_quantity' => $previousProductQuantity,
                'after_quantity' => (int) $product->product_quantity,
                'previous_quantity_at_location' => $previousQuantityAtLocation,
                'after_quantity_at_location' => (int) ($productStock->quantity ?? 0),
                'quantity_tax' => $taxId ? $quantity : 0,
                'quantity_non_tax' => $taxId ? 0 : $quantity,
                'broken_quantity' => (int) ($productStock->broken_quantity ?? 0),
                'broken_quantity_tax' => (int) ($productStock->broken_quantity_tax ?? 0),
                'broken_quantity_non_tax' => (int) ($productStock->broken_quantity_non_tax ?? 0),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $serialIds = $detail->serial_number_ids ?? [];
            if (!empty($serialIds)) {
                \Modules\Product\Entities\ProductSerialNumber::whereIn('id', $serialIds)
                    ->update([
                        'dispatch_detail_id' => null,
                        'location_id' => $locationId,
                        'status' => \Modules\Product\Entities\ProductSerialNumber::STATUS_ACTIVE,
                    ]);

                \Modules\Sale\Entities\SalesOrderSerialTracking::where('sale_id', $saleReturn->sale_id)
                    ->whereIn('product_serial_number_id', $serialIds)
                    ->update([
                        'return_date' => $receivedAt,
                    ]);

                foreach ($serialIds as $serialId) {
                    \App\Services\SerialNumberHistoryService::record(
                        $serialId,
                        \Modules\Product\Entities\SerialNumberHistory::EVENT_SALE_RETURNED,
                        $locationId,
                        $detail
                    );
                }
            }
        }

        $saleReturn->update([
            'status' => 'Awaiting Settlement',
            'received_by' => $actorId,
            'received_at' => $receivedAt,
        ]);

        app(\App\Support\SalesReturn\SaleReturnLifecycleSyncService::class)
            ->syncSourceSaleReturnStatusFromReceivedReturns($saleReturn);
    }

    /**
     * Settle a POS return (Cash Return).
     *
     * @param int $posReturnId
     * @return void
     */
    public function settlePaymentReturn(int $posReturnId): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId) {
            $posReturn = \Modules\Pos\Entities\PosReturn::with(['saleReturns'])->findOrFail($posReturnId);

            if ($posReturn->return_option !== \Modules\Pos\Entities\PosReturn::OPTION_CASH_RETURN) {
                throw new \Exception('Hanya retur dengan opsi kembali uang yang dapat diproses sebagai pengembalian tunai.');
            }

            if ($posReturn->status !== \Modules\Pos\Entities\PosReturn::STATUS_AWAITING_SETTLEMENT) {
                throw new \Exception("Hanya retur yang menunggu penyelesaian pembayaran yang dapat diselesaikan.");
            }

            $actorId = \Illuminate\Support\Facades\Auth::id();

            foreach ($posReturn->saleReturns as $saleReturn) {
                \Modules\SalesReturn\Entities\SaleReturnPayment::create([
                    'sale_return_id' => $saleReturn->id,
                    'amount' => $saleReturn->total_amount,
                    'date' => now()->toDateString(),
                    'reference' => 'SRPAY/' . $saleReturn->reference,
                    'payment_method' => 'CASH',
                    'note' => 'Penyelesaian Retur POS',
                ]);

                $saleReturn->update([
                    'status' => 'Completed',
                    'payment_status' => 'Paid',
                    'paid_amount' => $saleReturn->total_amount,
                    'due_amount' => 0,
                    'settled_at' => now(),
                    'settled_by' => $actorId,
                ]);

                app(\App\Support\SalesReturn\SaleReturnLifecycleSyncService::class)
                    ->archiveSourceSaleIfFullyReturnedAndCompleted($saleReturn, $actorId);
            }

            $posReturn->update([
                'status' => \Modules\Pos\Entities\PosReturn::STATUS_COMPLETED,
            ]);
        });
    }

    /**
     * Settle a POS return (Product Replacement).
     *
     * @param int $posReturnId
     * @return void
     */
    public function dispatchReplacement(int $posReturnId): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId) {
            $posReturn = \Modules\Pos\Entities\PosReturn::query()
                ->with(['saleReturns.saleReturnDetails'])
                ->whereKey($posReturnId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($posReturn->return_option !== \Modules\Pos\Entities\PosReturn::OPTION_PRODUCT_REPLACEMENT) {
                throw new \Exception('Hanya retur dengan opsi ganti produk yang dapat diproses sebagai pengiriman pengganti.');
            }

            if ($posReturn->status !== \Modules\Pos\Entities\PosReturn::STATUS_AWAITING_DISPATCH) {
                throw new \Exception("Hanya retur yang menunggu pengiriman pengganti yang dapat diselesaikan.");
            }

            $actorId = \Illuminate\Support\Facades\Auth::id();
            $settledAt = now();

            foreach ($posReturn->saleReturns as $saleReturn) {
                $dispatch = \Modules\Sale\Entities\Dispatch::create([
                    'sale_id' => $saleReturn->sale_id,
                    'dispatch_date' => $settledAt,
                    'status' => \Modules\Sale\Entities\Dispatch::STATUS_APPROVED,
                    'approved_by' => $actorId,
                    'approved_at' => $settledAt,
                ]);

                foreach ($saleReturn->saleReturnDetails as $detail) {
                    \Modules\Sale\Entities\DispatchDetail::create([
                        'dispatch_id' => $dispatch->id,
                        'sale_id' => $saleReturn->sale_id,
                        'product_id' => $detail->product_id,
                        'dispatched_quantity' => (int) $detail->quantity,
                        'location_id' => $detail->location_id,
                        'tax_id' => $detail->tax_id,
                    ]);

                    $this->adjustStockForReplacement($detail, $actorId);
                }

                $saleReturn->update([
                    'status' => 'COMPLETED',
                    'settled_at' => $settledAt,
                    'settled_by' => $actorId,
                ]);

                app(\App\Support\SalesReturn\SaleReturnLifecycleSyncService::class)
                    ->archiveSourceSaleIfFullyReturnedAndCompleted($saleReturn, $actorId);
            }

            $posReturn->update([
                'status' => \Modules\Pos\Entities\PosReturn::STATUS_COMPLETED,
                'settled_at' => $settledAt,
                'settled_by' => $actorId,
            ]);
        });
    }

    /**
     * Adjust stock for replacement items.
     */
    private function adjustStockForReplacement(\Modules\SalesReturn\Entities\SaleReturnDetail $detail, int $actorId): void
    {
        $product = \Modules\Product\Entities\Product::findOrFail($detail->product_id);
        $qty = $detail->quantity;
        $locationId = $detail->location_id;

        $productStock = \Modules\Product\Entities\ProductStock::where('product_id', $product->id)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (!$productStock) {
            throw new \Exception("Stok tidak ditemukan untuk produk {$product->product_name} di lokasi selected.");
        }

        $previousQuantity = (int) $product->product_quantity;
        $previousQuantityAtLocation = (int) $productStock->quantity;

        // Decrement stock
        $productStock->decrement('quantity', $qty);
        $product->decrement('product_quantity', $qty);

        $taxId = $productStock->tax_id ?? null;

        \Modules\Product\Entities\Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $product->setting_id,
            'quantity' => -$qty,
            'current_quantity' => (int) $product->product_quantity,
            'broken_quantity' => (int) ($productStock->broken_quantity ?? 0),
            'location_id' => $locationId,
            'user_id' => $actorId,
            'reason' => 'Dispatch replacement for Sale Return #' . $detail->sale_return_id,
            'type' => 'DISPATCH_RETURN',
            'previous_quantity' => $previousQuantity,
            'after_quantity' => (int) $product->product_quantity,
            'previous_quantity_at_location' => $previousQuantityAtLocation,
            'after_quantity_at_location' => (int) ($productStock->quantity ?? 0),
            'quantity_non_tax' => $taxId ? 0 : $qty,
            'quantity_tax' => $taxId ? $qty : 0,
            'broken_quantity_non_tax' => (int) ($productStock->broken_quantity_non_tax ?? 0),
            'broken_quantity_tax' => (int) ($productStock->broken_quantity_tax ?? 0),
        ]);
    }

    /**
     * Reject a POS return.
     *
     * @param int $posReturnId
     * @param string|null $reason
     * @return void
     */
    public function reject(int $posReturnId, ?string $reason = null): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId, $reason) {
            $posReturn = \Modules\Pos\Entities\PosReturn::query()
                ->whereKey($posReturnId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($posReturn->status !== \Modules\Pos\Entities\PosReturn::STATUS_PENDING_APPROVAL) {
                throw new \Exception("Only pending approval returns can be rejected.");
            }

            $actorId = \Illuminate\Support\Facades\Auth::id();
            $rejectedAt = now();

            $posReturn->update([
                'status' => \Modules\Pos\Entities\PosReturn::STATUS_REJECTED,
                'approval_status' => \Modules\Pos\Entities\PosReturn::APPROVAL_STATUS_REJECTED,
                'rejected_by' => $actorId,
                'rejected_at' => $rejectedAt,
                'rejection_reason' => $reason,
            ]);

            // Sync linked Sales Returns
            foreach ($posReturn->saleReturns as $saleReturn) {
                $saleReturn->update([
                    'status' => 'REJECTED',
                    'approval_status' => 'REJECTED',
                    'rejected_by' => $actorId,
                    'rejected_at' => $rejectedAt,
                    'rejection_reason' => $reason,
                    'approved_by' => null,
                    'approved_at' => null,
                ]);
            }
        });
    }
}
