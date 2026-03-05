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
use Modules\Pos\Services\PosReceiptService;
use Modules\People\Entities\Customer;

class PosSellController extends Controller
{
    public function index(Request $request): Renderable
    {
        $activeSession = $request->attributes->get('pos_active_session');

        if (! $activeSession instanceof PosSession) {
            abort(403, 'Active POS session context is required.');
        }

        $activeSession->loadMissing([
            'terminal:id,code,name',
        ]);

        return view('pos::sell', [
            'activeSession' => $activeSession,
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

        $settingId = $this->currentSettingId();

        $payload = $searchService->search(
            $settingId,
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

    public function cartShow(Request $request, PosCartService $cartService): JsonResponse
    {
        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId($request);

        return response()->json([
            'cart_snapshot' => $cartService->getSnapshot($settingId, $sessionId),
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
                (int) ($request->input('qty', 1))
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
                $request->validated()
            );
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
            $snapshot = $cartService->removeLine($settingId, $sessionId, $lineId);
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
                (string) $request->input('supervisor_identifier'),
                (string) $request->input('supervisor_pin')
            );
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
        $snapshot = $cartService->clear($settingId, $sessionId);

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

    public function checkoutFinalize(
        StorePosCheckoutFinalizeRequest $request,
        FinalizePosCheckoutService $finalizeService
    ): JsonResponse {
        $settingId = $this->currentSettingId();
        $activeSession = $request->attributes->get('pos_active_session');
        $user = $request->user();

        if (! $activeSession instanceof PosSession) {
            abort(403, 'Active POS session context is required.');
        }

        if (! $user) {
            abort(403, 'Authentication is required.');
        }

        try {
            $result = $finalizeService->finalize(
                $settingId,
                $activeSession,
                (int) $user->id,
                (string) $request->input('idempotency_key'),
                is_array($request->input('payment')) ? $request->input('payment') : [],
                $request->input('client_context')
            );
        } catch (PosCheckoutValidationException $exception) {
            return response()->json([
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ], 422);
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
}
