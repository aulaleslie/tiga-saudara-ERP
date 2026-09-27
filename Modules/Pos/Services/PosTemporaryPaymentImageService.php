<?php

namespace Modules\Pos\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Pos\Entities\PosTemporaryPaymentImage;
use Illuminate\Validation\ValidationException;
use Modules\Pos\Services\Exceptions\PosCheckoutPostingException;
use Carbon\Carbon;

class PosTemporaryPaymentImageService
{
    /**
     * Upload and create a temporary payment image.
     */
    public function uploadImage(UploadedFile $file, int $settingId, int $sessionId, int $cashierId, string $cartToken): PosTemporaryPaymentImage
    {
        if (!in_array($file->getMimeType(), ['image/jpeg', 'image/png'])) {
            throw ValidationException::withMessages(['image' => 'Hanya gambar JPEG atau PNG yang diperbolehkan.']);
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['image' => 'Ukuran gambar tidak boleh melebihi 5 MB.']);
        }

        // Generate unique token for this upload
        $token = Str::random(40);
        $path = $file->storeAs('pos/temp_payment_images', $token . '.' . $file->getClientOriginalExtension());

        return PosTemporaryPaymentImage::create([
            'token' => $token,
            'setting_id' => $settingId,
            'pos_session_id' => $sessionId,
            'cashier_id' => $cashierId,
            'cart_token' => $cartToken,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'expires_at' => Carbon::now()->addHours(24),
        ]);
    }

    /**
     * Get an active image by token, ensuring scope matches the current session/cart.
     */
    public function getActiveImage(
        string $token,
        int $settingId,
        int $sessionId,
        int $cashierId,
        string $cartToken
    ): ?PosTemporaryPaymentImage
    {
        return PosTemporaryPaymentImage::active()
            ->where('token', $token)
            ->where('setting_id', $settingId)
            ->where('pos_session_id', $sessionId)
            ->where('cashier_id', $cashierId)
            ->where('cart_token', $cartToken)
            ->first();
    }

    /**
     * Resolve and validate an image token in the context of a checkout.
     * Used during finalization validation to ensure the token is valid and in scope.
     */
    public function resolveImage(
        string $token,
        int $settingId,
        int $sessionId,
        int $cashierId,
        string $cartToken
    ): ?PosTemporaryPaymentImage
    {
        return $this->getActiveImage($token, $settingId, $sessionId, $cashierId, $cartToken);
    }

    /**
     * Delete an active image.
     */
    public function deleteImage(
        string $token,
        int $settingId,
        int $sessionId,
        int $cashierId,
        string $cartToken
    ): bool
    {
        $image = $this->getActiveImage($token, $settingId, $sessionId, $cashierId, $cartToken);

        if (!$image) {
            return false;
        }

        if (Storage::exists($image->path)) {
            Storage::delete($image->path);
        }

        return $image->delete();
    }

    /**
     * Delete all active images for a given cart token.
     */
    public function deleteAllByCartToken(string $cartToken, int $settingId, int $sessionId): void
    {
        $images = PosTemporaryPaymentImage::active()
            ->where('cart_token', $cartToken)
            ->where('setting_id', $settingId)
            ->where('pos_session_id', $sessionId)
            ->get();

        foreach ($images as $image) {
            if (Storage::exists($image->path)) {
                Storage::delete($image->path);
            }
            $image->delete();
        }
    }

    /**
     * Consume the temporary image and attach it to the target model.
     */
    public function consumeAndAttach(string $token, $model): bool
    {
        // Allow consumed images so split-posting can attach the same image to multiple sales
        $image = PosTemporaryPaymentImage::where('token', $token)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$image) {
            \Illuminate\Support\Facades\Log::error('PosCheckout: Payment image token not found or expired', ['token' => $token]);
            throw new PosCheckoutPostingException('MISSING_PAYMENT_IMAGE', 'Gambar pembayaran tidak ditemukan atau sudah kadaluarsa.');
        }

        if (!Storage::exists($image->path)) {
            \Illuminate\Support\Facades\Log::error('PosCheckout: Payment image file missing from storage', ['token' => $token, 'path' => $image->path]);
            throw new PosCheckoutPostingException('MISSING_PAYMENT_IMAGE_FILE', 'File gambar pembayaran hilang dari penyimpanan.');
        }

        $model->addMediaFromDisk($image->path)
            ->preservingOriginal() // Let the cleanup task handle deletion
            ->usingName(pathinfo($image->original_name, PATHINFO_FILENAME))
            ->usingFileName($image->original_name)
            ->toMediaCollection('attachments');

        $image->update([
            'consumed_at' => Carbon::now(),
        ]);

        return true;
    }
}
