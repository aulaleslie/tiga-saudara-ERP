<?php

namespace Modules\Reports\Tests\Feature;

use App\Services\Reports\OperationalTrialBalanceReportService;
use App\Services\Reports\OperationalTrialBalanceRowConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Setting\Entities\Setting;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnPayment;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Modules\Expense\Entities\Expense;
use Modules\Expense\Entities\ExpenseCategory;
use Tests\TestCase;

class OperationalTrialBalanceReportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting = Setting::factory()->create();
        \App\Models\User::factory()->create(['id' => 1]);
        \Modules\People\Entities\Customer::factory()->create(['id' => 1, 'setting_id' => $this->setting->id]);
        \Modules\People\Entities\Supplier::factory()->create(['id' => 1, 'setting_id' => $this->setting->id]);
        \Modules\Product\Entities\Product::create([
            'id' => 1,
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST1',
            'product_price' => 1000,
            'product_cost' => 600,
            'product_quantity' => 100,
            'product_stock_alert' => 10,
        ]);
    }

    public function test_it_generates_sales_and_payments_correctly()
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
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_DISPATCHED,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'date' => now()->format('Y-m-d')
        ]);

        \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => 1,
            'product_name' => 'Product 1',
            'product_code' => 'P1',
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 600,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 500,
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'Cash',
            'reference' => 'SP-001',
            'status' => 'ACTIVE'
        ]);

        $service = app(OperationalTrialBalanceReportService::class);
        $report = $service->generate($this->setting->id, now()->format('Y-m-d'), now()->format('Y-m-d'));

        $this->assertNotEmpty($report->categories);
        
        $assetCat = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_ASSET);
        $incomeCat = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_INCOME);
        
        $arRow = collect($assetCat->rows)->firstWhere('code', 'OP-110'); // Piutang Usaha
        $cashRow = collect($assetCat->rows)->firstWhere('code', 'OP-100'); // Kas & Bank
        $invRow = collect($assetCat->rows)->firstWhere('code', 'OP-120'); // Persediaan
        $revRow = collect($incomeCat->rows)->firstWhere('code', 'OP-400'); // Pendapatan Operasional
        
        $expCat = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_EXPENSE);
        $costRow = collect($expCat->rows)->firstWhere('code', 'OP-500'); // Beban Pokok

        $this->assertNotNull($arRow);
        $this->assertNotNull($cashRow);
        $this->assertNotNull($revRow);
        $this->assertNotNull($invRow);
        $this->assertNotNull($costRow);

        // Period Dr/Cr
        // Sale: AR Dr 1000, Rev Cr 1000, Cost Dr 600, Inv Cr 600
        // SalePayment: Cash Dr 500, AR Cr 500
        // Total AR: Dr 1000, Cr 500 => Net Dr 500 (Dr normal)
        $this->assertEquals(1000, $arRow->periodDebit);
        $this->assertEquals(500, $arRow->periodCredit);
        $this->assertEquals(500, $arRow->endingDebit);
        $this->assertEquals(0, $arRow->endingCredit);

        // Cash: Dr 500 (Dr normal)
        $this->assertEquals(500, $cashRow->periodDebit);
        $this->assertEquals(0, $cashRow->periodCredit);
        $this->assertEquals(500, $cashRow->endingDebit);
        $this->assertEquals(0, $cashRow->endingCredit);

        // Rev: Cr 1000 (Cr normal)
        $this->assertEquals(0, $revRow->periodDebit);
        $this->assertEquals(1000, $revRow->periodCredit);
        $this->assertEquals(0, $revRow->endingDebit);
        $this->assertEquals(1000, $revRow->endingCredit);
        
        // Inv: Cr 600 (Dr normal)
        $this->assertEquals(0, $invRow->periodDebit);
        $this->assertEquals(600, $invRow->periodCredit);
        $this->assertEquals(0, $invRow->endingDebit);
        $this->assertEquals(600, $invRow->endingCredit); // negative balance is credit
        
        // Cost: Dr 600 (Dr normal)
        $this->assertEquals(600, $costRow->periodDebit);
        $this->assertEquals(0, $costRow->periodCredit);
        $this->assertEquals(600, $costRow->endingDebit);
        $this->assertEquals(0, $costRow->endingCredit);
    }
    
    public function test_it_generates_purchases_and_payments_correctly()
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
            'paid_amount' => 0,
            'due_amount' => 2000,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d')
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 800,
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'Cash',
            'reference' => 'PP-001',
            'status' => 'ACTIVE'
        ]);

        $service = app(OperationalTrialBalanceReportService::class);
        $report = $service->generate($this->setting->id, now()->format('Y-m-d'), now()->format('Y-m-d'));

        $assetCat = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_ASSET);
        $liabCat = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_LIABILITY);
        $expCat = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_EXPENSE);

        $apRow = collect($liabCat->rows)->firstWhere('code', 'OP-200'); // Hutang Usaha
        $cashRow = collect($assetCat->rows)->firstWhere('code', 'OP-100'); // Kas & Bank
        $invRow = collect($assetCat->rows)->firstWhere('code', 'OP-120'); // Persediaan (Estimasi)
        $costRow = $expCat ? collect($expCat->rows)->firstWhere('code', 'OP-500') : null;

        $this->assertNotNull($apRow);
        $this->assertNotNull($cashRow);
        $this->assertNotNull($invRow);
        $this->assertNull($costRow, 'Purchases should not hit Beban Pokok');

        // Purchase: Inv Dr 2000, AP Cr 2000
        // PurchasePayment: AP Dr 800, Cash Cr 800
        // Net AP = Cr 2000 - Dr 800 = Cr 1200 (Cr normal)
        $this->assertEquals(800, $apRow->periodDebit);
        $this->assertEquals(2000, $apRow->periodCredit);
        $this->assertEquals(0, $apRow->endingDebit);
        $this->assertEquals(1200, $apRow->endingCredit);

        // Cash: Cr 800 (Dr normal)
        $this->assertEquals(0, $cashRow->periodDebit);
        $this->assertEquals(800, $cashRow->periodCredit);
        $this->assertEquals(0, $cashRow->endingDebit);
        $this->assertEquals(800, $cashRow->endingCredit); // Negative balance is Credit

        // Inv: Dr 2000 (Dr normal)
        $this->assertEquals(2000, $invRow->periodDebit);
        $this->assertEquals(0, $invRow->periodCredit);
        $this->assertEquals(2000, $invRow->endingDebit);
        $this->assertEquals(0, $invRow->endingCredit);
    }
    
    public function test_it_generates_expenses_correctly()
    {
        $category = ExpenseCategory::create(['category_name' => 'Test Category', 'category_description' => 'Test']);
        
        Expense::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'date' => now()->format('Y-m-d'),
            'reference' => 'EXP-001',
            'details' => 'Test Expense',
            'amount' => 300,
            'status' => 'APPROVED'
        ]);

        $service = app(OperationalTrialBalanceReportService::class);
        $report = $service->generate($this->setting->id, now()->format('Y-m-d'), now()->format('Y-m-d'));

        $assetCat = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_ASSET);
        $expCat = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_EXPENSE);

        $cashRow = collect($assetCat->rows)->firstWhere('code', 'OP-100');
        $costRow = collect($expCat->rows)->firstWhere('code', 'OP-500');

        $this->assertNotNull($cashRow);
        $this->assertNotNull($costRow);

        $this->assertEquals(0, $cashRow->endingDebit);
        $this->assertEquals(300, $cashRow->endingCredit); // Credit 300

        $this->assertEquals(300, $costRow->endingDebit);
        $this->assertEquals(0, $costRow->endingCredit); // Debit 300
    }
    
    public function test_it_generates_sale_returns_correctly()
    {
        $saleReturn = SaleReturn::create([
            'setting_id' => $this->setting->id,
            'customer_id' => 1,
            'customer_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'reference' => 'SR-001',
            'total_amount' => 400,
            'paid_amount' => 0,
            'due_amount' => 400,
            'date' => now()->format('Y-m-d')
        ]);

        SaleReturnPayment::create([
            'sale_return_id' => $saleReturn->id,
            'amount' => 100,
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'Cash',
            'reference' => 'SRP-001'
        ]);

        $service = app(OperationalTrialBalanceReportService::class);
        $report = $service->generate($this->setting->id, now()->format('Y-m-d'), now()->format('Y-m-d'));

        $assetCat = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_ASSET);
        $incomeCat = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_INCOME);

        $arRow = collect($assetCat->rows)->firstWhere('code', 'OP-110');
        $cashRow = collect($assetCat->rows)->firstWhere('code', 'OP-100');
        $returnRow = $incomeCat ? collect($incomeCat->rows)->firstWhere('code', 'OP-410') : null; // Retur Penjualan

        $this->assertNotNull($arRow);
        $this->assertNotNull($cashRow);
        $this->assertNull($returnRow, 'Sale return revenue reversal is ignored in GL');

        // SRP: AR Dr 100, Cash Cr 100
        $this->assertEquals(100, $arRow->endingDebit);
        $this->assertEquals(0, $arRow->endingCredit);

        $this->assertEquals(0, $cashRow->endingDebit);
        $this->assertEquals(100, $cashRow->endingCredit);
    }
    
    public function test_it_generates_purchase_returns_correctly()
    {
        $purchaseReturn = PurchaseReturn::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'reference' => 'PR-001',
            'total_amount' => 60000,
            'paid_amount' => 0,
            'due_amount' => 60000,
            'date' => now()->format('Y-m-d')
        ]);

        PurchaseReturnPayment::create([
            'purchase_return_id' => $purchaseReturn->id,
            'amount' => 20000,
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'Cash',
            'reference' => 'PRP-001'
        ]);

        $service = app(OperationalTrialBalanceReportService::class);
        $report = $service->generate($this->setting->id, now()->format('Y-m-d'), now()->format('Y-m-d'));

        $assetCat = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_ASSET);
        $liabCat = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_LIABILITY);
        $expCat = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_EXPENSE);

        $apRow = collect($liabCat->rows)->firstWhere('code', 'OP-200');
        $cashRow = collect($assetCat->rows)->firstWhere('code', 'OP-100');
        $returnRow = collect($assetCat->rows)->firstWhere('code', 'OP-120'); // Inventory

        $this->assertNotNull($apRow);
        $this->assertNotNull($cashRow);
        $this->assertNotNull($returnRow);

        // PR: AP Dr 600, Inventory Cr 600
        // PRP: Cash Dr 200, AP Cr 200
        $this->assertEquals(400, $apRow->endingDebit);
        $this->assertEquals(0, $apRow->endingCredit);

        $this->assertEquals(200, $cashRow->endingDebit);
        $this->assertEquals(0, $cashRow->endingCredit);

        $this->assertEquals(0, $returnRow->endingDebit);
        $this->assertEquals(600, $returnRow->endingCredit);
    }

    public function test_it_generates_livewire_purchase_returns_correctly()
    {
        $purchaseReturn = PurchaseReturn::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'reference' => 'PR-002',
            'total_amount' => 600,
            'paid_amount' => 0,
            'due_amount' => 600,
            'date' => now()->format('Y-m-d')
        ]);

        \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_name' => 'Product 1',
            'product_code' => 'P1',
            'quantity' => 1,
            'price' => 600,
            'unit_price' => 600,
            'sub_total' => 600,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'location_id' => 1 // Identifies as Livewire return
        ]);

        PurchaseReturnPayment::create([
            'purchase_return_id' => $purchaseReturn->id,
            'amount' => 200,
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'Cash',
            'reference' => 'PRP-002'
        ]);

        $service = app(OperationalTrialBalanceReportService::class);
        $report = $service->generate($this->setting->id, now()->format('Y-m-d'), now()->format('Y-m-d'));

        $assetCat = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_ASSET);
        $liabCat = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_LIABILITY);
        $expCat = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_EXPENSE);

        $apRow = collect($liabCat->rows)->firstWhere('code', 'OP-200');
        $cashRow = collect($assetCat->rows)->firstWhere('code', 'OP-100');
        $returnRow = collect($assetCat->rows)->firstWhere('code', 'OP-120');

        // PR: AP Dr 600, Inventory Cr 600 (not scaled because it has location_id)
        // PRP: Cash Dr 200, AP Cr 200
        $this->assertEquals(400, $apRow->endingDebit);
        $this->assertEquals(0, $apRow->endingCredit);

        $this->assertEquals(200, $cashRow->endingDebit);
        $this->assertEquals(0, $cashRow->endingCredit);

        $this->assertEquals(0, $returnRow->endingDebit);
        $this->assertEquals(600, $returnRow->endingCredit);
    }

    public function test_inactive_and_ineligible_records_are_ignored()
    {
        // Quotation sale
        Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => 1,
            'customer_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'status' => 'Quotation',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'date' => now()->format('Y-m-d')
        ]);

        // Pending purchase
        Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => 1,
            'supplier_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'status' => 'Pending', // Pending not received
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d')
        ]);

        // Unapproved Expense
        $category = ExpenseCategory::create(['category_name' => 'Test Category', 'category_description' => 'Test']);
        Expense::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'date' => now()->format('Y-m-d'),
            'reference' => 'EXP-002',
            'details' => 'Test Expense',
            'amount' => 300,
            'status' => 'PENDING'
        ]);

        $service = app(OperationalTrialBalanceReportService::class);
        $report = $service->generate($this->setting->id, now()->format('Y-m-d'), now()->format('Y-m-d'));

        $this->assertEmpty($report->categories);
    }
    
    public function test_beginning_and_running_balances_with_date_range()
    {
        $yesterday = now()->subDay()->format('Y-m-d');
        $today = now()->format('Y-m-d');
        
        // 1. Transaction yesterday (beginning balance)
        $sale1 = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => 1,
            'customer_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_DISPATCHED,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'date' => $yesterday,
            'created_at' => now()->subDay()
        ]);
        
        \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale1->id,
            'product_id' => 1,
            'product_name' => 'Product 1',
            'product_code' => 'P1',
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 600,
        ]);
        
        // 2. Transaction today (period movement)
        $sale2 = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => 1,
            'customer_name' => 'Test',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_DISPATCHED,
            'total_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'date' => $today,
            'created_at' => now()
        ]);

        \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale2->id,
            'product_id' => 1,
            'product_name' => 'Product 1',
            'product_code' => 'P1',
            'quantity' => 1,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 500,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 300,
        ]);

        $service = app(OperationalTrialBalanceReportService::class);
        $report = $service->generate($this->setting->id, $today, $today);

        $assetCat = collect($report->categories)->firstWhere('categoryName', OperationalTrialBalanceRowConfig::CATEGORY_ASSET);
        $arRow = collect($assetCat->rows)->firstWhere('code', 'OP-110');

        $this->assertNotNull($arRow);
        
        // AR Normal is Debit
        $this->assertEquals(1000, $arRow->openingDebit);
        $this->assertEquals(0, $arRow->openingCredit);
        $this->assertEquals(500, $arRow->periodDebit);
        $this->assertEquals(0, $arRow->periodCredit);
        $this->assertEquals(1500, $arRow->endingDebit);
        $this->assertEquals(0, $arRow->endingCredit);

        // Verify grand totals
        // Yesterday: AR Dr 1000, Rev Cr 1000, Cost Dr 600, Inv Cr 600. Total Dr = 1600, Total Cr = 1600
        $this->assertEquals(1600, $report->grandTotalOpeningDebit);
        $this->assertEquals(1600, $report->grandTotalOpeningCredit);
        // Today: AR Dr 500, Rev Cr 500, Cost Dr 300, Inv Cr 300. Total Dr = 800, Total Cr = 800
        $this->assertEquals(800, $report->grandTotalPeriodDebit);
        $this->assertEquals(800, $report->grandTotalPeriodCredit);
        // End: AR Dr 1500, Rev Cr 1500, Cost Dr 900, Inv Cr 900. Total Dr = 2400, Total Cr = 2400
        $this->assertEquals(2400, $report->grandTotalEndingDebit);
        $this->assertEquals(2400, $report->grandTotalEndingCredit);
    }

    public function test_empty_state_returns_empty_categories()
    {
        $service = app(OperationalTrialBalanceReportService::class);
        $report = $service->generate($this->setting->id, now()->format('Y-m-d'), now()->format('Y-m-d'));

        $this->assertEmpty($report->categories);
        $this->assertEquals(0, $report->grandTotalEndingDebit);
        $this->assertEquals(0, $report->grandTotalEndingCredit);
    }

    public function test_manual_journal_items_do_not_create_operational_rows()
    {
        $journal = \Modules\Setting\Entities\Journal::create([
            'setting_id' => $this->setting->id,
            'transaction_date' => now()->format('Y-m-d'),
            'description' => 'Manual journal',
        ]);
        
        $coa1 = \Modules\Setting\Entities\ChartOfAccount::create([
            'setting_id' => $this->setting->id,
            'type_id' => 1,
            'parent_id' => null,
            'account_number' => '1000',
            'category' => 'Kas & Bank',
            'code' => 'TEST1',
            'name' => 'Test 1'
        ]);
        $coa2 = \Modules\Setting\Entities\ChartOfAccount::create([
            'setting_id' => $this->setting->id,
            'type_id' => 1,
            'parent_id' => null,
            'account_number' => '2000',
            'category' => 'Kas & Bank',
            'code' => 'TEST2',
            'name' => 'Test 2'
        ]);

        \Illuminate\Support\Facades\DB::table('journal_items')->insert([
            'journal_id' => $journal->id,
            'chart_of_account_id' => $coa1->id,
            'amount' => 1000,
            'type' => 'debit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('journal_items')->insert([
            'journal_id' => $journal->id,
            'chart_of_account_id' => $coa2->id,
            'amount' => 1000,
            'type' => 'credit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(\App\Services\Reports\OperationalTrialBalanceReportService::class);
        $report = $service->generate($this->setting->id, now()->format('Y-m-d'), now()->format('Y-m-d'));

        $this->assertEmpty($report->categories);
    }
}
