<?php

namespace Modules\Pos\Http\Controllers;

use DomainException;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Http\Requests\StorePosCartLineRequest;
use Modules\Pos\Http\Requests\StorePosCartPriceOverrideRequest;
use Modules\Pos\Http\Requests\StorePosCheckoutFinalizeRequest;
use Modules\Pos\Http\Requests\StorePosCartSerialAssignmentRequest;
use Modules\Pos\Http\Requests\UpdatePosCartCustomerRequest;
use Modules\Pos\Http\Requests\UpdatePosCartLineRequest;
use Modules\Pos\Services\FinalizePosCheckoutService;
use Modules\Pos\Services\PosCartService;
use Modules\Pos\Services\PosCustomerSearchService;
use Modules\Pos\Services\PosProductSearchService;
use Modules\Pos\Services\Exceptions\PosCheckoutConflictException;
use Modules\Pos\Services\Exceptions\PosCheckoutPostingException;
use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;
use Modules\Pos\Services\Exceptions\PosCartMutationException;
use Modules\Pos\Services\PosReceiptService;
use Modules\Pos\Services\PosPaymentMethodSearchService;
use Modules\Pos\Services\PosRolePolicyService;
use Modules\People\Entities\Customer;

class PosSellController extends Controller
{
    public function index(Request $request, PosRolePolicyService $rolePolicyService): Renderable
    {
        $activeSession = $request->attributes->get('pos_active_session');

        if (! $activeSession instanceof PosSession) {
            abort(403, 'Active POS session context is required.');
        }

        $activeSession->loadMissing([
            'terminal:id,code,name',
        ]);

        $user = $request->user();
        if (! $user) {
            abort(403, 'Authentication is required.');
        }

        return view('pos::sell', [
            'activeSession' => $activeSession,
            'roleCapabilities' => $rolePolicyService->capabilityFlags($user),
        ]);
    }

    public function search(Request $request, PosProductSearchService $searchService): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $settingId = (int) session('setting_id');
        abort_if($settingId <= 0, 403, 'Setting context is required.');

        $payload = $searchService->search(
            $settingId,
            (string) $validated['q'],
            (int) ($validated['limit'] ?? 10)
        );

        return response()->json($payload);
    }

    public function customerSearch(Request $request, PosCustomerSearchService $searchService): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $payload = $searchService->search(
            (string) $validated['q'],
            (int) ($validated['limit'] ?? 10)
        );

        return response()->json($payload);
    }

    public function customerStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'tier' => ['nullable', 'in:WHOLESALER,RESELLER'],
        ]);

        $settingId = $this->currentSettingId();
        
        $uniqId = uniqid();
        $email = "noemail-{$uniqId}@placeholder.local";
        $phone = !empty($validated['customer_phone']) ? $validated['customer_phone'] : "nophone-{$uniqId}";

        $customer = Customer::create([
            'setting_id' => $settingId,
            'customer_name' => $validated['customer_name'],
            'contact_name' => '',
            'customer_email' => $email,
            'customer_phone' => $phone,
            'address' => '',
            'city' => '',
            'country' => '',
            'payment_term_id' => null,
            'tier' => $validated['tier'] ?? null,
        ]);

        $displayName = $customer->contact_name
            ? $customer->contact_name . ' - ' . $customer->customer_name
            : $customer->customer_name;

        return response()->json([
            'id' => (int) $customer->id,
            'customer_name' => (string) $customer->customer_name,
            'contact_name' => $customer->contact_name !== '' ? (string) $customer->contact_name : null,
            'customer_phone' => ($customer->customer_phone !== '' && strpos($customer->customer_phone, 'nophone-') !== 0) ? (string) $customer->customer_phone : null,
            'display_name' => $displayName,
        ]);
    }

    public function paymentMethodSearch(Request $request, PosPaymentMethodSearchService $searchService): JsonResponse
    {
        if ($denied = $this->ensureCheckoutPermission($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $settingId = $this->currentSettingId();

        $methods = $searchService->search(
            $settingId,
            ($validated['q'] ?? null)
        );

        return response()->json([
            'methods' => $methods,
        ]);
    }

    public function cartShow(Request $request, PosCartService $cartService): JsonResponse
    {
        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId($request);

        try {
            $snapshot = $cartService->getSnapshot($settingId, $sessionId);
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'cart_snapshot' => $snapshot,
        ]);
    }

    public function cartStoreLine(
        StorePosCartLineRequest $request,
        PosCartService $cartService
    ): JsonResponse {
        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId($request);

        try {
            $snapshot = $cartService->addLine(
                $settingId,
                $sessionId,
                (int) $request->input('product_id'),
                (int) ($request->input('qty', 1)),
                $request->input('conversion_id') !== null ? (int) $request->input('conversion_id') : null
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(['cart_snapshot' => $snapshot]);
    }

    public function cartUpdateLine(
        int $lineId,
        UpdatePosCartLineRequest $request,
        PosCartService $cartService
    ): JsonResponse {
        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId($request);

        try {
            $snapshot = $cartService->updateLine(
                $settingId,
                $sessionId,
                $lineId,
                $request->validated(),
                $request->input('approval_token'),
                $request->user()
            );
        } catch (PosCartMutationException $exception) {
            return response()->json([
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ], $exception->httpStatus());
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(['cart_snapshot' => $snapshot]);
    }

    public function cartDestroyLine(
        int $lineId,
        Request $request,
        PosCartService $cartService
    ): JsonResponse {
        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId($request);

        try {
            $snapshot = $cartService->removeLine(
                $settingId,
                $sessionId,
                $lineId,
                $request->input('approval_token'),
                $request->user()
            );
        } catch (PosCartMutationException $exception) {
            return response()->json([
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ], $exception->httpStatus());
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(['cart_snapshot' => $snapshot]);
    }

    public function cartUpdateDiscount(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Diskon tidak tersedia di POS kasir.',
        ], 422);
    }

    public function cartOverridePrice(
        int $lineId,
        StorePosCartPriceOverrideRequest $request,
        PosCartService $cartService
    ): JsonResponse {
        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId($request);
        $requestedBy = (int) ($request->user()?->id ?? 0);

        if ($requestedBy <= 0) {
            abort(403, 'Authentication is required.');
        }

        try {
            $snapshot = $cartService->overrideLinePrice(
                $settingId,
                $sessionId,
                $requestedBy,
                $lineId,
                (float) $request->input('unit_price'),
                $request->input('approval_token'),
                $request->user()
            );
        } catch (PosCartMutationException $exception) {
            return response()->json([
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ], $exception->httpStatus());
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(['cart_snapshot' => $snapshot]);
    }

    public function cartClear(Request $request, PosCartService $cartService): JsonResponse
    {
        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId($request);

        try {
            $snapshot = $cartService->clear(
                $settingId,
                $sessionId,
                $request->input('approval_token'),
                $request->user()
            );
        } catch (PosCartMutationException $exception) {
            return response()->json([
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ], $exception->httpStatus());
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(['cart_snapshot' => $snapshot]);
    }

    public function cartUpdateCustomer(
        UpdatePosCartCustomerRequest $request,
        PosCartService $cartService
    ): JsonResponse {
        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId($request);
        $customerId = $request->filled('customer_id') ? (int) $request->input('customer_id') : null;

        try {
            $snapshot = $cartService->updateCustomerSelection(
                $settingId,
                $sessionId,
                $customerId
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(['cart_snapshot' => $snapshot]);
    }

    public function cartAssignSerials(
        int $lineId,
        StorePosCartSerialAssignmentRequest $request,
        PosCartService $cartService
    ): JsonResponse {
        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId($request);

        try {
            $snapshot = $cartService->assignSerials(
                $settingId,
                $sessionId,
                $lineId,
                (array) $request->input('serial_numbers')
            );
        } catch (PosCheckoutValidationException $exception) {
            return response()->json([
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ], 422);
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(['cart_snapshot' => $snapshot]);
    }

    public function serialSearch(Request $request, PosCartService $cartService): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $settingId = $this->currentSettingId();

        $serials = $cartService->availableSerialsForProduct(
            $settingId,
            (int) $validated['product_id'],
            (string) ($validated['q'] ?? ''),
            (int) ($validated['limit'] ?? 20)
        );

        return response()->json(['serials' => $serials]);
    }

    public function cartAppendSerial(
        int $lineId,
        Request $request,
        PosCartService $cartService
    ): JsonResponse {
        $validated = $request->validate([
            'serial_number' => ['required', 'string', 'max:255'],
        ]);

        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId($request);

        try {
            $snapshot = $cartService->appendSerial(
                $settingId,
                $sessionId,
                $lineId,
                (string) $validated['serial_number']
            );
        } catch (PosCheckoutValidationException $exception) {
            return response()->json([
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ], 422);
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(['cart_snapshot' => $snapshot]);
    }

    public function cartRemoveSerial(
        int $lineId,
        string $serial,
        Request $request,
        PosCartService $cartService
    ): JsonResponse {
        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId($request);

        try {
            $snapshot = $cartService->removeSerial(
                $settingId,
                $sessionId,
                $lineId,
                $serial
            );
        } catch (PosCheckoutValidationException $exception) {
            return response()->json([
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ], 422);
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(['cart_snapshot' => $snapshot]);
    }

    public function scanResolve(Request $request, \Modules\Pos\Services\PosScanResolverService $scanService): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:255'],
        ]);

        $settingId = $this->currentSettingId();

        $result = $scanService->resolve(
            $settingId,
            (string) $validated['q']
        );

        return response()->json($result);
    }

    public function stagePayment(Request $request): JsonResponse
    {
        if ($denied = $this->ensureCheckoutPermission($request)) {
            return $denied;
        }

        $settingId = $this->currentSettingId();
        $activeSession = $request->attributes->get('pos_active_session');

        if (! $activeSession instanceof PosSession) {
            abort(403, 'Active POS session context is required.');
        }

        $validated = $request->validate([
            'cart_token' => ['required', 'string', 'uuid'],
            'payment_method_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'edc_reference' => ['nullable', 'string', 'max:255'],
            'grand_total' => ['required', 'numeric', 'min:0.01'],
        ]);

        $cartToken = $validated['cart_token'];
        $paymentMethodId = (int) $validated['payment_method_id'];
        $amount = (float) $validated['amount'];
        $edcReference = $validated['edc_reference'] ?? null;
        $grandTotal = (float) $validated['grand_total'];

        try {
            $sessionKey = "payment_chain_{$cartToken}";

            // Initialize chain if not exists
            if (! $request->session()->has($sessionKey)) {
                $request->session()->put($sessionKey, [
                    'payments' => [],
                    'remainder' => $grandTotal,
                    'staged_at' => now()->toIso8601String(),
                ]);
            }

            $chain = $request->session()->get($sessionKey);
            $remainder = (float) ($chain['remainder'] ?? 0);

            // Get payment method to check if cash (cash can overpay, non-cash cannot)
            $paymentMethod = \Modules\Setting\Entities\PaymentMethod::find($paymentMethodId);
            if (! $paymentMethod) {
                return response()->json([
                    'code' => 'INVALID_PAYMENT_METHOD',
                    'message' => 'Payment method not found.',
                ], 422);
            }

            // Validate amount based on payment method type
            // Non-cash: amount cannot exceed remainder (no overpayment)
            // Cash: amount can exceed remainder (overpayment allowed)
            if (! $paymentMethod->is_cash && $amount > $remainder) {
                return response()->json([
                    'code' => 'AMOUNT_EXCEEDS_REMAINDER',
                    'message' => "Non-cash payment cannot exceed remaining balance of {$remainder}.",
                ], 422);
            }

            // Add payment to chain
            $chain['payments'][] = [
                'method_id' => $paymentMethodId,
                'amount' => $amount,
                'edc_reference' => $edcReference,
                'stage_order' => count($chain['payments']) + 1,
                'created_at' => now()->toIso8601String(),
            ];

            // Update remainder
            $chain['remainder'] = round($remainder - $amount, 2);
            $request->session()->put($sessionKey, $chain);

            return response()->json([
                'payment_chain' => $chain,
                'remainder' => $chain['remainder'],
            ], 201);
        } catch (DomainException $exception) {
            return response()->json([
                'code' => 'STAGE_PAYMENT_ERROR',
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function getPaymentChain(Request $request): JsonResponse
    {
        if ($denied = $this->ensureCheckoutPermission($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'cart_token' => ['required', 'string', 'uuid'],
        ]);

        $cartToken = $validated['cart_token'];
        $sessionKey = "payment_chain_{$cartToken}";

        $chain = $request->session()->get($sessionKey);

        if (! $chain) {
            return response()->json([
                'has_chain' => false,
                'payment_chain' => null,
            ]);
        }

        return response()->json([
            'has_chain' => true,
            'payment_chain' => $chain,
        ]);
    }

    public function resetPaymentChain(Request $request): JsonResponse
    {
        if ($denied = $this->ensureCheckoutPermission($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'cart_token' => ['required', 'string', 'uuid'],
        ]);

        $cartToken = $validated['cart_token'];
        $sessionKey = "payment_chain_{$cartToken}";

        $request->session()->forget($sessionKey);

        return response()->json([
            'message' => 'Payment chain cleared.',
        ], 200);
    }

    public function checkoutFinalize(
        StorePosCheckoutFinalizeRequest $request,
        FinalizePosCheckoutService $finalizeService,
        PosRolePolicyService $rolePolicyService
    ): JsonResponse {
        if ($denied = $this->ensureCheckoutPermission($request)) {
            return $denied;
        }

        $settingId = $this->currentSettingId();
        $activeSession = $request->attributes->get('pos_active_session');
        $user = $request->user();

        if (! $activeSession instanceof PosSession) {
            abort(403, 'Active POS session context is required.');
        }

        if (! $user) {
            abort(403, 'Authentication is required.');
        }

        if (! $rolePolicyService->canCheckout($user)) {
            return response()->json([
                'code' => 'CHECKOUT_PERMISSION_REQUIRED',
                'message' => 'Anda tidak memiliki izin untuk menyelesaikan pembayaran POS.',
            ], 403);
        }

        try {
            // Check for pre-committed multi-stage payments in session (keyed by cart_token)
            // Support both legacy single-payment ('payment') and multi-payment ('payments') paths
            $paymentPayload = [];
            if (is_array($request->input('payment'))) {
                $paymentPayload = $request->input('payment');
            } elseif (is_array($request->input('payments'))) {
                $paymentPayload = ['payments' => $request->input('payments')];
            }
            $cartToken = (string) $request->input('cart_token', '');

            // If no payments in request, check if there's a staged payment chain in session
            if (empty($paymentPayload) && ! empty($cartToken)) {
                $sessionKey = "payment_chain_{$cartToken}";
                $sessionPaymentChain = $request->session()->get($sessionKey);

                if ($sessionPaymentChain && !empty($sessionPaymentChain['payments'])) {
                    // Map session payment chain fields from method_id/amount to payment_method_id/amount_paid
                    $mappedPayments = [];
                    foreach ($sessionPaymentChain['payments'] as $payment) {
                        $mappedPayments[] = [
                            'payment_method_id' => $payment['method_id'] ?? null,
                            'amount_paid' => $payment['amount'] ?? 0,
                            'reference' => $payment['edc_reference'] ?? null,
                            'stage_order' => $payment['stage_order'] ?? null,
                        ];
                    }

                    $paymentPayload = [
                        'payments' => $mappedPayments,
                    ];
                }
            }

            $result = $finalizeService->finalize(
                $settingId,
                $activeSession,
                (int) $user->id,
                (string) $request->input('idempotency_key'),
                $paymentPayload,
                $request->input('client_context')
            );
        } catch (PosCheckoutValidationException $exception) {
            $payload = [
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ];

            $details = $exception->details();
            if ($details !== []) {
                $payload['details'] = $details;
            }

            return response()->json($payload, 422);
        } catch (PosCheckoutConflictException $exception) {
            return response()->json([
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ], 409);
        } catch (PosCheckoutPostingException $exception) {
            return response()->json([
                'code' => $exception->errorCode(),
                'message' => 'Checkout posting failed due to an internal error.',
            ], 500);
        }

        // Clear session payment chain after successful finalization
        if ((int) $result['http_status'] === 201 && ! empty($cartToken)) {
            $sessionKey = "payment_chain_{$cartToken}";
            $request->session()->forget($sessionKey);
        }

        return response()->json($result['payload'], (int) $result['http_status'], [], JSON_PRESERVE_ZERO_FRACTION);
    }

    public function receiptView(PosCheckout $checkout, PosReceiptService $receiptService)
    {
        $settingId = $this->currentSettingId();
        
        if ($checkout->setting_id !== $settingId) {
            abort(403, 'Unauthorized access to receipt.');
        }

        $receiptData = $receiptService->getReceiptData($checkout);
        $receiptService->logPrint($settingId, $checkout->id, auth()->id(), 'PRINT');

        return view('pos::receipt', compact('receiptData'));
    }

    public function receiptReprint(PosCheckout $checkout, PosReceiptService $receiptService)
    {
        $settingId = $this->currentSettingId();
        
        if ($checkout->setting_id !== $settingId) {
            abort(403, 'Unauthorized access to receipt.');
        }

        $receiptData = $receiptService->getReceiptData($checkout);
        $receiptService->logPrint($settingId, $checkout->id, auth()->id(), 'REPRINT');

        return view('pos::receipt', compact('receiptData'));
    }

    private function currentSettingId(): int
    {
        $settingId = (int) session('setting_id');

        abort_if($settingId <= 0, 403, 'Setting context is required.');

        return $settingId;
    }

    private function activeSessionId(Request $request): int
    {
        $sessionId = (int) $request->attributes->get('pos_session_id');

        if ($sessionId > 0) {
            return $sessionId;
        }

        $activeSession = $request->attributes->get('pos_active_session');

        if ($activeSession instanceof PosSession) {
            return (int) $activeSession->id;
        }

        abort(403, 'Active POS session context is required.');
    }

    private function ensureCheckoutPermission(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'code' => 'UNAUTHENTICATED',
                'message' => 'Authentication is required.',
            ], 403);
        }

        if (! $user->can('pos.checkout.payment')) {
            return response()->json([
                'code' => 'CHECKOUT_PERMISSION_REQUIRED',
                'message' => 'Anda tidak memiliki izin untuk mengakses alur pembayaran POS.',
            ], 403);
        }

        return null;
    }
}
