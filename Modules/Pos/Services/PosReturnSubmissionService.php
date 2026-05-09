<?php

namespace Modules\Pos\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Sale\Entities\SaleDetails;
use App\Support\PosReturn\PosReturnQuantityGuard;
use Modules\Setting\Entities\Setting;
use Modules\Pos\Entities\PosCheckoutSale;

class PosReturnSubmissionService
{
    protected $snapshotService;
    protected $quantityGuard;
    protected $replacementGuard;

    public function __construct(PosReturnSnapshotService $snapshotService, PosReturnQuantityGuard $quantityGuard, PosReturnReplacementGuard $replacementGuard)
    {
        $this->snapshotService = $snapshotService;
        $this->quantityGuard = $quantityGuard;
        $this->replacementGuard = $replacementGuard;
    }

    /**
     * Store a new POS return draft.
     *
     * @param array $data
     * @return \Modules\Pos\Entities\PosReturn
     * @throws \Exception
     */
    public function store(array $data)
    {
        return DB::transaction(function () use ($data) {
            $transaction = PosTransaction::with(['customer', 'completedCheckout'])
                ->findOrFail($data['pos_transaction_id']);
            $checkout = $transaction->completedCheckout;

            if (!$checkout) {
                throw new \Exception("Transaction has no completed checkout.");
            }

            $currentSnapshot = $this->snapshotService->build($transaction->id);
            $validatedLines = $this->validateDraftLines(
                $data['lines'] ?? [],
                $currentSnapshot,
                $data['source_snapshot_hash'] ?? null,
                null,
                $data['return_option'] ?? null
            );

            // 3. Create POS Return header as Draft
            $posReturn = PosReturn::create([
                'reference' => $this->generatePosReturnReference($transaction->setting_id),
                'setting_id' => $transaction->setting_id,
                'pos_transaction_id' => $transaction->id,
                'pos_checkout_id' => $checkout->id,
                'transaction_code' => $transaction->code,
                'receipt_number' => $checkout->receipt_number,
                'customer_id' => $transaction->customer_id,
                'customer_name' => $transaction->customer_id ? (optional($transaction->customer)->customer_name ?? '-') : 'Walk-in Customer',
                'return_option' => PosReturn::OPTION_CASH_RETURN, // Keep standard option logic or defer to line-level
                'status' => PosReturn::STATUS_DRAFT,
                'approval_status' => PosReturn::APPROVAL_STATUS_DRAFT,
                'source_snapshot' => $currentSnapshot,
                'source_snapshot_hash' => $currentSnapshot['hash'],
                'total_amount' => 0, // Will be updated
                'created_by' => Auth::id(),
            ]);

            $totalAmount = 0;
            $checkoutSales = PosCheckoutSale::where('pos_checkout_id', $checkout->id)
                ->get()
                ->keyBy('sale_id');

            // 4. Process lines
            foreach ($validatedLines as $lineData) {
                $resolution = $lineData['resolution'] ?? PosReturnLine::RESOLUTION_NONE;
                $quantity = (float) ($lineData['quantity'] ?? 0);
                $returnedSerialId = $lineData['returned_serial_id'] ?? null;
                $isSerial = !empty($returnedSerialId);

                $saleDetail = SaleDetails::with(['bundleItems.product'])->findOrFail($lineData['sale_detail_id']);
                $checkoutSale = $checkoutSales->get($lineData['sale_id'] ?? $saleDetail->sale_id);
                
                if (!$checkoutSale) {
                    throw new \Exception("Checkout sale not found for sale_id {$saleDetail->sale_id}.");
                }

                // Guard returnable quantity - for non-serial, we check the general returnable qty
                // For serials, the qty is always 1, but we should ensure the specific serial is returnable.
                $returnableQty = $this->quantityGuard->getReturnableQuantity($saleDetail->dispatch_detail_id, $saleDetail->id);
                if (!$isSerial && $quantity > $returnableQty) {
                    throw new \Exception("Kuantitas retur melebihi batas yang diizinkan untuk produk {$saleDetail->product_name}.");
                }

                $isBundle = $saleDetail->bundleItems->isNotEmpty();
                $unitPrice = (float) $saleDetail->unit_price;
                if ($isBundle && $unitPrice === 0.0 && $saleDetail->quantity > 0) {
                    $unitPrice = (float) $checkoutSale->subtotal / $saleDetail->quantity;
                }

                // Task 3.9: For bundled serials, use the full original POS transaction line unit price
                // as the customer-facing price. This handles split-posting scenarios where the parent
                // Sale Detail price is the residual allocation, not the customer-visible bundle price.
                $ptl = null;
                $ptlIsBundle = false;
                if ($isSerial && !empty($lineData['pos_transaction_line_id'])) {
                    $ptl = \Modules\Pos\Entities\PosTransactionLine::find($lineData['pos_transaction_line_id']);
                    if ($ptl) {
                        $ptlIsBundle = !empty($ptl->line_meta['bundle_id'])
                            || !empty($ptl->line_meta['is_bundle'])
                            || !empty($ptl->line_meta['bundle_items']);
                        if ($ptlIsBundle && $ptl->unit_price > 0) {
                            $unitPrice = (float) $ptl->unit_price;
                            $isBundle = true;
                        }
                    }
                }

                $lineTotal = $quantity * $unitPrice;
                // For serials, qty=1, so line_total equals unit_price.
                if ($isSerial) {
                    $lineTotal = $unitPrice;
                }
                $expectedCashAmount = $resolution === PosReturnLine::RESOLUTION_CASH_RETURN ? $lineTotal : 0;
                
                $replacementSerialId = $resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT ? ($lineData['replacement_serial_id'] ?? null) : null;
                
                // 4.6 Validate replacement serial
                if ($replacementSerialId) {
                    $this->replacementGuard->validateReplacementSerial(
                        $saleDetail->product_id,
                        $replacementSerialId,
                        $returnedSerialId
                    );
                }

                // Create the return line using the specific source identity
                $returnLineData = [
                    'pos_return_id' => $posReturn->id,
                    'pos_checkout_sale_id' => $checkoutSale->id,
                    'sale_id' => $saleDetail->sale_id,
                    'sale_detail_id' => $saleDetail->id,
                    'dispatch_detail_id' => $saleDetail->dispatch_detail_id,
                    'pos_transaction_line_id' => $lineData['pos_transaction_line_id'] ?? null,
                    'source_setting_id' => $checkoutSale->source_setting_id,
                    'source_location_id' => $checkoutSale->source_location_id,
                    'tax_id' => $saleDetail->tax_id,
                    'product_id' => $saleDetail->product_id,
                    'product_name' => $saleDetail->product_name,
                    'product_code' => $saleDetail->product_code,
                    'quantity' => $isSerial ? 1 : $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'stock_behavior' => $saleDetail->product->stock_managed ? PosReturnLine::STOCK_BEHAVIOR_MANAGED : PosReturnLine::STOCK_BEHAVIOR_STOCKLESS,
                    'resolution' => $resolution,
                    'returned_serial_id' => $returnedSerialId,
                    'replacement_serial_id' => $replacementSerialId,
                    'expected_cash_amount' => $expectedCashAmount,
                ];

                $returnLine = PosReturnLine::create($returnLineData);
                if ($resolution === PosReturnLine::RESOLUTION_CASH_RETURN) {
                    $totalAmount += $expectedCashAmount;
                }

                // Task 3.7/3.9: Trace bundled components for actionable lines.
                // Task 3.10: Informational only — no stock reservation or mutation occurs here.
                if ($isBundle && $resolution !== PosReturnLine::RESOLUTION_NONE) {
                    // Task 3.9: Use PTL bundle items as authoritative source when available.
                    if ($ptlIsBundle && !empty($ptl->line_meta['bundle_items'])) {
                        $bundleTrace = array_map(function ($item) use ($quantity) {
                            return [
                                'product_id' => $item['product_id'] ?? null,
                                'quantity_per_bundle' => (float)($item['quantity'] ?? $item['qty'] ?? 0),
                                'total_component_quantity' => (float)($item['quantity'] ?? $item['qty'] ?? 0) * $quantity,
                            ];
                        }, $ptl->line_meta['bundle_items']);
                    } else {
                        $bundleTrace = $saleDetail->bundleItems->map(function ($bi) use ($quantity) {
                            return [
                                'product_id' => $bi->product_id,
                                'quantity_per_bundle' => $bi->quantity,
                                'total_component_quantity' => $bi->quantity * $quantity
                            ];
                        })->toArray();
                    }
                    if (!empty($bundleTrace)) {
                        $returnLine->update(['line_meta' => ['bundle_trace' => $bundleTrace]]);
                    }
                }
            }

            $posReturn->update(['total_amount' => $totalAmount]);

            return $posReturn;
        });
    }

    public function update(PosReturn $posReturn, array $data)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.edit'), 403);
        
        if (!$posReturn->isDraftEditable()) {
            throw new \Exception('Hanya retur draft yang dapat diubah.');
        }

        return DB::transaction(function () use ($posReturn, $data) {
            // Delete old associated draft lines
            $posReturn->lines()->delete();
            
            $transaction = $posReturn->posTransaction()->with(['customer', 'completedCheckout'])->firstOrFail();
            $currentSnapshot = $this->snapshotService->build($transaction->id);
            $validatedLines = $this->validateDraftLines(
                $data['lines'] ?? [],
                $currentSnapshot,
                $data['source_snapshot_hash'] ?? null,
                $posReturn->id,
                $data['return_option'] ?? $posReturn->return_option
            );

            $totalAmount = 0;
            $checkoutSales = PosCheckoutSale::where('pos_checkout_id', $posReturn->pos_checkout_id)
                ->get()
                ->keyBy('sale_id');

            foreach ($validatedLines as $lineData) {
                $resolution = $lineData['resolution'] ?? PosReturnLine::RESOLUTION_NONE;
                $quantity = (float) ($lineData['quantity'] ?? 0);
                $returnedSerialId = $lineData['returned_serial_id'] ?? null;
                $isSerial = !empty($returnedSerialId);

                $saleDetail = SaleDetails::with(['bundleItems.product'])->findOrFail($lineData['sale_detail_id']);
                $checkoutSale = $checkoutSales->get($lineData['sale_id'] ?? $saleDetail->sale_id);

                $returnableQty = $this->quantityGuard->getReturnableQuantity($saleDetail->dispatch_detail_id, $saleDetail->id, $posReturn->id);
                if (!$isSerial && $quantity > $returnableQty) {
                    throw new \Exception("Kuantitas retur melebihi batas yang diizinkan untuk produk {$saleDetail->product_name}.");
                }

                $isBundle = $saleDetail->bundleItems->isNotEmpty();
                $unitPrice = (float) $saleDetail->unit_price;
                if ($isBundle && $unitPrice === 0.0 && $saleDetail->quantity > 0) {
                    $unitPrice = (float) $checkoutSale->subtotal / $saleDetail->quantity;
                }

                // Task 3.9: For bundled serials, use the full original POS transaction line unit price.
                $ptl = null;
                $ptlIsBundle = false;
                if ($isSerial && !empty($lineData['pos_transaction_line_id'])) {
                    $ptl = \Modules\Pos\Entities\PosTransactionLine::find($lineData['pos_transaction_line_id']);
                    if ($ptl) {
                        $ptlIsBundle = !empty($ptl->line_meta['bundle_id'])
                            || !empty($ptl->line_meta['is_bundle'])
                            || !empty($ptl->line_meta['bundle_items']);
                        if ($ptlIsBundle && $ptl->unit_price > 0) {
                            $unitPrice = (float) $ptl->unit_price;
                            $isBundle = true;
                        }
                    }
                }

                $lineTotal = $isSerial ? $unitPrice : ($quantity * $unitPrice);
                $expectedCashAmount = $resolution === PosReturnLine::RESOLUTION_CASH_RETURN ? $lineTotal : 0;
                
                $replacementSerialId = $resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT ? ($lineData['replacement_serial_id'] ?? null) : null;
                
                // 4.6 Validate replacement serial
                if ($replacementSerialId) {
                    $this->replacementGuard->validateReplacementSerial(
                        $saleDetail->product_id,
                        $replacementSerialId,
                        $returnedSerialId,
                        $posReturn->id
                    );
                }

                $returnLineData = [
                    'pos_return_id' => $posReturn->id,
                    'pos_checkout_sale_id' => $checkoutSale->id,
                    'sale_id' => $saleDetail->sale_id,
                    'sale_detail_id' => $saleDetail->id,
                    'dispatch_detail_id' => $saleDetail->dispatch_detail_id,
                    'pos_transaction_line_id' => $lineData['pos_transaction_line_id'] ?? null,
                    'source_setting_id' => $checkoutSale->source_setting_id,
                    'source_location_id' => $checkoutSale->source_location_id,
                    'tax_id' => $saleDetail->tax_id,
                    'product_id' => $saleDetail->product_id,
                    'product_name' => $saleDetail->product_name,
                    'product_code' => $saleDetail->product_code,
                    'quantity' => $isSerial ? 1 : $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'stock_behavior' => $saleDetail->product->stock_managed ? PosReturnLine::STOCK_BEHAVIOR_MANAGED : PosReturnLine::STOCK_BEHAVIOR_STOCKLESS,
                    'resolution' => $resolution,
                    'returned_serial_id' => $returnedSerialId,
                    'replacement_serial_id' => $replacementSerialId,
                    'expected_cash_amount' => $expectedCashAmount,
                ];

                $returnLine = PosReturnLine::create($returnLineData);
                if ($resolution === PosReturnLine::RESOLUTION_CASH_RETURN) {
                    $totalAmount += $expectedCashAmount;
                }

                // Task 3.7/3.9: Trace bundled components for actionable lines.
                // Task 3.10: Informational only — no stock reservation or mutation occurs here.
                if ($isBundle && $resolution !== PosReturnLine::RESOLUTION_NONE) {
                    if ($ptlIsBundle && !empty($ptl->line_meta['bundle_items'])) {
                        $bundleTrace = array_map(function ($item) use ($quantity) {
                            return [
                                'product_id' => $item['product_id'] ?? null,
                                'quantity_per_bundle' => (float)($item['quantity'] ?? $item['qty'] ?? 0),
                                'total_component_quantity' => (float)($item['quantity'] ?? $item['qty'] ?? 0) * $quantity,
                            ];
                        }, $ptl->line_meta['bundle_items']);
                    } else {
                        $bundleTrace = $saleDetail->bundleItems->map(function ($bi) use ($quantity) {
                            return [
                                'product_id' => $bi->product_id,
                                'quantity_per_bundle' => $bi->quantity,
                                'total_component_quantity' => $bi->quantity * $quantity
                            ];
                        })->toArray();
                    }
                    if (!empty($bundleTrace)) {
                        $returnLine->update(['line_meta' => ['bundle_trace' => $bundleTrace]]);
                    }
                }
            }

            $posReturn->update([
                'total_amount' => $totalAmount,
                'status' => PosReturn::STATUS_DRAFT,
                'approval_status' => PosReturn::APPROVAL_STATUS_DRAFT,
                'source_snapshot' => $currentSnapshot,
                'source_snapshot_hash' => $currentSnapshot['hash'],
                'updated_by' => Auth::id(),
            ]);

            return $posReturn->refresh();
        });
    }

    public function submitDraftForApproval(PosReturn $posReturn): PosReturn
    {
        if (!$posReturn->isDraftSubmittable()) {
            throw new \Exception('Hanya retur draft yang dapat diajukan untuk persetujuan.');
        }

        return DB::transaction(function () use ($posReturn) {
            $lockedReturn = PosReturn::query()
                ->with('lines')
                ->whereKey($posReturn->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$lockedReturn->isDraftSubmittable()) {
                throw new \Exception('Hanya retur draft yang dapat diajukan untuk persetujuan.');
            }

            $transaction = $lockedReturn->posTransaction()->with(['customer', 'completedCheckout'])->firstOrFail();
            $currentSnapshot = $this->snapshotService->build($transaction->id);

            $this->validateDraftLines(
                $lockedReturn->lines->map(function (PosReturnLine $line) {
                    return [
                        'sale_detail_id' => $line->sale_detail_id,
                        'sale_id' => $line->sale_id,
                        'pos_transaction_line_id' => $line->pos_transaction_line_id,
                        'returned_serial_id' => $line->returned_serial_id,
                        'resolution' => $line->resolution,
                        'quantity' => (float) $line->quantity,
                        'replacement_serial_id' => $line->replacement_serial_id,
                    ];
                })->all(),
                $currentSnapshot,
                $lockedReturn->source_snapshot_hash,
                $lockedReturn->id
            );

            $lockedReturn->update([
                'status' => PosReturn::STATUS_PENDING_APPROVAL,
                'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
                'source_snapshot' => $currentSnapshot,
                'source_snapshot_hash' => $currentSnapshot['hash'],
                'updated_by' => Auth::id(),
            ]);

            return $lockedReturn->refresh();
        });
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $currentSnapshot
     * @return array<int, array<string, mixed>>
     */
    protected function validateDraftLines(array $lines, array $currentSnapshot, ?string $expectedSnapshotHash, ?int $ignorePosReturnId = null, ?string $defaultReturnOption = null): array
    {
        if (($currentSnapshot['hash'] ?? null) !== $expectedSnapshotHash) {
            throw new \Exception('Source snapshot is stale. Please refresh the page.');
        }

        $validatedLines = [];
        $usedReplacementSerialIds = [];

        foreach ($lines as $lineData) {
            $resolution = $lineData['resolution'] ?? PosReturnLine::RESOLUTION_NONE;
            $quantity = (float) ($lineData['quantity'] ?? 0);
            $returnedSerialId = $lineData['returned_serial_id'] ?? null;
            $isSerial = !empty($returnedSerialId);
            $hasExplicitResolution = array_key_exists('resolution', $lineData)
                && $lineData['resolution'] !== null
                && $lineData['resolution'] !== '';

            if (!$hasExplicitResolution
                && $resolution === PosReturnLine::RESOLUTION_NONE
                && $quantity > 0
                && in_array($defaultReturnOption, [PosReturn::OPTION_CASH_RETURN, PosReturn::OPTION_PRODUCT_REPLACEMENT], true)) {
                $resolution = $defaultReturnOption;
            }

            if (!in_array($resolution, [PosReturnLine::RESOLUTION_NONE, PosReturnLine::RESOLUTION_CASH_RETURN, PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT], true)) {
                throw new \Exception('Resolusi retur tidak valid.');
            }

            if (!$isSerial && ($resolution === PosReturnLine::RESOLUTION_NONE || $quantity <= 0)) {
                continue;
            }

            if ($isSerial && $quantity <= 0) {
                $quantity = 1;
            }

            $saleDetail = SaleDetails::with(['bundleItems.product', 'product'])->findOrFail($lineData['sale_detail_id']);
            $returnableQty = $this->quantityGuard->getReturnableQuantity($saleDetail->dispatch_detail_id, $saleDetail->id, $ignorePosReturnId);

            if (!$isSerial && $quantity > $returnableQty) {
                throw new \Exception("Kuantitas retur melebihi batas yang diizinkan untuk produk {$saleDetail->product_name}.");
            }

            $replacementSerialId = $resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT
                ? ($lineData['replacement_serial_id'] ?? null)
                : null;

            if ($resolution === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT && $isSerial && empty($replacementSerialId)) {
                throw new \Exception('Serial pengganti harus diisi untuk penggantian produk.');
            }

            if ($replacementSerialId) {
                if (in_array($replacementSerialId, $usedReplacementSerialIds, true)) {
                    throw new \Exception('Serial pengganti tidak boleh digunakan lebih dari satu kali dalam retur yang sama.');
                }

                $this->replacementGuard->validateReplacementSerial(
                    $saleDetail->product_id,
                    $replacementSerialId,
                    $returnedSerialId,
                    $ignorePosReturnId
                );

                $usedReplacementSerialIds[] = $replacementSerialId;
            }

            $lineData['quantity'] = $isSerial ? 1 : $quantity;
            $lineData['resolution'] = $resolution;
            $lineData['replacement_serial_id'] = $replacementSerialId;
            $validatedLines[] = $lineData;
        }

        $hasActionable = collect($validatedLines)->contains(function (array $lineData) {
            return in_array($lineData['resolution'] ?? PosReturnLine::RESOLUTION_NONE, [
                PosReturnLine::RESOLUTION_CASH_RETURN,
                PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            ], true);
        });

        if (!$hasActionable) {
            throw new \Exception('Minimal satu item harus dipilih untuk retur (ganti produk atau uang kembali).');
        }

        return $validatedLines;
    }

    protected function generatePosReturnReference($settingId)
    {
        return $this->generateReference($settingId, 'POSRT');
    }

    protected function generateReference($settingId, $modulePrefix)
    {
        $setting = Setting::find($settingId);
        $docPrefix = optional($setting)->document_prefix;
        
        $prefix = ($docPrefix ? $docPrefix . '-' : '') . $modulePrefix;
        $year = now()->format('y');
        $month = now()->format('m');
        
        $count = DB::table('pos_returns')->where('setting_id', $settingId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();
            
        return sprintf("%s-%s%s-%04d", $prefix, $year, $month, $count + 1);
    }
}
