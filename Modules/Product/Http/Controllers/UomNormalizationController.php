<?php

namespace Modules\Product\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Services\UomNormalizationEligibilityService;
use Modules\Purchase\Services\UomNormalizationExecutionService;
use Modules\Setting\Entities\Unit;

class UomNormalizationController extends Controller
{
    public function __construct(
        private UomNormalizationEligibilityService $eligibilityService,
        private UomNormalizationExecutionService $executionService,
    ) {
    }

    /**
     * Show the UOM normalization form for a product.
     */
    public function edit(Product $product)
    {
        $this->authorizeProduct($product);

        $product->loadMissing(['baseUnit', 'unit']);

        return view('product::uom-normalization.edit', [
            'product' => $product,
        ]);
    }

    /**
     * Server-backed Unit catalog search, excluding the product's current base unit.
     */
    public function searchUnits(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($product);

        $query = trim((string) $request->get('query', ''));
        $limit = min((int) $request->get('limit', 20), 50);
        $excludeUnitId = $request->get('exclude_unit_id', $product->base_unit_id);

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
     * Candidate purchase/receipt lines for the route Product across the active setting.
     */
    public function candidateLines(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($product);

        $settingId = session('setting_id');

        $lines = PurchaseDetail::where('product_id', $product->id)
            ->whereHas('purchase', function ($q) use ($settingId) {
                $q->where('setting_id', $settingId)
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
    public function preview(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($product);

        $request->validate([
            'target_unit_id' => 'required|integer|exists:units,id',
            'factor' => 'required|numeric|min:0.000001',
            'purchase_detail_ids' => 'required|array|min:1',
            'purchase_detail_ids.*' => 'integer|exists:purchase_details,id',
        ]);

        $targetUnit = Unit::findOrFail($request->target_unit_id);
        $factor = (float) $request->factor;
        $purchaseDetailIds = collect($request->purchase_detail_ids);
        $settingId = session('setting_id');

        $preview = $this->eligibilityService->generatePreview(
            $product,
            $targetUnit,
            $factor,
            $purchaseDetailIds,
            $settingId,
        );

        return response()->json([
            'success' => true,
            'preview' => $preview,
        ]);
    }

    /**
     * Execute the normalization batch.
     */
    public function store(Request $request, Product $product): JsonResponse|RedirectResponse
    {
        $this->authorizeProduct($product);

        $request->validate([
            'target_unit_id' => 'required|integer|exists:units,id',
            'factor' => 'required|numeric|min:0.000001',
            'purchase_detail_ids' => 'required|array|min:1',
            'purchase_detail_ids.*' => 'integer|exists:purchase_details,id',
            'reason' => 'required|string|min:3|max:500',
            'is_acknowledged' => 'required|accepted',
            'is_sales_price_warning_acknowledged' => 'required|accepted',
        ]);

        $targetUnit = Unit::findOrFail($request->target_unit_id);
        $factor = (float) $request->factor;
        $purchaseDetailIds = collect($request->purchase_detail_ids);
        $settingId = session('setting_id');

        try {
            $result = $this->executionService->execute(
                $product,
                $targetUnit,
                $factor,
                $purchaseDetailIds,
                $request->user(),
                $settingId,
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
            return redirect()->route('products.show', $product->id);
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

    private function authorizeProduct(Product $product): void
    {
        Gate::authorize('uomNormalize', $product);
    }
}
