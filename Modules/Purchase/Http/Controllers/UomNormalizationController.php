<?php

namespace Modules\Purchase\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\UomNormalizationBatch;
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

        $purchase->load([
            'purchaseDetails.product.conversions.unit',
            'purchaseDetails.product.conversions.baseUnit',
            'purchaseDetails.product.baseUnit',
            'purchaseDetails.receivedNoteDetails.receivedNote',
            'purchaseDetails.tax',
        ]);

        // Get unique products from purchase details
        $products = $purchase->purchaseDetails
            ->pluck('product')
            ->unique('id')
            ->filter(fn ($p) => $p && $p->stock_managed && !$p->merged_into_id)
            ->values();

        return view('purchase::uom-normalization.edit', [
            'purchase' => $purchase,
            'products' => $products,
        ]);
    }

    /**
     * Generate a preview for the normalization batch.
     */
    public function preview(Request $request, Purchase $purchase): JsonResponse
    {
        $this->authorizePurchase($purchase);

        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'conversion_id' => 'required|integer|exists:product_unit_conversions,id',
            'purchase_detail_ids' => 'required|array|min:1',
            'purchase_detail_ids.*' => 'integer|exists:purchase_details,id',
        ]);

        $product = Product::findOrFail($request->product_id);
        $conversion = ProductUnitConversion::findOrFail($request->conversion_id);
        $purchaseDetailIds = collect($request->purchase_detail_ids);

        $preview = $this->eligibilityService->generatePreview(
            $product,
            $conversion,
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
            'conversion_id' => 'required|integer|exists:product_unit_conversions,id',
            'purchase_detail_ids' => 'required|array|min:1',
            'purchase_detail_ids.*' => 'integer|exists:purchase_details,id',
            'reason' => 'required|string|min:3|max:500',
        ]);

        $product = Product::findOrFail($request->product_id);
        $conversion = ProductUnitConversion::findOrFail($request->conversion_id);
        $purchaseDetailIds = collect($request->purchase_detail_ids);

        try {
            $result = $this->executionService->execute(
                $product,
                $conversion,
                $purchaseDetailIds,
                $request->user(),
                $purchase->setting_id,
                $request->reason,
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
