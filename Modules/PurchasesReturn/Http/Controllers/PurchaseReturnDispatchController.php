<?php

namespace Modules\PurchasesReturn\Http\Controllers;

use App\Services\SerialNumberHistoryService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\PurchasesReturn\Entities\PurchaseReturn;

class PurchaseReturnDispatchController extends Controller
{
    public function requestDispatch(Request $request, PurchaseReturn $purchase_return)
    {
        abort_if(Gate::denies('purchaseReturns.dispatchRequest'), 403);

        $approvalStatus = Str::lower($purchase_return->approval_status ?? '');
        if ($approvalStatus !== 'approved') {
            toast('Dispatch hanya dapat diajukan setelah retur disetujui.', 'error');
            return back();
        }

        if ($purchase_return->return_dispatched_at) {
            toast('Retur sudah didispatch.', 'warning');
            return back();
        }

        $dispatchStatus = Str::lower($purchase_return->return_dispatch_status ?? '');
        if (in_array($dispatchStatus, ['pending_approval', 'approved', 'dispatched'], true)) {
            toast('Dispatch sudah diajukan atau diproses.', 'info');
            return back();
        }

        $rules = [
            'return_shipping_amount' => ['nullable', 'string'],
            'return_dispatch_note' => ['required', 'string', 'max:1000'],
            'return_awb_attachments' => ['required', 'array', 'min:1'],
        ];

        if ($request->hasFile('return_awb_attachments')) {
            $rules['return_awb_attachments.*'] = ['file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'];
        } elseif ($request->filled('return_awb_attachments')) {
            $rules['return_awb_attachments.*'] = ['string'];
        }

        $data = $request->validate($rules);

        $shippingAmount = $this->parseCurrency($data['return_shipping_amount'] ?? null);
        if ($shippingAmount === null) {
            $shippingAmount = 0;
        }
        if ($shippingAmount < 0) {
            return back()->withErrors(['return_shipping_amount' => 'Ongkir tidak valid.'])->withInput();
        }

        DB::transaction(function () use ($purchase_return, $data, $request, $shippingAmount) {
            $purchase_return->update([
                'return_dispatch_status' => 'pending_approval',
                'return_awb_number' => null,
                'return_shipping_amount' => round($shippingAmount, 2),
                'return_carrier' => null,
                'return_dispatch_note' => $data['return_dispatch_note'] ?? null,
                'dispatch_requested_by' => auth()->id(),
                'dispatch_requested_at' => now(),
                'dispatch_approved_by' => null,
                'dispatch_approved_at' => null,
                'dispatch_rejected_by' => null,
                'dispatch_rejected_at' => null,
                'dispatch_rejection_reason' => null,
            ]);

            $attachments = $data['return_awb_attachments'] ?? [];
            if ($request->hasFile('return_awb_attachments')) {
                foreach ($request->file('return_awb_attachments') as $file) {
                    $purchase_return->addMedia($file)->toMediaCollection('return_awb_attachments');
                }
            } else {
                foreach ($attachments as $file) {
                    $path = 'temp/dropzone/' . $file;
                    if (!Storage::exists($path)) {
                        continue;
                    }
                    $purchase_return->addMedia(Storage::path($path))
                        ->toMediaCollection('return_awb_attachments');
                }
            }
        });

        toast('Permintaan dispatch dikirim untuk persetujuan.', 'success');

        return back();
    }

    public function approveDispatch(PurchaseReturn $purchase_return)
    {
        abort_if(Gate::denies('purchaseReturns.dispatchApproval'), 403);

        $dispatchStatus = Str::lower($purchase_return->return_dispatch_status ?? '');
        if ($dispatchStatus !== 'pending_approval') {
            toast('Dispatch harus berstatus Pending Approval untuk disetujui.', 'error');
            return back();
        }

        DB::transaction(function () use ($purchase_return) {
            // Lock stock when dispatch is approved
            $this->lockReturnStock($purchase_return);

            $purchase_return->update([
                'return_dispatch_status' => 'dispatched',
                'status' => PurchaseReturn::STATUS_IN_RETURN,
                'return_dispatched_at' => now(),
                'return_dispatched_by' => auth()->id(),
                'dispatch_approved_by' => auth()->id(),
                'dispatch_approved_at' => now(),
                'dispatch_rejected_by' => null,
                'dispatch_rejected_at' => null,
                'dispatch_rejection_reason' => null,
            ]);
        });

        toast('Pengiriman retur disetujui dan dieksekusi.', 'success');

        return back();
    }

    public function rejectDispatch(Request $request, PurchaseReturn $purchase_return)
    {
        abort_if(Gate::denies('purchaseReturns.dispatchApproval'), 403);

        $dispatchStatus = Str::lower($purchase_return->return_dispatch_status ?? '');
        if ($dispatchStatus !== 'pending_approval') {
            toast('Dispatch harus berstatus Pending Approval untuk ditolak.', 'error');
            return back();
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($purchase_return, $data) {
            // Clear all stored attachments
            $purchase_return->clearMediaCollection('return_awb_attachments');

            // Reset dispatch status and clear all dispatch-related data
            $purchase_return->update([
                'return_dispatch_status' => 'rejected',
                'return_awb_number' => null,
                'return_shipping_amount' => 0,
                'return_carrier' => null,
                'return_dispatch_note' => null,
                'dispatch_requested_by' => null,
                'dispatch_requested_at' => null,
                'dispatch_approved_by' => null,
                'dispatch_approved_at' => null,
                'dispatch_rejected_by' => auth()->id(),
                'dispatch_rejected_at' => now(),
                'dispatch_rejection_reason' => $data['reason'] ?? null,
            ]);
        });

        toast('Pengiriman retur ditolak.', 'warning');

        return back();
    }

    private function parseCurrency(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $currency = settings()?->currency;
        $symbol = $currency?->symbol ?? '';
        $thousand = $currency?->thousand_separator ?? ',';
        $decimal = $currency?->decimal_separator ?? '.';

        $raw = str_replace($symbol, '', (string) $value);
        $raw = str_replace(' ', '', $raw);
        if ($thousand) {
            $raw = str_replace($thousand, '', $raw);
        }
        if ($decimal && $decimal !== '.') {
            $raw = str_replace($decimal, '.', $raw);
        }

        $raw = preg_replace('/[^0-9.\-]/', '', $raw);
        if ($raw === '' || $raw === '-' || $raw === '.') {
            return null;
        }
        if (!is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    private function lockReturnStock(PurchaseReturn $purchase_return): void
    {
        $purchase_return->loadMissing('purchaseReturnDetails');
        $dispatchHistoryRecordedSerialIds = [];

        foreach ($purchase_return->purchaseReturnDetails as $detail) {
            $quantity = (int) $detail->quantity;
            if ($quantity <= 0) {
                continue;
            }

            $product = Product::whereKey($detail->product_id)->lockForUpdate()->first();
            if (! $product) {
                continue;
            }

            $stock = ProductStock::where('product_id', $detail->product_id)
                ->where('location_id', $detail->location_id)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                continue;
            }

            $prevProductQty = (int) $product->product_quantity;
            $prevStockQty = (int) $stock->quantity;
            $prevBrokenQty = (int) $stock->broken_quantity;

            $remaining = $quantity;
            $deducted = [
                'broken_non_tax' => 0,
                'broken_tax' => 0,
                'good_non_tax' => 0,
                'good_tax' => 0,
            ];

            // Priority 1: broken_quantity_non_tax
            $take = min($remaining, (int) ($stock->broken_quantity_non_tax ?? 0));
            if ($take > 0) {
                $stock->decrement('broken_quantity_non_tax', $take);
                $stock->decrement('broken_quantity', $take);
                $deducted['broken_non_tax'] = $take;
                $remaining -= $take;
            }

            // Priority 2: broken_quantity_tax
            if ($remaining > 0) {
                $take = min($remaining, (int) ($stock->broken_quantity_tax ?? 0));
                if ($take > 0) {
                    $stock->decrement('broken_quantity_tax', $take);
                    $stock->decrement('broken_quantity', $take);
                    $deducted['broken_tax'] = $take;
                    $remaining -= $take;
                }
            }

            // Priority 3: quantity_non_tax (Good stock)
            if ($remaining > 0) {
                $take = min($remaining, (int) ($stock->quantity_non_tax ?? 0));
                if ($take > 0) {
                    $stock->decrement('quantity_non_tax', $take);
                    $stock->decrement('quantity', $take);
                    $deducted['good_non_tax'] = $take;
                    $remaining -= $take;
                }
            }

            // Priority 4: quantity_tax (Good stock)
            if ($remaining > 0) {
                $take = min($remaining, (int) ($stock->quantity_tax ?? 0));
                if ($take > 0) {
                    $stock->decrement('quantity_tax', $take);
                    $stock->decrement('quantity', $take);
                    $deducted['good_tax'] = $take;
                    $remaining -= $take;
                }
            }

            if ($remaining > 0) {
                // Should we throw exception or just deduct whatever left from good?
                // For safety, we already checked, but if somehow stock is insufficient:
                // throw new \Exception("Stok tidak mencukupi untuk dispatch {$product->product_name}");
            }

            // Update Product global quantity (only for Good stock deducted)
            $totalGoodDeducted = $deducted['good_non_tax'] + $deducted['good_tax'];
            if ($totalGoodDeducted > 0) {
                $product->decrement('product_quantity', $totalGoodDeducted);
            }
            
            // Only for broken stock deducted
            $totalBrokenDeducted = $deducted['broken_non_tax'] + $deducted['broken_tax'];
            if ($totalBrokenDeducted > 0) {
               $product->decrement('broken_quantity', $totalBrokenDeducted);
            }

            if (! empty($detail->serial_number_ids)) {
                $serialIds = collect($detail->serial_number_ids)
                    ->filter(fn ($id) => is_numeric($id))
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                if (! empty($serialIds)) {
                    $existingSerialIds = ProductSerialNumber::whereIn('id', $serialIds)
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all();

                    if (empty($existingSerialIds)) {
                        continue;
                    }

                    ProductSerialNumber::whereIn('id', $existingSerialIds)
                        ->update([
                            'status' => ProductSerialNumber::STATUS_RETURN_IN_PROCESS,
                            'is_in_return_process' => true,
                            'purchase_return_id' => $purchase_return->id,
                        ]);

                    $serialLocationId = $detail->location_id ?? $purchase_return->location_id;
                    $historySerialIds = array_values(array_diff($existingSerialIds, $dispatchHistoryRecordedSerialIds));

                    foreach ($historySerialIds as $serialId) {
                        SerialNumberHistoryService::record(
                            $serialId,
                            SerialNumberHistory::EVENT_PURCHASE_RETURN_DISPATCHED,
                            $serialLocationId,
                            $purchase_return,
                            "Pengiriman retur disetujui untuk {$purchase_return->reference}"
                        );
                    }

                    $dispatchHistoryRecordedSerialIds = array_values(array_unique(array_merge(
                        $dispatchHistoryRecordedSerialIds,
                        $historySerialIds
                    )));
                }
            }

            // Create Transactions for each deducted bucket
            $stock->refresh();
            $product->refresh();

            foreach ($deducted as $source => $qty) {
                if ($qty <= 0) continue;

                $type = match($source) {
                    'broken_non_tax' => 'PURCHASE_RETURN_BROKEN_NON_TAX',
                    'broken_tax' => 'PURCHASE_RETURN_BROKEN_TAX',
                    'good_non_tax' => 'PURCHASE_RETURN_GOOD_NON_TAX',
                    'good_tax' => 'PURCHASE_RETURN_GOOD_TAX',
                };

                Transaction::create([
                    'product_id' => $product->id,
                    'setting_id' => $purchase_return->setting_id,
                    'type' => $type,
                    'quantity' => -$qty,
                    'current_quantity' => $product->product_quantity,
                    'broken_quantity' => $stock->broken_quantity,
                    'location_id' => $detail->location_id,
                    'user_id' => auth()->id(),
                    'reason' => "Pengiriman retur: {$purchase_return->reference}",
                    'previous_quantity' => $prevProductQty,
                    'after_quantity' => $product->product_quantity,
                    'previous_quantity_at_location' => $prevStockQty,
                    'after_quantity_at_location' => $stock->quantity,
                    'quantity_tax' => ($source === 'good_tax') ? -$qty : 0,
                    'quantity_non_tax' => ($source === 'good_non_tax') ? -$qty : 0,
                    'broken_quantity_tax' => ($source === 'broken_tax') ? -$qty : 0,
                    'broken_quantity_non_tax' => ($source === 'broken_non_tax') ? -$qty : 0,
                ]);
            }
        }
    }
}
