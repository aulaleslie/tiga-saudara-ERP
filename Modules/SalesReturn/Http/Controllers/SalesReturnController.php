<?php

namespace Modules\SalesReturn\Http\Controllers;

use App\Support\SalesReturn\SaleReturnLifecycleSyncService;
use App\Support\SalesReturn\SalesReturnStateException;
use App\Support\SalesReturn\StandardReturnGuard;
use App\Services\SerialNumberHistoryService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\SalesOrderSerialTracking;
use Modules\SalesReturn\DataTables\SaleReturnsDataTable;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Throwable;

class SalesReturnController extends Controller
{
    public function __construct()
    {
        // Resource routes cannot be annotated individually; edit/update/destroy are mutations.
        $this->middleware('sales-return.standard')->only(['edit', 'update', 'destroy']);
    }


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
        $this->authorizeLinkedPosReturnPermission($sale_return, 'pos.returns.view');

        $sale_return->load([
            'saleReturnDetails',
            'saleReturnGoods',
            'saleReturnPayments',
            'customerCredit',
            'sale',
            'location',
            'settledBy',
            // Eager-load nested relations used in view to avoid lazy-loading violations
            'settlementItems.location',
            'settlementItems.detail',
            'settlementItems.serialNumber',
        ]);

        return view('salesreturn::show', compact('sale_return'));
    }


    public function edit(SaleReturn $sale_return) {
        abort_if(Gate::denies('saleReturnSettlements.submit'), 403);
        $this->authorizeLinkedPosReturnPermission($sale_return, 'pos.returns.edit');

        // Rule: Received -> Hard Block
        if (!is_null($sale_return->received_at)) {
            abort(403, 'Tidak dapat mengubah retur penjualan yang sudah diterima barangnya.');
        }

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

        $status = Str::lower($sale_return->status ?? '');

        if ($status !== 'awaiting settlement') {
            toast('Penyelesaian hanya dapat diproses setelah barang retur diterima (Awaiting Settlement).', 'error');
            return redirect()->route('sale-returns.show', $sale_return);
        }

        $sale_return->load([
            'saleReturnDetails',
            'saleReturnGoods',
            'customerCredit',
        ]);

        return view('salesreturn::settlement', compact('sale_return'));
    }


    public function archive(SaleReturn $sale_return)
    {
        abort_if(Gate::denies('saleReturns.archive'), 403);
        $this->authorizeLinkedPosReturnPermission($sale_return, 'pos.returns.delete');

        // Block if processed
        if (!is_null($sale_return->received_at)) {
            abort(403, 'Tidak dapat mengarsipkan retur penjualan yang sudah diterima barangnya.');
        }

        try {
            DB::transaction(function () use ($sale_return) {
                $locked = app(StandardReturnGuard::class)->lockForAction($sale_return);
                if (! is_null($locked->received_at)) {
                    throw new SalesReturnStateException('Tidak dapat mengarsipkan retur penjualan yang sudah diterima barangnya.');
                }

                $locked->update([
                    'archived_at' => now(),
                    'archived_by' => auth()->id(),
                ]);
            });
        } catch (SalesReturnStateException $e) {
            abort(403, $e->getMessage());
        }

        \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($sale_return));
        \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->resolveRevision($sale_return));
        \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($sale_return, 'dispatch'));
        \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->resolveRevision($sale_return, 'dispatch'));

        toast('Retur Penjualan Diarsipkan!', 'info');

        return redirect()->route('sale-returns.index');
    }

    public function destroy(SaleReturn $sale_return) {
        abort_if(Gate::denies('saleReturns.delete'), 403);
        $this->authorizeLinkedPosReturnPermission($sale_return, 'pos.returns.delete');

        // Rule: Received -> Hard Block
        if (!is_null($sale_return->received_at)) {
            abort(403, 'Tidak dapat menghapus retur penjualan yang sudah diterima barangnya.');
        }

        // Rule: Approved -> Require explicit archive permission
        if (Str::lower($sale_return->approval_status) === 'approved') {
            if (!auth()->user()->can('saleReturns.archive')) {
                abort(403, 'Anda tidak memiliki akses untuk mengarsipkan retur penjualan yang sudah disetujui.');
            }
        }

        DB::transaction(function () use ($sale_return) {
            $locked = app(StandardReturnGuard::class)->lockForAction($sale_return);
            if (! is_null($locked->received_at)) {
                throw new SalesReturnStateException('Tidak dapat menghapus retur penjualan yang sudah diterima barangnya.');
            }

            $locked->delete();
        });

        \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($sale_return));
        \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->resolveRevision($sale_return));
        \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($sale_return, 'dispatch'));
        \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->resolveRevision($sale_return, 'dispatch'));

        toast('Retur Penjualan Dihapus!', 'warning');

        return redirect()->route('sale-returns.index');
    }

    public function approve(SaleReturn $sale_return)
    {
        abort_if(Gate::denies('saleReturns.approve'), 403);
        $this->authorizeLinkedPosReturnPermission($sale_return, 'pos.returns.approve');

        $status = Str::lower($sale_return->approval_status ?? '');

        if ($status === 'approved') {
            toast('Retur penjualan sudah disetujui.', 'info');
            return back();
        }

        if ($status === 'rejected') {
            toast('Retur penjualan yang ditolak tidak dapat disetujui.', 'error');
            return back();
        }

        try {
            DB::transaction(function () use ($sale_return) {
                $guard = app(StandardReturnGuard::class);
                // Source sale -> header locks; state is re-read here, not trusted from the request.
                $locked = $guard->lockForAction($sale_return);
                $guard->assertCanApprove($locked);

                $locked->update([
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
            });
        } catch (SalesReturnStateException $e) {
            toast($e->getMessage(), 'error');

            return back();
        }

        \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($sale_return));
        \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->resolveRevision($sale_return));

        toast('Retur penjualan disetujui.', 'success');

        return back();
    }

    public function reject(Request $request, SaleReturn $sale_return)
    {
        abort_if(Gate::denies('saleReturns.approve'), 403);
        $this->authorizeLinkedPosReturnPermission($sale_return, 'pos.returns.approve');

        $status = Str::lower($sale_return->approval_status ?? '');

        if ($status === 'approved') {
            toast('Retur penjualan yang sudah disetujui tidak dapat ditolak.', 'error');
            return back();
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            DB::transaction(function () use ($sale_return, $data) {
                $guard = app(StandardReturnGuard::class);
                $locked = $guard->lockForAction($sale_return);
                $guard->assertCanReject($locked);

                $locked->update([
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
            });
        } catch (SalesReturnStateException $e) {
            toast($e->getMessage(), 'error');

            return back();
        }

        \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($sale_return));
        \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->notifyRevisionNeeded($sale_return, $sale_return->reference, $sale_return->setting_id, $data['reason'] ?? ''));

        toast('Retur penjualan ditolak.', 'warning');

        return back();
    }

    public function receive(SaleReturn $sale_return)
    {
        abort_if(Gate::denies('saleReturns.receive'), 403);
        $this->authorizeLinkedPosReturnPermission($sale_return, 'pos.returns.receive');

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
                $receivedAt = now();

                // Consignment buyout protocol: (setting, product) coordination locks precede the document locks.
                \Modules\Consignment\Services\ConsignmentBuyoutReservationGuard::lockCoordination(
                    (int) $sale_return->setting_id,
                    $sale_return->saleReturnDetails()->pluck('product_id')->all()
                );

                // Source sale -> header locks, then the authoritative state check: a replayed or
                // concurrent receive finds the return no longer awaiting receiving and writes nothing.
                $guard = app(StandardReturnGuard::class);
                $lockedSaleReturn = $guard->lockForAction($sale_return);
                $guard->assertCanReceive($lockedSaleReturn);

                // Standard receiving ALWAYS goes through held custody: goods are HELD, not sellable, and no
                // ProductStock / product_quantity / good-stock Transaction is touched here. An authorized
                // inspection release is the only way into sellable stock. Disabling the flag blocks NEW
                // receiving (it must never turn receipts into immediately sellable stock).
                if (! \App\Support\SalesReturn\StandardReturnCustodyService::enabled()) {
                    throw new SalesReturnStateException(
                        'Penerimaan retur penjualan dinonaktifkan sementara (custody retur belum diaktifkan). Custody yang sudah ada dan inspeksi tetap dapat diakses.'
                    );
                }

                app(\App\Support\SalesReturn\StandardReturnCustodyService::class)
                    ->receive($lockedSaleReturn, $receivedAt, (int) auth()->id());

                $lockedSaleReturn->forceFill([
                    'status' => 'Awaiting Settlement',
                    'received_by' => auth()->id(),
                    'received_at' => $receivedAt,
                    'settled_at' => null,
                    'settled_by' => null,
                    // Policy is stamped on FIRST receiving only.
                    'custody_policy_version' => \App\Support\SalesReturn\StandardReturnCustodyService::policyVersion(),
                    'custody_policy_applied_at' => $receivedAt,
                ])->save();

                app(SaleReturnLifecycleSyncService::class)
                    ->syncSourceSaleReturnStatusFromReceivedReturns($lockedSaleReturn);
            });
        } catch (SalesReturnStateException $e) {
            toast($e->getMessage(), 'info');

            return back();
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

    private function authorizeLinkedPosReturnPermission(SaleReturn $saleReturn, string $permission): void
    {
        if (! $saleReturn->pos_return_id) {
            return;
        }

        abort_if(Gate::denies($permission), 403);
    }
}
