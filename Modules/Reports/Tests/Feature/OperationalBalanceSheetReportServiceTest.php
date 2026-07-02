<?php

namespace Modules\Reports\Tests\Feature;

use App\Services\Reports\OperationalBalanceSheetReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Expense\Entities\Expense;
use Modules\Expense\Entities\ExpenseCategory;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class OperationalBalanceSheetReportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting = Setting::factory()->create();
        $this->service = new OperationalBalanceSheetReportService();
        
        \App\Models\User::factory()->create(['id' => 1]);
        \Modules\People\Entities\Customer::factory()->create(['id' => 1, 'setting_id' => $this->setting->id]);
        \Modules\People\Entities\Supplier::factory()->create(['id' => 1, 'setting_id' => $this->setting->id]);
    }

    public function test_paid_sales_increase_cash_and_unpaid_sales_create_receivables()
    {
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => 1,
            'customer_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 400,
            'due_amount' => 600,
            'date' => now()->format('Y-m-d')
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 400,
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'Cash',
            'reference' => 'SP-001',
            'status' => 'ACTIVE'
        ]);

        $report = $this->service->generate($this->setting->id, now()->format('Y-m-d'));

        $this->assertEquals(400, collect($report->assets->rows)->firstWhere('name', 'Kas & Bank dari Transaksi')->amount);
        $this->assertEquals(600, collect($report->assets->rows)->firstWhere('name', 'Piutang Usaha')->amount);
    }

    public function test_unpaid_purchases_create_payables_and_purchase_payments_reduce_cash()
    {
        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'total_amount' => 2000,
            'paid_amount' => 500,
            'due_amount' => 1500,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d')
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 500,
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'Cash',
            'reference' => 'PP-001',
            'status' => 'ACTIVE'
        ]);

        $report = $this->service->generate($this->setting->id, now()->format('Y-m-d'));

        $this->assertEquals(-500, collect($report->assets->rows)->firstWhere('name', 'Kas & Bank dari Transaksi')->amount);
        $this->assertEquals(1500, collect($report->liabilities->rows)->firstWhere('name', 'Hutang Usaha')->amount);
    }

    public function test_approved_expenses_reduce_cash_while_draft_excluded()
    {
        ExpenseCategory::create([
            'setting_id' => $this->setting->id,
            'category_name' => 'Test',
            'category_description' => 'Test'
        ]);

        // Approved expense
        Expense::create([
            'setting_id' => $this->setting->id,
            'category_id' => 1,
            'date' => now()->format('Y-m-d'),
            'reference' => 'EXP-001',
            'details' => 'Test',
            'status' => 'APPROVED',
            'amount' => 300, // 300 in cents after mutator
        ]);

        // Draft expense should be ignored
        Expense::create([
            'setting_id' => $this->setting->id,
            'category_id' => 1,
            'date' => now()->format('Y-m-d'),
            'reference' => 'EXP-002',
            'details' => 'Test',
            'status' => 'DRAFT',
            'amount' => 100,
        ]);

        $report = $this->service->generate($this->setting->id, now()->format('Y-m-d'));

        $this->assertEquals(-300, collect($report->assets->rows)->firstWhere('name', 'Kas & Bank dari Transaksi')->amount);
    }

    public function test_inventory_value_uses_transaction_replayed_stock_as_of_date()
    {
        $category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'C1',
            'category_name' => 'Cat1',
            'created_by' => 1
        ]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Test',
            'product_code' => 'T1',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 10,
            'product_cost' => 500, // mutates to 500 cents = $5
            'product_price' => 1000,
            'product_unit' => 'PC',
            'product_stock_alert' => 1,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'stock_managed' => true,
        ]);

        \Modules\Product\Entities\ProductPrice::create([
            'setting_id' => $this->setting->id,
            'product_id' => $product->id,
            'average_purchase_price' => 5
        ]);

        $location = \Modules\Setting\Entities\Location::factory()->create(['setting_id' => $this->setting->id]);

        // Past transaction (should be included)
        \Modules\Product\Entities\Transaction::create([
            'setting_id' => $this->setting->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'type' => 'BUY',
            'quantity' => 10,
            'current_quantity' => 10,
            'previous_quantity' => 0,
            'previous_quantity_at_location' => 0,
            'after_quantity' => 10,
            'after_quantity_at_location' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'reason' => 'PR-001',
        ]);
        \Illuminate\Support\Facades\DB::table('transactions')
            ->where('reason', 'PR-001')
            ->update(['created_at' => Carbon::now()->subDays(5)]);
        
        // Mock a purchase so the Transaction date mapper finds a date
        Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test',
            'reference' => 'PR-001',
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'total_amount' => 5000,
            'paid_amount' => 5000,
            'due_amount' => 0,
            'date' => Carbon::now()->subDays(5)->format('Y-m-d'),
            'due_date' => Carbon::now()->subDays(5)->format('Y-m-d')
        ]);

        // Future transaction (should be excluded)
        \Modules\Product\Entities\Transaction::create([
            'setting_id' => $this->setting->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'type' => 'BUY',
            'quantity' => 5,
            'current_quantity' => 15,
            'previous_quantity' => 10,
            'previous_quantity_at_location' => 10,
            'after_quantity' => 15,
            'after_quantity_at_location' => 15,
            'quantity_tax' => 0,
            'quantity_non_tax' => 5,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'reason' => 'PR-002',
        ]);
        \Illuminate\Support\Facades\DB::table('transactions')
            ->where('reason', 'PR-002')
            ->update(['created_at' => Carbon::now()->addDays(5)]);

        Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test',
            'reference' => 'PR-002',
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'total_amount' => 2500,
            'paid_amount' => 2500,
            'due_amount' => 0,
            'date' => Carbon::now()->addDays(5)->format('Y-m-d'),
            'due_date' => Carbon::now()->addDays(5)->format('Y-m-d')
        ]);

        $asOfDate = Carbon::now()->subDays(2)->format('Y-m-d');
        $report = $this->service->generate($this->setting->id, $asOfDate);

        // 10 qty * 5 cost = 50 (ignoring the future 5 qty)
        $this->assertEquals(50, collect($report->assets->rows)->firstWhere('name', 'Persediaan Barang')->amount);
    }

    public function test_inventory_value_respects_stock_snapshot_effective_date()
    {
        $category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'C_SNAP',
            'category_name' => 'Snap Cat',
            'created_by' => 1
        ]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Snap Test',
            'product_code' => 'T_SNAP',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 10,
            'product_cost' => 500, // 5
            'product_price' => 1000,
            'product_unit' => 'PC',
            'product_stock_alert' => 1,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'stock_managed' => true,
        ]);

        \Modules\Product\Entities\ProductPrice::create([
            'setting_id' => $this->setting->id,
            'product_id' => $product->id,
            'average_purchase_price' => 5
        ]);

        $location = \Modules\Setting\Entities\Location::factory()->create(['setting_id' => $this->setting->id]);

        // A snapshot import transaction uploaded 5 days ago
        $transaction = \Modules\Product\Entities\Transaction::create([
            'setting_id' => $this->setting->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'type' => 'ADJ',
            'quantity' => 20, // difference of 20
            'current_quantity' => 20,
            'previous_quantity' => 0,
            'previous_quantity_at_location' => 0,
            'after_quantity' => 20,
            'after_quantity_at_location' => 20,
            'quantity_tax' => 0,
            'quantity_non_tax' => 20,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'reason' => 'STOCK SNAPSHOT IMPORT OVERWRITE',
        ]);
        
        $transaction->created_at = Carbon::now()->subDays(5);
        $transaction->save(['timestamps' => false]);

        // Report generated 10 days ago (BEFORE snapshot):
        // Snapshot should be EXCLUDED. Stock value is 0.
        $asOfDateBefore = Carbon::now()->subDays(10)->format('Y-m-d');
        $reportBefore = $this->service->generate($this->setting->id, $asOfDateBefore);
        $this->assertEquals(0, collect($reportBefore->assets->rows)->firstWhere('name', 'Persediaan Barang')->amount);

        // Report generated 2 days ago (AFTER snapshot):
        // Snapshot should be INCLUDED. 20 qty * 5 cost = 100.
        $asOfDateAfter = Carbon::now()->subDays(2)->format('Y-m-d');
        $reportAfter = $this->service->generate($this->setting->id, $asOfDateAfter);
        $this->assertEquals(100, collect($reportAfter->assets->rows)->firstWhere('name', 'Persediaan Barang')->amount);
    }

    public function test_inventory_multi_setting_is_scoped()
    {
        $setting2 = Setting::factory()->create();
        
        $location1 = \Modules\Setting\Entities\Location::factory()->create(['setting_id' => $this->setting->id]);
        $location2 = \Modules\Setting\Entities\Location::factory()->create(['setting_id' => $setting2->id]);

        $category1 = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'C1',
            'category_name' => 'Cat1',
            'created_by' => 1
        ]);
        
        $category2 = Category::create([
            'setting_id' => $setting2->id,
            'category_code' => 'C2',
            'category_name' => 'Cat2',
            'created_by' => 1
        ]);

        $product1 = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category1->id,
            'product_name' => 'Test1',
            'product_code' => 'T1',
            'product_barcode_symbology' => 'C128',
            'product_unit' => 'PC',
            'product_price' => 1000,
            'product_quantity' => 10,
            'product_cost' => 500, // 5
            'product_stock_alert' => 1,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'stock_managed' => true,
        ]);
        
        \Modules\Product\Entities\ProductPrice::create([
            'setting_id' => $this->setting->id,
            'product_id' => $product1->id,
            'average_purchase_price' => 5
        ]);
        
        $product2 = Product::create([
            'setting_id' => $setting2->id,
            'category_id' => $category2->id,
            'product_name' => 'Test2',
            'product_code' => 'T2',
            'product_barcode_symbology' => 'C128',
            'product_unit' => 'PC',
            'product_price' => 2000,
            'product_quantity' => 20,
            'product_cost' => 1000, // 10
            'product_stock_alert' => 1,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'stock_managed' => true,
        ]);
        
        \Modules\Product\Entities\ProductPrice::create([
            'setting_id' => $setting2->id,
            'product_id' => $product2->id,
            'average_purchase_price' => 10
        ]);

        \Modules\Product\Entities\Transaction::create([
            'setting_id' => $this->setting->id,
            'product_id' => $product1->id,
            'location_id' => $location1->id,
            'type' => 'BUY',
            'quantity' => 10,
            'current_quantity' => 10,
            'previous_quantity' => 0,
            'previous_quantity_at_location' => 0,
            'after_quantity' => 10,
            'after_quantity_at_location' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'reason' => 'PR-001',
        ]);
        \Illuminate\Support\Facades\DB::table('transactions')
            ->where('reason', 'PR-001')
            ->update(['created_at' => Carbon::now()->subDays(5)]);
        Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test',
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'paid_amount' => 5000,
            'due_amount' => 0,
            'reference' => 'PR-001',
            'date' => Carbon::now()->subDays(5)->format('Y-m-d'),
            'due_date' => Carbon::now()->subDays(5)->format('Y-m-d'),
            'total_amount' => 5000,
        ]);

        \Modules\Product\Entities\Transaction::create([
            'setting_id' => $setting2->id,
            'product_id' => $product2->id,
            'location_id' => $location2->id,
            'type' => 'BUY',
            'quantity' => 20,
            'current_quantity' => 20,
            'previous_quantity' => 0,
            'previous_quantity_at_location' => 0,
            'after_quantity' => 20,
            'after_quantity_at_location' => 20,
            'quantity_tax' => 0,
            'quantity_non_tax' => 20,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'reason' => 'PR-002',
        ]);
        \Illuminate\Support\Facades\DB::table('transactions')
            ->where('reason', 'PR-002')
            ->update(['created_at' => Carbon::now()->subDays(5)]);
        Purchase::create([
            'setting_id' => $setting2->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test',
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'paid_amount' => 20000,
            'due_amount' => 0,
            'reference' => 'PR-002',
            'date' => Carbon::now()->subDays(5)->format('Y-m-d'),
            'due_date' => Carbon::now()->subDays(5)->format('Y-m-d'),
            'total_amount' => 20000,
        ]);

        $asOfDate = Carbon::now()->subDays(2)->format('Y-m-d');

        // Report for setting 1 only
        $report1 = $this->service->generate($this->setting->id, $asOfDate);
        $this->assertEquals(50, collect($report1->assets->rows)->firstWhere('name', 'Persediaan Barang')->amount);

        // Report for both settings
        $reportBoth = $this->service->generate([$this->setting->id, $setting2->id], $asOfDate);
        // 10 * 5 + 20 * 10 = 50 + 200 = 250
        $this->assertEquals(250, collect($reportBoth->assets->rows)->firstWhere('name', 'Persediaan Barang')->amount);
    }

    public function test_derived_equity_balances_total_assets_against_total_liabilities()
    {
        // Add Sale
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => 1,
            'customer_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_DISPATCHED,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'date' => now()->format('Y-m-d')
        ]);
        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 1000,
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'Cash',
            'reference' => 'SP-002',
            'status' => 'ACTIVE'
        ]);

        // Add Purchase
        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_RECEIVED,
            'total_amount' => 400,
            'paid_amount' => 100,
            'due_amount' => 300,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d')
        ]);
        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 100,
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'Cash',
            'reference' => 'PP-002',
            'status' => 'ACTIVE'
        ]);

        $report = $this->service->generate($this->setting->id, now()->format('Y-m-d'));

        // Cash: 1000 (sale) - 100 (purchase) = 900
        // Receivables: 0
        // Inventory: 0
        // Total Assets: 900
        
        // Payables: 400 - 100 = 300
        // Total Liabilities: 300
        
        // Equity = Assets - Liabilities = 900 - 300 = 600

        $this->assertEquals(900, $report->assets->total);
        $this->assertEquals(300, $report->liabilities->total);
        $this->assertEquals(600, $report->equity->total);
        $this->assertEquals($report->assets->total, $report->liabilities->total + $report->equity->total);
    }

    public function test_legacy_purchase_returns_are_scaled_correctly()
    {
        // Legacy Purchase Return (created before Jan 12, 2026)
        $purchaseReturn = \Modules\PurchasesReturn\Entities\PurchaseReturn::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'date' => now()->format('Y-m-d'),
            'total_amount' => 50000, // 500 in cents
            'paid_amount' => 50000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'created_at' => Carbon::parse('2025-12-01 10:00:00'),
            'updated_at' => Carbon::parse('2025-12-01 10:00:00')
        ]);

        \Modules\PurchasesReturn\Entities\PurchaseReturnPayment::create([
            'purchase_return_id' => $purchaseReturn->id,
            'amount' => 50000, // 500 in cents
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'CASH',
            'reference' => 'REF-001',
            'created_at' => Carbon::parse('2025-12-01 10:00:00'),
            'updated_at' => Carbon::parse('2025-12-01 10:00:00')
        ]);

        // Add a Purchase so payables does not bottom out at 0
        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'status' => \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED,
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d')
        ]);

        $report = $this->service->generate($this->setting->id, now()->format('Y-m-d'));

        // Legacy amounts should be divided by 100
        $this->assertEquals(500, collect($report->assets->rows)->firstWhere('name', 'Kas & Bank dari Transaksi')->amount);
        $this->assertEquals(500, collect($report->liabilities->rows)->firstWhere('name', 'Hutang Usaha')->amount);
    }

    public function test_livewire_purchase_returns_are_unscaled()
    {
        // Livewire Purchase Return (created after Jan 12, 2026)
        $purchaseReturn = \Modules\PurchasesReturn\Entities\PurchaseReturn::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'date' => now()->format('Y-m-d'),
            'total_amount' => 800, // True decimal
            'paid_amount' => 800,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Pending',
            'created_at' => Carbon::parse('2026-02-01 10:00:00'),
            'updated_at' => Carbon::parse('2026-02-01 10:00:00')
        ]);

        \Modules\PurchasesReturn\Entities\PurchaseReturnPayment::create([
            'purchase_return_id' => $purchaseReturn->id,
            'amount' => 800, // True decimal
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'CASH',
            'reference' => 'REF-002',
            'created_at' => Carbon::parse('2026-02-01 10:00:00'),
            'updated_at' => Carbon::parse('2026-02-01 10:00:00')
        ]);

        // Add a Purchase so payables does not bottom out at 0
        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'status' => \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED,
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d')
        ]);

        $category = \Modules\Product\Entities\Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'C1',
            'category_name' => 'Cat1',
            'created_by' => 1
        ]);

        $product = \Modules\Product\Entities\Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Test Product',
            'product_code' => 'T1',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 10,
            'product_cost' => 500, // 500 cents
            'product_price' => 1000,
            'product_unit' => 'PC',
            'product_stock_alert' => 1,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'stock_managed' => true,
        ]);

        $detail = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'location_id' => \Modules\Setting\Entities\Location::where('setting_id', $this->setting->id)->first()->id ?? 1, // Simulates Livewire flow enforcing location_id
            'product_name' => 'Test Product',
            'product_code' => 'T1',
            'quantity' => 1,
            'price' => 800,
            'unit_price' => 800,
            'sub_total' => 800,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'status' => \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_APPROVED_AWAITING_RECEIVE,
        ]);

        $report = $this->service->generate($this->setting->id, now()->format('Y-m-d'));

        // Livewire amounts should NOT be divided
        $this->assertEquals(800, collect($report->assets->rows)->firstWhere('name', 'Kas & Bank dari Transaksi')->amount);
        $this->assertEquals(200, collect($report->liabilities->rows)->firstWhere('name', 'Hutang Usaha')->amount);
    }


    public function test_legacy_purchase_returns_with_manual_payment_and_settlement_are_scaled_correctly()
    {
        // Legacy Purchase Return (created before Jan 12, 2026)
        $purchaseReturn = \Modules\PurchasesReturn\Entities\PurchaseReturn::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'date' => now()->format('Y-m-d'),
            'total_amount' => 50000, // 500 in cents
            'paid_amount' => 30000,
            'due_amount' => 20000,
            'status' => 'Completed',
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'created_at' => \Carbon\Carbon::parse('2025-12-01 10:00:00'),
            'updated_at' => \Carbon\Carbon::parse('2025-12-01 10:00:00')
        ]);

        // 1. Initial payment (cents) created at the exact same time
        \Modules\PurchasesReturn\Entities\PurchaseReturnPayment::create([
            'purchase_return_id' => $purchaseReturn->id,
            'amount' => 10000, // 100 in cents
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'CASH',
            'reference' => 'INV-001',
            'created_at' => \Carbon\Carbon::parse('2025-12-01 10:00:00'),
            'updated_at' => \Carbon\Carbon::parse('2025-12-01 10:00:00')
        ]);

        // 2. Manual payment added later (decimal)
        \Modules\PurchasesReturn\Entities\PurchaseReturnPayment::create([
            'purchase_return_id' => $purchaseReturn->id,
            'amount' => 200, // 200 in true decimal
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'CASH',
            'reference' => 'INV-001', // manual payments reuse the same reference prefix
            'created_at' => \Carbon\Carbon::parse('2025-12-02 10:00:00'),
            'updated_at' => \Carbon\Carbon::parse('2025-12-02 10:00:00')
        ]);

        // 3. Settlement payment added later (cents, safely separated by PAY-RET prefix thanks to controller fix)
        \Modules\PurchasesReturn\Entities\PurchaseReturnPayment::create([
            'purchase_return_id' => $purchaseReturn->id,
            'amount' => 20000, // 200 in cents
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'Ubah Nota',
            'reference' => 'PAY-RET/INV-001/' . time(),
            'created_at' => \Carbon\Carbon::parse('2025-12-03 10:00:00'),
            'updated_at' => \Carbon\Carbon::parse('2025-12-03 10:00:00')
        ]);

        // Add a Purchase so payables does not bottom out at 0
        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'status' => \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED,
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d')
        ]);

        $report = $this->service->generate($this->setting->id, now()->format('Y-m-d'));

        // Expected Cash Inflow:
        // Initial Payment: 10000 cents -> 100
        // Manual Payment: 200 decimal -> 200
        // Settlement Payment: 20000 cents -> 200
        // Total = 100 + 200 + 200 = 500
        $this->assertEquals(500, collect($report->assets->rows)->firstWhere('name', 'Kas & Bank dari Transaksi')->amount);
    }



    public function test_legacy_purchase_returns_with_edited_initial_payment_are_scaled_correctly()
    {
        // Legacy Purchase Return
        $purchaseReturn = \Modules\PurchasesReturn\Entities\PurchaseReturn::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'date' => now()->format('Y-m-d'),
            'total_amount' => 50000, // 500 in cents
            'paid_amount' => 15000,
            'due_amount' => 35000,
            'status' => 'Completed',
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'created_at' => \Carbon\Carbon::parse('2025-12-01 10:00:00'),
            'updated_at' => \Carbon\Carbon::parse('2025-12-01 10:00:00')
        ]);

        // 1. Initial payment, but EDITED!
        \Modules\PurchasesReturn\Entities\PurchaseReturnPayment::create([
            'purchase_return_id' => $purchaseReturn->id,
            'amount' => 150, // 150 in TRUE DECIMAL (the user edited it from 10000 cents)
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'CASH',
            'reference' => 'INV-001',
            'created_at' => \Carbon\Carbon::parse('2025-12-01 10:00:00'),
            // updated_at is GREATER than created_at, indicating it was edited via UI
            'updated_at' => \Carbon\Carbon::parse('2025-12-02 10:00:00')
        ]);

        // Add a Purchase so payables does not bottom out at 0
        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'status' => \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED,
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d')
        ]);

        $report = $this->service->generate($this->setting->id, now()->format('Y-m-d'));

        // Expected Cash Inflow:
        // Edited Initial Payment: 150 decimal -> 150 (NOT 1.5)
        $this->assertEquals(150, collect($report->assets->rows)->firstWhere('name', 'Kas & Bank dari Transaksi')->amount);
    }



    public function test_legacy_purchase_returns_with_multiple_settlements_are_scaled_correctly()
    {
        // Legacy Purchase Return
        $purchaseReturn = \Modules\PurchasesReturn\Entities\PurchaseReturn::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'date' => now()->format('Y-m-d'),
            'total_amount' => 50000, // 500 in cents
            'paid_amount' => 50000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'created_at' => \Carbon\Carbon::parse('2025-12-01 10:00:00'),
            'updated_at' => \Carbon\Carbon::parse('2025-12-01 10:00:00')
        ]);

        // 1. Settlement payment added later (cents)
        $payment = \Modules\PurchasesReturn\Entities\PurchaseReturnPayment::create([
            'purchase_return_id' => $purchaseReturn->id,
            'amount' => 20000, // 200 in cents
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'CASH', // Cash is counted in Kas & Bank
            'reference' => 'PAY-RET/INV-001/1',
            'created_at' => \Carbon\Carbon::parse('2025-12-03 10:00:00'),
            'updated_at' => \Carbon\Carbon::parse('2025-12-03 10:00:00')
        ]);

        // 2. Second settlement added later. The controller increments the existing row, updating updated_at!
        // So updated_at > created_at, but it is a PAY-RET/ row, so it must still be scaled as cents!
        $payment->amount = 50000; // 500 in cents (200 + 300)
        $payment->updated_at = \Carbon\Carbon::parse('2025-12-04 10:00:00');
        $payment->save();

        // Add a Purchase so payables does not bottom out at 0
        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'status' => \Modules\Purchase\Entities\Purchase::STATUS_RECEIVED,
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d')
        ]);

        $report = $this->service->generate($this->setting->id, now()->format('Y-m-d'));

        // Expected Cash Inflow:
        // Incremented Settlement Payment: 50000 cents -> 500 (NOT 50000)
        $this->assertEquals(500, collect($report->assets->rows)->firstWhere('name', 'Kas & Bank dari Transaksi')->amount);
    }

    public function test_tax_rows_are_calculated_from_eligible_purchases_and_sales()
    {
        // Sale with tax
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => 1,
            'customer_name' => 'Test',
            'tax_percentage' => 10,
            'tax_amount' => 100,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'total_amount' => 1100,
            'paid_amount' => 1100,
            'due_amount' => 0,
            'date' => now()->format('Y-m-d')
        ]);
        
        // Purchase with tax
        $purchase = Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test',
            'tax_percentage' => 10,
            'tax_amount' => 50,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'total_amount' => 550,
            'paid_amount' => 550,
            'due_amount' => 0,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d')
        ]);

        $report = $this->service->generate($this->setting->id, now()->format('Y-m-d'));

        // PPN Masukan should be 50 (from purchase)
        $this->assertEquals(50, collect($report->assets->rows)->firstWhere('name', 'PPN Masukan')->amount);
        
        // PPN Keluaran should be 100 (from sale)
        $this->assertEquals(100, collect($report->liabilities->rows)->firstWhere('name', 'PPN Keluaran')->amount);
        
        // Hutang Pajak should not exist anymore (replaced by PPN Keluaran)
        $this->assertNull(collect($report->liabilities->rows)->firstWhere('name', 'Hutang Pajak'));
    }

    public function test_earnings_rows_are_calculated_from_profit_loss_service()
    {
        $lastYear = now()->subYear()->format('Y');
        $thisYear = now()->format('Y');
        
        $category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'C1',
            'category_name' => 'Cat1',
            'created_by' => 1
        ]);
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Test',
            'product_code' => 'T1',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 10,
            'product_cost' => 500,
            'product_price' => 1000,
            'product_unit' => 'PC',
            'product_stock_alert' => 1,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'stock_managed' => true,
        ]);

        // Prior year sale (Profit: 100)
        Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => 1,
            'customer_name' => 'Test',
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'total_amount' => 200,
            'paid_amount' => 200,
            'due_amount' => 0,
            'date' => $lastYear . '-06-01'
        ]);
        $saleLastYearDetails = \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => Sale::latest('id')->first()->id,
            'product_id' => $product->id,
            'product_name' => 'Test',
            'product_code' => 'T1',
            'quantity' => 1,
            'price' => 200,
            'unit_price' => 200,
            'sub_total' => 200,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 100 // DPP = 200, HPP = 100, Profit = 100
        ]);
        SalePayment::create([
            'sale_id' => Sale::latest('id')->first()->id,
            'amount' => 200,
            'date' => $lastYear . '-06-01',
            'payment_method' => 'Cash',
            'reference' => 'SP-001',
            'status' => 'ACTIVE'
        ]);

        // Current year sale (Profit: 50)
        Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => 1,
            'customer_name' => 'Test',
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'total_amount' => 150,
            'paid_amount' => 150,
            'due_amount' => 0,
            'date' => $thisYear . '-02-01'
        ]);
        $saleThisYearDetails = \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => Sale::latest('id')->first()->id,
            'product_id' => $product->id,
            'product_name' => 'Test',
            'product_code' => 'T1',
            'quantity' => 1,
            'price' => 150,
            'unit_price' => 150,
            'sub_total' => 150,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 100 // DPP = 150, HPP = 100, Profit = 50
        ]);
        SalePayment::create([
            'sale_id' => Sale::latest('id')->first()->id,
            'amount' => 150,
            'date' => $thisYear . '-02-01',
            'payment_method' => 'Cash',
            'reference' => 'SP-002',
            'status' => 'ACTIVE'
        ]);
        
        $report = $this->service->generate($this->setting->id, now()->format('Y-m-d'));

        $this->assertEquals(100, collect($report->equity->rows)->firstWhere('name', 'Pendapatan sampai Tahun lalu')->amount);
        $this->assertEquals(50, collect($report->equity->rows)->firstWhere('name', 'Pendapatan Periode ini')->amount);
        
        // Total cash = 350. Assets = 350. Liabilities = 0.
        // Pendapatan lalu = 100. Pendapatan ini = 50. Total Pendapatan = 150.
        // Modal = 350 - 0 - 150 = 200.
        $this->assertEquals(200, collect($report->equity->rows)->firstWhere('name', 'Modal / Ekuitas')->amount);
        $this->assertEquals($report->assets->total, $report->liabilities->total + $report->equity->total);
    }

    public function test_csv_export_content_shape_and_values()
    {
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => 1,
            'customer_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 100,
            'due_amount' => 0,
            'date' => now()->format('Y-m-d'),
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'reference' => 'SL-001'
        ]);
        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 100,
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'Cash',
            'reference' => 'SP-001',
            'status' => 'ACTIVE'
        ]);

        $filters = [
            'asOfDate' => now()->format('Y-m-d'),
            'settingIds' => [$this->setting->id],
            'scopeLabel' => 'Test Scope'
        ];

        $export = new \App\Exports\OperationalBalanceSheetReportCsvExport($filters);
        
        $headings = $export->headings();
        $this->assertEquals(['Bagian', 'Keterangan', 'Nilai'], $headings);

        $data = $export->array();
        $this->assertIsArray($data);
        
        // Assert Kas & Bank dari Transaksi should be 100
        $kasRow = collect($data)->first(function($row) {
            return $row[1] === 'Kas & Bank dari Transaksi';
        });
        
        $this->assertNotNull($kasRow);
        $this->assertEquals('Aset', $kasRow[0]);
        $this->assertEquals(100, $kasRow[2]);
    }
}
