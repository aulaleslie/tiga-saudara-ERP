<?php

namespace Modules\Pos\Services;

use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Exceptions\PosReturnManualCorrectionRequiredException;

class PosReturnLifecycleService
{
    /**
     * Approve a POS return.
     *
     * @param int $posReturnId
     * @param string|null $returnOption
     * @return void
     */
    public function approve(int $posReturnId, ?string $returnOption = null): void
    {
        $this->runLifecycleMutation($posReturnId, 'approve', function () use ($posReturnId, $returnOption) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId, $returnOption) {
                $posReturn = \Modules\Pos\Entities\PosReturn::query()
                    ->whereKey($posReturnId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertManualCorrectionIsNotRequired($posReturn);

                if ($posReturn->status !== \Modules\Pos\Entities\PosReturn::STATUS_PENDING_APPROVAL) {
                    throw new \Exception("Only pending approval returns can be approved.");
                }

                $actorId = \Illuminate\Support\Facades\Auth::id();
                $approvedAt = now();

                $updateData = [
                    'status' => \Modules\Pos\Entities\PosReturn::STATUS_APPROVED,
                    'approval_status' => \Modules\Pos\Entities\PosReturn::APPROVAL_STATUS_APPROVED,
                    'approved_by' => $actorId,
                    'approved_at' => $approvedAt,
                ];

                if ($returnOption) {
                    if (!in_array($returnOption, [\Modules\Pos\Entities\PosReturn::OPTION_CASH_RETURN, \Modules\Pos\Entities\PosReturn::OPTION_PRODUCT_REPLACEMENT])) {
                        throw new \Exception("Invalid return option: {$returnOption}");
                    }
                    $updateData['return_option'] = $returnOption;
                }

                $posReturn->update($updateData);

                $this->syncApprovedSaleReturns($posReturn, $actorId, $approvedAt);
            });
        });
    }

    protected function syncApprovedSaleReturns(\Modules\Pos\Entities\PosReturn $posReturn, ?int $actorId, \Illuminate\Support\Carbon $approvedAt): void
    {
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
    }

    /**
     * Receive a POS return.
     *
     * @param int $posReturnId
     * @return void
     */
    public function receive(int $posReturnId): void
    {
        $this->runLifecycleMutation($posReturnId, 'receive', function () use ($posReturnId) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId) {
            $posReturn = \Modules\Pos\Entities\PosReturn::with(['saleReturns.saleReturnDetails', 'lines'])->findOrFail($posReturnId);

            $this->assertManualCorrectionIsNotRequired($posReturn);

            if ($posReturn->status !== \Modules\Pos\Entities\PosReturn::STATUS_APPROVED) {
                throw new \Exception("Only approved returns can be received.");
            }

            $this->receiveLinkedSaleReturns($posReturn);
            $this->applyReceivedDispatchQuantityAdjustments($posReturn);

            $nextStatus = $posReturn->return_option === \Modules\Pos\Entities\PosReturn::OPTION_CASH_RETURN
                ? \Modules\Pos\Entities\PosReturn::STATUS_AWAITING_SETTLEMENT
                : \Modules\Pos\Entities\PosReturn::STATUS_AWAITING_DISPATCH;

            $posReturn->update([
                'status' => $nextStatus,
                'received_by' => \Illuminate\Support\Facades\Auth::id(),
                'received_at' => now(),
            ]);
            });
        });
    }

    protected function receiveLinkedSaleReturns(\Modules\Pos\Entities\PosReturn $posReturn): void
    {
        foreach ($posReturn->saleReturns as $saleReturn) {
            $this->processSaleReturnReceiving($saleReturn);
        }
    }

    protected function applyReceivedDispatchQuantityAdjustments(\Modules\Pos\Entities\PosReturn $posReturn): void
    {
        foreach ($posReturn->lines as $line) {
            if (! $line->dispatch_detail_id) {
                continue;
            }

            $dispatchDetail = \Modules\Sale\Entities\DispatchDetail::find($line->dispatch_detail_id);
            if (! $dispatchDetail) {
                continue;
            }

            $dispatchDetail->decrement('dispatched_quantity', $line->quantity);
        }
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
        $this->runLifecycleMutation($posReturnId, 'settle_payment_return', function () use ($posReturnId) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId) {
            $posReturn = \Modules\Pos\Entities\PosReturn::query()
                ->with(['saleReturns.saleReturnPayments'])
                ->whereKey($posReturnId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertManualCorrectionIsNotRequired($posReturn);

            if ($posReturn->return_option !== \Modules\Pos\Entities\PosReturn::OPTION_CASH_RETURN) {
                throw new \Exception('Hanya retur dengan opsi kembali uang yang dapat diproses sebagai pengembalian tunai.');
            }

            if ($posReturn->status !== \Modules\Pos\Entities\PosReturn::STATUS_AWAITING_SETTLEMENT) {
                throw new \Exception("Hanya retur yang menunggu penyelesaian pembayaran yang dapat diselesaikan.");
            }

            $actorId = \Illuminate\Support\Facades\Auth::id();
            $settledAt = now();
            $remainingPosReturnAmount = (float) $posReturn->total_amount;
            $processedAmount = 0.0;

            foreach ($posReturn->saleReturns as $saleReturn) {
                $remainingRefundable = $this->remainingRefundableAmount($saleReturn);
                if ($remainingRefundable <= 0) {
                    continue;
                }

                $settlementAmount = min($remainingRefundable, $remainingPosReturnAmount);
                if ($settlementAmount <= 0) {
                    break;
                }

                $this->settleLinkedCashReturn($saleReturn, $settlementAmount, $settledAt, $actorId);

                $processedAmount += $settlementAmount;
                $remainingPosReturnAmount = max(0, $remainingPosReturnAmount - $settlementAmount);
            }

            if ($processedAmount <= 0) {
                throw new \RuntimeException('Tidak ada sisa nominal pengembalian tunai yang dapat diproses.');
            }

            $posReturn->update([
                'status' => \Modules\Pos\Entities\PosReturn::STATUS_COMPLETED,
                'settled_at' => $settledAt,
                'settled_by' => $actorId,
            ]);
            });
        });
    }

    protected function settleLinkedCashReturn(
        \Modules\SalesReturn\Entities\SaleReturn $saleReturn,
        float $settlementAmount,
        \Illuminate\Support\Carbon $settledAt,
        ?int $actorId
    ): void {
        \Modules\SalesReturn\Entities\SaleReturnPayment::create([
            'sale_return_id' => $saleReturn->id,
            'amount' => $settlementAmount,
            'date' => $settledAt->toDateString(),
            'reference' => 'SRPAY/' . $saleReturn->reference,
            'payment_method' => 'CASH',
            'note' => 'Penyelesaian Retur POS',
        ]);

        $newPaidAmount = (float) $saleReturn->paid_amount + $settlementAmount;
        $newDueAmount = max(0, (float) $saleReturn->due_amount - $settlementAmount);

        $saleReturn->update([
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'paid_amount' => $newPaidAmount,
            'due_amount' => $newDueAmount,
            'settled_at' => $settledAt,
            'settled_by' => $actorId,
        ]);

        app(\App\Support\SalesReturn\SaleReturnLifecycleSyncService::class)
            ->archiveSourceSaleIfFullyReturnedAndCompleted($saleReturn, $actorId);
    }

    /**
     * Settle a POS return (Product Replacement).
     *
     * @param int $posReturnId
     * @return void
     */
    public function dispatchReplacement(int $posReturnId): void
    {
        $this->runLifecycleMutation($posReturnId, 'dispatch_replacement', function () use ($posReturnId) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId) {
            $posReturn = \Modules\Pos\Entities\PosReturn::query()
                ->with(['saleReturns.saleReturnDetails.posReturnLine'])
                ->whereKey($posReturnId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertManualCorrectionIsNotRequired($posReturn);

            if ($posReturn->return_option !== \Modules\Pos\Entities\PosReturn::OPTION_PRODUCT_REPLACEMENT) {
                throw new \Exception('Hanya retur dengan opsi ganti produk yang dapat diproses sebagai pengiriman pengganti.');
            }

            if ($posReturn->status !== \Modules\Pos\Entities\PosReturn::STATUS_AWAITING_DISPATCH) {
                throw new \Exception("Hanya retur yang menunggu pengiriman pengganti yang dapat diselesaikan.");
            }

            $actorId = \Illuminate\Support\Facades\Auth::id();
            $settledAt = now();

            foreach ($posReturn->saleReturns as $saleReturn) {
                $this->dispatchReplacementForSaleReturn($saleReturn, $actorId, $settledAt);
            }

            $posReturn->update([
                'status' => \Modules\Pos\Entities\PosReturn::STATUS_COMPLETED,
                'settled_at' => $settledAt,
                'settled_by' => $actorId,
            ]);
            });
        });
    }

    protected function dispatchReplacementForSaleReturn(
        \Modules\SalesReturn\Entities\SaleReturn $saleReturn,
        ?int $actorId,
        \Illuminate\Support\Carbon $settledAt
    ): void {
        $dispatch = \Modules\Sale\Entities\Dispatch::create([
            'sale_id' => $saleReturn->sale_id,
            'dispatch_date' => $settledAt,
            'status' => \Modules\Sale\Entities\Dispatch::STATUS_APPROVED,
            'approved_by' => $actorId,
            'approved_at' => $settledAt,
        ]);

        foreach ($saleReturn->saleReturnDetails as $detail) {
            $this->assertReplacementDispatchEligibility($detail);

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

    /**
     * Adjust stock for replacement items.
     */
    protected function adjustStockForReplacement(\Modules\SalesReturn\Entities\SaleReturnDetail $detail, int $actorId): void
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

        if ((int) $productStock->quantity < (int) $qty) {
            throw new \RuntimeException('Stok produk pengganti tidak mencukupi di lokasi sumber asli retur.');
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

    private function remainingRefundableAmount(\Modules\SalesReturn\Entities\SaleReturn $saleReturn): float
    {
        $totalAmount = (float) $saleReturn->total_amount;
        $paidAmount = (float) $saleReturn->paid_amount;
        $dueAmount = $saleReturn->due_amount !== null
            ? (float) $saleReturn->due_amount
            : max(0, $totalAmount - $paidAmount);

        return max(0, min($dueAmount, $totalAmount - $paidAmount));
    }

    private function assertReplacementDispatchEligibility(\Modules\SalesReturn\Entities\SaleReturnDetail $detail): void
    {
        $line = $detail->posReturnLine;

        if (! $line) {
            throw new \RuntimeException('Detail retur POS tidak memiliki referensi baris sumber untuk pengiriman pengganti.');
        }

        if ((int) $line->replacement_product_id !== (int) $detail->product_id) {
            throw new \RuntimeException('Produk pengganti harus menggunakan SKU yang sama dengan barang yang diretur.');
        }

        if ((float) $line->replacement_quantity !== (float) $detail->quantity) {
            throw new \RuntimeException('Jumlah barang pengganti harus sama dengan jumlah barang retur yang sudah diterima.');
        }

        if ((int) $line->source_setting_id !== (int) $detail->saleReturn->setting_id) {
            throw new \RuntimeException('Pengiriman pengganti harus berasal dari owner atau setting sumber asli retur.');
        }

        if ((int) $line->source_location_id !== (int) $detail->location_id) {
            throw new \RuntimeException('Pengiriman pengganti harus berasal dari lokasi sumber asli retur.');
        }
    }

    public function archive(int $posReturnId, ?string $reason = null): void
    {
        $this->runLifecycleMutation($posReturnId, 'archive', function () use ($posReturnId, $reason) {
            $this->transitionToAuditedReversalState($posReturnId, \Modules\Pos\Entities\PosReturn::STATUS_ARCHIVED, $reason);
        });
    }

    public function cancel(int $posReturnId, ?string $reason = null): void
    {
        $this->runLifecycleMutation($posReturnId, 'cancel', function () use ($posReturnId, $reason) {
            $this->transitionToAuditedReversalState($posReturnId, \Modules\Pos\Entities\PosReturn::STATUS_CANCELLED, $reason);
        });
    }

    private function transitionToAuditedReversalState(int $posReturnId, string $targetStatus, ?string $reason = null): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId, $targetStatus, $reason) {
            $posReturn = \Modules\Pos\Entities\PosReturn::query()
                ->with('saleReturns')
                ->whereKey($posReturnId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertManualCorrectionIsNotRequired($posReturn);

            $this->assertCanBeReversedBeforeReceiving($posReturn, $targetStatus);

            $actorId = \Illuminate\Support\Facades\Auth::id();
            $timestamp = now();

            $attributes = [
                'status' => $targetStatus,
                'is_reversed' => true,
                'updated_by' => $actorId,
            ];

            if ($targetStatus === \Modules\Pos\Entities\PosReturn::STATUS_ARCHIVED) {
                $attributes['archived_by'] = $actorId;
                $attributes['archived_at'] = $timestamp;
                $attributes['archive_reason'] = $reason;
            } else {
                $attributes['cancelled_by'] = $actorId;
                $attributes['cancelled_at'] = $timestamp;
                $attributes['cancel_reason'] = $reason;
            }

            $posReturn->update($attributes);

            foreach ($posReturn->saleReturns as $saleReturn) {
                $this->applyAuditedReversalToLinkedSaleReturn($saleReturn, $targetStatus, $actorId, $timestamp, $reason);
            }
        });
    }

    protected function applyAuditedReversalToLinkedSaleReturn(
        \Modules\SalesReturn\Entities\SaleReturn $saleReturn,
        string $targetStatus,
        ?int $actorId,
        \Illuminate\Support\Carbon $timestamp,
        ?string $reason = null
    ): void {
        if ($targetStatus === \Modules\Pos\Entities\PosReturn::STATUS_ARCHIVED) {
            $saleReturn->forceFill([
                'archived_by' => $actorId,
                'archived_at' => $timestamp,
            ])->save();

            return;
        }

        $saleReturn->update([
            'status' => 'Cancelled',
            'rejected_by' => $actorId,
            'rejected_at' => $timestamp,
            'rejection_reason' => $reason,
        ]);
    }

    private function assertCanBeReversedBeforeReceiving(\Modules\Pos\Entities\PosReturn $posReturn, string $targetStatus): void
    {
        $isBlocked = $posReturn->received_at !== null
            || in_array($posReturn->status, [
                \Modules\Pos\Entities\PosReturn::STATUS_AWAITING_SETTLEMENT,
                \Modules\Pos\Entities\PosReturn::STATUS_AWAITING_DISPATCH,
                \Modules\Pos\Entities\PosReturn::STATUS_COMPLETED,
            ], true);

        if ($isBlocked) {
            if ($targetStatus === \Modules\Pos\Entities\PosReturn::STATUS_ARCHIVED) {
                throw new \RuntimeException('Retur POS yang sudah diterima, diselesaikan, atau dikirim tidak dapat diarsipkan.');
            }

            throw new \RuntimeException('Retur POS yang sudah diterima, diselesaikan, atau dikirim tidak dapat dibatalkan.');
        }

        if (! in_array($posReturn->status, [
            \Modules\Pos\Entities\PosReturn::STATUS_APPROVED,
            \Modules\Pos\Entities\PosReturn::STATUS_AWAITING_RECEIVING,
        ], true)) {
            throw new \RuntimeException('Hanya retur POS yang sudah disetujui dan belum diterima yang dapat dibalik secara audit.');
        }
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
        $this->runLifecycleMutation($posReturnId, 'reject', function () use ($posReturnId, $reason) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId, $reason) {
            $posReturn = \Modules\Pos\Entities\PosReturn::query()
                ->whereKey($posReturnId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertManualCorrectionIsNotRequired($posReturn);

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

            $this->syncRejectedSaleReturns($posReturn, $actorId, $rejectedAt, $reason);
            });
        });
    }

    protected function runLifecycleMutation(int $posReturnId, string $lifecycleAction, callable $callback): void
    {
        try {
            $callback();
        } catch (PosReturnManualCorrectionRequiredException $exception) {
            $this->markManualCorrectionRequired($posReturnId, $exception->lifecycleAction(), $exception->auditReason());

            throw new \RuntimeException(
                'Retur POS memerlukan koreksi manual teraudit sebelum proses berikutnya dapat dijalankan. ' . $exception->auditReason(),
                0,
                $exception
            );
        }
    }

    protected function markManualCorrectionRequired(int $posReturnId, string $lifecycleAction, string $reason): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($posReturnId, $lifecycleAction, $reason) {
            $posReturn = PosReturn::query()
                ->whereKey($posReturnId)
                ->lockForUpdate()
                ->firstOrFail();

            $posReturn->forceFill([
                'status' => PosReturn::STATUS_MANUAL_CORRECTION_REQUIRED,
                'manual_correction_action' => $lifecycleAction,
                'manual_correction_reason' => $reason,
                'manual_correction_required_by' => \Illuminate\Support\Facades\Auth::id(),
                'manual_correction_required_at' => now(),
                'updated_by' => \Illuminate\Support\Facades\Auth::id(),
            ])->save();
        });
    }

    protected function assertManualCorrectionIsNotRequired(PosReturn $posReturn): void
    {
        if (! $posReturn->requiresManualCorrection()) {
            return;
        }

        throw new \RuntimeException('Retur POS ini sedang diblokir dan memerlukan koreksi manual teraudit sebelum aksi lifecycle lain dijalankan.');
    }

    protected function syncRejectedSaleReturns(
        \Modules\Pos\Entities\PosReturn $posReturn,
        ?int $actorId,
        \Illuminate\Support\Carbon $rejectedAt,
        ?string $reason = null
    ): void {
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
    }
}
