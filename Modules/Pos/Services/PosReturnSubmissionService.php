<?php

namespace Modules\Pos\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use App\Support\PosReturn\PosReturnQuantityGuard;
use Modules\Setting\Entities\Setting;
use Modules\Pos\Entities\PosCheckoutSale;

class PosReturnSubmissionService
{
    protected $snapshotService;
    protected $quantityGuard;

    public function __construct(PosReturnSnapshotService $snapshotService, PosReturnQuantityGuard $quantityGuard)
    {
        $this->snapshotService = $snapshotService;
        $this->quantityGuard = $quantityGuard;
    }

    /**
     * Store a new POS return.
     *
     * @param array $data
     * @return \Modules\Pos\Entities\PosReturn
     * @throws \Exception
     */
    public function store(array $data)
    {
        return DB::transaction(function () use ($data) {
            $transaction = PosTransaction::findOrFail($data['pos_transaction_id']);
            $checkout = $transaction->completedCheckout;

            if (!$checkout) {
                throw new \Exception("Transaction has no completed checkout.");
            }

            // 1. Revalidate snapshot hash (T043)
            $currentSnapshot = $this->snapshotService->build($transaction->id);
            if ($currentSnapshot['hash'] !== $data['source_snapshot_hash']) {
                throw new \Exception("Source snapshot is stale. Please refresh the page.");
            }

            // 2. Validate return option (T044)
            if (!in_array($data['return_option'], [PosReturn::OPTION_CASH_RETURN, PosReturn::OPTION_PRODUCT_REPLACEMENT])) {
                throw new \Exception("Invalid return option.");
            }

            // 3. Create POS Return header (T048)
            $posReturn = PosReturn::create([
                'reference' => $this->generatePosReturnReference($transaction->setting_id),
                'setting_id' => $transaction->setting_id,
                'pos_transaction_id' => $transaction->id,
                'pos_checkout_id' => $checkout->id,
                'transaction_code' => $transaction->code,
                'receipt_number' => $checkout->receipt_number,
                'customer_id' => $transaction->customer_id,
                'customer_name' => $transaction->customer_id ? (optional($transaction->customer)->customer_name ?? '-') : 'Walk-in Customer',
                'return_option' => $data['return_option'],
                'status' => PosReturn::STATUS_PENDING_APPROVAL,
                'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
                'source_snapshot' => $currentSnapshot,
                'source_snapshot_hash' => $currentSnapshot['hash'],
                'total_amount' => 0, // Will be updated
                'created_by' => Auth::id(),
            ]);

            $totalAmount = 0;
            $lineGroups = []; // Grouped by sale_id for linked SaleReturn
            
            // Load checkout sales to get source location and setting
            $checkoutSales = PosCheckoutSale::where('pos_checkout_id', $checkout->id)
                ->get()
                ->keyBy('sale_id');

            // 4. Process lines (T045, T046, T047, T048)
            foreach ($data['lines'] as $lineData) {
                $saleDetail = SaleDetails::with(['bundleItems.product', 'sale'])->findOrFail($lineData['sale_detail_id']);
                
                // Validate quantity
                if (!$this->quantityGuard->isValid($saleDetail->dispatch_detail_id, $lineData['quantity'], ['sale_detail_id' => $saleDetail->id])) {
                    throw new \Exception("Invalid return quantity for product: " . $saleDetail->product->product_name);
                }

                $checkoutSale = $checkoutSales->get($saleDetail->sale_id);
                if (!$checkoutSale) {
                    throw new \Exception("Sale ID {$saleDetail->sale_id} not found in checkout sales.");
                }

                $isBundle = $saleDetail->bundleItems->isNotEmpty();
                
                if ($isBundle) {
                    // Handle bundle expansion (T047)
                    foreach ($saleDetail->bundleItems as $bi) {
                        $componentQty = $lineData['quantity'] * $bi->quantity;
                        
                        $returnLine = $this->createReturnLine($posReturn, $checkoutSale, $saleDetail, $bi->product, $componentQty, [
                            'bundle_group_key' => $saleDetail->id,
                            'bundle_parent_sale_detail_id' => $saleDetail->id,
                            'bundle_quantity' => $lineData['quantity'],
                            'component_quantity_per_bundle' => $bi->quantity,
                        ]);
                        
                        $lineGroups[$saleDetail->sale_id][] = $returnLine;
                    }
                } else {
                    $returnLine = $this->createReturnLine($posReturn, $checkoutSale, $saleDetail, $saleDetail->product, $lineData['quantity']);
                    $lineGroups[$saleDetail->sale_id][] = $returnLine;
                    $totalAmount += $returnLine->line_total;
                }
            }

            $posReturn->update(['total_amount' => $totalAmount]);

            // 5. Create linked Sales Returns (T049)
            foreach ($lineGroups as $saleId => $lines) {
                $sale = \Modules\Sale\Entities\Sale::findOrFail($saleId);
                $saleReturnAmount = collect($lines)->sum('line_total');
                $checkoutSale = $checkoutSales->get($saleId);

                $saleReturn = SaleReturn::create([
                    'setting_id' => $posReturn->setting_id,
                    'pos_return_id' => $posReturn->id,
                    'sale_id' => $sale->id,
                    'reference' => $this->generateSaleReturnReference($posReturn->setting_id),
                    'date' => now()->toDateString(),
                    'customer_id' => $sale->customer_id,
                    'customer_name' => $sale->customer_name ?? '-',
                    'location_id' => $checkoutSale->source_location_id,
                    'tax_amount' => 0,
                    'discount_amount' => 0,
                    'shipping_amount' => 0,
                    'total_amount' => $saleReturnAmount,
                    'paid_amount' => 0,
                    'due_amount' => $saleReturnAmount,
                    'status' => 'Pending Approval',
                    'approval_status' => 'pending',
                    'payment_status' => 'Unpaid',
                    'payment_method' => 'CASH',
                    'created_by' => Auth::id(),
                ]);

                foreach ($lines as $returnLine) {
                    $saleReturnDetail = SaleReturnDetail::create([
                        'sale_return_id' => $saleReturn->id,
                        'pos_return_line_id' => $returnLine->id,
                        'sale_detail_id' => $returnLine->sale_detail_id,
                        'dispatch_detail_id' => $returnLine->dispatch_detail_id,
                        'product_id' => $returnLine->product_id,
                        'product_name' => $returnLine->product_name,
                        'product_code' => $returnLine->product_code,
                        'quantity' => $returnLine->quantity,
                        'price' => $returnLine->unit_price,
                        'unit_price' => $returnLine->unit_price,
                        'sub_total' => $returnLine->line_total,
                        'product_discount_amount' => 0,
                        'product_discount_type' => 'fixed',
                        'product_tax_amount' => 0,
                        'location_id' => $returnLine->source_location_id,
                        'tax_id' => $returnLine->tax_id,
                    ]);

                    $returnLine->update([
                        'sale_return_id' => $saleReturn->id,
                        'sale_return_detail_id' => $saleReturnDetail->id,
                    ]);
                }
            }

            return $posReturn;
        });
    }

    protected function createReturnLine(PosReturn $posReturn, PosCheckoutSale $checkoutSale, SaleDetails $saleDetail, $product, $quantity, $extra = [])
    {
        $unitPrice = $saleDetail->unit_price;
        $lineTotal = $quantity * $unitPrice;

        return PosReturnLine::create(array_merge([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => $checkoutSale->id,
            'sale_id' => $saleDetail->sale_id,
            'sale_detail_id' => $saleDetail->id,
            'dispatch_detail_id' => $saleDetail->dispatch_detail_id,
            'source_setting_id' => $checkoutSale->source_setting_id,
            'source_location_id' => $checkoutSale->source_location_id,
            'tax_id' => $saleDetail->tax_id,
            'product_id' => $product->id,
            'product_name' => $product->product_name ?? $saleDetail->product_name,
            'product_code' => $product->product_code ?? $saleDetail->product_code,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'stock_behavior' => $product->stock_managed ? PosReturnLine::STOCK_BEHAVIOR_MANAGED : PosReturnLine::STOCK_BEHAVIOR_STOCKLESS,
            'replacement_product_id' => $posReturn->return_option === PosReturn::OPTION_PRODUCT_REPLACEMENT ? $product->id : null,
            'replacement_quantity' => $posReturn->return_option === PosReturn::OPTION_PRODUCT_REPLACEMENT ? $quantity : null,
        ], $extra));
    }

    protected function generatePosReturnReference($settingId)
    {
        return $this->generateReference($settingId, 'POSRT');
    }

    protected function generateSaleReturnReference($settingId)
    {
        return $this->generateReference($settingId, 'SR');
    }

    protected function generateReference($settingId, $modulePrefix)
    {
        $setting = Setting::find($settingId);
        $docPrefix = optional($setting)->document_prefix;
        
        $prefix = ($docPrefix ? $docPrefix . '-' : '') . $modulePrefix;
        $year = now()->format('y');
        $month = now()->format('m');
        
        // This is a simplified count for testing and initial implementation.
        // In production, we might want to use a more robust sequence generator.
        $count = DB::table('pos_returns')->where('setting_id', $settingId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count() +
            DB::table('sale_returns')->where('setting_id', $settingId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();
            
        return sprintf("%s-%s%s-%04d", $prefix, $year, $month, $count + 1);
    }
}
