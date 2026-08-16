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
use Modules\Product\Entities\Product;
use App\Support\ProductBundleResolver;

class PosSellController extends Controller
{
    public function index(Request $request, PosRolePolicyService $rolePolicyService): Renderable
    {
        $activeSession = $request->attributes->get('pos_active_session');

        if (! $activeSession instanceof PosSession) {
            abort(403, 'Konteks sesi POS yang aktif diperlukan.');
        }

        $activeSession->loadMissing([
            'terminal:id,code,name',
        ]);

        $user = $request->user();
        if (! $user) {
            abort(403, 'Otentikasi diperlukan.');
        }

        return view('pos::sell', [
            'activeSession' => $activeSession,
            'roleCapabilities' => $rolePolicyService->capabilityFlags($user, $activeSession),
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

    public function productBundles(Product $product): JsonResponse
    {
        $settingId = $this->currentSettingId();

        $bundles = ProductBundleResolver::forProduct((int) $product->id, $settingId);

        return response()->json([
            'bundles' => $bundles->map(function ($bundle) {
                return [
                    'id' => $bundle->id,
                    'name' => $bundle->name,
                    'price' => (float) ($bundle->bundle_sale_price ?? 0),
                    'legacy_price' => (float) ($bundle->price ?? 0),
                    'items' => $bundle->items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'product_id' => $item->product_id,
                            'name' => $item->product->product_name,
                            'quantity' => (float) $item->quantity,
                            'stock_managed' => (bool) $item->product->stock_managed,
                            'serial_number_required' => (bool) $item->product->serial_number_required,
                        ];
                    }),
                ];
            }),
        ]);
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
            'contact_name' => null,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'address' => '',
            'city' => '',
            'country' => '',
            'payment_term_id' => null,
            'tier' => $validated['tier'] ?? null,
        ]);

        return response()->json([
            'id' => (int) $customer->id,
            'customer_name' => (string) $customer->customer_name,
            'contact_name' => null,
            'customer_phone' => ($customer->customer_phone !== '' && strpos($customer->customer_phone, 'nophone-') !== 0) ? (string) $customer->customer_phone : null,
            'display_name' => $customer->display_name,
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

    public function paymentTermsSearch(Request $request): JsonResponse
    {
        if ($denied = $this->ensureCheckoutPermission($request)) {
            return $denied;
        }

        $terms = \Modules\Purchase\Entities\PaymentTerm::query()
            ->orderBy('longevity', 'asc')
            ->get(['id', 'name', 'longevity']);

        return response()->json([
            'terms' => $terms,
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
                $request->input('conversion_id') !== null ? (int) $request->input('conversion_id') : null,
                $request->input('bundle_id') !== null ? (int) $request->input('bundle_id') : null
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

    /**
     * Retired: the ambiguous unit-price endpoint whose payload could mean
     * either a unit price or a row total. Superseded by the explicit
     * `cartOverrideLineUnitPrice` contract; kept non-mutating so an old client
     * cannot bypass the new validation.
     */
    public function cartOverridePrice(
        int $lineId,
        StorePosCartPriceOverrideRequest $request,
        PosCartService $cartService
    ): JsonResponse {
        return response()->json([
            'status' => 'retired',
            'message' => 'Endpoint ini telah digantikan. Gunakan ubah harga satuan atau ubah total baris.',
            'code' => 'FEATURE_RETIRED',
        ], 422);
    }

    /**
     * Apply or request a unit-price override for one cart row.
     */
    public function cartOverrideLineUnitPrice(
        int $lineId,
        \Modules\Pos\Http\Requests\StorePosCartLineUnitPriceOverrideRequest $request,
        PosCartService $cartService
    ): JsonResponse {
        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId($request);
        $requestedBy = (int) ($request->user()?->id ?? 0);

        if ($requestedBy <= 0) {
            abort(403, 'Authentication is required.');
        }

        try {
            $snapshot = $cartService->overrideLineUnitPrice(
                $settingId,
                $sessionId,
                $requestedBy,
                $lineId,
                $request->input('unit_price', 0),
                $request->input('reason'),
                $request->input('approval_token'),
                $request->user()
            );
        } catch (PosCartMutationException $exception) {
            return response()->json([
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ], $exception->httpStatus());
        } catch (\Modules\Pos\Services\Exceptions\PosCartCompensationFailedException $exception) {
            return response()->json([
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ], $exception->httpStatus());
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'cart_snapshot' => $snapshot,
        ]);
    }

    public function cartOverrideLineTotal(
        int $lineId,
        \Modules\Pos\Http\Requests\StorePosCartLineTotalOverrideRequest $request,
        PosCartService $cartService
    ): JsonResponse {
        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId($request);
        $requestedBy = (int) ($request->user()?->id ?? 0);

        if ($requestedBy <= 0) {
            abort(403, 'Authentication is required.');
        }

        try {
            $snapshot = $cartService->overrideLineTotal(
                $settingId,
                $sessionId,
                $requestedBy,
                $lineId,
                $request->input('line_total', 0),
                $request->input('reason'),
                $request->input('approval_token'),
                $request->user()
            );
        } catch (PosCartMutationException $exception) {
            return response()->json([
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ], $exception->httpStatus());
        } catch (\Modules\Pos\Services\Exceptions\PosCartCompensationFailedException $exception) {
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

    public function cartUpdateNote(
        \Modules\Pos\Http\Requests\UpdatePosCartNoteRequest $request,
        PosCartService $cartService
    ): JsonResponse {
        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId($request);
        $note = $request->input('note');

        try {
            $snapshot = $cartService->updateNote(
                $settingId,
                $sessionId,
                $note
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
        $bundleItemId = $request->filled('bundle_item_id') ? (int) $request->input('bundle_item_id') : null;

        try {
            $snapshot = $cartService->assignSerials(
                $settingId,
                $sessionId,
                $lineId,
                (array) $request->input('serial_numbers'),
                $bundleItemId
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
            'bundle_item_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId($request);
        $bundleItemId = isset($validated['bundle_item_id']) ? (int) $validated['bundle_item_id'] : null;

        try {
            $snapshot = $cartService->appendSerial(
                $settingId,
                $sessionId,
                $lineId,
                (string) $validated['serial_number'],
                $bundleItemId
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
        $validated = $request->validate([
            'bundle_item_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId($request);
        $bundleItemId = isset($validated['bundle_item_id']) ? (int) $validated['bundle_item_id'] : null;

        try {
            $snapshot = $cartService->removeSerial(
                $settingId,
                $sessionId,
                $lineId,
                $serial,
                $bundleItemId
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

    public function stagePayment(Request $request, \Modules\Pos\Services\PosTemporaryPaymentImageService $imageService): JsonResponse
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
            'is_debt' => ['nullable', 'boolean'],
            'payment_term_id' => ['nullable', 'integer', 'required_if:is_debt,true'],
            'payment_image_token' => ['nullable', 'string', 'max:255'],
        ]);

        $cartToken = $validated['cart_token'];
        $paymentMethodId = (int) $validated['payment_method_id'];
        $amount = (float) $validated['amount'];
        $edcReference = $validated['edc_reference'] ?? null;
        $grandTotal = (float) $validated['grand_total'];
        $paymentImageToken = $validated['payment_image_token'] ?? null;

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

            $isDebt = (bool)($validated['is_debt'] ?? false);

            // Validate amount based on payment method type
            // Non-cash: amount cannot exceed remainder (no overpayment)
            if (! $paymentMethod->is_cash && $amount > $remainder) {
                return response()->json([
                    'code' => 'AMOUNT_EXCEEDS_REMAINDER',
                    'message' => "Non-cash payment cannot exceed remaining balance of {$remainder}.",
                ], 422);
            }

            // Cash: amount cannot be less than remainder (must cover full balance) - UNLESS it is a debt payment
            if ($paymentMethod->is_cash && $amount < $remainder && !$isDebt) {
                return response()->json([
                    'code' => 'CASH_UNDERPAYMENT',
                    'message' => "Cash payment must be at least {$remainder}.",
                ], 422);
            }

            // Image token validation
            $imageMeta = null;
            if ($paymentImageToken) {
                if ($paymentMethod->is_cash) {
                    return response()->json([
                        'code' => 'CASH_NO_IMAGE_ALLOWED',
                        'message' => 'Bukti pembayaran tidak diperbolehkan untuk metode tunai.',
                    ], 422);
                }

                $image = $imageService->getActiveImage(
                    $paymentImageToken,
                    $settingId,
                    (int) $activeSession->id,
                    (int) $request->user()->id,
                    $cartToken
                );
                if (!$image) {
                    return response()->json([
                        'code' => 'INVALID_IMAGE_TOKEN',
                        'message' => 'Bukti pembayaran tidak valid atau sudah kedaluwarsa.',
                    ], 422);
                }

                $imageMeta = [
                    'token' => $image->token,
                    'original_name' => $image->original_name,
                    'size' => $image->size,
                ];
            }

            // Add payment to chain
            $chain['payments'][] = [
                'method_id' => $paymentMethodId,
                'amount' => $amount,
                'edc_reference' => $edcReference,
                'payment_image' => $imageMeta,
                'stage_order' => count($chain['payments']) + 1,
                'created_at' => now()->toIso8601String(),
            ];

            // Update remainder
            $chain['remainder'] = round($remainder - $amount, 2);
            $chain['is_debt'] = $isDebt;
            $chain['payment_term_id'] = $validated['payment_term_id'] ?? null;
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

        if ($chain) {
            return response()->json([
                'has_chain' => true,
                'payment_chain' => $chain,
            ]);
        }

        return response()->json([
            'has_chain' => false,
            'payment_chain' => null,
        ]);
    }

    public function syncDebtState(Request $request): JsonResponse
    {
        if ($denied = $this->ensureCheckoutPermission($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'cart_token' => ['required', 'string', 'uuid'],
            'grand_total' => ['required', 'numeric', 'min:0.01'],
            'is_debt' => ['required', 'boolean'],
            'payment_term_id' => ['nullable', 'integer'],
        ]);

        $cartToken = $validated['cart_token'];
        $sessionKey = "payment_chain_{$cartToken}";

        // Initialize chain if not exists
        if (! $request->session()->has($sessionKey)) {
            $request->session()->put($sessionKey, [
                'payments' => [],
                'remainder' => (float)$validated['grand_total'],
                'staged_at' => now()->toIso8601String(),
            ]);
        }

        $chain = $request->session()->get($sessionKey);
        $chain['is_debt'] = $validated['is_debt'];
        $chain['payment_term_id'] = $validated['payment_term_id'] ?? null;
        $request->session()->put($sessionKey, $chain);

        return response()->json(['success' => true]);
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

        $activeSession = $request->attributes->get('pos_active_session');
        if (! $activeSession instanceof PosSession) {
            abort(403, 'Active POS session context is required.');
        }

        app(\Modules\Pos\Services\PosTemporaryPaymentImageService::class)->deleteAllByCartToken(
            $cartToken,
            $this->currentSettingId(),
            (int) $activeSession->id
        );

        return response()->json([
            'message' => 'Payment chain cleared.',
        ], 200);
    }

    public function checkoutPreflight(
        Request $request,
        FinalizePosCheckoutService $finalizeService
    ): JsonResponse {
        if ($denied = $this->ensureCheckoutPermission($request)) {
            return $denied;
        }

        $settingId = $this->currentSettingId();
        $activeSession = $request->attributes->get('pos_active_session');

        if (! $activeSession instanceof PosSession) {
            abort(403, 'Active POS session context is required.');
        }

        try {
            $acknowledge = (bool) $request->input('acknowledge_lifecycle_warning', false);
            $result = $finalizeService->preflight($settingId, $activeSession, $acknowledge);
            return response()->json($result);
        } catch (PosCheckoutValidationException $exception) {
            $payload = [
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ];

            $details = $exception->details();
            if ($details !== []) {
                $payload['details'] = $details;
                if (isset($details['warning'])) {
                    $payload['warning'] = $details['warning'];
                }
            }

            return response()->json($payload, 422);
        }
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

            $paymentPayload['is_debt'] = $request->boolean('is_debt');
            $paymentPayload['payment_term_id'] = $request->input('payment_term_id');
            $paymentPayload['approval_token'] = $request->input('approval_token');
            $paymentPayload['acknowledge_lifecycle_warning'] = $request->boolean('acknowledge_lifecycle_warning');

            // If no payments in request, check if there's a staged payment chain in session
            if (empty($request->input('payment')) && empty($request->input('payments')) && ! empty($cartToken)) {
                $sessionKey = "payment_chain_{$cartToken}";
                $sessionPaymentChain = $request->session()->get($sessionKey);

                if ($sessionPaymentChain) {
                    if (!empty($sessionPaymentChain['payments'])) {
                        // Map session payment chain fields from method_id/amount to payment_method_id/amount_paid
                        $mappedPayments = [];
                        foreach ($sessionPaymentChain['payments'] as $payment) {
                            $mappedPayments[] = [
                                'payment_method_id' => $payment['method_id'] ?? null,
                                'amount_paid' => $payment['amount'] ?? 0,
                                'reference' => $payment['edc_reference'] ?? null,
                                'stage_order' => $payment['stage_order'] ?? null,
                                'payment_image_token' => $payment['payment_image']['token'] ?? null,
                            ];
                        }

                        $paymentPayload['payments'] = $mappedPayments;
                    }

                    if (!empty($sessionPaymentChain['is_debt'])) {
                        $paymentPayload['is_debt'] = true;
                        if (!empty($sessionPaymentChain['payment_term_id'])) {
                            $paymentPayload['payment_term_id'] = $sessionPaymentChain['payment_term_id'];
                        }
                    }
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
        } catch (DomainException $exception) {
            return response()->json([
                'code' => $exception->getMessage(),
                'message' => $exception->getMessage(),
            ], 422);
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
            abort(403, 'Akses ke struk tidak sah.');
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

        $activeSession = $request->attributes->get('pos_active_session');

        if (! $activeSession instanceof PosSession) {
            return response()->json([
                'code' => 'ACTIVE_SESSION_REQUIRED',
                'message' => 'Konteks sesi POS aktif diperlukan.',
            ], 403);
        }

        /** @var PosRolePolicyService $rolePolicyService */
        $rolePolicyService = app(PosRolePolicyService::class);

        if (! $rolePolicyService->canCheckout($user, $activeSession)) {
            return response()->json([
                'code' => 'CHECKOUT_TERMINAL_REQUIRED',
                'message' => 'Sesi kasir harus terhubung ke terminal sebelum membuka pembayaran POS.',
            ], 403);
        }

        return null;
    }
}
