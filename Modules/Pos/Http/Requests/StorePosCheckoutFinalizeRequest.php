<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Services\PosRolePolicyService;

class StorePosCheckoutFinalizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $activeSession = $this->attributes->get('pos_active_session');

        if (! $user || ! $activeSession instanceof PosSession) {
            return false;
        }

        return app(PosRolePolicyService::class)->canCheckout($user, $activeSession);
    }

    protected function failedAuthorization(): void
    {
        $user = $this->user();
        $activeSession = $this->attributes->get('pos_active_session');

        if (! $user) {
            throw new HttpResponseException(response()->json([
                'code' => 'UNAUTHENTICATED',
                'message' => 'Authentication is required.',
            ], 403));
        }

        if (! $user->can('pos.checkout.payment')) {
            throw new HttpResponseException(response()->json([
                'code' => 'CHECKOUT_PERMISSION_REQUIRED',
                'message' => 'Anda tidak memiliki izin untuk mengakses alur pembayaran POS.',
            ], 403));
        }

        if (! $activeSession instanceof PosSession) {
            throw new HttpResponseException(response()->json([
                'code' => 'ACTIVE_SESSION_REQUIRED',
                'message' => 'Konteks sesi POS aktif diperlukan.',
            ], 403));
        }

        throw new HttpResponseException(response()->json([
            'code' => 'CHECKOUT_TERMINAL_REQUIRED',
            'message' => 'Sesi kasir harus terhubung ke terminal sebelum membuka pembayaran POS.',
        ], 403));
    }

    public function rules(): array
    {
        $legacyPayment = $this->input('payment');
        $multiPayments = $this->input('payments');
        $cartToken = $this->input('cart_token');
        $hasLegacyPayment = $legacyPayment !== null && is_array($legacyPayment);
        $hasMultiPayments = $multiPayments !== null && is_array($multiPayments);
        $hasCartToken = ! empty($cartToken);

        $isDebt = (bool) $this->input('is_debt', false);

        $rules = [
            'idempotency_key' => ['required', 'string', 'max:100'],
            'cart_token' => ['nullable', 'string', 'uuid'],
            'client_context' => ['nullable', 'array'],
            'is_debt' => ['nullable', 'boolean'],
            'payment_term_id' => ['nullable', 'integer', 'exists:payment_terms,id'],
            'approval_token' => ['nullable', 'string'],
            'acknowledge_lifecycle_warning' => ['nullable', 'boolean'],
        ];

        // If cart_token is provided, we'll fetch payments from session, so payment fields are optional
        if ($hasCartToken) {
            return $rules;
        }

        $minAmountRule = $isDebt ? 'gte:0' : 'gt:0';
        $paymentMethodRule = $isDebt ? 'nullable' : 'required';

        // Support legacy single-payment path
        if ($hasLegacyPayment && ! $hasMultiPayments) {
            $rules['payment'] = ['required', 'array'];
            $rules['payment.payment_method_id'] = [$paymentMethodRule, 'integer', 'exists:payment_methods,id'];
            $rules['payment.amount_paid'] = ['required', 'numeric', $minAmountRule];
            $rules['payment.reference'] = ['nullable', 'string', 'max:255'];
        }
        // Support new multi-payment path
        elseif ($hasMultiPayments && ! $hasLegacyPayment) {
            $rules['payments'] = ['required', 'array', 'min:1'];
            $rules['payments.*.payment_method_id'] = [$paymentMethodRule, 'integer', 'exists:payment_methods,id'];
            $rules['payments.*.amount_paid'] = ['required', 'numeric', $minAmountRule];
            $rules['payments.*.reference'] = ['nullable', 'string', 'max:255'];
        } else {
            // Must provide exactly one: either payment or payments[]
            $rules['payment'] = ['required_without:payments', 'array'];
            $rules['payment.payment_method_id'] = [$paymentMethodRule, 'integer', 'exists:payment_methods,id'];
            $rules['payment.amount_paid'] = ['required', 'numeric', $minAmountRule];
            $rules['payment.reference'] = ['nullable', 'string', 'max:255'];
            $rules['payments'] = ['required_without:payment', 'array'];
        }

        return $rules;
    }
}
