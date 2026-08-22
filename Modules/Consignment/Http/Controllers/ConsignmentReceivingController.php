<?php

namespace Modules\Consignment\Http\Controllers;

use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Services\ConsignmentReceivingService;
use Modules\Setting\Entities\Location;

class ConsignmentReceivingController extends Controller
{
    protected ConsignmentReceivingService $receivingService;

    public function __construct(ConsignmentReceivingService $receivingService)
    {
        $this->receivingService = $receivingService;
    }

    public function index(Request $request)
    {
        abort_if(Gate::denies('consignments.access'), 403);
        $settingId = (int) session('setting_id');

        $query = ConsignmentReceiving::with(['receival.supplier', 'location', 'receiver', 'approver'])
            ->where('setting_id', $settingId)
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        $receivings = $query->paginate(20)->withQueryString();
        $locations = Location::where('setting_id', $settingId)->consignment()->get();

        return view('consignment::receivings.index', compact('receivings', 'locations'));
    }

    public function create(Request $request, int $receivalId)
    {
        abort_if(Gate::denies('consignments.receive'), 403);
        $settingId = (int) session('setting_id');

        $receival = ConsignmentReceival::with(['supplier', 'lines.product', 'lines.tax', 'lines.unit'])
            ->where('setting_id', $settingId)
            ->findOrFail($receivalId);

        if (!$receival->isApproved()) {
            toast('Penerimaan fisik hanya dapat dibuat untuk dokumen yang sudah disetujui.', 'error');
            return redirect()->route('consignments.receivals.show', $receival->id);
        }

        if ($receival->activeReceiving) {
            toast('Dokumen ini sudah memiliki penerimaan fisik aktif.', 'error');
            return redirect()->route('consignments.receivals.show', $receival->id);
        }

        $locations = Location::where('setting_id', $settingId)->consignment()->get();

        return view('consignment::receivings.create', compact('receival', 'locations'));
    }

    public function store(Request $request, int $receivalId): RedirectResponse
    {
        abort_if(Gate::denies('consignments.receive'), 403);
        $settingId = (int) session('setting_id');

        $receival = ConsignmentReceival::with('lines')
            ->where('setting_id', $settingId)
            ->findOrFail($receivalId);

        $request->validate([
            'location_id' => 'required|integer|exists:locations,id',
            'date' => 'required|date',
            'external_delivery_number' => 'nullable|string|max:100',
            'note' => 'nullable|string',
            'details' => 'required|array',
            'details.*.quantity_received' => 'required|numeric|min:0.001',
            'details.*.serial_numbers' => 'nullable',
            'details.*.notes' => 'nullable|string|max:255',
        ]);

        try {
            $receiving = $this->receivingService->createPendingReceiving(
                $receival,
                $request->all(),
                auth()->id()
            );

            toast('Penerimaan fisik berhasil dicatat dan berstatus PENDING.', 'success');
            return redirect()->route('consignments.receivings.show', $receiving->id);
        } catch (Exception $e) {
            return redirect()->back()->withInput()->withErrors(['details' => $e->getMessage()]);
        }
    }

    public function show(int $id)
    {
        abort_if(Gate::denies('consignments.access'), 403);
        $settingId = (int) session('setting_id');

        $receiving = ConsignmentReceiving::with([
            'receival.supplier',
            'location',
            'receiver',
            'approver',
            'rejecter',
            'reverser',
            'details.product',
            'details.receivalLine',
            'details.serialNumbers',
            'details.transaction',
            'details.reversalTransaction',
        ])->where('setting_id', $settingId)->findOrFail($id);

        $reversalPreview = null;
        if ($receiving->isApproved()) {
            $reversalPreview = $this->receivingService->previewReversal($receiving);
        }

        return view('consignment::receivings.show', compact('receiving', 'reversalPreview'));
    }

    public function approve(int $id): RedirectResponse
    {
        abort_if(Gate::denies('consignments.receive.approve'), 403);
        $settingId = (int) session('setting_id');

        $receiving = ConsignmentReceiving::where('setting_id', $settingId)->findOrFail($id);

        try {
            $this->receivingService->approveReceiving($receiving, auth()->id());
            toast('Penerimaan fisik konsinyasi berhasil disetujui. Stok dan HPP rata-rata telah diperbarui.', 'success');
        } catch (Exception $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('consignments.receivings.show', $receiving->id);
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        abort_if(Gate::denies('consignments.receive.reject'), 403);
        $settingId = (int) session('setting_id');

        $receiving = ConsignmentReceiving::where('setting_id', $settingId)->findOrFail($id);

        $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        try {
            $this->receivingService->rejectPendingReceiving($receiving, auth()->id(), $request->input('rejection_reason'));
            toast('Penerimaan fisik konsinyasi telah ditolak.', 'info');
        } catch (Exception $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('consignments.receivings.show', $receiving->id);
    }

    public function reverse(Request $request, int $id): RedirectResponse
    {
        abort_if(Gate::denies('consignments.reverse'), 403);
        $settingId = (int) session('setting_id');

        $receiving = ConsignmentReceiving::where('setting_id', $settingId)->findOrFail($id);

        $request->validate([
            'reversal_reason' => 'required|string|max:500',
        ]);

        try {
            $this->receivingService->reverseReceiving($receiving, auth()->id(), $request->input('reversal_reason'));
            toast('Penerimaan fisik konsinyasi berhasil dibatalkan (reversal). Stok telah dipulihkan.', 'warning');
        } catch (Exception $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('consignments.receivings.show', $receiving->id);
    }
}
