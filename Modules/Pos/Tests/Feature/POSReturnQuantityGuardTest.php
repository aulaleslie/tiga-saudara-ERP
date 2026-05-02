<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Services\PosReturnSnapshotService;
use App\Support\PosReturn\PosReturnQuantityGuard;
use Modules\Setting\Entities\Setting;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Pos\Entities\PosCheckoutSale;
use Spatie\Permission\Models\Permission;
use Modules\People\Entities\Customer;

class POSReturnQuantityGuardTest extends PosTransactionFeatureTestCase
{
    protected $user;
    protected $submissionService;
    protected $snapshotService;
    protected $quantityGuard;
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
        $this->quantityGuard = app(PosReturnQuantityGuard::class);
        
        $this->setting = $this->createSetting('Quantity Guard Test');
        
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
    public function it_correctly_calculates_returnable_quantity_for_partial_returns()
    {
        // Setup
        $transaction = $this->setupTransaction('TXN-QTY-1');
        $product = $this->createStockedProduct($this->setting, $this->location);
        $sale = $this->setupSale($transaction, 1000);
        
        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 1000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Initial check
        $this->assertEquals(10, $this->quantityGuard->getReturnableQuantity(null, $saleDetail->id));

        // Submit return for 3 units
        $snapshot = $this->snapshotService->build($transaction->id);
        $this->actingAsInSetting($this->user, $this->setting);
        $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 3,
                ]
            ]
        ]);

        // Check after partial return
        $this->assertEquals(7, $this->quantityGuard->getReturnableQuantity(null, $saleDetail->id));
        $this->assertTrue($this->quantityGuard->isValid(null, 7, ['sale_detail_id' => $saleDetail->id]));
        $this->assertFalse($this->quantityGuard->isValid(null, 8, ['sale_detail_id' => $saleDetail->id]));
    }

    /** @test */
    public function it_releases_returnable_quantity_when_return_is_reversed()
    {
        // Setup
        $transaction = $this->setupTransaction('TXN-QTY-2');
        $product = $this->createStockedProduct($this->setting, $this->location);
        $sale = $this->setupSale($transaction, 1000);
        
        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 1000,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // Submit return for 5 units
        $snapshot = $this->snapshotService->build($transaction->id);
        $this->actingAsInSetting($this->user, $this->setting);
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 5,
                ]
            ]
        ]);

        $this->assertEquals(5, $this->quantityGuard->getReturnableQuantity(null, $saleDetail->id));

        // Mark as reversed
        $posReturn->update(['is_reversed' => true]);

        // Should be back to 10
        $this->assertEquals(10, $this->quantityGuard->getReturnableQuantity(null, $saleDetail->id));
    }

    protected function setupTransaction($code)
    {
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => $code,
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
            'receipt_number' => 'RCP-' . $code,
            'idempotency_key' => 'IDEM-' . $code,
            'payload_hash' => 'HASH-' . $code,
        ]);

        $transaction->update(['completed_checkout_id' => $checkout->id]);
        
        return $transaction;
    }

    protected function setupSale($transaction, $total)
    {
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => $total,
            'paid_amount' => $total,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-' . $transaction->code,
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $transaction->completed_checkout_id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => $total,
            'split_key' => 'SPLIT-' . $transaction->code,
            'tax_bucket' => 'NON_TAX',
        ]);
        
        return $sale;
    }
}
