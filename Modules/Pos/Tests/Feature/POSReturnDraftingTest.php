<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturnLine;
use Modules\People\Entities\Customer;

class POSReturnDraftingTest extends PosTransactionFeatureTestCase
{
    protected $submissionService;
    protected $snapshotService;
    protected $setting;
    protected $user;
    protected $terminal;
    protected $session;
    protected $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->submissionService = app(PosReturnSubmissionService::class);
        $this->snapshotService = app(PosReturnSnapshotService::class);
        
        $this->setting = $this->createSetting('Drafting Test');
        
        \Spatie\Permission\Models\Permission::findOrCreate('pos.access', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('pos.returns.create', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('pos.returns.edit', 'web');

        $this->user = $this->createUserForSetting($this->setting, 'Admin', ['pos.access', 'pos.returns.create', 'pos.returns.edit']);
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /** @test */
    public function it_can_create_and_reenter_a_draft_return()
    {
        $this->actingAsInSetting($this->user, $this->setting);

        // Setup transaction
        $product = $this->createStockedProduct($this->setting, $this->location);
        
        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => 'TXN-DRAFT',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ]);
        
        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 1000,
            'receipt_number' => 'RCP-DRAFT',
            'idempotency_key' => 'IDEM-DRAFT',
            'payload_hash' => 'HASH-DRAFT',
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-DRAFT',
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 1000,
            'split_key' => 'SPLIT-DRAFT',
            'tax_bucket' => 'NON_TAX',
        ]);

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

        $snapshot = $this->snapshotService->build($transaction->id);

        // 1. Create a draft
        $posReturn = $this->submissionService->store([
            'pos_transaction_id' => $transaction->id,
            'source_snapshot' => $snapshot,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_id' => $sale->id,
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 2,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $this->assertEquals(PosReturn::STATUS_DRAFT, $posReturn->status);
        $this->assertEquals(2, $posReturn->lines()->first()->quantity);

        // 2. Re-enter and update
        $this->submissionService->update($posReturn, [
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_id' => $sale->id,
                    'sale_detail_id' => $saleDetail->id,
                    'quantity' => 5,
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                ]
            ]
        ]);

        $posReturn->refresh();
        $this->assertEquals(PosReturn::STATUS_DRAFT, $posReturn->status); // Update logic currently resets status to draft
        $this->assertEquals(5, $posReturn->lines()->first()->quantity);
        $this->assertEquals(500, (float) $posReturn->total_amount);
    }
}
