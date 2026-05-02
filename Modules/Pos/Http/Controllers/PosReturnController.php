<?php

namespace Modules\Pos\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Pos\Services\PosReturnLookupService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Entities\PosReturn;

class PosReturnController extends Controller
{
    protected $lookupService;
    protected $submissionService;
    protected $lifecycleService;
    protected $snapshotService;

    public function __construct(
        PosReturnLookupService $lookupService,
        PosReturnSubmissionService $submissionService,
        PosReturnLifecycleService $lifecycleService,
        \Modules\Pos\Services\PosReturnSnapshotService $snapshotService
    ) {
        $this->lookupService = $lookupService;
        $this->submissionService = $submissionService;
        $this->lifecycleService = $lifecycleService;
        $this->snapshotService = $snapshotService;
    }

    public function index()
    {
        return view('pos::returns.index');
    }

    public function create()
    {
        return view('pos::returns.create');
    }

    public function lookup(Request $request)
    {
        $identifier = $request->get('identifier');
        $result = $this->lookupService->lookup($identifier);
        
        if ($result) {
            $result['snapshot'] = $this->snapshotService->build($result['pos_transaction_id']);
            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Transaksi tidak ditemukan atau belum diposting.'
        ], 404);
    }

    public function store(Request $request)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.create'), 403);
        
        $posReturn = $this->submissionService->store($request->all());
        
        toast('Retur POS berhasil disimpan.', 'success');
        
        return redirect()->route('pos.returns.show', $posReturn->id);
    }

    public function show(PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.view'), 403);
        
        $return->load([
            'lines.product',
            'posTransaction',
            'posCheckout',
            'approvedBy',
            'rejectedBy',
            'receivedBy',
            'settledBy',
            'saleReturns.location',
            'saleReturns.saleReturnDetails.product',
        ]);
        
        return view('pos::returns.show', compact('return'));
    }

    public function edit(PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.edit'), 403);
        
        if ($return->status !== PosReturn::STATUS_PENDING_APPROVAL) {
            abort(403, 'Hanya retur yang masih menunggu persetujuan yang dapat diubah.');
        }
        
        return view('pos::returns.edit', compact('return'));
    }

    public function update(Request $request, PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.edit'), 403);
        
        if ($return->status !== PosReturn::STATUS_PENDING_APPROVAL) {
            abort(403, 'Hanya retur yang masih menunggu persetujuan yang dapat diubah.');
        }
        
        // Update logic will be implemented in submission service or here
        // For now, redirect to index or back
        return redirect()->route('pos.returns.index');
    }

    public function destroy(PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.delete'), 403);
        
        if ($return->status !== PosReturn::STATUS_PENDING_APPROVAL) {
            abort(403, 'Hanya retur yang masih menunggu persetujuan yang dapat dihapus.');
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($return) {
            // Releasing eligibility if needed (is_reversed is already handled by active() scope)
            // But deletion is a hard remove, so it naturally releases it.
            $return->saleReturns()->delete(); // Also delete linked sale returns
            $return->lines()->delete();
            $return->delete();
        });

        toast('Retur POS berhasil dihapus.', 'warning');
        
        return redirect()->route('pos.returns.index');
    }

    public function approve(PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.approve'), 403);

        try {
            $this->lifecycleService->approve($return->id);
            toast('Retur POS berhasil disetujui.', 'success');
        } catch (\Throwable $throwable) {
            report($throwable);
            toast($throwable->getMessage(), 'error');
        }

        return back();
    }

    public function reject(Request $request, PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.approve'), 403);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->lifecycleService->reject($return->id, $data['reason'] ?? null);
            toast('Retur POS berhasil ditolak.', 'warning');
        } catch (\Throwable $throwable) {
            report($throwable);
            toast($throwable->getMessage(), 'error');
        }

        return back();
    }

    public function receive(PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.receive'), 403);

        try {
            $this->lifecycleService->receive($return->id);
            toast('Retur POS berhasil diterima.', 'success');
        } catch (\Throwable $throwable) {
            report($throwable);
            toast($throwable->getMessage(), 'error');
        }

        return back();
    }

    public function settle(PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.settle'), 403);

        if ($return->return_option !== PosReturn::OPTION_CASH_RETURN) {
            toast('Penyelesaian tunai hanya tersedia untuk retur dengan opsi kembali uang.', 'error');
            return back();
        }

        try {
            $this->lifecycleService->settlePaymentReturn($return->id);
            toast('Retur POS berhasil diselesaikan dengan pengembalian tunai.', 'success');
        } catch (\Throwable $throwable) {
            report($throwable);
            toast($throwable->getMessage(), 'error');
        }

        return back();
    }

    public function dispatch(PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.dispatch'), 403);

        if ($return->return_option !== PosReturn::OPTION_PRODUCT_REPLACEMENT) {
            toast('Pengiriman pengganti hanya tersedia untuk retur dengan opsi ganti produk.', 'error');
            return back();
        }

        try {
            $this->lifecycleService->dispatchReplacement($return->id);
            toast('Pengiriman pengganti berhasil diproses.', 'success');
        } catch (\Throwable $throwable) {
            report($throwable);
            toast($throwable->getMessage(), 'error');
        }

        return back();
    }

    public function archive(Request $request, PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.delete'), 403);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->lifecycleService->archive($return->id, $data['reason'] ?? null);
            toast('Retur POS berhasil diarsipkan.', 'warning');
        } catch (\Throwable $throwable) {
            report($throwable);
            toast($throwable->getMessage(), 'error');
        }

        return back();
    }

    public function cancel(Request $request, PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.delete'), 403);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->lifecycleService->cancel($return->id, $data['reason'] ?? null);
            toast('Retur POS berhasil dibatalkan.', 'warning');
        } catch (\Throwable $throwable) {
            report($throwable);
            toast($throwable->getMessage(), 'error');
        }

        return back();
    }
}
