<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Purchase\Services\PurchaseCorrectionService;
use Modules\Purchase\Services\PurchaseCostRecalculationService;
use Modules\Purchase\Services\PaymentCorrectionPreviewService;

class PurchaseCorrectionController extends Controller
{
    public function __construct(
        private PurchaseCorrectionService $correctionService,
        private PurchaseCostRecalculationService $recalculationService,
        private PaymentCorrectionPreviewService $paymentPreviewService,
    )
    {
    }

    public function edit(Purchase $purchase): View
    {
        $this->authorize('correct', $purchase);
        $this->guardReceiveStatusEligibility($purchase);

        return view('purchase::corrections.edit', [
            'purchase' => $purchase->load([
                'purchaseDetails',
                'purchasePayments',
                'corrections',
            ]),
        ]);
    }

    public function store(Purchase $purchase, Request $request): JsonResponse
    {
        $this->authorize('correct', $purchase);
        $this->guardReceiveStatusEligibility($purchase);

        $validated = $request->validate([
            'reason' => 'required|string|min:1',
            'line_corrections' => 'array',
            'line_corrections.*.detail_id' => 'required|integer',
            'line_corrections.*.unit_price' => 'nullable|numeric|min:0',
            'line_corrections.*.product_discount_amount' => 'nullable|numeric|min:0',
            'global_discount_amount' => 'nullable|numeric|min:0',
            'shipping_amount' => 'nullable|numeric|min:0',
            'selected_payment_id' => 'nullable|integer',
            'confirmation_token' => 'nullable|string',
        ]);

        // Count active payments to determine if token is required
        $activePaymentCount = PurchasePayment::where('purchase_id', $purchase->id)
            ->where('status', PurchasePayment::STATUS_ACTIVE)
            ->count();

        // For multiple active payments, require confirmation token
        $confirmationToken = $request->get('confirmation_token');
        if ($activePaymentCount > 1 && !$confirmationToken) {
            return response()->json([
                'error' => 'Confirmation token is required for purchases with multiple active payments',
            ], 422);
        }

        $result = $this->correctionService->correct(
            purchase: $purchase,
            actor: auth()->user(),
            reason: $validated['reason'],
            lineCorrections: $validated['line_corrections'] ?? [],
            globalDiscount: $validated['global_discount_amount'] ?? null,
            shipping: $validated['shipping_amount'] ?? null,
            selectedPaymentId: $validated['selected_payment_id'] ?? null,
            confirmationToken: $confirmationToken,
        );

        if (!$result['success']) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Koreksi penerimaan berhasil disimpan',
            'correction_id' => $result['correction_id'],
        ]);
    }

    public function previewPaymentCorrection(Purchase $purchase, Request $request): JsonResponse
    {
        $this->authorize('correct', $purchase);
        $this->guardReceiveStatusEligibility($purchase);

        $validated = $request->validate([
            'line_corrections' => 'array',
            'line_corrections.*.detail_id' => 'required|integer',
            'line_corrections.*.unit_price' => 'nullable|numeric|min:0',
            'line_corrections.*.product_discount_amount' => 'nullable|numeric|min:0',
            'global_discount_amount' => 'nullable|numeric|min:0',
            'shipping_amount' => 'nullable|numeric|min:0',
            'selected_payment_id' => 'nullable|integer',
        ]);

        $preview = $this->paymentPreviewService->previewPaymentCorrection(
            purchase: $purchase,
            lineCorrections: $validated['line_corrections'] ?? [],
            globalDiscount: $validated['global_discount_amount'] ?? null,
            shipping: $validated['shipping_amount'] ?? null,
            selectedPaymentId: $validated['selected_payment_id'] ?? null,
        );

        return response()->json($preview);
    }

    public function previewRecalculation(Purchase $purchase): JsonResponse
    {
        $this->authorize('correct', $purchase);
        $this->guardReceiveStatusEligibility($purchase);

        $preview = $this->recalculationService->previewRecalculation($purchase);

        return response()->json($preview);
    }

    public function executeRecalculation(Purchase $purchase, Request $request): JsonResponse
    {
        $this->authorize('correct', $purchase);
        $this->guardReceiveStatusEligibility($purchase);

        $validated = $request->validate([
            'include_downstream_hpp' => 'boolean',
        ]);

        $result = $this->recalculationService->executeRecalculation(
            purchase: $purchase,
            actor: auth()->user(),
            includeDownstreamHpp: $validated['include_downstream_hpp'] ?? false,
        );

        if (!$result['success']) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Rekalkulation biaya berhasil dijalankan',
            'result' => $result['result'],
        ]);
    }

    private function guardReceiveStatusEligibility(Purchase $purchase): void
    {
        \Modules\Purchase\Services\PurchaseSourceGuard::assertCommercialEditAllowed($purchase);

        if (!in_array($purchase->status, [Purchase::STATUS_RECEIVED, Purchase::STATUS_RECEIVED_PARTIALLY])) {
            throw new AuthorizationException('Purchase must be in RECEIVED or RECEIVED PARTIALLY status for correction');
        }
    }
}
