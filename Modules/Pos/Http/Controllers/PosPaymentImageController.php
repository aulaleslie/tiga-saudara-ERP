<?php

namespace Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Pos\Http\Requests\DeletePosPaymentImageRequest;
use Modules\Pos\Http\Requests\UploadPosPaymentImageRequest;
use Modules\Pos\Services\PosRolePolicyService;
use Modules\Pos\Services\PosTemporaryPaymentImageService;

class PosPaymentImageController extends Controller
{
    public function __construct(
        private readonly PosTemporaryPaymentImageService $imageService
    ) {}

    private function ensureStagedChainFlowAccessible($request, string $cartToken): ?JsonResponse
    {
        $chain = $request->session()->get("payment_chain_{$cartToken}");
        if (! is_array($chain)) {
            return null;
        }

        /** @var PosRolePolicyService $rolePolicy */
        $rolePolicy = app(PosRolePolicyService::class);
        $chainFlowResolution = $rolePolicy->resolvePaymentChainFlow($chain);

        if ($chainFlowResolution['contradictory']) {
            return response()->json([
                'code' => 'FLOW_STATE_MISMATCH',
                'message' => $rolePolicy->flowStateMismatchMessage(),
            ], 422);
        }

        if (! $chainFlowResolution['has_explicit_flow']) {
            return null;
        }

        $flow = $chainFlowResolution['flow'];
        $user = $request->user();
        if ($user && $rolePolicy->hasFlowAuthority($user, $flow)) {
            return null;
        }

        return response()->json([
            'code' => 'CHECKOUT_FLOW_PERMISSION_REQUIRED',
            'missing_permission' => $rolePolicy->missingPermissionForFlow($flow),
            'message' => $rolePolicy->flowPermissionDeniedMessage($flow, true),
        ], 403);
    }

    protected function currentSettingId(): int
    {
        $settingId = (int) session('setting_id');
        if ($settingId <= 0) {
            abort(403, 'Setting context is required.');
        }
        return $settingId;
    }

    protected function activeSessionId(): ?int
    {
        $sessionId = (int) request()->attributes->get('pos_session_id');
        if ($sessionId > 0) {
            return $sessionId;
        }
        
        $activeSession = request()->attributes->get('pos_active_session');
        if ($activeSession && isset($activeSession->id)) {
            return (int) $activeSession->id;
        }

        return null;
    }

    public function upload(UploadPosPaymentImageRequest $request): JsonResponse
    {
        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId();
        $cashierId = Auth::id();
        $cartToken = $request->input('cart_token');

        if (!$sessionId) {
            return response()->json(['message' => 'Sesi POS tidak ditemukan.'], 403);
        }

        if ($denied = $this->ensureStagedChainFlowAccessible($request, (string) $cartToken)) {
            return $denied;
        }

        try {
            $image = $this->imageService->uploadImage(
                $request->file('image'),
                $settingId,
                $sessionId,
                $cashierId,
                $cartToken
            );

            return response()->json([
                'token' => $image->token,
                'original_name' => $image->original_name,
                'size' => $image->size,
                'expires_at' => $image->expires_at->toIso8601String(),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function delete(DeletePosPaymentImageRequest $request): JsonResponse
    {
        $settingId = $this->currentSettingId();
        $sessionId = $this->activeSessionId();
        $cartToken = $request->input('cart_token');
        $token = $request->input('token');

        if (!$sessionId) {
            return response()->json(['message' => 'Sesi POS tidak ditemukan.'], 403);
        }

        if ($denied = $this->ensureStagedChainFlowAccessible($request, (string) $cartToken)) {
            return $denied;
        }

        $sessionKey = "payment_chain_{$cartToken}";
        $chain = $request->session()->get($sessionKey);
        if ($chain && !empty($chain['payments'])) {
            foreach ($chain['payments'] as $payment) {
                $committedToken = is_array($payment['payment_image'] ?? null)
                    ? ($payment['payment_image']['token'] ?? null)
                    : ($payment['payment_image_token'] ?? null);

                if ($committedToken === $token) {
                    return response()->json([
                        'code' => 'PAYMENT_IMAGE_ALREADY_COMMITTED',
                        'message' => 'Bukti pembayaran sudah terikat pada transaksi pembayaran dan tidak dapat dihapus.',
                    ], 409);
                }
            }
        }

        $deleted = $this->imageService->deleteImage(
            $token,
            $settingId,
            $sessionId,
            (int) Auth::id(),
            $cartToken
        );

        if (!$deleted) {
            return response()->json(['message' => 'Gambar tidak ditemukan atau tidak dapat dihapus.'], 404);
        }

        return response()->json(['message' => 'Gambar berhasil dihapus.']);
    }
}
