<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use App\Models\User;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Pos\Entities\PosCheckoutSale;
use Spatie\Permission\Models\Permission;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\SaleBundleItem;

class POSReturnSubmissionTest extends PosTransactionFeatureTestCase
{
    protected $user;
    protected $submissionService;
    protected $snapshotService;
    protected $setting;
    protected $location;
    protected $terminal;
    protected $session;
    protected $customer;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);
        
        $this->setting = $this->createSetting('POS Return Test');
        
        Permission::findOrCreate('pos.returns.create', 'web');
        
        $this->user = $this->createUserForSetting($this->setting, 'POS Return Clerk', [
            'pos.access',
            'pos.returns.create',
        ]);
        
        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /** @test */
    public function it_can_submit_payment_return_for_non_bundle_pos_lines()
    {
        // 1. Setup POS Transaction and Posted Checkout
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-001',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);
        
        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 1000,
            'receipt_number' => 'RCP-001',
            'idempotency_key' => 'IDEM-001',
            'payload_hash' => 'HASH-001',
        ]);

        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-001',
            'sale_price' => 500,
        ]);
        
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-001',
        ]);

        $checkoutSale = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 1000,
            'split_key' => 'SPLIT-001',
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 1000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // 2. Build Snapshot
        $snapshot = $this->snapshotService->build($transaction->id);

        // 3. Prepare Submission Data
        $data = [
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                ]
            ]
        ];

        // 4. Submit
        $this->actingAsInSetting($this->user, $this->setting);
        $posReturn = $this->submissionService->store($data);

        // 5. Assertions
        $this->assertNotNull($posReturn);
        $this->assertEquals(PosReturn::STATUS_PENDING_APPROVAL, $posReturn->status);
        $this->assertEquals(PosReturn::OPTION_CASH_RETURN, $posReturn->return_option);
        $this->assertCount(1, $posReturn->lines);
        $this->assertEquals(1, $posReturn->lines->first()->quantity);
        
        // Assert linked Sales Return exists
        $this->assertCount(1, $posReturn->saleReturns);
    }

    /** @test */
    public function it_can_submit_product_replacement_for_non_bundle_pos_lines()
    {
        // 1. Setup POS Transaction and Posted Checkout
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-002',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);
        
        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 1000,
            'receipt_number' => 'RCP-002',
            'idempotency_key' => 'IDEM-002',
            'payload_hash' => 'HASH-002',
        ]);

        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'PRD-002',
            'sale_price' => 500,
        ]);
        
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-002',
        ]);

        $checkoutSale = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 1000,
            'split_key' => 'SPLIT-002',
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 1000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        $data = [
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1,
                ]
            ]
        ];

        $this->actingAsInSetting($this->user, $this->setting);
        $posReturn = $this->submissionService->store($data);

        $this->assertNotNull($posReturn);
        $this->assertEquals(PosReturn::OPTION_PRODUCT_REPLACEMENT, $posReturn->return_option);
        $this->assertEquals(1, $posReturn->lines->first()->replacement_quantity);
        $this->assertEquals($product->id, $posReturn->lines->first()->replacement_product_id);
    }

    /** @test */
    public function it_can_submit_return_for_bundle_pos_lines()
    {
        // 1. Setup POS Transaction and Posted Checkout
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-BUNDLE',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);
        
        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 2000,
            'receipt_number' => 'RCP-BUNDLE',
            'idempotency_key' => 'IDEM-BUNDLE',
            'payload_hash' => 'HASH-BUNDLE',
        ]);

        $transaction->update(['completed_checkout_id' => $checkout->id]);

        // Create a bundle product
        $bundleProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Super Bundle',
            'product_code' => 'BND-001',
            'product_price' => 1000,
            'product_cost' => 500,
        ]);

        // Create components
        $comp1 = $this->createStockedProduct($this->setting, $this->location, ['product_code' => 'COMP-1']);
        $comp2 = $this->createStockedProduct($this->setting, $this->location, ['product_code' => 'COMP-2']);
        
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 2000,
            'paid_amount' => 2000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-BUNDLE',
        ]);

        $checkoutSale = PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 2000,
            'split_key' => 'SPLIT-BUNDLE',
            'tax_bucket' => 'NON_TAX',
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $bundleProduct->id,
            'quantity' => 2,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 2000,
            'product_name' => $bundleProduct->product_name,
            'product_code' => $bundleProduct->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Link components to sale detail
        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'product_id' => $comp1->id,
            'quantity' => 2, // 2 per bundle
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'name' => $comp1->product_name,
            'price' => 0,
            'sub_total' => 0,
        ]);
        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'product_id' => $comp2->id,
            'quantity' => 1, // 1 per bundle
            'bundle_id' => 1,
            'bundle_item_id' => 2,
            'name' => $comp2->product_name,
            'price' => 0,
            'sub_total' => 0,
        ]);

        $snapshot = $this->snapshotService->build($transaction->id);

        $data = [
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 1, // Returning 1 bundle
                ]
            ]
        ];

        $this->actingAsInSetting($this->user, $this->setting);
        $posReturn = $this->submissionService->store($data);

        $this->assertNotNull($posReturn);
        // Bundle should expand to components in PosReturnLine
        $this->assertCount(2, $posReturn->lines);
        
        $line1 = $posReturn->lines->where('product_id', $comp1->id)->first();
        $this->assertNotNull($line1);
        $this->assertEquals(2, $line1->quantity); // 1 bundle * 2 per bundle
        
        $line2 = $posReturn->lines->where('product_id', $comp2->id)->first();
        $this->assertNotNull($line2);
        $this->assertEquals(1, $line2->quantity); // 1 bundle * 1 per bundle
        
        // Assert linked Sales Return details also expanded
        $saleReturn = $posReturn->saleReturns->first();
        $this->assertCount(2, $saleReturn->saleReturnDetails);
    }
}
