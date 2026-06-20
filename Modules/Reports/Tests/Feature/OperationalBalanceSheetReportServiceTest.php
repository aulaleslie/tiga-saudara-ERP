<?php

namespace Modules\Reports\Tests\Feature;

use App\Services\Reports\OperationalBalanceSheetReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Expense\Entities\Expense;
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
            'total_amount' => 2000,
            'paid_amount' => 500,
            'due_amount' => 1500,
            'date' => now()->format('Y-m-d')
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 500,
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'Cash',
            'status' => 'ACTIVE'
        ]);

        $report = $this->service->generate($this->setting->id, now()->format('Y-m-d'));

        $this->assertEquals(-500, collect($report->assets->rows)->firstWhere('name', 'Kas & Bank dari Transaksi')->amount);
        $this->assertEquals(1500, collect($report->liabilities->rows)->firstWhere('name', 'Hutang Usaha')->amount);
    }

    public function test_approved_expenses_reduce_cash_while_draft_excluded()
    {
        // Approved expense
        Expense::create([
            'setting_id' => $this->setting->id,
            'category_id' => 1,
            'date' => now()->format('Y-m-d'),
            'reference' => 'EXP-001',
            'details' => 'Test',
            'status' => 'APPROVED',
            'amount' => 30000, // 300 in cents
        ]);

        // Draft expense should be ignored
        Expense::create([
            'setting_id' => $this->setting->id,
            'category_id' => 1,
            'date' => now()->format('Y-m-d'),
            'reference' => 'EXP-002',
            'details' => 'Test',
            'status' => 'DRAFT',
            'amount' => 10000,
        ]);

        $report = $this->service->generate($this->setting->id, now()->format('Y-m-d'));

        $this->assertEquals(-300, collect($report->assets->rows)->firstWhere('name', 'Kas & Bank dari Transaksi')->amount);
    }

    public function test_inventory_value_contributes_to_assets()
    {
        Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => 1,
            'product_name' => 'Test',
            'product_code' => 'T1',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 10,
            'product_cost' => 500, // 500 cents = $5
            'product_price' => 1000,
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
            'status' => Purchase::STATUS_RECEIVED,
            'total_amount' => 400,
            'paid_amount' => 100,
            'due_amount' => 300,
            'date' => now()->format('Y-m-d')
        ]);
        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 100,
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'Cash',
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
}
