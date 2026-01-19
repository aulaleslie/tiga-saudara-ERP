<?php

namespace Modules\PurchasesReturn\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\PurchasesReturn\Entities\PurchaseReturn;

class PurchaseReturnApprovalController extends Controller
{
    public function approve(PurchaseReturn $purchase_return)
    {
        abort_if(Gate::denies('purchaseReturns.approval'), 403);

        $status = Str::lower($purchase_return->approval_status ?? '');

        if ($status === 'approved') {
            toast('Retur pembelian sudah disetujui.', 'info');
            return back();
        }

        // Validate stock before approval
        $errors = $this->validateStockForApproval($purchase_return);
        if (!empty($errors)) {
            foreach ($errors as $error) {
                toast($error, 'error');
            }
            return back();
        }

        DB::transaction(function () use ($purchase_return) {
            $purchase_return->update([
                'approval_status' => 'approved',
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'status' => 'Awaiting Settlement',
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
                'settled_at' => null,
                'settled_by' => null,
            ]);
        });

        toast('Retur pembelian disetujui.', 'success');

        return back();
    }

    public function reject(Request $request, PurchaseReturn $purchase_return)
    {
        abort_if(Gate::denies('purchaseReturns.approval'), 403);

        $status = Str::lower($purchase_return->approval_status ?? '');

        if ($status === 'approved') {
            toast('Retur yang sudah disetujui tidak dapat ditolak.', 'error');
            return back();
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $purchase_return->update([
            'approval_status' => 'rejected',
            'status' => 'Rejected',
            'rejected_by' => auth()->id(),
            'rejected_at' => now(),
            'rejection_reason' => $data['reason'] ?? null,
        ]);

        toast('Retur pembelian ditolak.', 'warning');

        return back();
    }

    private function validateStockForApproval(PurchaseReturn $purchase_return): array
    {
        $errors = [];
        $purchase_return->loadMissing('purchaseReturnDetails.product');

        foreach ($purchase_return->purchaseReturnDetails as $detail) {
            $product = $detail->product;
            $locationId = $detail->location_id;
            $quantity = $detail->quantity;

            if (! $product) {
                $errors[] = "Produk pada baris #{$detail->id} tidak ditemukan.";
                continue;
            }

            $stock = ProductStock::where('product_id', $product->id)
                ->where('location_id', $locationId)
                ->first();

            if (! $stock || $stock->quantity < $quantity) {
                $currentQty = $stock ? $stock->quantity : 0;
                $errors[] = "Stok tidak mencukupi untuk '{$product->product_name}' di lokasi yang dipilih. (Diminta: {$quantity}, Tersedia: {$currentQty})";
            }

            if (! empty($detail->serial_number_ids)) {
                $serialsWithWrongLocation = ProductSerialNumber::whereIn('id', $detail->serial_number_ids)
                    ->where('product_id', $product->id)
                    ->get();

                foreach ($serialsWithWrongLocation as $serial) {
                    if ($serial->location_id != $locationId) {
                        $errors[] = "Nomor seri '{$serial->serial_number}' sekarang berada di lokasi yang berbeda.";
                    }
                    if ($serial->status !== 'active') {
                        $errors[] = "Nomor seri '{$serial->serial_number}' sudah tidak aktif ({$serial->status}).";
                    }
                    if ($serial->is_in_return_process) {
                        $errors[] = "Nomor seri '{$serial->serial_number}' sedang dalam proses retur.";
                    }
                }

                if ($serialsWithWrongLocation->count() < count($detail->serial_number_ids)) {
                    $errors[] = "Beberapa nomor seri untuk '{$product->product_name}' tidak ditemukan.";
                }
            }
        }

        return $errors;
    }

}
