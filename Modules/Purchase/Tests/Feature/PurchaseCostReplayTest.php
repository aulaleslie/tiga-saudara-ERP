<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseCorrection;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Purchase\Services\PurchaseCostRecalculationService;
use Modules\Purchase\Services\PurchaseCostReplayEngine;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseCostReplayTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Supplier $supplier;
    private Product $product;
    private User $financeUser;
    private PurchaseCostReplayEngine $replayEngine;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::findOrCreate('purchases.received.correct', 'web');
        $role = Role::firstOrCreate(['name' => 'Finance', 'guard_name' => 'web']);
        $role->givePermissionTo('purchases.received.correct');

        $this->financeUser = User::factory()->create(['is_active' => 1]);
        $this->financeUser->assignRole($role);

        $this->setting = Setting::factory()->create([
            'company_name' => 'CV TIGA NUSA COMPUTER',
        ]);
        $this->financeUser->settings()->attach($this->setting->id, ['role_id' => $role->id]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_cost' => 1000,
            'product_price' => 2000,
            'stock_managed' => 1,
            'setting_id' => $this->setting->id,
        ]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '123',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test',
            'setting_id' => $this->setting->id,
        ]);

        $this->replayEngine = app(PurchaseCostReplayEngine::class);
    }

    public function test_global_discount_and_shipping_adjust_corrected_received_cost(): void
    {
        // Create purchase with global discount and shipping
        $purchase = Purchase::create([
            'date' => Carbon::now()->subDay(),
            'due_date' => Carbon::now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => 11000,
            'paid_amount' => 11000,
            'due_amount' => 0,
            'discount_amount' => 1000,    // Global discount
            'shipping_amount' => 1500,     // Shipping
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
        ]);

        // Create purchase detail: 10 units @ 1000 = 10000 (before adjustments)
        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'sub_total' => 10000,
            'product_tax_amount' => 0,
        ]);

        // Create received note
        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => Carbon::now()->subDay(),
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => Carbon::now()->subDay(),
            'setting_id' => $this->setting->id,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $detail->id,
            'quantity_received' => 10,
        ]);

        // Create payment
        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 11000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'Cash',
            'date' => Carbon::now()->toDateString(),
            'reference' => 'PAY-001',
        ]);

        // Create correction record
        $correction = PurchaseCorrection::create([
            'setting_id' => $this->setting->id,
            'purchase_id' => $purchase->id,
            'actor_user_id' => $this->financeUser->id,
            'reason' => 'Supplier correction',
            'field_corrections' => [],
        ]);

        // Replay costs
        $recalcService = app(PurchaseCostRecalculationService::class);
        $result = $recalcService->executeRecalculation(
            $purchase,
            $this->financeUser,
            false,
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['result']['purchase_prices_updated']);

        // Verify cost calculation
        $productPrice = ProductPrice::where('product_id', $this->product->id)
            ->where('setting_id', $this->setting->id)
            ->first();

        // Expected: (10000 - 1000 + 1500) / 10 = 1050
        $this->assertEquals(1050.00, $productPrice->average_purchase_price);
    }

    public function test_zero_dpp_lines_excluded_from_allocation(): void
    {
        // Create purchase with mixed lines (positive DPP and zero DPP)
        $purchase = Purchase::create([
            'date' => Carbon::now()->subDay(),
            'due_date' => Carbon::now()->addDays(30),
            'reference' => 'PO-002',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => 11000,
            'paid_amount' => 11000,
            'due_amount' => 0,
            'discount_amount' => 1000,
            'shipping_amount' => 0,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
        ]);

        // Line 1: 10 units @ 1000 = 10000 DPP (positive)
        $detail1 = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'sub_total' => 10000,
            'product_tax_amount' => 0,
        ]);

        // Line 2: 5 units @ 0 = 0 DPP (should not receive allocation)
        $detail2 = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 5,
            'unit_price' => 0,
            'price' => 0,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'sub_total' => 0,
            'product_tax_amount' => 0,
        ]);

        // Create received notes for both
        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => Carbon::now()->subDay(),
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => Carbon::now()->subDay(),
            'setting_id' => $this->setting->id,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $detail1->id,
            'quantity_received' => 10,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $detail2->id,
            'quantity_received' => 5,
        ]);

        // Create payment
        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 11000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'Cash',
            'date' => Carbon::now()->toDateString(),
            'reference' => 'PAY-001',
        ]);

        // Create correction
        $correction = PurchaseCorrection::create([
            'setting_id' => $this->setting->id,
            'purchase_id' => $purchase->id,
            'actor_user_id' => $this->financeUser->id,
            'reason' => 'Supplier correction',
            'field_corrections' => [],
        ]);

        // Replay
        $recalcService = app(PurchaseCostRecalculationService::class);
        $result = $recalcService->executeRecalculation($purchase, $this->financeUser, false);

        $this->assertTrue($result['success']);

        $productPrice = ProductPrice::where('product_id', $this->product->id)
            ->where('setting_id', $this->setting->id)
            ->first();

        // Expected: line 1 = (10000 - 1000) = 9000, line 2 = 0, total cost = 9000
        // Total qty = 15 (10 + 5), so average = 9000 / 15 = 600
        $this->assertEquals(600.00, $productPrice->average_purchase_price);
    }

    public function test_partial_receipt_prorates_allocated_cost(): void
    {
        $purchase = Purchase::create([
            'date' => Carbon::now()->subDay(),
            'due_date' => Carbon::now()->addDays(30),
            'reference' => 'PO-003',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED_PARTIALLY,
            'payment_status' => 'PARTIAL',
            'payment_method' => 'Cash',
            'total_amount' => 5500,
            'paid_amount' => 5500,
            'due_amount' => 0,
            'discount_amount' => 500,
            'shipping_amount' => 500,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
        ]);

        // Order 10 units but only receive 5
        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'sub_total' => 10000,
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => Carbon::now()->subDay(),
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => Carbon::now()->subDay(),
            'setting_id' => $this->setting->id,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $detail->id,
            'quantity_received' => 5,  // Only 5 received
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 5500,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'Cash',
            'date' => Carbon::now()->toDateString(),
            'reference' => 'PAY-002',
        ]);

        $correction = PurchaseCorrection::create([
            'setting_id' => $this->setting->id,
            'purchase_id' => $purchase->id,
            'actor_user_id' => $this->financeUser->id,
            'reason' => 'Partial receipt correction',
            'field_corrections' => [],
        ]);

        $recalcService = app(PurchaseCostRecalculationService::class);
        $result = $recalcService->executeRecalculation($purchase, $this->financeUser, false);

        $this->assertTrue($result['success']);

        $productPrice = ProductPrice::where('product_id', $this->product->id)
            ->where('setting_id', $this->setting->id)
            ->first();

        // Prorated: (10000 - 500 + 500) * (5/10) / 5 = 10000 * 0.5 / 5 = 1000
        $this->assertEquals(1000.00, $productPrice->average_purchase_price);
    }

    public function test_repeated_replay_is_deterministic(): void
    {
        $purchase = Purchase::create([
            'date' => Carbon::now()->subDay(),
            'due_date' => Carbon::now()->addDays(30),
            'reference' => 'PO-004',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => 11000,
            'paid_amount' => 11000,
            'due_amount' => 0,
            'discount_amount' => 1000,
            'shipping_amount' => 500,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'sub_total' => 10000,
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => Carbon::now()->subDay(),
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => Carbon::now()->subDay(),
            'setting_id' => $this->setting->id,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $detail->id,
            'quantity_received' => 10,
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 11000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'Cash',
            'date' => Carbon::now()->toDateString(),
            'reference' => 'PAY-003',
        ]);

        $correction = PurchaseCorrection::create([
            'setting_id' => $this->setting->id,
            'purchase_id' => $purchase->id,
            'actor_user_id' => $this->financeUser->id,
            'reason' => 'Test correction',
            'field_corrections' => [],
        ]);

        // First run
        $recalcService = app(PurchaseCostRecalculationService::class);
        $result1 = $recalcService->executeRecalculation($purchase, $this->financeUser, false);
        $price1 = ProductPrice::where('product_id', $this->product->id)
            ->where('setting_id', $this->setting->id)
            ->first()->average_purchase_price;

        // Second run with same inputs
        $result2 = $recalcService->executeRecalculation($purchase, $this->financeUser, false);
        $price2 = ProductPrice::where('product_id', $this->product->id)
            ->where('setting_id', $this->setting->id)
            ->first()->average_purchase_price;

        $this->assertTrue($result1['success']);
        $this->assertTrue($result2['success']);
        $this->assertEquals($price1, $price2);
    }

    public function test_input_tax_excluded_from_hpp(): void
    {
        $purchase = Purchase::create([
            'date' => Carbon::now()->subDay(),
            'due_date' => Carbon::now()->addDays(30),
            'reference' => 'PO-005',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => 12000,
            'paid_amount' => 12000,
            'due_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
        ]);

        // Line: 10 @ 1000 = 10000 + 2000 tax = 12000 total
        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'sub_total' => 10000,
            'product_tax_amount' => 2000,  // Input tax should be excluded
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => Carbon::now()->subDay(),
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => Carbon::now()->subDay(),
            'setting_id' => $this->setting->id,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $detail->id,
            'quantity_received' => 10,
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 12000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'Cash',
            'date' => Carbon::now()->toDateString(),
            'reference' => 'PAY-004',
        ]);

        $correction = PurchaseCorrection::create([
            'setting_id' => $this->setting->id,
            'purchase_id' => $purchase->id,
            'actor_user_id' => $this->financeUser->id,
            'reason' => 'Test tax exclusion',
            'field_corrections' => [],
        ]);

        $recalcService = app(PurchaseCostRecalculationService::class);
        $result = $recalcService->executeRecalculation($purchase, $this->financeUser, false);

        $this->assertTrue($result['success']);

        $productPrice = ProductPrice::where('product_id', $this->product->id)
            ->where('setting_id', $this->setting->id)
            ->first();

        // Expected: (10000 - 2000) / 10 = 800 (tax excluded)
        $this->assertEquals(800.00, $productPrice->average_purchase_price);
    }

    public function test_downstream_sale_hpp_replay_uses_historical_cost(): void
    {
        // Create a received purchase
        $purchase = Purchase::create([
            'date' => Carbon::now()->subDay(),
            'due_date' => Carbon::now()->addDays(30),
            'reference' => 'PO-006',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => 11000,
            'paid_amount' => 11000,
            'due_amount' => 0,
            'discount_amount' => 1000,
            'shipping_amount' => 0,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'sub_total' => 10000,
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => Carbon::now()->subDay(),
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => Carbon::now()->subDay(),
            'setting_id' => $this->setting->id,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $detail->id,
            'quantity_received' => 10,
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 11000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'Cash',
            'date' => Carbon::now()->toDateString(),
            'reference' => 'PAY-005',
        ]);

        // Create a sale using this product
        $sale = \Modules\Sale\Entities\Sale::create([
            'date' => now(),
            'reference' => 'SALE-001',
            'setting_id' => $this->setting->id,
            'customer_name' => 'Test Customer',
            'status' => 'COMPLETED',
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => 5000,
            'paid_amount' => 5000,
            'due_amount' => 0,
        ]);

        $saleDetail = \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 5,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 5000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        // Create correction
        $correction = PurchaseCorrection::create([
            'setting_id' => $this->setting->id,
            'purchase_id' => $purchase->id,
            'actor_user_id' => $this->financeUser->id,
            'reason' => 'Supplier correction',
            'field_corrections' => [],
        ]);

        // Replay with downstream HPP enabled
        $recalcService = app(PurchaseCostRecalculationService::class);
        $result = $recalcService->executeRecalculation(
            $purchase,
            $this->financeUser,
            true,  // Include downstream HPP
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['result']['sales_hpp_updated']);

        // Verify sale detail HPP was updated
        $saleDetail->refresh();
        $expectedUnitCost = 900.00; // (10000 - 1000) / 10
        $this->assertEquals($expectedUnitCost, $saleDetail->cost_unit_snapshot);
        $this->assertEquals(round($expectedUnitCost * 5, 2), $saleDetail->cost_total_snapshot);
        $this->assertEquals('CORRECTION_REPLAY', $saleDetail->cost_snapshot_source);
    }

    public function test_imported_hpp_protection_skips_authorized_snapshots(): void
    {
        // Create a received purchase
        $purchase = Purchase::create([
            'date' => Carbon::now()->subDay(),
            'due_date' => Carbon::now()->addDays(30),
            'reference' => 'PO-007',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 10000,
            'due_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
        ]);

        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'sub_total' => 10000,
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => Carbon::now()->subDay(),
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => Carbon::now()->subDay(),
            'setting_id' => $this->setting->id,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $detail->id,
            'quantity_received' => 10,
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 10000,
            'status' => PurchasePayment::STATUS_ACTIVE,
            'payment_method' => 'Cash',
            'date' => Carbon::now()->toDateString(),
            'reference' => 'PAY-006',
        ]);

        // Create a sale with imported HPP
        $sale = \Modules\Sale\Entities\Sale::create([
            'date' => now(),
            'reference' => 'SALE-002',
            'setting_id' => $this->setting->id,
            'customer_name' => 'Test Customer 2',
            'status' => 'COMPLETED',
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => 5000,
            'paid_amount' => 5000,
            'due_amount' => 0,
        ]);

        $saleDetail = \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => 5,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 5000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 950,
            'cost_total_snapshot' => 4750,
            'cost_snapshot_source' => 'HPP_SNAPSHOT_IMPORT',  // Imported - should be protected
        ]);

        // Create correction
        $correction = PurchaseCorrection::create([
            'setting_id' => $this->setting->id,
            'purchase_id' => $purchase->id,
            'actor_user_id' => $this->financeUser->id,
            'reason' => 'Supplier correction',
            'field_corrections' => [],
        ]);

        // Replay with downstream HPP enabled
        $recalcService = app(PurchaseCostRecalculationService::class);
        $result = $recalcService->executeRecalculation(
            $purchase,
            $this->financeUser,
            true,
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['result']['sales_hpp_updated']);
        $this->assertEquals(1, $result['result']['imported_hpp_skipped']);

        // Verify sale detail HPP was NOT updated
        $saleDetail->refresh();
        $this->assertEquals(950, $saleDetail->cost_unit_snapshot);
        $this->assertEquals(4750, $saleDetail->cost_total_snapshot);
        $this->assertEquals('HPP_SNAPSHOT_IMPORT', $saleDetail->cost_snapshot_source);
    }

    /**
     * A canonical quantity can now be fractional (e.g. a converted BOX purchase
     * received as 0.688 PCS). Cost replay must prorate that receipt through plain
     * float arithmetic without truncating or misreading the 3-decimal canonical
     * quantity. Ordered 1.000, received across two fractional receipts (0.312 +
     * 0.688 = 1.000). This guards against integer truncation and gross proration
     * errors on fractional quantities; it is not a float-precision-drift
     * discriminator (assertEquals() tolerates float error, and this particular
     * split is not guaranteed to expose binary rounding the way a targeted
     * discriminating case would).
     */
    public function test_fractional_canonical_quantity_receipts_prorate_cost_correctly(): void
    {
        $purchase = Purchase::create([
            'date' => Carbon::now()->subDay(),
            'due_date' => Carbon::now()->addDays(30),
            'reference' => 'PO-DECIMAL-001',
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'PAID',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
        ]);

        // Ordered 1.000 canonical PCS at Rp1,000/PCS (e.g. a converted BOX line).
        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'quantity' => '1.000',
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'sub_total' => 1000,
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => Carbon::now()->subDay(),
            'status' => ReceivedNote::STATUS_APPROVED,
            'approved_at' => Carbon::now()->subDay(),
            'setting_id' => $this->setting->id,
        ]);

        foreach (['0.312', '0.688'] as $chunk) {
            ReceivedNoteDetail::create([
                'received_note_id' => $receivedNote->id,
                'po_detail_id' => $detail->id,
                'quantity_received' => $chunk,
            ]);
        }

        $correction = PurchaseCorrection::create([
            'setting_id' => $this->setting->id,
            'purchase_id' => $purchase->id,
            'actor_user_id' => $this->financeUser->id,
            'reason' => 'Fractional canonical quantity correction',
            'field_corrections' => [],
        ]);

        $recalcService = app(PurchaseCostRecalculationService::class);
        $result = $recalcService->executeRecalculation($purchase, $this->financeUser, false);

        $this->assertTrue($result['success']);

        $productPrice = ProductPrice::where('product_id', $this->product->id)
            ->where('setting_id', $this->setting->id)
            ->first();

        // The two fractional receipts sum to exactly the full ordered quantity, so
        // the fully-received line's average cost must land on the line's own unit
        // price (Rp1,000), not a value skewed by float-drift proration.
        $this->assertEquals(1000.00, (float) $productPrice->average_purchase_price);
    }
}
