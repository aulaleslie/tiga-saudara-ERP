<?php

namespace Modules\Purchase\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;

class PurchasePaymentStoreService
{
    /**
     * Atomically process and record an individual purchase payment.
     *
     * Locks the Purchase and active payment rows for update, revalidates live due amount,
     * and updates Purchase header totals.
     *
     * @param Purchase $purchase
     * @param User $actor
     * @param array $data
     * @param string|null $attachment
     * @return PurchasePayment
     * @throws \DomainException|\InvalidArgumentException
     */
    public function store(Purchase $purchase, User $actor, array $data, ?string $attachment = null): PurchasePayment
    {
        $amount = (float) ($data['amount'] ?? 0);

        if ($amount < 0.01) {
            throw new \InvalidArgumentException('Payment amount must be at least 0.01.');
        }

        // Validate and sanitize attachment file path before opening transaction or acquiring locks
        $validatedAttachmentPath = $this->validateAndSanitizeAttachment($attachment);

        $createdMedia = [];

        try {
            return DB::transaction(function () use ($purchase, $actor, $data, $validatedAttachmentPath, $amount, &$createdMedia) {
                /** @var Purchase $lockedPurchase */
                $lockedPurchase = Purchase::withArchived()->lockForUpdate()->findOrFail($purchase->id);

                // Re-verify that Purchase allows payment operations (allows ordinary and consignment-billing)
                PurchaseSourceGuard::assertPaymentAllowed($lockedPurchase);

                // Deterministically lock all active payment rows for update
                $activePayments = PurchasePayment::where('purchase_id', $lockedPurchase->id)
                    ->where('status', PurchasePayment::STATUS_ACTIVE)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $effectivePaid = (float) $activePayments->sum('amount');
                $liveDue = max(0.0, round((float) $lockedPurchase->total_amount - $effectivePaid, 2));

                if ($amount > $liveDue + 0.001) {
                    throw new \DomainException(
                        "Payment amount (" . number_format($amount, 2) . ") exceeds the remaining due amount (" . number_format($liveDue, 2) . ")."
                    );
                }

                $pmId = (int) ($data['payment_method_id'] ?? 0);
                /** @var \Modules\Setting\Entities\PaymentMethod|null $pm */
                $pm = \Modules\Setting\Entities\PaymentMethod::where('id', $pmId)
                    ->lockForUpdate()
                    ->first();

                if (!$pm || !$pm->is_active) {
                    throw new \InvalidArgumentException("Payment method #{$pmId} is not active or available.");
                }
                $paymentMethodName = $pm->name;

                $payment = PurchasePayment::create([
                    'date' => $data['date'],
                    'reference' => $data['reference'],
                    'amount' => $amount,
                    'note' => $data['note'] ?? null,
                    'purchase_id' => $lockedPurchase->id,
                    'payment_method_id' => $pm->id,
                    'payment_method' => $paymentMethodName,
                    'status' => PurchasePayment::STATUS_ACTIVE,
                ]);

                // Store uploaded attachment file if validated
                if (!empty($validatedAttachmentPath)) {
                    $media = $payment->addMedia($validatedAttachmentPath)->toMediaCollection('attachments');
                    $createdMedia[] = $media;
                }

                // Recalculate and update Purchase header totals under lock
                $newEffectivePaid = round($effectivePaid + $amount, 2);
                $newDue = max(0.0, round((float) $lockedPurchase->total_amount - $newEffectivePaid, 2));
                $paymentStatus = $newDue <= 0.01
                    ? Purchase::PAYMENT_STATUS_PAID
                    : ($newEffectivePaid > 0.01 ? Purchase::PAYMENT_STATUS_PARTIAL : Purchase::PAYMENT_STATUS_UNPAID);

                $lockedPurchase->update([
                    'paid_amount' => $newEffectivePaid,
                    'due_amount' => $newDue,
                    'payment_status' => $paymentStatus,
                ]);

                return $payment;
            });
        } catch (\Throwable $e) {
            // Compensating filesystem cleanup for media stored during failed attempt
            foreach ($createdMedia as $media) {
                try {
                    if ($media && file_exists($media->getPath())) {
                        @unlink($media->getPath());
                    }
                    $media?->delete();
                } catch (\Throwable $cleanEx) {
                    \Illuminate\Support\Facades\Log::error('Failed to purge physical payment media file during transaction rollback cleanup.', [
                        'media_id' => $media?->id,
                        'file_path' => $media?->getPath(),
                        'exception' => $cleanEx,
                    ]);
                }
            }

            throw $e;
        }
    }

    /**
     * Validate attachment filename and ensure path stays strictly within temp/dropzone.
     *
     * @param string|null $attachment
     * @return string|null
     * @throws \InvalidArgumentException
     */
    protected function validateAndSanitizeAttachment(?string $attachment): ?string
    {
        if (empty($attachment)) {
            return null;
        }

        $filename = basename($attachment);
        if ($filename !== $attachment || str_contains($attachment, '..')) {
            throw new \InvalidArgumentException("Invalid attachment file path. Path traversal is strictly prohibited.");
        }

        $stagingDir = Storage::path('temp/dropzone');
        $targetPath = $stagingDir . DIRECTORY_SEPARATOR . $filename;

        if (!file_exists($targetPath) || !is_readable($targetPath)) {
            throw new \InvalidArgumentException("Payment attachment file '{$filename}' does not exist or is not readable.");
        }

        $realStaging = realpath($stagingDir);
        $realTarget = realpath($targetPath);
        $stagingBound = $realStaging ? rtrim($realStaging, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '';

        if (!$realStaging || !$realTarget || !str_starts_with($realTarget, $stagingBound)) {
            throw new \InvalidArgumentException("Attachment file path escapes the temporary dropzone directory.");
        }

        $allowedMimes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($realTarget, PATHINFO_EXTENSION));
        $mime = function_exists('mime_content_type') ? strtolower((string) @mime_content_type($realTarget)) : '';

        if (!in_array($ext, $allowedExts, true) || empty($mime) || !in_array($mime, $allowedMimes, true)) {
            throw new \InvalidArgumentException("Payment attachment has an unsupported file extension or MIME type ('ext: {$ext}, mime: {$mime}'). Allowed types: pdf, jpg, jpeg, png, webp.");
        }

        if (filesize($realTarget) > 10240 * 1024) {
            throw new \InvalidArgumentException("Payment attachment exceeds the maximum allowed size of 10MB.");
        }

        return $realTarget;
    }
}
