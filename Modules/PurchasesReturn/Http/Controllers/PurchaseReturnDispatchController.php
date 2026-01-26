<?php

namespace Modules\PurchasesReturn\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
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

            // Mark serial numbers as returned
            foreach ($purchase_return->purchaseReturnDetails as $detail) {
                if (! empty($detail->serial_number_ids)) {
                    ProductSerialNumber::whereIn('id', $detail->serial_number_ids)
                        ->update([
                            'status' => 'returned',
                            'is_in_return_process' => false,
                        ]);
                }
            }

            $purchase_return->update([
                'return_dispatch_status' => 'dispatched',
                'status' => 'Return Dispatched',
                'return_dispatched_at' => now(),
                'return_dispatched_by' => auth()->id(),
                'dispatch_approved_by' => auth()->id(),
                'dispatch_approved_at' => now(),
                'dispatch_rejected_by' => null,
                'dispatch_rejected_at' => null,
                'dispatch_rejection_reason' => null,
            ]);
        });

        toast('Dispatch retur disetujui dan dieksekusi.', 'success');

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

        toast('Dispatch retur ditolak.', 'warning');

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

            $availableBrokenNonTax = max(0, (int) ($stock->broken_quantity_non_tax ?? 0));
            $availableBrokenTax = max(0, (int) ($stock->broken_quantity_tax ?? 0));
            $availableBroken = $availableBrokenNonTax + $availableBrokenTax;

            $brokenToDeduct = min($quantity, $availableBroken);
            $remainingToDeduct = $quantity - $brokenToDeduct;

            // Deduct from Broken first
            if ($brokenToDeduct > 0) {
                $brokenNonTaxToDeduct = min($brokenToDeduct, $availableBrokenNonTax);
                $brokenTaxToDeduct = $brokenToDeduct - $brokenNonTaxToDeduct;

                $stock->decrement('broken_quantity', $brokenToDeduct);
                if ($brokenNonTaxToDeduct > 0) {
                    $stock->decrement('broken_quantity_non_tax', $brokenNonTaxToDeduct);
                }
                if ($brokenTaxToDeduct > 0) {
                    $stock->decrement('broken_quantity_tax', $brokenTaxToDeduct);
                }
            }

            // Deduct from Good if still needed
            if ($remainingToDeduct > 0) {
                $availableNonTax = max(0, (int) ($stock->quantity_non_tax ?? 0));
                $nonTaxToDeduct = min($remainingToDeduct, $availableNonTax);
                $taxToDeduct = $remainingToDeduct - $nonTaxToDeduct;

                $stock->decrement('quantity', $remainingToDeduct);
                if ($nonTaxToDeduct > 0) {
                    $stock->decrement('quantity_non_tax', $nonTaxToDeduct);
                }
                if ($taxToDeduct > 0) {
                    $stock->decrement('quantity_tax', $taxToDeduct);
                }
            }

            $newQuantity = max(0, (int) $product->product_quantity - $remainingToDeduct);
            $newBrokenQuantity = (int) $product->broken_quantity - $brokenToDeduct;
            $product->update([
                'product_quantity' => $newQuantity,
                'broken_quantity' => max(0, $newBrokenQuantity)
            ]);

            if (! empty($detail->serial_number_ids)) {
                ProductSerialNumber::whereIn('id', $detail->serial_number_ids)
                    ->update([
                        'is_in_return_process' => true,
                        'purchase_return_id' => $purchase_return->id,
                    ]);
            }
        }
    }
}
