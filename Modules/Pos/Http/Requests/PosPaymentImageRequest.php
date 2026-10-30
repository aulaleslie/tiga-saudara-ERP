<?php

namespace Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Services\PosRolePolicyService;

abstract class PosPaymentImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $activeSession = $this->attributes->get('pos_active_session');

        if (! $user || ! $activeSession instanceof PosSession) {
            return false;
        }

        return $user->can('pos.sell')
            && app(PosRolePolicyService::class)->canCheckout($user, $activeSession);
    }

    protected function failedAuthorization(): void
    {
        $user = $this->user();
        $activeSession = $this->attributes->get('pos_active_session');
        $rolePolicy = app(PosRolePolicyService::class);

        if (! $user || ! $user->can('pos.sell') || ! $rolePolicy->hasCheckoutAuthority($user)) {
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
}

