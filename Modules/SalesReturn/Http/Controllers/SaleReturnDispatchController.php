<?php

namespace Modules\SalesReturn\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement;

class SaleReturnDispatchController extends Controller
{
    public function showDispatchForm(SaleReturn $saleReturn)
    {
        abort_if(Gate::denies('saleReturnSettlements.dispatchRequest'), 403);

        $items = SaleReturnItemSettlement::where('sale_return_id', $saleReturn->id)
            ->whereIn('status', [
                SaleReturnItemSettlement::STATUS_APPROVED_AWAITING_DISPATCH,
                SaleReturnItemSettlement::STATUS_DISPATCH_REQUESTED,
            ])
            ->get();

        return view('salesreturn::dispatch', compact('saleReturn', 'items'));
    }

    public function requestDispatch(Request $request, SaleReturn $saleReturn)
    {
        abort_if(Gate::denies('saleReturnSettlements.dispatchRequest'), 403);

        $data = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|integer|exists:sale_return_item_settlements,id',
            'items.*.dispatched_serial_number' => 'nullable|string|max:255',
            'items.*.source_location_id' => 'nullable|integer|exists:locations,id',
        ]);

        try {
            DB::transaction(function () use ($data, $saleReturn) {
                foreach ($data['items'] as $it) {
                    $item = SaleReturnItemSettlement::where('sale_return_id', $saleReturn->id)
                        ->where('id', $it['id'])
                        ->first();

                    if (!$item) continue;

                    $item->update([
                        'dispatched_serial_number' => $it['dispatched_serial_number'] ?? null,
                        'dispatch_requested_at' => now(),
                        'dispatch_requested_by' => Auth::id(),
                        'status' => SaleReturnItemSettlement::STATUS_DISPATCH_REQUESTED,
                        // store chosen source location for non-serial items temporarily
                        'location_id' => $it['source_location_id'] ?? $item->location_id,
                    ]);
                }
            });

            return back()->with('success', 'Pengajuan pengiriman berhasil dikirim untuk persetujuan.');
        } catch (\Exception $e) {
            Log::error('Failed to request dispatch for sale return', ['error' => $e->getMessage()]);
            return back()->with('error', 'Gagal mengajukan pengiriman.');
        }
    }

    public function approveDispatch(Request $request, SaleReturnItemSettlement $item)
    {
        abort_if(Gate::denies('saleReturnSettlements.dispatchApproval'), 403);

        if ($item->status !== SaleReturnItemSettlement::STATUS_DISPATCH_REQUESTED) {
            return back()->with('error', 'Item tidak dalam status pengajuan pengiriman.');
        }

        try {
            DB::transaction(function () use ($request, $item) {
                $detail = $item->detail;
                $product = $detail->product;

                // If product has serials
                if ($product->serial_number_required) {
                    $outgoingSerial = $item->dispatched_serial_number ?? $item->serialNumber?->serial_number;

                    // If same as returned serial => repair (no stock movement)
                    if ($outgoingSerial && $outgoingSerial === ($item->serialNumber?->serial_number ?? null)) {
                        // mark approved
                        $item->update([
                            'status' => SaleReturnItemSettlement::STATUS_APPROVED,
                            'dispatch_approved_at' => now(),
                            'dispatch_approved_by' => Auth::id(),
                        ]);
                        return;
                    }

                    // Replacement: find the outgoing serial in stock and decrement it
                    if ($outgoingSerial) {
                        $sn = \Modules\Product\Entities\ProductSerialNumber::where('product_id', $product->id)
                            ->where('serial_number', $outgoingSerial)
                            ->lockForUpdate()
                            ->first();

                        if (!$sn) {
                            throw new \Exception("Serial {$outgoingSerial} tidak ditemukan untuk produk.");
                        }

                        $sourceLocationId = $sn->location_id;
                        $taxId = $sn->tax_id;

                        $sourceStock = \Modules\Product\Entities\ProductStock::where('product_id', $product->id)
                            ->where('location_id', $sourceLocationId)
                            ->lockForUpdate()
                            ->first();

                        if (!$sourceStock) {
                            throw new \Exception("Stok tidak ditemukan untuk produk {$product->product_name} di lokasi sumber.");
                        }

                        if ($sourceStock->quantity < 1) {
                            throw new \Exception("Stok tidak cukup untuk produk {$product->product_name} di lokasi sumber.");
                        }

                        // decrement
                        $previousQuantity = $product->product_quantity;
                        $previousQuantityAtLocation = $sourceStock->quantity;

                        $sourceStock->decrement('quantity', 1);
                        if ($taxId) {
                            $sourceStock->decrement('quantity_tax', 1);
                        } else {
                            $sourceStock->decrement('quantity_non_tax', 1);
                        }

                        $product->decrement('product_quantity', 1);

                        $afterQuantity = $product->product_quantity;
                        $afterQuantityAtLocation = $sourceStock->quantity;

                        \Modules\Product\Entities\Transaction::create([
                            'product_id' => $product->id,
                            'setting_id' => session('setting_id'),
                            'quantity' => -1,
                            'current_quantity' => $afterQuantity,
                            'broken_quantity' => 0,
                            'location_id' => $sourceLocationId,
                            'user_id' => Auth::id(),
                            'reason' => 'Dispatch replacement for Sale Return #' . $item->saleReturn->reference,
                            'type' => 'DISPATCH_RETURN',
                            'previous_quantity' => $previousQuantity,
                            'after_quantity' => $afterQuantity,
                            'previous_quantity_at_location' => $previousQuantityAtLocation,
                            'after_quantity_at_location' => $afterQuantityAtLocation,
                            'quantity_non_tax' => $taxId ? 0 : 1,
                            'quantity_tax' => $taxId ? 1 : 0,
                            'broken_quantity_non_tax' => 0,
                            'broken_quantity_tax' => 0,
                        ]);

                        // mark the serial as dispatched (clear location)
                        $sn->update([
                            'location_id' => null,
                        ]);

                        // finalize item
                        $item->update([
                            'status' => SaleReturnItemSettlement::STATUS_DISPATCHED,
                            'dispatch_approved_at' => now(),
                            'dispatch_approved_by' => Auth::id(),
                        ]);
                        return;
                    }

                    throw new \Exception('Dispatch serial number diperlukan untuk produk serial.');
                }

                // Non-serial product: use stored location_id as source (set during request)
                $sourceLocationId = $item->location_id;
                $quantity = $detail->quantity ?? 1;

                $sourceStock = \Modules\Product\Entities\ProductStock::where('product_id', $product->id)
                    ->where('location_id', $sourceLocationId)
                    ->lockForUpdate()
                    ->first();

                if (!$sourceStock) {
                    throw new \Exception("Stok tidak ditemukan untuk produk {$product->product_name} di lokasi sumber.");
                }

                if ($sourceStock->quantity < $quantity) {
                    throw new \Exception("Stok tidak cukup untuk produk {$product->product_name} di lokasi sumber.");
                }

                $taxId = $sourceStock->tax_id ?? null;
                $previousQuantity = $product->product_quantity;
                $previousQuantityAtLocation = $sourceStock->quantity;

                $sourceStock->decrement('quantity', $quantity);
                if ($taxId) {
                    $sourceStock->decrement('quantity_tax', $quantity);
                } else {
                    $sourceStock->decrement('quantity_non_tax', $quantity);
                }

                $product->decrement('product_quantity', $quantity);

                $afterQuantity = $product->product_quantity;
                $afterQuantityAtLocation = $sourceStock->quantity;

                \Modules\Product\Entities\Transaction::create([
                    'product_id' => $product->id,
                    'setting_id' => session('setting_id'),
                    'quantity' => -$quantity,
                    'current_quantity' => $afterQuantity,
                    'broken_quantity' => 0,
                    'location_id' => $sourceLocationId,
                    'user_id' => Auth::id(),
                    'reason' => 'Dispatch replacement for Sale Return #' . $item->saleReturn->reference,
                    'type' => 'DISPATCH_RETURN',
                    'previous_quantity' => $previousQuantity,
                    'after_quantity' => $afterQuantity,
                    'previous_quantity_at_location' => $previousQuantityAtLocation,
                    'after_quantity_at_location' => $afterQuantityAtLocation,
                    'quantity_non_tax' => $taxId ? 0 : $quantity,
                    'quantity_tax' => $taxId ? $quantity : 0,
                    'broken_quantity_non_tax' => 0,
                    'broken_quantity_tax' => 0,
                ]);

                // finalize item
                $item->update([
                    'status' => SaleReturnItemSettlement::STATUS_DISPATCHED,
                    'dispatch_approved_at' => now(),
                    'dispatch_approved_by' => Auth::id(),
                ]);
            });

            return back()->with('success', 'Pengiriman disetujui.');
        } catch (\Exception $e) {
            Log::error('Failed to approve dispatch', ['error' => $e->getMessage()]);
            return back()->with('error', 'Gagal menyetujui pengiriman.');
        }
    }

    /**
     * Direct dispatch item (from modal on detail page).
     * For items with STATUS_APPROVED_AWAITING_DISPATCH.
     */
    public function dispatchItem(Request $request, SaleReturnItemSettlement $item)
    {
        abort_if(Gate::denies('saleReturnSettlements.dispatch'), 403);

        if ($item->status !== SaleReturnItemSettlement::STATUS_APPROVED_AWAITING_DISPATCH) {
            return back()->with('error', 'Item tidak dalam status menunggu pengiriman.');
        }

        $request->validate([
            'dispatched_serial_number' => 'nullable|string|max:255',
            'dispatch_note' => 'nullable|string|max:500',
        ]);

        try {
            DB::transaction(function () use ($request, $item) {
                $detail = $item->detail;
                $product = $detail->product;

                // If product has serials
                if ($product->serial_number_required) {
                    $outgoingSerial = $request->dispatched_serial_number ?? $item->serialNumber?->serial_number;

                    // If same as returned serial => repair (no stock movement needed)
                    if ($outgoingSerial && $outgoingSerial === ($item->serialNumber?->serial_number ?? null)) {
                        $item->update([
                            'status' => SaleReturnItemSettlement::STATUS_DISPATCHED,
                            'dispatched_serial_number' => $outgoingSerial,
                            'dispatch_approved_at' => now(),
                            'dispatch_approved_by' => Auth::id(),
                        ]);
                        return;
                    }

                    // Replacement: find the outgoing serial in stock and decrement it
                    if ($outgoingSerial) {
                        $sn = \Modules\Product\Entities\ProductSerialNumber::where('product_id', $product->id)
                            ->where('serial_number', $outgoingSerial)
                            ->lockForUpdate()
                            ->first();

                        if (!$sn) {
                            throw new \Exception("Serial {$outgoingSerial} tidak ditemukan untuk produk.");
                        }

                        $sourceLocationId = $sn->location_id;
                        $taxId = $sn->tax_id;

                        $sourceStock = \Modules\Product\Entities\ProductStock::where('product_id', $product->id)
                            ->where('location_id', $sourceLocationId)
                            ->lockForUpdate()
                            ->first();

                        if (!$sourceStock) {
                            throw new \Exception("Stok tidak ditemukan untuk produk {$product->product_name} di lokasi sumber.");
                        }

                        if ($sourceStock->quantity < 1) {
                            throw new \Exception("Stok tidak cukup untuk produk {$product->product_name} di lokasi sumber.");
                        }

                        // decrement
                        $previousQuantity = $product->product_quantity;
                        $previousQuantityAtLocation = $sourceStock->quantity;

                        $sourceStock->decrement('quantity', 1);
                        if ($taxId) {
                            $sourceStock->decrement('quantity_tax', 1);
                        } else {
                            $sourceStock->decrement('quantity_non_tax', 1);
                        }

                        $product->decrement('product_quantity', 1);

                        $afterQuantity = $product->product_quantity;
                        $afterQuantityAtLocation = $sourceStock->quantity;

                        \Modules\Product\Entities\Transaction::create([
                            'product_id' => $product->id,
                            'setting_id' => session('setting_id'),
                            'quantity' => -1,
                            'current_quantity' => $afterQuantity,
                            'broken_quantity' => 0,
                            'location_id' => $sourceLocationId,
                            'user_id' => Auth::id(),
                            'reason' => 'Dispatch replacement for Sale Return #' . $item->saleReturn->reference,
                            'type' => 'DISPATCH_RETURN',
                            'previous_quantity' => $previousQuantity,
                            'after_quantity' => $afterQuantity,
                            'previous_quantity_at_location' => $previousQuantityAtLocation,
                            'after_quantity_at_location' => $afterQuantityAtLocation,
                            'quantity_non_tax' => $taxId ? 0 : 1,
                            'quantity_tax' => $taxId ? 1 : 0,
                            'broken_quantity_non_tax' => 0,
                            'broken_quantity_tax' => 0,
                        ]);

                        // mark the serial as dispatched (clear location)
                        $sn->update([
                            'location_id' => null,
                        ]);

                        // finalize item
                        $item->update([
                            'status' => SaleReturnItemSettlement::STATUS_DISPATCHED,
                            'dispatched_serial_number' => $outgoingSerial,
                            'dispatch_approved_at' => now(),
                            'dispatch_approved_by' => Auth::id(),
                        ]);
                        return;
                    }

                    throw new \Exception('Dispatch serial number diperlukan untuk produk serial.');
                }

                // Non-serial product: For METHOD_UNPROCESSED, just mark as dispatched (return to customer)
                // For METHOD_PRODUCT_REPAIR, stock was already received, now we dispatch replacement
                $method = strtoupper($item->method ?? '');
                
                if ($method === 'UNPROCESSED') {
                    // Unprocessed: just mark dispatched, no stock movement
                    $item->update([
                        'status' => SaleReturnItemSettlement::STATUS_DISPATCHED,
                        'dispatch_approved_at' => now(),
                        'dispatch_approved_by' => Auth::id(),
                    ]);
                    return;
                }

                // For repair of non-serial product, decrement stock from location
                $sourceLocationId = $item->location_id ?? $item->detail->location_id;
                $quantity = $detail->quantity ?? 1;

                $sourceStock = \Modules\Product\Entities\ProductStock::where('product_id', $product->id)
                    ->where('location_id', $sourceLocationId)
                    ->lockForUpdate()
                    ->first();

                if (!$sourceStock) {
                    throw new \Exception("Stok tidak ditemukan untuk produk {$product->product_name} di lokasi sumber.");
                }

                if ($sourceStock->quantity < $quantity) {
                    throw new \Exception("Stok tidak cukup untuk produk {$product->product_name} di lokasi sumber.");
                }

                $taxId = $sourceStock->tax_id ?? null;
                $previousQuantity = $product->product_quantity;
                $previousQuantityAtLocation = $sourceStock->quantity;

                $sourceStock->decrement('quantity', $quantity);
                if ($taxId) {
                    $sourceStock->decrement('quantity_tax', $quantity);
                } else {
                    $sourceStock->decrement('quantity_non_tax', $quantity);
                }

                $product->decrement('product_quantity', $quantity);

                $afterQuantity = $product->product_quantity;
                $afterQuantityAtLocation = $sourceStock->quantity;

                \Modules\Product\Entities\Transaction::create([
                    'product_id' => $product->id,
                    'setting_id' => session('setting_id'),
                    'quantity' => -$quantity,
                    'current_quantity' => $afterQuantity,
                    'broken_quantity' => 0,
                    'location_id' => $sourceLocationId,
                    'user_id' => Auth::id(),
                    'reason' => 'Dispatch replacement for Sale Return #' . $item->saleReturn->reference,
                    'type' => 'DISPATCH_RETURN',
                    'previous_quantity' => $previousQuantity,
                    'after_quantity' => $afterQuantity,
                    'previous_quantity_at_location' => $previousQuantityAtLocation,
                    'after_quantity_at_location' => $afterQuantityAtLocation,
                    'quantity_non_tax' => $taxId ? 0 : $quantity,
                    'quantity_tax' => $taxId ? $quantity : 0,
                    'broken_quantity_non_tax' => 0,
                    'broken_quantity_tax' => 0,
                ]);

                // finalize item
                $item->update([
                    'status' => SaleReturnItemSettlement::STATUS_DISPATCHED,
                    'dispatch_approved_at' => now(),
                    'dispatch_approved_by' => Auth::id(),
                ]);
            });

            return back()->with('success', 'Pengiriman berhasil diproses.');
        } catch (\Exception $e) {
            Log::error('Failed to dispatch item', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return back()->with('error', 'Gagal memproses pengiriman: ' . $e->getMessage());
        }
    }

    public function rejectDispatch(Request $request, SaleReturnItemSettlement $item)
    {
        abort_if(Gate::denies('saleReturnSettlements.dispatchApproval'), 403);

        $request->validate(['rejection_reason' => 'required|string|max:255']);

        if ($item->status !== SaleReturnItemSettlement::STATUS_DISPATCH_REQUESTED) {
            return back()->with('error', 'Item tidak dalam status pengajuan pengiriman.');
        }

        try {
            DB::transaction(function () use ($request, $item) {
                $item->update([
                    'status' => SaleReturnItemSettlement::STATUS_APPROVED_AWAITING_DISPATCH,
                    'dispatch_rejected_at' => now(),
                    'dispatch_rejected_by' => Auth::id(),
                    'dispatch_rejection_reason' => $request->rejection_reason,
                ]);
            });

            return back()->with('success', 'Pengajuan pengiriman ditolak.');
        } catch (\Exception $e) {
            Log::error('Failed to reject dispatch', ['error' => $e->getMessage()]);
            return back()->with('error', 'Gagal menolak pengiriman.');
        }
    }
}
