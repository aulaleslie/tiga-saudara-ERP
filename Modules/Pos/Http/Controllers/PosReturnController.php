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
        
        $return->load(['lines.product', 'posTransaction', 'posCheckout', 'saleReturns']);
        
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
        $this->lifecycleService->approve($return->id);
        return back();
    }

    public function reject(Request $request, PosReturn $return)
    {
        $this->lifecycleService->reject($return->id, $request->get('reason'));
        return back();
    }

    public function receive(PosReturn $return)
    {
        // TODO: Implement receive
    }

    public function settle(PosReturn $return)
    {
        // TODO: Implement settle
    }

    public function dispatch(PosReturn $return)
    {
        // TODO: Implement dispatch
    }
}
