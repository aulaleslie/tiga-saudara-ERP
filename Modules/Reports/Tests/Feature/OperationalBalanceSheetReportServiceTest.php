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

    public function test_inventory_value_contributes_to_assets()
    {
        Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'C1',
            'category_name' => 'Cat1',
            'created_by' => 1
        ]);

        Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => 1,
            'product_name' => 'Test',
            'product_code' => 'T1',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 10,
            'product_cost' => 5, // mutates to 500 cents = $5
            'product_price' => 10,
            'product_unit' => 'PC',
            'product_stock_alert' => 1,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'stock_managed' => true,
        ]);

        $report = $this->service->generate($this->setting->id, now()->format('Y-m-d'));

        // 10 qty * 5 cost = 50
        $this->assertEquals(50, collect($report->assets->rows)->firstWhere('name', 'Persediaan Barang')->amount);
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
}
