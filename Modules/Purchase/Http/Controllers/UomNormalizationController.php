<?php

namespace Modules\Purchase\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\UomNormalizationBatch;
use Modules\Setting\Entities\Unit;
use Modules\Purchase\Services\UomNormalizationEligibilityService;
use Modules\Purchase\Services\UomNormalizationExecutionService;

class UomNormalizationController extends Controller
{
    public function __construct(
        private UomNormalizationEligibilityService $eligibilityService,
        private UomNormalizationExecutionService $executionService,
    ) {
    }

    /**
     * Show the UOM normalization form for a purchase.
     */
    public function edit(Purchase $purchase)
    {
        $this->authorizePurchase($purchase);

        // Optional preselect hint: the purchase's own products, for initial
        // display convenience only. The searchable product/unit dropdowns
        // hit dedicated search endpoints and are NOT limited to this list.
        $purchase->load(['purchaseDetails.product.baseUnit']);

        $preselectProducts = $purchase->purchaseDetails
            ->pluck('product')
            ->unique('id')
            ->filter(fn ($p) => $p && $p->stock_managed && !$p->merged_into_id)
            ->map(fn ($p) => [
                'id' => $p->id,
                'product_name' => $p->product_name,
                'product_code' => $p->product_code,
                'display_name' => $p->product_name . ' (' . $p->product_code . ')',
                'base_unit_id' => $p->base_unit_id,
                'base_unit_name' => optional($p->baseUnit)->name,
            ])
            ->values();

        return view('purchase::uom-normalization.edit', [
            'purchase' => $purchase,
            'preselectProducts' => $preselectProducts,
        ]);
    }

    /**
     * Server-backed product search scoped to the active setting. Not limited
     * to this Purchase's products — any stock-managed product with purchase
     * history in the setting is discoverable.
     */
    public function searchProducts(Request $request, Purchase $purchase): JsonResponse
    {
        $this->authorizePurchase($purchase);

        $query = trim((string) $request->get('query', ''));
        $limit = min((int) $request->get('limit', 20), 50);

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $products = Product::query()
            ->where('stock_managed', true)
            ->whereNull('merged_into_id')
            ->where(function ($q) use ($query) {
                $q->where('product_name', 'like', "%{$query}%")
                  ->orWhere('product_code', 'like', "%{$query}%")
                  ->orWhere('barcode', 'like', "%{$query}%");
            })
            ->whereHas('purchaseDetails.purchase', function ($q) use ($purchase) {
                $q->where('setting_id', $purchase->setting_id);
            })
            ->with('baseUnit')
            ->limit($limit)
            ->get();

        return response()->json($products->map(fn ($p) => [
            'id' => $p->id,
            'product_name' => $p->product_name,
            'product_code' => $p->product_code,
            'display_name' => $p->product_name . ' (' . $p->product_code . ')',
            'base_unit_id' => $p->base_unit_id,
            'base_unit_name' => optional($p->baseUnit)->name,
        ])->values());
    }

    /**
     * Server-backed Unit catalog search, excluding the product's current
     * base unit.
     */
    public function searchUnits(Request $request, Purchase $purchase): JsonResponse
    {
        $this->authorizePurchase($purchase);

        $query = trim((string) $request->get('query', ''));
        $limit = min((int) $request->get('limit', 20), 50);
        $excludeUnitId = $request->get('exclude_unit_id');

        if (strlen($query) < 1) {
            return response()->json([]);
        }

        $units = Unit::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('short_name', 'like', "%{$query}%");
            })
            ->when($excludeUnitId, fn ($q) => $q->where('id', '!=', $excludeUnitId))
            ->limit($limit)
            ->get(['id', 'name', 'short_name']);

        return response()->json($units->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'short_name' => $u->short_name,
            'display_name' => $u->name,
        ])->values());
    }

    /**
     * Candidate purchase/receipt lines for a single selected product, scoped
     * to the active setting and non-voided purchases. Fetched on demand
     * rather than rendering every product's lines up front.
     */
    public function candidateLines(Request $request, Purchase $purchase): JsonResponse
    {
        $this->authorizePurchase($purchase);

        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
        ]);

        $lines = \Modules\Purchase\Entities\PurchaseDetail::where('product_id', $request->product_id)
            ->whereHas('purchase', function ($q) use ($purchase) {
                $q->where('setting_id', $purchase->setting_id)
                  ->whereNotIn('status', ['VOID', 'CANCELLED']);
            })
            ->with(['purchase', 'receivedNoteDetails.receivedNote'])
            ->get();

        return response()->json($lines->map(function ($detail) {
            $received = $detail->receivedNoteDetails
                ->filter(fn ($rnd) => $rnd->receivedNote && $rnd->receivedNote->status === 'APPROVED')
                ->sum('quantity_received');

            return [
                'id' => $detail->id,
                'product_id' => $detail->product_id,
                'purchase_reference' => optional($detail->purchase)->reference,
                'product_name' => $detail->product_name,
                'product_code' => $detail->product_code,
                'quantity' => (float) $detail->quantity,
                'received_quantity' => (float) $received,
                'is_complete' => $received >= (float) $detail->quantity,
                'unit_price' => (float) $detail->unit_price,
                'sub_total' => (float) $detail->sub_total,
            ];
        })->values());
    }

    /**
     * Generate a preview for the normalization batch.
     */
    public function preview(Request $request, Purchase $purchase): JsonResponse
    {
        $this->authorizePurchase($purchase);

        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'target_unit_id' => 'required|integer|exists:units,id',
            'factor' => 'required|numeric|min:0.000001',
            'purchase_detail_ids' => 'required|array|min:1',
            'purchase_detail_ids.*' => 'integer|exists:purchase_details,id',
        ]);

        $product = Product::findOrFail($request->product_id);
        $targetUnit = Unit::findOrFail($request->target_unit_id);
        $factor = (float) $request->factor;
        $purchaseDetailIds = collect($request->purchase_detail_ids);

        $preview = $this->eligibilityService->generatePreview(
            $product,
            $targetUnit,
            $factor,
            $purchaseDetailIds,
            $purchase->setting_id,
        );

        return response()->json([
            'success' => true,
            'preview' => $preview,
        ]);
    }

    /**
     * Execute the normalization batch.
     */
    public function store(Request $request, Purchase $purchase): JsonResponse|RedirectResponse
    {
        $this->authorizePurchase($purchase);

        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'target_unit_id' => 'required|integer|exists:units,id',
            'factor' => 'required|numeric|min:0.000001',
            'purchase_detail_ids' => 'required|array|min:1',
            'purchase_detail_ids.*' => 'integer|exists:purchase_details,id',
            'reason' => 'required|string|min:3|max:500',
            'is_acknowledged' => 'required|accepted',
            'is_sales_price_warning_acknowledged' => 'required|accepted',
        ]);

        $product = Product::findOrFail($request->product_id);
        $targetUnit = Unit::findOrFail($request->target_unit_id);
        $factor = (float) $request->factor;
        $purchaseDetailIds = collect($request->purchase_detail_ids);

        try {
            $result = $this->executionService->execute(
                $product,
                $targetUnit,
                $factor,
                $purchaseDetailIds,
                $request->user(),
                $purchase->setting_id,
                $request->reason,
                (bool) $request->is_acknowledged,
                (bool) $request->is_sales_price_warning_acknowledged,
            );

            if (!$result['success']) {
                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => $result['error'],
                    ], 422);
                }

                toast($result['error'], 'error');
                return redirect()->back();
            }

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Normalisasi UOM berhasil dijalankan.',
                    'batch_id' => $result['batch']->id,
                ]);
            }

            toast('Normalisasi UOM berhasil dijalankan.', 'success');
            return redirect()->route('purchases.show', $purchase->id);
        } catch (\RuntimeException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            toast($e->getMessage(), 'error');
            return redirect()->back();
        }
    }

    /**
     * Show normalization audit history for a purchase.
     */
    public function history(Purchase $purchase): JsonResponse
    {
        $this->authorizePurchase($purchase);

        $batches = UomNormalizationBatch::whereHas('lines', function ($q) use ($purchase) {
            $q->whereHas('purchaseDetail', fn ($q2) => $q2->where('purchase_id', $purchase->id));
        })
        ->with(['lines', 'actor', 'product', 'sourceUnit', 'baseUnit'])
        ->orderByDesc('created_at')
        ->get();

        return response()->json([
            'success' => true,
            'batches' => $batches,
        ]);
    }

    private function authorizePurchase(Purchase $purchase): void
    {
        Gate::authorize('uomNormalize', $purchase);
    }
}
