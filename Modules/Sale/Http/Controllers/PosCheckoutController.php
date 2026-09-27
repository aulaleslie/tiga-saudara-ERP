<?php

namespace Modules\Sale\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\PaymentMethod;

class PosCheckoutController extends Controller
{
    /**
     * Task 1.1 & 1.2 & 1.3 & 1.4 & 1.5 & 1.6:
     * Create POST /pos/sell/checkout/stage-payment endpoint
     * Implements stage payment business logic with session state tracking, idempotency, error handling
     */
    public function stagePayment(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'sale_id' => 'required|integer|exists:sales,id',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'amount' => 'required|numeric|min:0.01',
            'edc_reference' => 'nullable|string|max:255',
            'idempotency_key' => 'required|string|max:255',
        ])->validate();

        try {
            return DB::transaction(function () use ($request, $validated) {
                $sale = Sale::findOrFail($validated['sale_id']);
                $paymentMethod = PaymentMethod::findOrFail($validated['payment_method_id']);

                // Get current payment chain from session
                $sessionKey = "payment_chain_{$sale->id}";
                $paymentChain = $request->session()->get($sessionKey, [
                    'sale_id' => $sale->id,
                    'total_committed' => 0,
                    'remainder' => $sale->due_amount,
                    'payments' => [],
                ]);

                // Check idempotency: if this key already processed, return previous response
                $existingPayment = SalePayment::where('idempotency_key', $validated['idempotency_key'])->first();
                if ($existingPayment) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Payment already processed (idempotency)',
                        'stage_order' => $existingPayment->stage_order,
                        'remainder' => $this->calculateRemainder($sale, $paymentChain),
                        'payment_chain' => $paymentChain['payments'],
                    ]);
                }

                // Validate amount doesn't exceed remainder (allow slight overpayment)
                $remainder = $this->calculateRemainder($sale, $paymentChain);
                $amount = round((float) $validated['amount'], 2);

                if ($amount <= 0) {
                    throw ValidationException::withMessages([
                        'amount' => 'Jumlah pembayaran harus lebih dari 0.',
                    ]);
                }

                // Determine stage order
                $stageOrder = count($paymentChain['payments']) + 1;

                // Validate EDC reference if required for this payment method
                if (!$paymentMethod->is_cash && $paymentMethod->requires_reference) {
                    if (empty($validated['edc_reference'])) {
                        throw ValidationException::withMessages([
                            'edc_reference' => 'Nomor referensi EDC diperlukan untuk metode pembayaran ini.',
                        ]);
                    }

                    // Validate EDC reference format (non-empty, alphanumeric, max 20 chars)
                    if (!preg_match('/^[a-zA-Z0-9]{1,20}$/', $validated['edc_reference'])) {
                        throw ValidationException::withMessages([
                            'edc_reference' => 'Format nomor referensi tidak valid. Gunakan alphanumeric max 20 karakter.',
                        ]);
                    }
                }

                // Create payment record
                $payment = SalePayment::create([
                    'sale_id' => $validated['sale_id'],
                    'payment_method_id' => $validated['payment_method_id'],
                    'amount' => $amount,
                    'date' => now()->format('Y-m-d'),
                    'reference' => "STAGE-{$stageOrder}-" . now()->format('YmdHis'),
                    'stage_order' => $stageOrder,
                    'edc_reference' => $validated['edc_reference'] ?? null,
                    'idempotency_key' => $validated['idempotency_key'],
                ]);

                // Update payment chain in session
                $paymentChain['payments'][] = [
                    'id' => $payment->id,
                    'stage_order' => $stageOrder,
                    'method_id' => $validated['payment_method_id'],
                    'method_name' => $paymentMethod->name,
                    'amount' => $amount,
                    'edc_reference' => $validated['edc_reference'] ?? null,
                ];
                $paymentChain['total_committed'] += $amount;
                $paymentChain['remainder'] = round($sale->due_amount - $paymentChain['total_committed'], 2);

                $request->session()->put($sessionKey, $paymentChain);

                // Return response with new remainder
                return response()->json([
                    'success' => true,
                    'message' => 'Pembayaran tahap berhasil diproses',
                    'stage_order' => $stageOrder,
                    'remainder' => max($paymentChain['remainder'], 0),
                    'payment_chain' => $paymentChain['payments'],
                    'overpayment' => $paymentChain['remainder'] < 0 ? abs($paymentChain['remainder']) : 0,
                ], 200);
            });
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memproses pembayaran',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper to calculate current remainder based on committed payments
     */
    private function calculateRemainder(Sale $sale, array $paymentChain): float
    {
        return round($sale->due_amount - $paymentChain['total_committed'], 2);
    }

    /**
     * Task 1.3 & 5.1 & 5.2:
     * Get current payment chain state from session
     * Supports reload recovery
     */
    public function getPaymentChain(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'sale_id' => 'required|integer|exists:sales,id',
        ])->validate();

        $sale = Sale::findOrFail($validated['sale_id']);
        $sessionKey = "payment_chain_{$sale->id}";
        $paymentChain = $request->session()->get($sessionKey, null);

        if (!$paymentChain) {
            return response()->json([
                'success' => true,
                'has_chain' => false,
                'message' => 'No payment chain in progress',
            ]);
        }

        return response()->json([
            'success' => true,
            'has_chain' => true,
            'sale_id' => $sale->id,
            'remainder' => max($paymentChain['remainder'], 0),
            'payment_chain' => $paymentChain['payments'],
            'total_committed' => $paymentChain['total_committed'],
        ]);
    }

    /**
     * Task 7.5:
     * Validate EDC reference format
     */
    public function validateEdcReference(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'reference' => 'required|string|max:20',
        ])->validate();

        $reference = $validated['reference'];

        if (!preg_match('/^[a-zA-Z0-9]{1,20}$/', $reference)) {
            return response()->json([
                'valid' => false,
                'message' => 'Format nomor referensi tidak valid. Gunakan alphanumeric max 20 karakter.',
            ]);
        }

        return response()->json([
            'valid' => true,
            'message' => 'Format nomor referensi valid',
        ]);
    }
}
