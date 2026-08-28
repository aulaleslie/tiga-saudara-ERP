<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Facades\DB;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Validation\ValidationException;

class GlobalPurchasePaymentService
{
    /**
     * Store multiple payments for a single supplier atomically.
     *
     * @param int $supplierId
     * @param array $data Expected structure:
     *        - allocations: [purchase_id => amount]
     *        - reference: string
     *        - date: date
     *        - payment_method_id: int
     *        - note: string (optional)
     *        - attachment: string (file name from dropzone) (optional)
     * @return array Array of created PurchasePayment instances
     * @throws \Exception
     */
    public function storeMultiPayment($supplierId, array $data)
    {
        $allocations = $data['allocations'] ?? [];
        if (empty($allocations)) {
            throw ValidationException::withMessages(['allocations' => 'Tidak ada alokasi yang diberikan.']);
        }

        // Pre-validate attachment file, path containment, MIME, extension, and size
        $attachmentPath = null;
        if (!empty($data['attachment'])) {
            $basename = basename($data['attachment']);
            if ($basename !== $data['attachment'] || str_contains($data['attachment'], '..')) {
                throw ValidationException::withMessages(['attachment' => 'Path traversal tidak diperbolehkan.']);
            }

            $stagingDir = \Illuminate\Support\Facades\Storage::path('temp/dropzone');
            $targetPath = $stagingDir . DIRECTORY_SEPARATOR . $basename;
            $realStaging = realpath($stagingDir);
            $realTarget = realpath($targetPath);
            $stagingBound = $realStaging ? rtrim($realStaging, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '';

            if (!$realStaging || !$realTarget || !str_starts_with($realTarget, $stagingBound)) {
                throw ValidationException::withMessages(['attachment' => 'File lampiran tidak valid atau berada di luar direktori dropzone.']);
            }

            $allowedMimes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
            $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
            $ext = strtolower(pathinfo($realTarget, PATHINFO_EXTENSION));
            $mime = function_exists('mime_content_type') ? strtolower((string) @mime_content_type($realTarget)) : '';

            if (!in_array($ext, $allowedExts, true) || empty($mime) || !in_array($mime, $allowedMimes, true)) {
                throw ValidationException::withMessages(['attachment' => 'File lampiran memiliki ekstensi atau MIME type yang tidak didukung.']);
            }

            if (filesize($realTarget) > 10240 * 1024) {
                throw ValidationException::withMessages(['attachment' => 'Ukuran file lampiran melebihi batas 10MB.']);
            }

            $attachmentPath = $realTarget;
        }

        // Filter positive allocations
        $positiveAllocations = [];
        foreach ($allocations as $pId => $rawAmount) {
            $rawFloat = (float) $rawAmount;
            if ($rawFloat <= 0) {
                continue;
            }
            $amount = round($rawFloat, 2);
            if ($amount < 0.01) {
                throw ValidationException::withMessages(['allocations' => "Alokasi untuk Pembelian ID {$pId} minimal Rp 0,01."]);
            }
            $positiveAllocations[(int) $pId] = $amount;
        }

        if (empty($positiveAllocations)) {
            throw ValidationException::withMessages(['allocations' => 'Tidak ada alokasi positif yang valid untuk diproses.']);
        }

        $createdMedia = [];

        try {
            return DB::transaction(function () use ($supplierId, $data, $positiveAllocations, $attachmentPath, &$createdMedia) {
                $targetPurchaseIds = array_keys($positiveAllocations);
                sort($targetPurchaseIds); // Deterministic ordering by ID to prevent deadlocks

                // 1. Lock all candidate Purchases in deterministic ID order
                $purchases = Purchase::whereIn('id', $targetPurchaseIds)
                    ->where('supplier_id', $supplierId)
                    ->globalPaymentEligible()
                    ->whereNull('archived_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($targetPurchaseIds as $pId) {
                    if (!$purchases->has($pId)) {
                        throw ValidationException::withMessages(['allocations' => "Pembelian dengan ID {$pId} tidak ditemukan atau pemasok tidak cocok."]);
                    }
                }

                // 2. Lock active payment rows for all target Purchases in deterministic ID order
                $activePaymentsMap = PurchasePayment::whereIn('purchase_id', $targetPurchaseIds)
                    ->where('status', PurchasePayment::STATUS_ACTIVE)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->groupBy('purchase_id');

                // 3. Lock and verify active PaymentMethod
                $paymentMethodId = $data['payment_method_id'] ?? null;
                $paymentMethod = $paymentMethodId
                    ? \Modules\Setting\Entities\PaymentMethod::where('id', $paymentMethodId)->lockForUpdate()->first()
                    : null;

                if (!$paymentMethod || !$paymentMethod->is_active) {
                    throw ValidationException::withMessages(['payment_method_id' => 'Metode pembayaran tidak valid atau tidak aktif.']);
                }

                $createdPayments = [];

                foreach ($positiveAllocations as $purchaseId => $amount) {
                    /** @var Purchase $purchase */
                    $purchase = $purchases->get($purchaseId);

                    // Revalidate live due balance under purchase and payment locks
                    $activePayments = $activePaymentsMap->get($purchaseId);
                    $effectivePaid = (float) ($activePayments ? $activePayments->sum('amount') : 0);
                    $liveDueAmount = max(0.0, round((float) $purchase->total_amount - $effectivePaid, 2));

                    if ($amount > $liveDueAmount + 0.0001) {
                        throw ValidationException::withMessages(['allocations' => "Alokasi untuk Pembelian {$purchase->reference} melebihi sisa tagihan saat ini."]);
                    }

                    // Create payment
                    $payment = PurchasePayment::create([
                        'purchase_id' => $purchase->id,
                        'amount' => $amount,
                        'date' => $data['date'],
                        'reference' => $data['reference'],
                        'payment_method_id' => $paymentMethod->id,
                        'payment_method' => $paymentMethod->name,
                        'note' => $data['note'] ?? null,
                    ]);

                    if (!empty($data['attachment'])) {
                        $payment->_temporary_attachment = $data['attachment'];
                    }

                    // Replicate attachment to media collection if provided
                    if ($attachmentPath) {
                        $media = $payment->addMedia($attachmentPath)->preservingOriginal()->toMediaCollection('attachments');
                        $createdMedia[] = $media;
                    }

                    // Sync purchase header balances from canonical active payments
                    $newActivePayments = round($effectivePaid + $amount, 2);
                    $newDueAmount = max(0.0, round((float) $purchase->total_amount - $newActivePayments, 2));
                    $newStatus = ($newDueAmount <= 0.0001) ? \App\Constants\PaymentStatus::PAID : \App\Constants\PaymentStatus::PARTIAL;

                    $purchase->update([
                        'paid_amount' => $newActivePayments,
                        'due_amount' => $newDueAmount,
                        'payment_status' => $newStatus,
                    ]);

                    $createdPayments[] = $payment;
                }

                // Clean up staging attachment source after successful media creation across all payments
                if ($attachmentPath && file_exists($attachmentPath)) {
                    @unlink($attachmentPath);
                }

                return $createdPayments;
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
                    \Illuminate\Support\Facades\Log::error('Failed to purge physical global payment media file during rollback cleanup.', [
                        'media_id' => $media?->id,
                        'file_path' => $media?->getPath(),
                        'exception' => $cleanEx,
                    ]);
                }
            }
            throw $e;
        }
    }
}
