<?php

namespace Modules\Consignment\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Consignment\Entities\ConsignmentAllocationAuditLog;
use Modules\Consignment\Entities\ConsignmentBillingConfirmation;
use Modules\Consignment\Entities\ConsignmentBillingConfirmationLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceivalLine;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Entities\ConsignmentReceiptAllocation;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSerializedAllocation;
use Modules\Consignment\Entities\ConsignmentPurchaseDetailLineage;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;

class ConsignmentBillingConversionService
{
    protected ConsignmentBillingPreviewService $previewService;

    public function __construct(ConsignmentBillingPreviewService $previewService)
    {
        $this->previewService = $previewService;
    }

    /**
     * Convert an approved, billing-ready confirmation into a financially active Purchase.
     *
     * @param int $confirmationId
     * @param int $settingId
     * @param int $userId
     * @param array{
     *     supplier_invoice_number: string,
     *     invoice_date: string,
     *     reporting_date?: ?string,
     *     due_date?: ?string,
     *     payment_term_id?: ?int,
     *     tax_ref_no?: ?string,
     *     billing_notes?: ?string
     * } $metadata
     * @param array<mixed> $attachments
     * @return Purchase
     *
     * @throws \DomainException|\InvalidArgumentException
     */
    public function convert(int $confirmationId, int $settingId, int $userId, array $metadata, array $attachments = []): Purchase
    {
        // 1. Initial setting-scoped idempotency check BEFORE attachment validation
        // so that retries with consumed staging file paths return the existing Purchase immediately.
        $existingConfirmation = ConsignmentBillingConfirmation::where('id', $confirmationId)
            ->where('setting_id', $settingId)
            ->first();

        if ($existingConfirmation && $existingConfirmation->purchase_id !== null) {
            $existingPurchase = $this->validateAndResolveLinkedPurchase($existingConfirmation, $settingId);
            if ($existingPurchase) {
                return $existingPurchase;
            }
        }

        // Attachment identities are recorded in the audit payload even though the files
        // themselves are stored after commit.
        $attachmentNames = [];
        foreach ($attachments as $file) {
            if ($file && method_exists($file, 'getClientOriginalName')) {
                $attachmentNames[] = $file->getClientOriginalName();
            } elseif (is_string($file)) {
                $attachmentNames[] = basename($file);
            }
        }

        $createdMedia = [];

        try {
            // Enforce file type, MIME, size, readability, and count validation inside try block
            // so validation policy failures trigger durable audit logging without acquiring locks.
            $this->validateAttachments($attachments);

            $purchase = DB::transaction(function () use ($confirmationId, $settingId, $userId, $metadata, $attachmentNames, $attachments, &$createdMedia) {
                /** @var ConsignmentBillingConfirmation|null $confirmation */
                // Scoped by active setting so a foreign confirmation is indistinguishable
                // from a nonexistent one.
                $confirmation = ConsignmentBillingConfirmation::where('id', $confirmationId)
                    ->where('setting_id', $settingId)
                    ->lockForUpdate()
                    ->first();

                if (!$confirmation) {
                    throw new \InvalidArgumentException("Confirmation #{$confirmationId} is not available.");
                }

                /** @var \Modules\People\Entities\Supplier|null $supplier */
                // Suppliers are shared master data across settings; the confirmation's
                // supplier is locked by identity only, without a setting predicate.
                $supplier = \Modules\People\Entities\Supplier::where('id', $confirmation->supplier_id)
                    ->lockForUpdate()
                    ->first();

                if (!$supplier) {
                    throw new \InvalidArgumentException("Supplier #{$confirmation->supplier_id} is not available.");
                }

                if (isset($supplier->is_active) && !$supplier->is_active) {
                    throw new \DomainException("Supplier #{$supplier->id} ({$supplier->supplier_name}) is inactive.");
                }

                // Lock all evidence hierarchy in single global deterministic order:
                // 1. Confirmation & Confirmation lines
                // 2. Receipt allocations
                // 3. Receivings, Receivals & Receival lines
                // 4. Receiving details
                // 5. Sold sources
                // 6. Serialized allocations
                // 7. Payment term (if specified)

                $lines = ConsignmentBillingConfirmationLine::where('consignment_billing_confirmation_id', $confirmationId)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $soldSourceIds = $lines->pluck('consignment_sold_source_id')->filter()->unique()->sort()->values()->toArray();
                $lineIds = $lines->pluck('id')->filter()->unique()->sort()->values()->toArray();

                $crdIds = [];
                if (!empty($lineIds)) {
                    $receiptAllocations = ConsignmentReceiptAllocation::whereIn('consignment_billing_confirmation_line_id', $lineIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    $crdIds = $receiptAllocations->pluck('consignment_receiving_detail_id')->filter()->unique()->sort()->values()->toArray();
                }

                if (!empty($crdIds)) {
                    $unlockedDetails = ConsignmentReceivingDetail::whereIn('id', $crdIds)->get();
                    $receivingIds = $unlockedDetails->pluck('consignment_receiving_id')->filter()->unique()->sort()->values()->toArray();

                    if (!empty($receivingIds)) {
                        $receivings = ConsignmentReceiving::whereIn('id', $receivingIds)
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get();

                        $receivalIds = $receivings->pluck('consignment_receival_id')->filter()->unique()->sort()->values()->toArray();
                        if (!empty($receivalIds)) {
                            ConsignmentReceival::whereIn('id', $receivalIds)
                                ->orderBy('id')
                                ->lockForUpdate()
                                ->get();

                            ConsignmentReceivalLine::whereIn('consignment_receival_id', $receivalIds)
                                ->orderBy('id')
                                ->lockForUpdate()
                                ->get();
                        }
                    }

                    $lockedDetails = ConsignmentReceivingDetail::whereIn('id', $crdIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    // Re-verify that locked CRD parent IDs still match previously locked receiving set
                    $recheckedReceivingIds = $lockedDetails->pluck('consignment_receiving_id')->filter()->unique()->sort()->values()->toArray();
                    if ($recheckedReceivingIds !== $receivingIds) {
                        throw new \DomainException("Receiving detail parent hierarchy changed during lock acquisition.");
                    }
                }

                if (!empty($soldSourceIds)) {
                    ConsignmentSoldSource::whereIn('id', $soldSourceIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                }

                $serializedAllocations = ConsignmentSerializedAllocation::where('consignment_billing_confirmation_id', $confirmationId)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $serialIds = $serializedAllocations->pluck('product_serial_number_id')->filter()->unique()->sort()->values()->toArray();
                if (!empty($serialIds)) {
                    \Modules\Product\Entities\ProductSerialNumber::whereIn('id', $serialIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                }

                if (!empty($crdIds)) {
                    DB::table('consignment_receiving_detail_serial_numbers')
                        ->whereIn('consignment_receiving_detail_id', $crdIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                }

                if (!empty($metadata['payment_term_id'])) {
                    \Modules\Purchase\Entities\PaymentTerm::where('id', $metadata['payment_term_id'])
                        ->lockForUpdate()
                        ->first();
                }

                // Idempotency check: if already linked to a Purchase, verify provenance under lock
                if ($confirmation->purchase_id !== null) {
                    $existingPurchase = $this->validateAndResolveLinkedPurchase($confirmation, $settingId);
                    if ($existingPurchase) {
                        return $existingPurchase;
                    }
                }

                // Run preview validation under lock
                $preview = $this->previewService->generatePreview($confirmationId, $settingId, $metadata);

                if (!$preview['valid']) {
                    $reasonStr = implode('; ', $preview['blockers']);
                    throw new \DomainException("Billing conversion blocked: {$reasonStr}");
                }

                // Fail before consuming a reference number or writing any document: a
                // malformed preview must not burn a sequence value or reach the unique
                // lineage constraint as a raw duplicate-key error.
                $this->assertPreviewLineageIsUnique($confirmation, $preview);

                $invoiceDate = \Carbon\Carbon::parse($metadata['invoice_date']);

                // Allocate Purchase reference transactionally
                $reference = Purchase::generateReference($settingId, $invoiceDate);

                $resolvedDueDate = !empty($preview['resolved_due_date'])
                    ? \Carbon\Carbon::parse($preview['resolved_due_date'])
                    : (!empty($metadata['due_date']) ? \Carbon\Carbon::parse($metadata['due_date']) : $invoiceDate);

                // Create physically complete, financially active Purchase
                $purchase = Purchase::create([
                    'setting_id' => $settingId,
                    'supplier_id' => $confirmation->supplier_id,
                    'date' => $invoiceDate,
                    'reporting_date' => !empty($metadata['reporting_date']) ? \Carbon\Carbon::parse($metadata['reporting_date']) : $invoiceDate,
                    'due_date' => $resolvedDueDate,
                    'payment_term_id' => $preview['payment_term_id'] ?? ($metadata['payment_term_id'] ?? null),
                    'reference' => $reference,
                    'supplier_purchase_number' => trim($metadata['supplier_invoice_number']),
                    'tax_ref_no' => $metadata['tax_ref_no'] ?? null,
                    'total_amount' => $preview['totals']['total_amount'],
                    'sub_total' => $preview['totals']['sub_total'],
                    'tax_amount' => $preview['totals']['tax_amount'],
                    'paid_amount' => 0.0,
                    'due_amount' => $preview['totals']['total_amount'],
                    'status' => Purchase::STATUS_RECEIVED,
                    'source_type' => Purchase::SOURCE_CONSIGNMENT_BILLING,
                    'payment_status' => 'Unpaid',
                    'payment_method' => 'Bank Transfer',
                    'note' => $metadata['billing_notes'] ?? null,
                ]);

                // Create details and lineages. Identities are collected so the audit payload
                // carries the generated evidence, not just totals.
                $createdDetailIds = [];
                $createdLineageIds = [];

                foreach ($preview['lines'] as $line) {
                    $purchaseDetail = PurchaseDetail::create([
                        'purchase_id' => $purchase->id,
                        'product_id' => $line['product_id'],
                        'product_name' => $line['product_name'],
                        'product_code' => $line['product_code'],
                        'quantity' => $line['quantity'],
                        'price' => $line['unit_price'],
                        'unit_price' => $line['unit_price'],
                        'sub_total' => $line['sub_total'],
                        'tax_id' => $line['tax_id'],
                        'product_tax_amount' => $line['product_tax_amount'],
                        'product_discount_type' => 'fixed',
                        'product_discount_amount' => 0.0,
                    ]);

                    $createdDetailIds[] = $purchaseDetail->id;

                    // Create lineage records
                    foreach ($line['allocations'] as $allocMeta) {
                        // Match serialized allocations for this receiving detail & confirmation line
                        $matchedSerAllocs = array_values(array_filter($line['serialized_allocations'], function ($serMeta) use ($allocMeta) {
                            return (int) $serMeta['receiving_detail_id'] === (int) $allocMeta['receiving_detail_id']
                                && (int) ($serMeta['confirmation_line_id'] ?? $allocMeta['confirmation_line_id']) === (int) $allocMeta['confirmation_line_id'];
                        }));

                        if (!empty($matchedSerAllocs)) {
                            $totalAllocTax = (float) $allocMeta['tax_amount'];
                            $serCount = count($matchedSerAllocs);
                            $baseSerTax = round($totalAllocTax / $serCount, 2);
                            $distributedTaxSum = 0.0;

                            // Create one lineage row per serialized allocation
                            foreach ($matchedSerAllocs as $idx => $serMeta) {
                                $isLast = ($idx === $serCount - 1);
                                $serTaxAmount = $isLast ? round($totalAllocTax - $distributedTaxSum, 2) : $baseSerTax;
                                $distributedTaxSum += $serTaxAmount;

                                $createdLineage = ConsignmentPurchaseDetailLineage::create([
                                    'setting_id' => $settingId,
                                    'purchase_id' => $purchase->id,
                                    'purchase_detail_id' => $purchaseDetail->id,
                                    'consignment_billing_confirmation_id' => $confirmation->id,
                                    'consignment_billing_confirmation_line_id' => $allocMeta['confirmation_line_id'],
                                    'consignment_receipt_allocation_id' => $allocMeta['receipt_allocation_id'],
                                    'consignment_serialized_allocation_id' => $serMeta['serialized_allocation_id'],
                                    'product_id' => $line['product_id'],
                                    'consignment_receiving_detail_id' => $allocMeta['receiving_detail_id'],
                                    'billed_base_quantity' => 1.0, // 1 per serial
                                    'unit_cost' => $allocMeta['unit_cost'],
                                    'unit_dpp' => $allocMeta['unit_dpp'],
                                    'tax_id' => $allocMeta['tax_id'],
                                    'tax_rate' => $allocMeta['tax_rate'],
                                    'tax_amount' => $serTaxAmount,
                                    'commercial_snapshot' => [
                                        'product_name' => $line['product_name'],
                                        'product_code' => $line['product_code'],
                                        'unit_price' => $line['unit_price'],
                                        'tax_rate' => $allocMeta['tax_rate'],
                                        'original_stored_tax_amount' => $allocMeta['original_stored_tax_amount'] ?? $allocMeta['tax_amount'],
                                        'is_legacy_full_lot_tax' => $allocMeta['is_legacy_full_lot_tax'] ?? false,
                                        'tax_snapshot_version' => $allocMeta['tax_snapshot_version'] ?? null,
                                    ],
                                ]);

                                $createdLineageIds[] = $createdLineage->id;
                            }
                        } else {
                            // Non-serialized receipt allocation lineage row
                            $createdLineage = ConsignmentPurchaseDetailLineage::create([
                                'setting_id' => $settingId,
                                'purchase_id' => $purchase->id,
                                'purchase_detail_id' => $purchaseDetail->id,
                                'consignment_billing_confirmation_id' => $confirmation->id,
                                'consignment_billing_confirmation_line_id' => $allocMeta['confirmation_line_id'],
                                'consignment_receipt_allocation_id' => $allocMeta['receipt_allocation_id'],
                                'consignment_serialized_allocation_id' => null,
                                'product_id' => $line['product_id'],
                                'consignment_receiving_detail_id' => $allocMeta['receiving_detail_id'],
                                'billed_base_quantity' => $allocMeta['billed_base_quantity'],
                                'unit_cost' => $allocMeta['unit_cost'],
                                'unit_dpp' => $allocMeta['unit_dpp'],
                                'tax_id' => $allocMeta['tax_id'],
                                'tax_rate' => $allocMeta['tax_rate'],
                                'tax_amount' => $allocMeta['tax_amount'],
                                'commercial_snapshot' => [
                                    'product_name' => $line['product_name'],
                                    'product_code' => $line['product_code'],
                                    'unit_price' => $line['unit_price'],
                                    'tax_rate' => $allocMeta['tax_rate'],
                                    'original_stored_tax_amount' => $allocMeta['original_stored_tax_amount'] ?? $allocMeta['tax_amount'],
                                    'is_legacy_full_lot_tax' => $allocMeta['is_legacy_full_lot_tax'] ?? false,
                                    'tax_snapshot_version' => $allocMeta['tax_snapshot_version'] ?? null,
                                ],
                            ]);

                            $createdLineageIds[] = $createdLineage->id;
                        }
                    }

                    $summedLineageQty = (float) ConsignmentPurchaseDetailLineage::where('purchase_detail_id', $purchaseDetail->id)->sum('billed_base_quantity');
                    if (abs($summedLineageQty - (float) $purchaseDetail->quantity) > 0.0001) {
                        throw new \LogicException("Purchase detail #{$purchaseDetail->id} quantity [{$purchaseDetail->quantity}] does not equal summed lineage quantity [{$summedLineageQty}].");
                    }
                }

                // Link Purchase and update billing state on confirmation
                $confirmation->update([
                    'purchase_id' => $purchase->id,
                    'is_ready_for_billing' => false,
                    'billed_by' => $userId,
                    'billed_at' => now(),
                    'supplier_invoice_number' => trim($metadata['supplier_invoice_number']),
                    'invoice_date' => $invoiceDate,
                    'reporting_date' => !empty($metadata['reporting_date']) ? \Carbon\Carbon::parse($metadata['reporting_date']) : $invoiceDate,
                    'due_date' => $resolvedDueDate,
                    'payment_term_id' => $preview['payment_term_id'] ?? ($metadata['payment_term_id'] ?? null),
                    'tax_ref_no' => $metadata['tax_ref_no'] ?? null,
                    'billing_notes' => $metadata['billing_notes'] ?? null,
                ]);

                // Store attachments inside transaction to ensure atomic completion
                $attachmentEvidence = [];
                if (!empty($attachments) && method_exists($purchase, 'addMedia')) {
                    foreach ($attachments as $file) {
                        if ($file) {
                            $originalName = $file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile
                                ? $file->getClientOriginalName()
                                : (is_string($file) ? basename($file) : 'attachment');

                            $filePath = $file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile
                                ? $file->getRealPath()
                                : (is_string($file) ? $file : null);

                            $fileHash = ($filePath && file_exists($filePath)) ? hash_file('sha256', $filePath) : null;

                            $media = $purchase->addMedia($file)
                                ->withCustomProperties([
                                    'source' => 'CONSIGNMENT_BILLING',
                                    'confirmation_id' => $confirmation->id,
                                    'confirmation_number' => $confirmation->confirmation_number,
                                    'file_hash' => $fileHash,
                                    'original_name' => $originalName,
                                ])
                                ->toMediaCollection('attachments');

                            $createdMedia[] = $media;
                            $attachmentEvidence[] = [
                                'media_id' => $media->id,
                                'file_name' => $media->file_name,
                                'original_name' => $media->getCustomProperty('original_name') ?: $originalName,
                                'mime_type' => $media->mime_type,
                                'size' => $media->size,
                                'sha256' => $fileHash,
                            ];
                        }
                    }
                }

                // Sanitize metadata payload for audit to contain only scalar attributes (removing UploadedFile objects)
                $sanitizedMetadata = [
                    'supplier_invoice_number' => trim($metadata['supplier_invoice_number'] ?? ''),
                    'invoice_date' => $invoiceDate->format('Y-m-d'),
                    'reporting_date' => !empty($metadata['reporting_date']) ? \Carbon\Carbon::parse($metadata['reporting_date'])->format('Y-m-d') : $invoiceDate->format('Y-m-d'),
                    'due_date' => $resolvedDueDate->format('Y-m-d'),
                    'payment_term_id' => $preview['payment_term_id'] ?? ($metadata['payment_term_id'] ?? null),
                    'tax_ref_no' => $metadata['tax_ref_no'] ?? null,
                    'billing_notes' => $metadata['billing_notes'] ?? null,
                ];

                // Append immutable full conversion audit log only after attachment success
                ConsignmentAllocationAuditLog::create([
                    'consignment_billing_confirmation_id' => $confirmation->id,
                    'action' => 'BILLING_CONVERTED',
                    'actor_id' => $userId,
                    'reason' => "Converted to Purchase #{$purchase->reference} (Supplier Invoice: {$sanitizedMetadata['supplier_invoice_number']})",
                    'snapshot' => [
                        'purchase_id' => $purchase->id,
                        'purchase_reference' => $purchase->reference,
                        'totals' => $preview['totals'],
                        'metadata' => $sanitizedMetadata,
                        // Generated evidence identities, so the audit record stands on its own.
                        'purchase_detail_ids' => $createdDetailIds,
                        'lineage_ids' => $createdLineageIds,
                        'lines' => $preview['lines'],
                        'attachment_count' => count($attachmentEvidence),
                        'attachment_names' => array_column($attachmentEvidence, 'original_name'),
                        'attachments' => $attachmentEvidence,
                    ],
                ]);

                return $purchase;
            });

            return $purchase;
        } catch (\Throwable $e) {
            // Purge physical media files created during attachment process using instance-scoped authorization
            foreach ($createdMedia as $media) {
                try {
                    $path = method_exists($media, 'getPath') ? $media->getPath() : null;
                    if ($path && file_exists($path)) {
                        @unlink($path);
                    }
                    if (method_exists($media, 'delete')) {
                        $media->isAuthorizedCompensatingRollback = true;
                        $media->delete();
                    }
                } catch (\Throwable $cleanupError) {
                    Log::error('Failed to clean up consignment billing attachment physical file after failure.', [
                        'confirmation_id' => $confirmationId,
                        'media_id' => $media->id ?? null,
                        'error' => $cleanupError->getMessage(),
                    ]);
                }
            }

            Log::error('Consignment billing conversion failed; transaction rolled back.', [
                'confirmation_id' => $confirmationId,
                'exception' => $e,
            ]);

            // Durable audit evidence for the failed decision, written outside the
            // rolled-back conversion transaction at the application-service boundary.
            $isDomainRejection = $e instanceof \DomainException || $e instanceof \InvalidArgumentException;
            $auditReason = $isDomainRejection ? $e->getMessage() : 'System error during conversion: ' . $e->getMessage();

            $this->recordFailedAttempt(
                $confirmationId,
                $settingId,
                $userId,
                $metadata,
                $auditReason
            );

            if (!$isDomainRejection && !($e instanceof \RuntimeException)) {
                throw new \RuntimeException("Supplier invoice attachments or billing conversion could not be completed: {$e->getMessage()}", 0, $e);
            }

            throw $e;
        }
    }

    /**
     * Record a durable audit record for a rejected/failed conversion attempt.
     *
     * Failed attempts roll their transaction back, so this must be called by the caller
     * AFTER the failure, never inside the conversion transaction — otherwise the evidence
     * would be rolled back along with the attempt. Blocker text is sanitized to the
     * domain-level reasons the operator is allowed to see.
     */
    public function recordFailedAttempt(int $confirmationId, int $settingId, int $userId, array $metadata, string $reason): void
    {
        try {
            $confirmation = ConsignmentBillingConfirmation::where('id', $confirmationId)
                ->where('setting_id', $settingId)
                ->first();

            if (!$confirmation) {
                return;
            }

            ConsignmentAllocationAuditLog::create([
                'consignment_billing_confirmation_id' => $confirmation->id,
                'action' => ConsignmentAllocationAuditLog::ACTION_BILLING_CONVERSION_FAILED,
                'actor_id' => $userId,
                'reason' => \Illuminate\Support\Str::limit($reason, 2000),
                'snapshot' => [
                    'attempted_at' => now()->toDateTimeString(),
                    'metadata' => \Illuminate\Support\Arr::only($metadata, [
                        'supplier_invoice_number',
                        'invoice_date',
                        'reporting_date',
                        'due_date',
                        'payment_term_id',
                        'tax_ref_no',
                    ]),
                ],
            ]);
        } catch (\Throwable $e) {
            // Audit persistence must never mask the original failure.
            Log::error('Failed to persist consignment billing conversion failure audit record.', [
                'confirmation_id' => $confirmationId,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Validate attachment items (type, MIME, size, readability, count) at application service boundary.
     *
     * @param array $attachments
     * @throws \InvalidArgumentException
     */
    protected function validateAttachments(array $attachments): void
    {
        if (count($attachments) > 10) {
            throw new \InvalidArgumentException("Maximum of 10 supplier invoice attachments allowed.");
        }

        $allowedMimes = [
            'application/pdf',
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/webp',
        ];

        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        $maxSizeBytes = 10240 * 1024; // 10 MB

        foreach ($attachments as $idx => $file) {
            if (!$file) {
                continue;
            }

            if ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                if (!$file->isValid()) {
                    throw new \InvalidArgumentException("Attachment #" . ($idx + 1) . " is not a valid uploaded file.");
                }

                $ext = strtolower((string) $file->getClientOriginalExtension());
                $realPath = $file->getRealPath();
                $mime = ($realPath && file_exists($realPath) && function_exists('mime_content_type'))
                    ? strtolower((string) @mime_content_type($realPath))
                    : strtolower((string) $file->getMimeType());

                if (!in_array($mime, $allowedMimes, true) || !in_array($ext, $allowedExtensions, true) || empty($mime)) {
                    throw new \InvalidArgumentException("Attachment #" . ($idx + 1) . " has an unsupported file type or extension ('ext: {$ext}, mime: {$mime}'). Allowed types: pdf, jpg, jpeg, png, webp.");
                }

                if ($file->getSize() > $maxSizeBytes) {
                    throw new \InvalidArgumentException("Attachment #" . ($idx + 1) . " exceeds the maximum allowed size of 10MB.");
                }
            } elseif (is_string($file)) {
                $realTarget = realpath($file);
                if (!$realTarget || !file_exists($realTarget) || !is_readable($realTarget)) {
                    throw new \InvalidArgumentException("Attachment #" . ($idx + 1) . " file path '{$file}' does not exist or is not readable.");
                }

                $stagingDir = storage_path('app/temp/consignment-billing');
                if (!file_exists($stagingDir)) {
                    mkdir($stagingDir, 0755, true);
                }

                $realStaging = realpath($stagingDir);
                $stagingBound = $realStaging ? rtrim($realStaging, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '';

                if (!$realStaging || !str_starts_with($realTarget, $stagingBound)) {
                    throw new \InvalidArgumentException("Attachment #" . ($idx + 1) . " file path escapes the dedicated consignment billing staging directory.");
                }

                $ext = strtolower(pathinfo($realTarget, PATHINFO_EXTENSION));
                $mime = function_exists('mime_content_type') ? strtolower((string) @mime_content_type($realTarget)) : '';

                if (!in_array($ext, $allowedExtensions, true) || empty($mime) || !in_array($mime, $allowedMimes, true)) {
                    throw new \InvalidArgumentException("Attachment #" . ($idx + 1) . " has an unsupported file extension or MIME type ('ext: {$ext}, mime: {$mime}'). Allowed types: pdf, jpg, jpeg, png, webp.");
                }

                if (filesize($realTarget) > $maxSizeBytes) {
                    throw new \InvalidArgumentException("Attachment #" . ($idx + 1) . " exceeds the maximum allowed size of 10MB.");
                }
            } else {
                throw new \InvalidArgumentException("Attachment #" . ($idx + 1) . " must be a valid UploadedFile instance or file path string.");
            }
        }
    }

    /**
     * Validate linked Purchase provenance on idempotent retries.
     *
     * @param ConsignmentBillingConfirmation $confirmation
     * @param int $settingId
     * @return Purchase|null
     *
     * @throws \DomainException
     */
    private function validateAndResolveLinkedPurchase(ConsignmentBillingConfirmation $confirmation, int $settingId): ?Purchase
    {
        if ($confirmation->purchase_id === null) {
            return null;
        }

        /** @var Purchase|null $purchase */
        $purchase = Purchase::where('id', $confirmation->purchase_id)
            ->where('setting_id', $settingId)
            ->first();

        if (!$purchase) {
            throw new \DomainException("Linked Purchase #{$confirmation->purchase_id} is missing or does not belong to active setting #{$settingId}.");
        }

        if ((int) $purchase->supplier_id !== (int) $confirmation->supplier_id) {
            throw new \DomainException("Linked Purchase #{$purchase->id} supplier [#{$purchase->supplier_id}] does not match confirmation supplier [#{$confirmation->supplier_id}].");
        }

        if ($purchase->source_type !== Purchase::SOURCE_CONSIGNMENT_BILLING) {
            throw new \DomainException("Linked Purchase #{$purchase->id} source type [{$purchase->source_type}] is invalid; expected [" . Purchase::SOURCE_CONSIGNMENT_BILLING . "].");
        }

        $hasLineage = ConsignmentPurchaseDetailLineage::where('purchase_id', $purchase->id)
            ->where('consignment_billing_confirmation_id', $confirmation->id)
            ->exists();

        if (!$hasLineage) {
            throw new \DomainException("Linked Purchase #{$purchase->id} lacks purchase detail lineage ownership for confirmation #{$confirmation->id}.");
        }

        return $purchase;
    }

    /**
     * Assert the preview describes each approved allocation exactly once before any
     * document is written.
     *
     * Grouping bugs can duplicate one group's evidence while dropping another's. Without
     * this check the first symptom is a raw duplicate-key error on uniq_cpdl_csa, after a
     * reference number has already been consumed. These are programming faults rather
     * than user-correctable domain states, so they raise LogicException.
     */
    protected function assertPreviewLineageIsUnique(ConsignmentBillingConfirmation $confirmation, array $preview): void
    {
        $serialCounts = [];
        $receiptCounts = [];

        foreach ($preview['lines'] ?? [] as $line) {
            foreach ($line['serialized_allocations'] ?? [] as $serMeta) {
                $id = (int) ($serMeta['serialized_allocation_id'] ?? 0);
                if ($id > 0) {
                    $serialCounts[$id] = ($serialCounts[$id] ?? 0) + 1;
                }
            }

            foreach ($line['allocations'] ?? [] as $allocMeta) {
                $id = (int) ($allocMeta['receipt_allocation_id'] ?? 0);
                if ($id > 0) {
                    $receiptCounts[$id] = ($receiptCounts[$id] ?? 0) + 1;
                }
            }
        }

        $duplicateSerials = array_keys(array_filter($serialCounts, fn ($n) => $n > 1));
        if (! empty($duplicateSerials)) {
            sort($duplicateSerials);
            throw new \LogicException(
                "Billing preview for confirmation #{$confirmation->id} lists serialized allocation(s) ["
                . implode(', ', $duplicateSerials) . "] more than once; conversion aborted before writing any document."
            );
        }

        $duplicateReceipts = array_keys(array_filter($receiptCounts, fn ($n) => $n > 1));
        if (! empty($duplicateReceipts)) {
            sort($duplicateReceipts);
            throw new \LogicException(
                "Billing preview for confirmation #{$confirmation->id} lists receipt allocation(s) ["
                . implode(', ', $duplicateReceipts) . "] more than once; conversion aborted before writing any document."
            );
        }

        // Every approved serialized allocation must be represented, or lineage would be
        // silently incomplete rather than duplicated.
        $approvedSerialIds = ConsignmentSerializedAllocation::where('consignment_billing_confirmation_id', $confirmation->id)
            ->where('status', ConsignmentSerializedAllocation::STATUS_APPROVED)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $missingSerials = array_values(array_diff($approvedSerialIds, array_keys($serialCounts)));
        if (! empty($missingSerials)) {
            throw new \LogicException(
                "Billing preview for confirmation #{$confirmation->id} omits approved serialized allocation(s) ["
                . implode(', ', $missingSerials) . "]; conversion aborted before writing any document."
            );
        }

        $unexpectedSerials = array_values(array_diff(array_keys($serialCounts), $approvedSerialIds));
        if (! empty($unexpectedSerials)) {
            throw new \LogicException(
                "Billing preview for confirmation #{$confirmation->id} lists serialized allocation(s) ["
                . implode(', ', $unexpectedSerials) . "] that are not approved evidence; conversion aborted before writing any document."
            );
        }

        // Receipt allocations get the same completeness treatment as serials: duplicates
        // alone would not catch a group whose allocations were dropped, nor one carrying
        // an allocation belonging to a different confirmation.
        $authoritativeReceiptIds = ConsignmentReceiptAllocation::whereIn(
            'consignment_billing_confirmation_line_id',
            ConsignmentBillingConfirmationLine::where('consignment_billing_confirmation_id', $confirmation->id)->pluck('id')
        )
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $missingReceipts = array_values(array_diff($authoritativeReceiptIds, array_keys($receiptCounts)));
        if (! empty($missingReceipts)) {
            throw new \LogicException(
                "Billing preview for confirmation #{$confirmation->id} omits receipt allocation(s) ["
                . implode(', ', $missingReceipts) . "]; conversion aborted before writing any document."
            );
        }

        $foreignReceipts = array_values(array_diff(array_keys($receiptCounts), $authoritativeReceiptIds));
        if (! empty($foreignReceipts)) {
            throw new \LogicException(
                "Billing preview for confirmation #{$confirmation->id} lists receipt allocation(s) ["
                . implode(', ', $foreignReceipts) . "] that do not belong to this confirmation; conversion aborted before writing any document."
            );
        }
    }
}
