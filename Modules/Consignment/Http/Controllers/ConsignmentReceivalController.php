<?php

namespace Modules\Consignment\Http\Controllers;

use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Services\ConsignmentReceivalLifecycleService;
use Modules\Consignment\Services\ConsignmentReceivalService;
use Modules\Consignment\Services\ConsignmentReferenceService;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;

class ConsignmentReceivalController extends Controller
{
    protected ConsignmentReceivalService $receivalService;
    protected ConsignmentReceivalLifecycleService $lifecycleService;

    public function __construct(
        ConsignmentReceivalService $receivalService,
        ConsignmentReceivalLifecycleService $lifecycleService
    ) {
        $this->receivalService = $receivalService;
        $this->lifecycleService = $lifecycleService;
    }

    public function index(Request $request)
    {
        abort_if(Gate::denies('consignments.access'), 403);
        $settingId = (int) session('setting_id');

        $query = ConsignmentReceival::with(['supplier', 'lines', 'creator', 'activeReceiving'])
            ->where('setting_id', $settingId)
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        $receivals = $query->paginate(20)->withQueryString();
        // Suppliers are shared master data: not scoped by setting.
        $suppliers = Supplier::orderBy('supplier_name')->get();

        return view('consignment::receivals.index', compact('receivals', 'suppliers'));
    }

    public function create()
    {
        abort_if(Gate::denies('consignments.create'), 403);
        $settingId = (int) session('setting_id');
        $setting = Setting::findOrFail($settingId);
        $taxes = Tax::orderBy('name')->get();
        return view('consignment::receivals.create', compact('setting', 'taxes'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('consignments.create'), 403);
        $settingId = (int) session('setting_id');
        $setting = Setting::findOrFail($settingId);

        $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'date' => 'required|date',
            'supplier_delivery_reference' => 'nullable|string|max:100',
            'note' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer|exists:products,id',
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.unit_cost' => 'required|numeric|min:0.01',
            'lines.*.tax_id' => 'nullable|integer|exists:taxes,id',
            'lines.*.notes' => 'nullable|string|max:255',
        ]);

        try {
            $normalizedLines = $this->receivalService->normalizeLines($setting, $request->input('lines', []));

            $receival = DB::transaction(function () use ($request, $setting, $normalizedLines) {
                $receival = ConsignmentReferenceService::createReceivalWithReference([
                    'setting_id' => $setting->id,
                    'supplier_id' => $request->supplier_id,
                    'date' => $request->date,
                    'supplier_delivery_reference' => $request->supplier_delivery_reference,
                    'note' => $request->note,
                    'status' => ConsignmentReceival::STATUS_DRAFT,
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                ]);

                foreach ($normalizedLines as $lineData) {
                    $lineData['consignment_receival_id'] = $receival->id;
                    ConsignmentReceivalLine::create($lineData);
                }

                return $receival;
            });

            toast('Draft penerimaan konsinyasi berhasil dibuat.', 'success');
            return redirect()->route('consignments.receivals.show', $receival->id);
        } catch (Exception $e) {
            return redirect()->back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }
    }

    public function show(int $id)
    {
        abort_if(Gate::denies('consignments.access'), 403);
        $settingId = (int) session('setting_id');

        $receival = ConsignmentReceival::with([
            'supplier',
            'lines.product',
            'lines.tax',
            'creator',
            'submitter',
            'approver',
            'rejecter',
            'receivings.location',
            'receivings.receiver',
            'receivings.approver',
            'receivings.rejecter',
            'receivings.details.product',
            'receivings.details.serialNumbers',
        ])->where('setting_id', $settingId)->findOrFail($id);

        return view('consignment::receivals.show', compact('receival'));
    }

    public function edit(int $id)
    {
        abort_if(Gate::denies('consignments.edit'), 403);
        $settingId = (int) session('setting_id');

        $receival = ConsignmentReceival::with(['supplier', 'lines.product'])->where('setting_id', $settingId)->findOrFail($id);

        if (!$receival->canBeEdited()) {
            toast('Dokumen tidak dapat diubah karena statusnya ' . $receival->status, 'error');
            return redirect()->route('consignments.receivals.show', $receival->id);
        }

        $setting = Setting::findOrFail($settingId);
        $taxes = Tax::orderBy('name')->get();
        return view('consignment::receivals.edit', compact('receival', 'setting', 'taxes'));
    }

    public function searchSuppliers(Request $request): JsonResponse
    {
        abort_if(Gate::denies('consignments.create') && Gate::denies('consignments.edit'), 403);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $term = $validated['q'];
        // Suppliers are shared master data: searchable across settings, active only.
        $suppliers = Supplier::query()
            ->where('is_active', true)
            ->where(function ($query) use ($term) {
                $query->where('supplier_name', 'like', "%{$term}%")
                    ->orWhere('contact_name', 'like', "%{$term}%");
            })
            ->orderBy('supplier_name')
            ->simplePaginate(20, ['id', 'supplier_name', 'contact_name']);

        return response()->json([
            'results' => $suppliers->getCollection()->map(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'text' => $supplier->contact_name
                    ? "{$supplier->supplier_name} - {$supplier->contact_name}"
                    : $supplier->supplier_name,
            ])->values(),
            'pagination' => ['more' => $suppliers->hasMorePages()],
        ]);
    }

    public function searchProducts(Request $request): JsonResponse
    {
        abort_if(Gate::denies('consignments.create') && Gate::denies('consignments.edit'), 403);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $settingId = (int) session('setting_id');
        $products = Product::query()
            ->active()
            ->where('stock_managed', true)
            ->whereDoesntHave('bundles', fn ($query) => $query->where('setting_id', $settingId))
            ->globalSearch($validated['q'])
            ->orderBy('product_name')
            ->simplePaginate(20, ['products.id', 'products.product_name', 'products.product_code', 'products.serial_number_required']);

        return response()->json([
            'results' => $products->getCollection()->map(fn (Product $product) => [
                'id' => $product->id,
                'text' => trim($product->product_name . ($product->product_code ? " ({$product->product_code})" : '')),
                'serialized' => (bool) $product->serial_number_required,
            ])->values(),
            'pagination' => ['more' => $products->hasMorePages()],
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        abort_if(Gate::denies('consignments.edit'), 403);
        $settingId = (int) session('setting_id');
        $setting = Setting::findOrFail($settingId);

        $receival = ConsignmentReceival::where('setting_id', $settingId)->findOrFail($id);

        $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'date' => 'required|date',
            'supplier_delivery_reference' => 'nullable|string|max:100',
            'note' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer|exists:products,id',
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.unit_cost' => 'required|numeric|min:0.01',
            'lines.*.tax_id' => 'nullable|integer|exists:taxes,id',
            'lines.*.notes' => 'nullable|string|max:255',
        ]);

        try {
            $normalizedLines = $this->receivalService->normalizeLines($setting, $request->input('lines', []));

            $this->lifecycleService->update(
                $receival,
                $request->only(['supplier_id', 'date', 'supplier_delivery_reference', 'note']),
                $normalizedLines,
                auth()->id()
            );

            toast('Dokumen penerimaan konsinyasi berhasil diperbarui.', 'success');
            return redirect()->route('consignments.receivals.show', $receival->id);
        } catch (Exception $e) {
            return redirect()->back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }
    }

    public function destroy(int $id): RedirectResponse
    {
        abort_if(Gate::denies('consignments.delete'), 403);
        $settingId = (int) session('setting_id');

        $receival = ConsignmentReceival::where('setting_id', $settingId)->findOrFail($id);

        try {
            $this->lifecycleService->delete($receival);
            toast('Draft penerimaan konsinyasi berhasil dihapus.', 'info');
            return redirect()->route('consignments.receivals.index');
        } catch (Exception $e) {
            toast($e->getMessage(), 'error');
            return redirect()->route('consignments.receivals.show', $receival->id);
        }
    }

    public function submit(int $id): RedirectResponse
    {
        abort_if(Gate::denies('consignments.submit'), 403);
        $settingId = (int) session('setting_id');
        $receival = ConsignmentReceival::where('setting_id', $settingId)->findOrFail($id);

        try {
            $this->lifecycleService->submit($receival, auth()->id());
            toast('Dokumen konsinyasi berhasil diajukan untuk persetujuan.', 'success');
        } catch (Exception $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('consignments.receivals.show', $receival->id);
    }

    public function approve(int $id): RedirectResponse
    {
        abort_if(Gate::denies('consignments.approve'), 403);
        $settingId = (int) session('setting_id');
        $receival = ConsignmentReceival::where('setting_id', $settingId)->findOrFail($id);

        try {
            $this->lifecycleService->approve($receival, auth()->id());
            toast('Dokumen konsinyasi berhasil disetujui.', 'success');
        } catch (Exception $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('consignments.receivals.show', $receival->id);
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        abort_if(Gate::denies('consignments.reject'), 403);
        $settingId = (int) session('setting_id');
        $receival = ConsignmentReceival::where('setting_id', $settingId)->findOrFail($id);

        $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        try {
            $this->lifecycleService->reject($receival, auth()->id(), $request->input('rejection_reason'));
            toast('Dokumen konsinyasi telah ditolak.', 'info');
        } catch (Exception $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('consignments.receivals.show', $receival->id);
    }
}
