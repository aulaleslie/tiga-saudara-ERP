<?php

namespace Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Pos\Exceptions\PosException;
use Modules\Pos\Http\Requests\DeletePosPaymentImageRequest;
use Modules\Pos\Http\Requests\UploadPosPaymentImageRequest;
use Modules\Pos\Services\PosTemporaryPaymentImageService;

class PosPaymentImageController extends Controller
{
    public function __construct(
        private readonly PosTemporaryPaymentImageService $imageService
    ) {}

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
        } catch (PosException $e) {
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

        $deleted = $this->imageService->deleteImage(
            $token,
            $settingId,
            $sessionId,
            $cartToken
        );

        if (!$deleted) {
            return response()->json(['message' => 'Gambar tidak ditemukan atau tidak dapat dihapus.'], 404);
        }

        return response()->json(['message' => 'Gambar berhasil dihapus.']);
    }
}
