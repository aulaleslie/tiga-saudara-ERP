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
     *        - attachments: [purchase_id => [filename, ...]] (optional)
     * @return array Array of created PurchasePayment instances
     * @throws \Exception
     */
    public function storeMultiPayment($supplierId, array $data)
    {
        $allocations = $data['allocations'] ?? [];
        if (empty($allocations)) {
            throw ValidationException::withMessages(['allocations' => 'Tidak ada alokasi yang diberikan.']);
        }

        $rawAttachments = $data['attachments'] ?? [];
        if (!is_array($rawAttachments)) {
            throw ValidationException::withMessages(['attachments' => 'Format data lampiran tidak valid.']);
        }

        // Filter positive allocations
        $positiveAllocations = [];
        foreach ($allocations as $pId => $rawAmount) {
            $rawFloat = (float) $rawAmount;
            if ($rawFloat <= 0) {
                // If this row has attachments but non-positive allocation, reject the entire submission
                if (!empty($rawAttachments[$pId]) && is_array($rawAttachments[$pId]) && count(array_filter($rawAttachments[$pId])) > 0) {
                    throw ValidationException::withMessages([
                        'attachments' => "Baris pembelian #{$pId} memiliki lampiran namun nominal alokasinya 0.",
                        "allocations.{$pId}" => "Baris pembelian #{$pId} memiliki lampiran namun nominal alokasinya 0.",
                    ]);
                }
                continue;
            }
            $amount = round($rawFloat, 2);
            if ($amount < 0.01) {
                throw ValidationException::withMessages(['allocations' => "Alokasi untuk Pembelian ID {$pId} minimal Rp 0,01."]);
            }
            $positiveAllocations[(int) $pId] = $amount;
        }

        // Check if any key in rawAttachments is not in allocations or has 0 allocation
        foreach ($rawAttachments as $pId => $files) {
            if (!empty($files) && is_array($files) && count(array_filter($files)) > 0) {
                if (!isset($positiveAllocations[(int) $pId])) {
                    throw ValidationException::withMessages([
                        'attachments' => "Baris pembelian #{$pId} memiliki lampiran namun nominal alokasinya 0 atau tidak ada.",
                        "allocations.{$pId}" => "Baris pembelian #{$pId} memiliki lampiran namun nominal alokasinya 0 atau tidak ada.",
                    ]);
                }
            }
        }

        if (empty($positiveAllocations)) {
            throw ValidationException::withMessages(['allocations' => 'Tidak ada alokasi positif yang valid untuk diproses.']);
        }

        // Pre-validate all attachments across all rows using PurchasePaymentStoreService rules
        // Ensure no duplicate file is used anywhere across the entire submission
        /** @var PurchasePaymentStoreService $paymentStoreService */
        $paymentStoreService = app(PurchasePaymentStoreService::class);
        $globalSeenFiles = [];
        $validatedAttachmentsByPurchase = []; // [purchaseId => [ ['path' => ..., 'original_name' => ...], ... ]]

        foreach ($positiveAllocations as $purchaseId => $amount) {
            $rowFiles = $rawAttachments[$purchaseId] ?? [];
            if (!is_array($rowFiles)) {
                throw ValidationException::withMessages(["attachments.{$purchaseId}" => "Format lampiran untuk pembelian #{$purchaseId} tidak valid."]);
            }

            // Reject malformed file entries (non-string, blank) instead of silently dropping them
            foreach ($rowFiles as $idx => $file) {
                if (!is_string($file) || trim($file) === '') {
                    throw ValidationException::withMessages([
                        "attachments.{$purchaseId}" => "Lampiran pada indeks {$idx} untuk pembelian #{$purchaseId} tidak valid.",
                    ]);
                }

                $trimmed = trim($file);
                if (isset($globalSeenFiles[$trimmed])) {
                    throw ValidationException::withMessages([
                        'attachments' => "Lampiran duplikat '{$trimmed}' terdeteksi pada lebih dari satu alokasi atau berulang.",
                    ]);
                }
                $globalSeenFiles[$trimmed] = true;
            }

            if (!empty($rowFiles)) {
                try {
                    $validatedItems = $paymentStoreService->validateMultipleAttachments($rowFiles);
                    $validatedAttachmentsByPurchase[$purchaseId] = $validatedItems;
                } catch (\InvalidArgumentException $e) {
                    throw ValidationException::withMessages([
                        "attachments.{$purchaseId}" => $e->getMessage(),
                    ]);
                }
            } else {
                $validatedAttachmentsByPurchase[$purchaseId] = [];
            }
        }

        $createdMedia = [];

        try {
            return DB::transaction(function () use (
                $supplierId,
                $data,
                $positiveAllocations,
                $validatedAttachmentsByPurchase,
                &$createdMedia
            ) {
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
                $processedFilesToClean = [];

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

                    // Attach only this row's validated files to the generated payment
                    $rowAttachments = $validatedAttachmentsByPurchase[$purchaseId] ?? [];
                    foreach ($rowAttachments as $item) {
                        $validPath = $item['path'];
                        $originalName = $item['original_name'];

                        $media = $payment->addMedia($validPath)
                            ->withCustomProperties([
                                'original_name' => $originalName,
                            ])
                            ->toMediaCollection('attachments');

                        $createdMedia[] = $media;
                        $processedFilesToClean[] = $validPath;

                        // Clean up .meta file if exists
                        $metaPath = $validPath . '.meta';
                        if (file_exists($metaPath)) {
                            @unlink($metaPath);
                        }
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

                // Clean up staging files after successful transaction
                foreach ($processedFilesToClean as $path) {
                    if (file_exists($path)) {
                        @unlink($path);
                    }
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
