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

            // 1. Revalidate snapshot hash
            $currentSnapshot = $this->snapshotService->build($transaction->id);
            if ($currentSnapshot['hash'] !== $data['source_snapshot_hash']) {
                throw new \Exception("Source snapshot is stale. Please refresh the page.");
            }

            // 2. Validate that at least one submitted line has an actionable resolution
            $hasActionable = false;
            foreach ($data['lines'] ?? [] as $lineData) {
                $resolution = $lineData['resolution'] ?? PosReturnLine::RESOLUTION_NONE;
                if (in_array($resolution, [PosReturnLine::RESOLUTION_CASH_RETURN, PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT])) {
                    $hasActionable = true;
                    break;
                }
            }

            if (!$hasActionable) {
                throw new \Exception("Minimal satu item harus dipilih untuk retur (ganti produk atau uang kembali).");
            }

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
            foreach ($data['lines'] ?? [] as $lineData) {
                $resolution = $lineData['resolution'] ?? PosReturnLine::RESOLUTION_NONE;
                $quantity = (float) ($lineData['quantity'] ?? 0);
                $returnedSerialId = $lineData['returned_serial_id'] ?? null;
                $isSerial = !empty($returnedSerialId);

                // Persist non-serial rows only when they have actionable resolution and positive quantity
                if (!$isSerial && ($resolution === PosReturnLine::RESOLUTION_NONE || $quantity <= 0)) {
                    continue;
                }

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

                $lineTotal = $quantity * $unitPrice;
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

                // Trace bundled components if actionable
                if ($isBundle && $resolution !== PosReturnLine::RESOLUTION_NONE) {
                    // We only persist parent-level info. Real extraction will happen on approval/submission.
                    // Or we persist them as child lines here.
                    // The instruction says "Persist bundled component trace rows or metadata only for actionable serialized bundled parent rows."
                    // Currently we can just store the bundle details as a JSON metadata or let them be created when needed.
                    // For now, we update line meta with bundle trace.
                    $bundleTrace = $saleDetail->bundleItems->map(function ($bi) use ($quantity) {
                        return [
                            'product_id' => $bi->product_id,
                            'quantity_per_bundle' => $bi->quantity,
                            'total_component_quantity' => $bi->quantity * $quantity
                        ];
                    })->toArray();
                    $returnLine->update(['line_meta' => ['bundle_trace' => $bundleTrace]]);
                }
            }

            $posReturn->update(['total_amount' => $totalAmount]);

            return $posReturn;
        });
    }

    public function update(PosReturn $posReturn, array $data)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.edit'), 403);
        
        if (!$posReturn->isDraftEditable() && !$posReturn->isRejectedEditable()) {
            throw new \Exception('Hanya retur berstatus draft atau ditolak yang dapat diubah.');
        }

        return DB::transaction(function () use ($posReturn, $data) {
            // Delete old associated draft lines
            $posReturn->lines()->delete();
            
            // Revalidate snapshot hash
            $transaction = $posReturn->posTransaction()->with(['customer', 'completedCheckout'])->firstOrFail();
            $currentSnapshot = $this->snapshotService->build($transaction->id);
            if ($currentSnapshot['hash'] !== $data['source_snapshot_hash']) {
                throw new \Exception("Source snapshot is stale. Please refresh the page.");
            }

            $hasActionable = false;
            foreach ($data['lines'] ?? [] as $lineData) {
                $resolution = $lineData['resolution'] ?? PosReturnLine::RESOLUTION_NONE;
                if (in_array($resolution, [PosReturnLine::RESOLUTION_CASH_RETURN, PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT])) {
                    $hasActionable = true;
                    break;
                }
            }

            if (!$hasActionable) {
                throw new \Exception("Minimal satu item harus dipilih untuk retur (ganti produk atau uang kembali).");
            }

            $totalAmount = 0;
            $checkoutSales = PosCheckoutSale::where('pos_checkout_id', $posReturn->pos_checkout_id)
                ->get()
                ->keyBy('sale_id');

            foreach ($data['lines'] ?? [] as $lineData) {
                $resolution = $lineData['resolution'] ?? PosReturnLine::RESOLUTION_NONE;
                $quantity = (float) ($lineData['quantity'] ?? 0);
                $returnedSerialId = $lineData['returned_serial_id'] ?? null;
                $isSerial = !empty($returnedSerialId);

                if (!$isSerial && ($resolution === PosReturnLine::RESOLUTION_NONE || $quantity <= 0)) {
                    continue;
                }

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

                $lineTotal = $quantity * $unitPrice;
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

                if ($isBundle && $resolution !== PosReturnLine::RESOLUTION_NONE) {
                    $bundleTrace = $saleDetail->bundleItems->map(function ($bi) use ($quantity) {
                        return [
                            'product_id' => $bi->product_id,
                            'quantity_per_bundle' => $bi->quantity,
                            'total_component_quantity' => $bi->quantity * $quantity
                        ];
                    })->toArray();
                    $returnLine->update(['line_meta' => ['bundle_trace' => $bundleTrace]]);
                }
            }

            // Move rejected drafts back to draft approval status when updated
            $newApprovalStatus = $posReturn->approval_status === PosReturn::APPROVAL_STATUS_REJECTED ? PosReturn::APPROVAL_STATUS_DRAFT : $posReturn->approval_status;

            $posReturn->update([
                'total_amount' => $totalAmount,
                'status' => PosReturn::STATUS_DRAFT,
                'approval_status' => $newApprovalStatus,
                'source_snapshot' => $currentSnapshot,
                'source_snapshot_hash' => $currentSnapshot['hash'],
                'updated_by' => Auth::id(),
            ]);

            return $posReturn->refresh();
        });
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
