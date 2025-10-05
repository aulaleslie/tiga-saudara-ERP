<?php

namespace Modules\SalesReturn\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\DataTables\SaleReturnsDataTable;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Throwable;

class SalesReturnController extends Controller
{

    public function index(SaleReturnsDataTable $dataTable) {
        abort_if(Gate::denies('saleReturns.access'), 403);

        return $dataTable->render('salesreturn::index');
    }


    public function create() {
        abort_if(Gate::denies('saleReturns.create'), 403);

        return view('salesreturn::create');
    }


    public function store() {
        abort(404, 'Gunakan formulir Livewire untuk membuat retur penjualan.');
    }


    public function show(SaleReturn $sale_return) {
        abort_if(Gate::denies('saleReturns.show'), 403);

        $sale_return->load([
            'saleReturnDetails',
            'saleReturnGoods',
            'saleReturnPayments',
            'customerCredit',
            'sale',
            'location',
            'settledBy',
        ]);

        return view('salesreturn::show', compact('sale_return'));
    }


    public function edit(SaleReturn $sale_return) {
        abort_if(Gate::denies('saleReturns.edit'), 403);

        if ($sale_return->settled_at) {
            toast('Retur penjualan sudah diselesaikan dan tidak dapat diedit.', 'info');
            return redirect()->route('sale-returns.show', $sale_return);
        }

        return view('salesreturn::edit', compact('sale_return'));
    }


    public function update(SaleReturn $sale_return) {
        abort(404, 'Gunakan formulir Livewire untuk memperbarui retur penjualan.');
    }


    public function settlement(SaleReturn $sale_return)
    {
        abort_if(Gate::denies('saleReturns.edit'), 403);

        $status = Str::lower($sale_return->approval_status ?? '');

        if ($status !== 'approved') {
            toast('Penyelesaian hanya dapat diproses setelah retur disetujui.', 'error');
            return redirect()->route('sale-returns.show', $sale_return);
        }

        $sale_return->load([
            'saleReturnDetails',
            'saleReturnGoods',
            'customerCredit',
        ]);

        return view('salesreturn::settlement', compact('sale_return'));
    }


    public function destroy(SaleReturn $sale_return) {
        abort_if(Gate::denies('saleReturns.delete'), 403);

        $sale_return->delete();

        toast('Retur Penjualan Dihapus!', 'warning');

        return redirect()->route('sale-returns.index');
    }

    public function approve(SaleReturn $sale_return)
    {
        abort_if(Gate::denies('saleReturns.approve'), 403);

        $status = Str::lower($sale_return->approval_status ?? '');

        if ($status === 'approved') {
            toast('Retur penjualan sudah disetujui.', 'info');
            return back();
        }

        if ($status === 'rejected') {
            toast('Retur penjualan yang ditolak tidak dapat disetujui.', 'error');
            return back();
        }

        $sale_return->update([
            'approval_status' => 'approved',
            'status' => 'Awaiting Receiving',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'received_by' => null,
            'received_at' => null,
            'settled_at' => null,
            'settled_by' => null,
        ]);

        toast('Retur penjualan disetujui.', 'success');

        return back();
    }

    public function reject(Request $request, SaleReturn $sale_return)
    {
        abort_if(Gate::denies('saleReturns.approve'), 403);

        $status = Str::lower($sale_return->approval_status ?? '');

        if ($status === 'approved') {
            toast('Retur penjualan yang sudah disetujui tidak dapat ditolak.', 'error');
            return back();
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $sale_return->update([
            'approval_status' => 'rejected',
            'status' => 'Rejected',
            'rejected_by' => auth()->id(),
            'rejected_at' => now(),
            'rejection_reason' => $data['reason'] ?? null,
            'approved_by' => null,
            'approved_at' => null,
            'received_by' => null,
            'received_at' => null,
            'settled_at' => null,
            'settled_by' => null,
        ]);

        toast('Retur penjualan ditolak.', 'warning');

        return back();
    }

    public function receive(SaleReturn $sale_return)
    {
        abort_if(Gate::denies('saleReturns.receive'), 403);

        $approvalStatus = Str::lower($sale_return->approval_status ?? '');
        $status = Str::lower($sale_return->status ?? '');

        if ($approvalStatus !== 'approved') {
            toast('Retur penjualan harus disetujui sebelum diterima.', 'error');
            return back();
        }

        if ($status === 'awaiting settlement' || $status === 'completed') {
            toast('Retur penjualan sudah diterima.', 'info');
            return back();
        }

        if ($status !== 'awaiting receiving') {
            toast('Retur penjualan belum siap untuk diterima.', 'error');
            return back();
        }

        try {
            DB::transaction(function () use ($sale_return) {
                $lockedSaleReturn = SaleReturn::query()
                    ->whereKey($sale_return->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $details = SaleReturnDetail::query()
                    ->with('dispatchDetail')
                    ->where('sale_return_id', $lockedSaleReturn->id)
                    ->lockForUpdate()
                    ->get();

                $dispatchDetails = DispatchDetail::query()
                    ->whereIn('id', $details->pluck('dispatch_detail_id')->filter()->unique()->all())
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($details as $detail) {
                    $quantity = (int) ($detail->quantity ?? 0);
                    if ($quantity <= 0) {
                        continue;
                    }

                    $dispatchDetail = $dispatchDetails->get($detail->dispatch_detail_id);
                    if (! $dispatchDetail) {
                        throw new \RuntimeException('Detail pengiriman tidak ditemukan untuk retur penjualan.');
                    }

                    $locationId = $detail->location_id
                        ?? $lockedSaleReturn->location_id
                        ?? $dispatchDetail->location_id;

                    if (! $locationId) {
                        throw new \RuntimeException('Lokasi penerimaan retur tidak dapat ditentukan.');
                    }

                    $product = Product::query()
                        ->where('id', $detail->product_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $productStock = ProductStock::query()
                        ->where('product_id', $detail->product_id)
                        ->where('location_id', $locationId)
                        ->lockForUpdate()
                        ->first();

                    if (! $productStock) {
                        $productStock = ProductStock::create([
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
                    $productStock->broken_quantity = (int) ($productStock->broken_quantity_non_tax ?? 0)
                        + (int) ($productStock->broken_quantity_tax ?? 0);
                    $productStock->tax_id = $taxId;
                    $productStock->save();

                    $product->product_quantity = (int) $product->product_quantity + $quantity;
                    $product->save();

                    $serialIds = collect($detail->serial_number_ids ?? [])
                        ->map(fn ($id) => (int) $id)
                        ->filter()
                        ->values()
                        ->all();

                    if (! empty($serialIds)) {
                        $serials = ProductSerialNumber::query()
                            ->whereIn('id', $serialIds)
                            ->lockForUpdate()
                            ->get();

                        if ($serials->count() !== count($serialIds)) {
                            throw new \RuntimeException('Sebagian nomor seri tidak ditemukan.');
                        }

                        foreach ($serials as $serial) {
                            if ((int) $serial->dispatch_detail_id !== (int) $dispatchDetail->id) {
                                throw new \RuntimeException('Nomor seri tidak berasal dari pengiriman penjualan ini.');
                            }
                        }

                        ProductSerialNumber::query()
                            ->whereIn('id', $serialIds)
                            ->update([
                                'dispatch_detail_id' => null,
                                'location_id' => $locationId,
                                'tax_id' => $taxId,
                            ]);
                    }
                }

                $lockedSaleReturn->forceFill([
                    'status' => 'Awaiting Settlement',
                    'received_by' => auth()->id(),
                    'received_at' => now(),
                    'settled_at' => null,
                    'settled_by' => null,
                ])->save();

                $sale = $lockedSaleReturn->sale()->lockForUpdate()->first();
                if ($sale) {
                    $dispatchedQuantity = DispatchDetail::query()
                        ->where('sale_id', $sale->id)
                        ->sum('dispatched_quantity');

                    $returnedQuantity = SaleReturnDetail::query()
                        ->whereHas('saleReturn', function ($query) use ($sale) {
                            $query->where('sale_id', $sale->id)
                                ->whereIn('status', ['Awaiting Settlement', 'Completed']);
                        })
                        ->sum('quantity');

                    if ($dispatchedQuantity > 0 && $returnedQuantity >= $dispatchedQuantity) {
                        $sale->status = Sale::STATUS_RETURNED;
                        $sale->save();
                    } elseif ($returnedQuantity > 0) {
                        $sale->status = Sale::STATUS_RETURNED_PARTIALLY;
                        $sale->save();
                    }
                }
            });
        } catch (Throwable $e) {
            Log::error('Gagal menerima retur penjualan', [
                'sale_return_id' => $sale_return->id,
                'message' => $e->getMessage(),
            ]);

            toast('Gagal memproses penerimaan retur penjualan.', 'error');

            return back();
        }

        toast('Retur penjualan berhasil diterima.', 'success');

        return back();
    }
}
