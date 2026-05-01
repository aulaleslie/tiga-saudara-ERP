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
        $this->submissionService->store($request->all());
        return redirect()->route('pos.returns.index');
    }

    public function show(PosReturn $return)
    {
        return view('pos::returns.show', compact('return'));
    }

    public function edit(PosReturn $return)
    {
        return view('pos::returns.edit', compact('return'));
    }

    public function update(Request $request, PosReturn $return)
    {
        // TODO: Implement update
    }

    public function destroy(PosReturn $return)
    {
        $return->delete();
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
