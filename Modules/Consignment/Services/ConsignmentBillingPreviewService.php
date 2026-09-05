<?php

namespace Modules\Consignment\Services;

use Modules\Consignment\Entities\ConsignmentBillingConfirmation;
use Modules\Consignment\Entities\ConsignmentReceiptAllocation;
use Modules\Consignment\Entities\ConsignmentSerializedAllocation;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Setting;

class ConsignmentBillingPreviewService
{
    /**
     * Build preview for an approved billing-ready confirmation with user invoice metadata.
     *
     * @param int $confirmationId
     * @param int $settingId
     * @param array{
     *     supplier_invoice_number: string,
     *     invoice_date: string,
     *     reporting_date?: ?string,
     *     due_date?: ?string,
     *     payment_term_id?: ?int,
     *     tax_ref_no?: ?string,
     *     billing_notes?: ?string
     * } $metadata
     * @return array{
     *     valid: bool,
     *     blockers: array<string>,
     *     confirmation: array,
     *     supplier: array,
     *     metadata: array,
     *     lines: array<array>,
     *     totals: array{
     *         sub_total: float,
     *         tax_amount: float,
     *         total_amount: float,
     *         paid_amount: float,
     *         due_amount: float
     *     }
     * }
     */
    public function generatePreview(int $confirmationId, int $settingId, array $metadata): array
    {
        $blockers = [];
        $resolvedDueDate = null;
        $paymentTermId = null;

        /** @var ConsignmentBillingConfirmation|null $confirmation */
        $confirmation = ConsignmentBillingConfirmation::with([
            'supplier',
            'setting',
            'lines.soldSource.serials',
            'lines.soldSource.product',
            'lines.receiptAllocations.receivingDetail.product',
            'lines.receiptAllocations.receivingDetail.receiving.receival',
            'lines.receiptAllocations.receivingDetail.receivalLine',
            'lines.receiptAllocations.tax',
            'serializedAllocations.receivingDetail.product',
            'serializedAllocations.receivingDetail.serialNumbers',
            'serializedAllocations.productSerialNumber',
        ])
            ->where('id', $confirmationId)
            // Scope by active setting in the query itself: a foreign confirmation and a
            // nonexistent one must be indistinguishable, so probing IDs cannot reveal
            // whether a record exists in another setting.
            ->where('setting_id', $settingId)
            ->first();

        if (!$confirmation) {
            return $this->buildErrorResult(['Confirmation record is not available.']);
        }

        /** @var \Modules\People\Entities\Supplier|null $supplier */
        // Suppliers are shared master data across settings; only active status is enforced.
        $supplier = \Modules\People\Entities\Supplier::find($confirmation->supplier_id);

        if (!$supplier) {
            $blockers[] = "Supplier #{$confirmation->supplier_id} is not available.";
        } elseif (isset($supplier->is_active) && !$supplier->is_active) {
            $blockers[] = "Supplier #{$supplier->id} ({$supplier->supplier_name}) is inactive.";
        }

        if (!$confirmation->isApproved()) {
            $blockers[] = "Confirmation status is [{$confirmation->status}]; must be [APPROVED].";
        }

        if (!$confirmation->is_ready_for_billing || $confirmation->isBilled()) {
            $blockers[] = "Confirmation is not ready for billing or has already been converted.";
        }

        // Validate metadata
        $supplierInvoiceNumber = trim($metadata['supplier_invoice_number'] ?? '');
        if (empty($supplierInvoiceNumber)) {
            $blockers[] = "Supplier invoice number is required.";
        }

        $invoiceDateStr = $metadata['invoice_date'] ?? null;
        if (empty($invoiceDateStr)) {
            $blockers[] = "Invoice date is required.";
        }

        $dueDateStr = $metadata['due_date'] ?? null;
        $paymentTermId = !empty($metadata['payment_term_id']) ? (int) $metadata['payment_term_id'] : null;

        if (empty($dueDateStr) && empty($paymentTermId)) {
            $blockers[] = "At least one of due date or payment term is required.";
        }

        $paymentTerm = null;
        if (!empty($paymentTermId)) {
            $paymentTerm = \Modules\Purchase\Entities\PaymentTerm::where('id', $paymentTermId)
                ->where('is_active', true)
                ->first();
            if (!$paymentTerm) {
                $blockers[] = "Selected payment term #{$paymentTermId} is invalid or inactive.";
            }
        }

        if (!empty($invoiceDateStr)) {
            $invoiceCarbon = \Carbon\Carbon::parse($invoiceDateStr)->startOfDay();

            if (!empty($dueDateStr)) {
                $dueCarbon = \Carbon\Carbon::parse($dueDateStr)->startOfDay();
                if ($dueCarbon->lt($invoiceCarbon)) {
                    $blockers[] = "Due date [{$dueDateStr}] cannot be before invoice date [{$invoiceDateStr}].";
                } else {
                    $resolvedDueDate = $dueCarbon->format('Y-m-d');
                }
            } elseif ($paymentTerm) {
                $resolvedDueDate = (clone $invoiceCarbon)->addDays((int) $paymentTerm->longevity)->format('Y-m-d');
            }
        }

        if (empty($blockers) === false && count($blockers) > 0) {
            // Header level blockers prevent preview construction
            return [
                'valid' => false,
                'blockers' => $blockers,
                'confirmation' => $confirmation ? [
                    'id' => $confirmation->id,
                    'confirmation_number' => $confirmation->confirmation_number,
                    'supplier_id' => $confirmation->supplier_id,
                ] : [],
                'supplier' => $confirmation && $confirmation->supplier ? [
                    'id' => $confirmation->supplier->id,
                    'name' => $confirmation->supplier->supplier_name,
                ] : [],
                'metadata' => $metadata,
                'lines' => [],
                'totals' => [
                    'sub_total' => 0.0,
                    'tax_amount' => 0.0,
                    'total_amount' => 0.0,
                    'paid_amount' => 0.0,
                    'due_amount' => 0.0,
                ],
            ];
        }

        // Build deterministic commercial line items from allocations
        $linesResult = $this->buildPreviewLines($confirmation);
        $blockers = array_merge($blockers, $linesResult['blockers']);

        return [
            'valid' => empty($blockers),
            'resolved_due_date' => $resolvedDueDate,
            'payment_term_id' => $paymentTermId,
            'blockers' => $blockers,
            'confirmation' => [
                'id' => $confirmation->id,
                'confirmation_number' => $confirmation->confirmation_number,
                'supplier_id' => $confirmation->supplier_id,
                'date' => $confirmation->date?->format('Y-m-d'),
            ],
            'supplier' => [
                'id' => $confirmation->supplier->id,
                'name' => $confirmation->supplier->supplier_name,
            ],
            'metadata' => [
                'supplier_invoice_number' => $supplierInvoiceNumber,
                'invoice_date' => $invoiceDateStr,
                'reporting_date' => $metadata['reporting_date'] ?? $invoiceDateStr,
                'due_date' => $resolvedDueDate,
                'payment_term_id' => $paymentTermId,
                'tax_ref_no' => $metadata['tax_ref_no'] ?? null,
                'billing_notes' => $metadata['billing_notes'] ?? null,
            ],
            'lines' => $linesResult['lines'],
            'totals' => $linesResult['totals'],
        ];
    }

    /**
     * Build preview lines grouped by distinct commercial snapshot.
     *
     * @return array{
     *     lines: array<array>,
     *     totals: array{
     *         sub_total: float,
     *         tax_amount: float,
     *         total_amount: float,
     *         paid_amount: float,
     *         due_amount: float
     *     },
     *     blockers: array<string>
     * }
     */
    protected function buildPreviewLines(ConsignmentBillingConfirmation $confirmation): array
    {
        $blockers = [];
        $groups = [];
        $totalConfirmedQuantity = 0.0;
        $totalAllocatedQuantity = 0.0;

        foreach ($confirmation->lines as $line) {
            $totalConfirmedQuantity += (float) $line->allocated_base_quantity;

            // Revalidate line-level confirmation evidence against sold source
            $soldSource = $line->soldSource;
            if (!$soldSource) {
                $blockers[] = "Confirmation line #{$line->id} is missing its sold source evidence.";
            } else {
                if ((int) $soldSource->setting_id !== (int) $confirmation->setting_id) {
                    $blockers[] = "Sold source #{$soldSource->id} setting [#{$soldSource->setting_id}] does not match confirmation setting [#{$confirmation->setting_id}].";
                }
                if ((int) $soldSource->product_id !== (int) $line->product_id) {
                    $blockers[] = "Sold source #{$soldSource->id} product [#{$soldSource->product_id}] does not match confirmation line product [#{$line->product_id}].";
                }
                if ((int) $soldSource->location_id !== (int) $line->location_id) {
                    $blockers[] = "Sold source #{$soldSource->id} location [#{$soldSource->location_id}] does not match confirmation line location [#{$line->location_id}].";
                }
            }

            if ($line->receiptAllocations->isEmpty()) {
                $blockers[] = "Confirmation line #{$line->id} (product #{$line->product_id}) has no receipt allocations.";
                continue;
            }

            $lineReceiptQty = 0.0;
            foreach ($line->receiptAllocations as $alloc) {
                $qty = (float) $alloc->allocated_base_quantity;
                $lineReceiptQty += $qty;
                $totalAllocatedQuantity += $qty;

                $crd = $alloc->receivingDetail;
                if (!$crd) {
                    $blockers[] = "Allocation #{$alloc->id} has missing receiving detail evidence.";
                    continue;
                }

                $product = $crd->product;
                if (!$product) {
                    $blockers[] = "Receiving detail #{$crd->id} has missing product evidence.";
                    continue;
                }

                if ((int) $crd->product_id !== (int) $line->product_id) {
                    $blockers[] = "Receiving detail #{$crd->id} product [#{$crd->product_id}] does not match confirmation line product [#{$line->product_id}].";
                }

                $receiving = $crd->receiving;
                if (!$receiving) {
                    $blockers[] = "Receiving detail #{$crd->id} has missing receiving document evidence.";
                    continue;
                }

                if ((int) $receiving->setting_id !== (int) $confirmation->setting_id) {
                    $blockers[] = "Receiving #{$receiving->id} setting [#{$receiving->setting_id}] does not match confirmation setting [#{$confirmation->setting_id}].";
                }

                $recSupplierId = $receiving->supplier_id ?? $receiving->receival?->supplier_id;
                if ((int) $recSupplierId !== (int) $confirmation->supplier_id) {
                    $blockers[] = "Receiving #{$receiving->id} supplier [#{$recSupplierId}] does not match confirmation supplier [#{$confirmation->supplier_id}].";
                }

                if ((int) $receiving->location_id !== (int) $line->location_id) {
                    $blockers[] = "Receiving #{$receiving->id} location [#{$receiving->location_id}] does not match confirmation line location [#{$line->location_id}].";
                }

                if ($receiving->status !== \Modules\Consignment\Entities\ConsignmentReceiving::STATUS_APPROVED) {
                    $blockers[] = "Receiving #{$receiving->id} status is [{$receiving->status}]; must be [APPROVED].";
                }

                $receival = $receiving->receival;
                if (!$receival) {
                    $blockers[] = "Receiving detail #{$crd->id} has missing receival document evidence.";
                    continue;
                }

                if ((int) $receival->setting_id !== (int) $confirmation->setting_id) {
                    $blockers[] = "Receival #{$receival->id} setting [#{$receival->setting_id}] does not match confirmation setting [#{$confirmation->setting_id}].";
                }

                if ((int) $receival->supplier_id !== (int) $confirmation->supplier_id) {
                    $blockers[] = "Receival #{$receival->id} supplier [#{$receival->supplier_id}] does not match confirmation supplier [#{$confirmation->supplier_id}].";
                }

                if ($receival->status !== \Modules\Consignment\Entities\ConsignmentReceival::STATUS_APPROVED) {
                    $blockers[] = "Receival #{$receival->id} status is [{$receival->status}]; must be [APPROVED].";
                }

                // Revalidate receipt-allocation cost, DPP, tax against receiving detail / receival line snapshots
                $unitCost = (float) $alloc->unit_cost;
                $unitDpp = (float) $alloc->unit_dpp;
                $taxId = $alloc->tax_id !== null ? (int) $alloc->tax_id : null;
                $crdTaxId = $crd->tax_id !== null ? (int) $crd->tax_id : null;
                $taxRate = (float) ($alloc->tax_rate ?? ($alloc->tax?->value ?? 0));
                $crdTaxRate = (float) ($crd->tax_rate ?? ($crd->tax?->value ?? 0));

                if (abs($unitCost - (float) $crd->unit_cost) > 0.001) {
                    $blockers[] = "Receipt allocation #{$alloc->id} unit cost [{$unitCost}] does not match receiving detail unit cost [{$crd->unit_cost}].";
                }
                if (abs($unitDpp - (float) $crd->unit_dpp) > 0.001) {
                    $blockers[] = "Receipt allocation #{$alloc->id} unit DPP [{$unitDpp}] does not match receiving detail unit DPP [{$crd->unit_dpp}].";
                }
                if ($taxId !== $crdTaxId) {
                    $blockers[] = "Receipt allocation #{$alloc->id} tax ID [" . ($taxId ?? 'NULL') . "] does not match receiving detail tax ID [" . ($crdTaxId ?? 'NULL') . "].";
                }
                if (abs($taxRate - $crdTaxRate) > 0.0001) {
                    $blockers[] = "Receipt allocation #{$alloc->id} tax rate [{$taxRate}] does not match receiving detail tax rate [{$crdTaxRate}].";
                }

                $soldSource = $line->soldSource;
                $soldSideSerials = [];
                if ($soldSource) {
                    $soldSideSerials = $soldSource->serials->pluck('product_serial_number_id')->filter()->map(fn($v) => (int) $v)->all();
                    if (empty($soldSideSerials) && !empty($soldSource->serial_identities)) {
                        $soldSideSerials = \Modules\Product\Entities\ProductSerialNumber::where('product_id', $soldSource->product_id)
                            ->whereIn('serial_number', (array) $soldSource->serial_identities)
                            ->pluck('id')->map(fn($v) => (int) $v)->all();
                    }
                }

                $soldSideIsSerialized = !empty($soldSideSerials)
                    || !empty($soldSource?->serial_identities)
                    || (bool) ($soldSource?->product?->is_serialized ?? $soldSource?->product?->has_serial_number ?? false);

                $receivingSideIsSerialized = (bool) ($crd->receivalLine?->is_serialized ?? ($crd->product?->is_serialized ?? $crd->product?->has_serial_number ?? false));

                if ($soldSideIsSerialized !== $receivingSideIsSerialized) {
                    $blockers[] = "Confirmation line #{$line->id} sold-side serialization [" . ($soldSideIsSerialized ? 'true' : 'false') . "] does not match receiving detail #{$crd->id} serialization [" . ($receivingSideIsSerialized ? 'true' : 'false') . "].";
                }

                $isSerialized = $soldSideIsSerialized || $receivingSideIsSerialized;

                if ($isSerialized && fmod($qty, 1.0) != 0.0) {
                    $blockers[] = "Serialized receipt allocation #{$alloc->id} quantity [{$qty}] must be integral.";
                }

                $expectedTaxAmount = round(($unitDpp > 0 ? $unitDpp : $unitCost) * $qty * ($taxRate / 100.0), 2);
                $allocTaxAmount = (float) $alloc->tax_amount;
                $crdTotalTaxAmount = (float) ($crd->tax_amount ?? 0);

                // Tax evidence classification is explicit: it comes from the indexed
                // tax_snapshot_version column, never inferred from a missing marker.
                // Every pre-existing allocation was migrated to version 1 at cutover, so a
                // missing/unknown version means corrupted or hand-written evidence and blocks.
                $snapshotVersion = $alloc->tax_snapshot_version;
                $isLegacyFullLotTax = false;

                if ($snapshotVersion === null) {
                    $blockers[] = "Receipt allocation #{$alloc->id} has no tax snapshot version; tax evidence cannot be classified.";
                } elseif (!in_array((int) $snapshotVersion, ConsignmentReceiptAllocation::SUPPORTED_TAX_SNAPSHOT_VERSIONS, true)) {
                    $blockers[] = "Receipt allocation #{$alloc->id} has unsupported tax snapshot version [{$snapshotVersion}].";
                } else {
                    // Legacy (v1) allocations stored the full-lot receiving-detail tax; accept
                    // that shape only when it exactly matches the receiving detail total.
                    $isLegacyFullLotTax = ((int) $snapshotVersion === ConsignmentReceiptAllocation::TAX_SNAPSHOT_VERSION_LEGACY)
                        && abs($allocTaxAmount - $crdTotalTaxAmount) < 0.001
                        && $qty < (float) $crd->quantity_received;

                    if (abs($allocTaxAmount - $expectedTaxAmount) > 0.001 && !$isLegacyFullLotTax) {
                        $blockers[] = "Receipt allocation #{$alloc->id} tax amount [{$alloc->tax_amount}] does not match expected tax calculation [{$expectedTaxAmount}].";
                    }
                }

                // Billed tax amount to record on Purchase detail & lineage: normalized proportional tax for legacy or stored tax for v2
                $billedTaxAmount = $isLegacyFullLotTax ? $expectedTaxAmount : $allocTaxAmount;

                // Commercial snapshot grouping key
                $groupKey = implode('|', [
                    $crd->product_id,
                    number_format($unitCost, 2, '.', ''),
                    number_format($unitDpp, 2, '.', ''),
                    $taxId ?? '0',
                    number_format($taxRate, 4, '.', ''),
                ]);

                if (!isset($groups[$groupKey])) {
                    $groups[$groupKey] = [
                        'product_id' => $crd->product_id,
                        'product_name' => $product->product_name,
                        'product_code' => $product->product_code,
                        'quantity' => 0.0,
                        'unit_cost' => $unitCost,
                        'unit_dpp' => $unitDpp,
                        'unit_price' => $unitDpp > 0 ? $unitDpp : $unitCost,
                        'tax_id' => $taxId,
                        'tax_rate' => $taxRate,
                        'tax_name' => $alloc->tax?->name,
                        'allocations' => [],
                        'serialized_allocations' => [],
                    ];
                }

                $groups[$groupKey]['quantity'] += $qty;
                $groups[$groupKey]['allocations'][] = [
                    'receipt_allocation_id' => $alloc->id,
                    'confirmation_line_id' => $line->id,
                    'receiving_detail_id' => $crd->id,
                    'billed_base_quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'unit_dpp' => $unitDpp,
                    'tax_id' => $taxId,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $billedTaxAmount,
                    'original_stored_tax_amount' => $allocTaxAmount,
                    'is_legacy_full_lot_tax' => $isLegacyFullLotTax,
                    'tax_snapshot_version' => $snapshotVersion,
                    'is_serialized' => $isSerialized,
                ];
            }

            if (abs($lineReceiptQty - (float) $line->allocated_base_quantity) > 0.0001) {
                $blockers[] = "Confirmation line #{$line->id} quantity [{$line->allocated_base_quantity}] does not match total receipt allocations [{$lineReceiptQty}].";
            }
        }

        // Validate overall quantity reconciliation
        if (abs($totalConfirmedQuantity - $totalAllocatedQuantity) > 0.0001) {
            $blockers[] = "Total confirmation quantity [{$totalConfirmedQuantity}] does not match total receipt allocation quantity [{$totalAllocatedQuantity}].";
        }

        $seenSerials = [];

        // Link serialized allocations if present
        foreach ($confirmation->serializedAllocations as $serAlloc) {
            if ($serAlloc->status !== \Modules\Consignment\Entities\ConsignmentSerializedAllocation::STATUS_APPROVED) {
                $blockers[] = "Serialized allocation #{$serAlloc->id} status is [{$serAlloc->status}]; must be [APPROVED].";
            }

            $crd = $serAlloc->receivingDetail;
            if (!$crd) {
                $blockers[] = "Serialized allocation #{$serAlloc->id} has missing receiving detail evidence.";
            } elseif (!$serAlloc->productSerialNumber) {
                $blockers[] = "Serialized allocation #{$serAlloc->id} has missing product serial number evidence.";
            } else {
                if ((int) $serAlloc->productSerialNumber->product_id !== (int) $crd->product_id) {
                    $blockers[] = "Serialized allocation #{$serAlloc->id} serial product [#{$serAlloc->productSerialNumber->product_id}] does not match receiving detail product [#{$crd->product_id}].";
                }

                $hasSerialInCrd = $crd->serialNumbers->contains('id', $serAlloc->product_serial_number_id);
                if (!$hasSerialInCrd) {
                    $blockers[] = "Serialized allocation #{$serAlloc->id} serial #{$serAlloc->product_serial_number_id} is not present in receiving detail #{$crd->id} provenance.";
                }
            }

            $crdId = (int) $serAlloc->consignment_receiving_detail_id;
            $confLineId = (int) $serAlloc->consignment_billing_confirmation_line_id;

            $confLine = $confirmation->lines->firstWhere('id', $confLineId);
            if (!$confLine) {
                $blockers[] = "Serialized allocation #{$serAlloc->id} references missing confirmation line #{$confLineId}.";
            } else {
                if ((int) $serAlloc->consignment_sold_source_id !== (int) $confLine->consignment_sold_source_id) {
                    $blockers[] = "Serialized allocation #{$serAlloc->id} sold source ID [#{$serAlloc->consignment_sold_source_id}] does not match confirmation line #{$confLine->id} sold source ID [#{$confLine->consignment_sold_source_id}].";
                }

                $soldSource = $confLine->soldSource;
                if ($soldSource) {
                    $soldSideSerialIds = $soldSource->serials->pluck('product_serial_number_id')->filter()->map(fn($v) => (int) $v)->all();
                    $soldSideSerialStrings = is_array($soldSource->serial_identities) ? $soldSource->serial_identities : [];

                    $isValidSerial = false;
                    if (!empty($soldSideSerialIds)) {
                        $isValidSerial = in_array((int) $serAlloc->product_serial_number_id, $soldSideSerialIds, true);
                    } elseif (!empty($soldSideSerialStrings)) {
                        $allocSnStr = $serAlloc->productSerialNumber?->serial_number;
                        $normalizedSoldStrings = array_map(fn ($s) => ProductSerialNumber::normalize((string) $s), $soldSideSerialStrings);
                        $isValidSerial = $allocSnStr !== null && in_array(ProductSerialNumber::normalize((string) $allocSnStr), $normalizedSoldStrings, true);
                    } else {
                        $isValidSerial = true;
                    }

                    if (!$isValidSerial) {
                        $blockers[] = "Serialized allocation #{$serAlloc->id} serial #{$serAlloc->product_serial_number_id} is not present in sold source #{$soldSource->id} serial identities.";
                    }
                }
            }

            $serialId = (int) $serAlloc->product_serial_number_id;
            if (isset($seenSerials[$serialId])) {
                $blockers[] = "Duplicate serial allocation #{$serAlloc->id}: physical serial #{$serialId} is allocated more than once across confirmation.";
            }
            $seenSerials[$serialId] = true;

            $matchedGroup = false;

            // Write through $groups[$groupKey] rather than iterating by reference. A
            // `foreach ($groups as $gk => &$grp)` leaves $grp bound to the final element
            // after the loop, so every later `foreach ($groups as $grp)` would overwrite
            // that element with each value it visits, silently duplicating one group's
            // contents and dropping another's.
            foreach ($groups as $groupKey => $group) {
                foreach ($group['allocations'] as $allocMeta) {
                    if ((int) $allocMeta['receiving_detail_id'] === $crdId && (int) $allocMeta['confirmation_line_id'] === $confLineId) {
                        if (empty($allocMeta['is_serialized'])) {
                            $blockers[] = "Non-serialized confirmation line #{$confLineId} receiving detail #{$crdId} has attached serial allocation #{$serAlloc->id}.";
                        }

                        $groups[$groupKey]['serialized_allocations'][] = [
                            'serialized_allocation_id' => $serAlloc->id,
                            'confirmation_line_id' => $confLineId,
                            'product_serial_number_id' => $serAlloc->product_serial_number_id,
                            'serial_number' => $serAlloc->productSerialNumber?->serial_number,
                            'receiving_detail_id' => $crdId,
                        ];
                        $matchedGroup = true;
                        break;
                    }
                }

                if ($matchedGroup) {
                    break;
                }
            }
            if (!$matchedGroup) {
                $blockers[] = "Serialized allocation #{$serAlloc->id} (serial #{$serAlloc->product_serial_number_id}) could not be matched to any receipt allocation line.";
            }
        }

        // Reconcile serial cardinality per receipt allocation
        foreach ($groups as $grp) {
            foreach ($grp['allocations'] as $allocMeta) {
                if (!empty($allocMeta['is_serialized'])) {
                    $crdId = (int) $allocMeta['receiving_detail_id'];
                    $confLineId = (int) $allocMeta['confirmation_line_id'];
                    $requiredQty = (int) $allocMeta['billed_base_quantity'];

                    $matchedSerAllocs = array_values(array_filter($grp['serialized_allocations'], function ($serMeta) use ($crdId, $confLineId) {
                        return (int) $serMeta['receiving_detail_id'] === $crdId
                            && (int) ($serMeta['confirmation_line_id'] ?? $confLineId) === $confLineId;
                    }));

                    $actualCount = count($matchedSerAllocs);
                    if ($actualCount !== $requiredQty) {
                        $blockers[] = "Serialized confirmation line #{$confLineId} receiving detail #{$crdId} requires [{$requiredQty}] serials, but [{$actualCount}] approved serial allocations were provided.";
                    }
                }
            }
        }

        // Process final calculations per preview line
        $previewLines = [];
        $headerSubTotal = 0.0;
        $headerTaxAmount = 0.0;

        foreach ($groups as $key => $grp) {
            $qty = (float) $grp['quantity'];
            $unitPrice = (float) $grp['unit_price'];
            $lineSubTotal = round($qty * $unitPrice, 2);

            $taxRate = (float) $grp['tax_rate'];
            $lineTaxAmount = round(array_sum(array_column($grp['allocations'], 'tax_amount')), 2);
            $lineTotalAmount = round($lineSubTotal + $lineTaxAmount, 2);

            // Sanity check monetary values
            if (is_nan($lineTotalAmount) || is_infinite($lineTotalAmount) || $lineTotalAmount < 0) {
                $blockers[] = "Calculated line total [{$lineTotalAmount}] for product #{$grp['product_id']} is invalid.";
            }

            $headerSubTotal += $lineSubTotal;
            $headerTaxAmount += $lineTaxAmount;

            $previewLines[] = [
                'product_id' => $grp['product_id'],
                'product_name' => $grp['product_name'],
                'product_code' => $grp['product_code'],
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'sub_total' => $lineSubTotal,
                'tax_id' => $grp['tax_id'],
                'tax_rate' => $taxRate,
                'tax_name' => $grp['tax_name'],
                'product_tax_amount' => $lineTaxAmount,
                'total_amount' => $lineTotalAmount,
                'allocations' => $grp['allocations'],
                'serialized_allocations' => $grp['serialized_allocations'],
            ];
        }

        $headerTotalAmount = round($headerSubTotal + $headerTaxAmount, 2);

        return [
            'lines' => $previewLines,
            'totals' => [
                'sub_total' => round($headerSubTotal, 2),
                'tax_amount' => round($headerTaxAmount, 2),
                'total_amount' => round($headerTotalAmount, 2),
                'paid_amount' => 0.0,
                'due_amount' => round($headerTotalAmount, 2),
            ],
            'blockers' => $blockers,
        ];
    }

    private function buildErrorResult(array $blockers): array
    {
        return [
            'valid' => false,
            'blockers' => $blockers,
            'confirmation' => [],
            'supplier' => [],
            'metadata' => [],
            'lines' => [],
            'totals' => [
                'sub_total' => 0.0,
                'tax_amount' => 0.0,
                'total_amount' => 0.0,
                'paid_amount' => 0.0,
                'due_amount' => 0.0,
            ],
        ];
    }
}
