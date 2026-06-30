<?php

namespace Modules\Reports\Tests\Feature;

use App\Services\Reports\OperationalGeneralLedgerReportFilterData;
use App\Services\Reports\OperationalGeneralLedgerReportService;
use App\Services\Reports\OperationalGeneralLedgerBucketConfig;
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
use Carbon\Carbon;

class OperationalGeneralLedgerReportServiceTest extends TestCase
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
            'cost_unit_snapshot' => 600, // For HPP
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 500,
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'Cash',
            'reference' => 'SP-001',
            'status' => 'ACTIVE'
        ]);

        $service = new OperationalGeneralLedgerReportService();
        $filter = new OperationalGeneralLedgerReportFilterData(now()->format('Y-m-d'), now()->format('Y-m-d'));
        
        $report = $service->generate($this->setting->id, $filter);

        $this->assertNotEmpty($report->buckets);
        
        $arBucket = collect($report->buckets)->firstWhere('key', OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE);
        $cashBucket = collect($report->buckets)->firstWhere('key', OperationalGeneralLedgerBucketConfig::CASH_BANK);
        $revBucket = collect($report->buckets)->firstWhere('key', OperationalGeneralLedgerBucketConfig::OPERATIONAL_REVENUE);
        $costBucket = collect($report->buckets)->firstWhere('key', OperationalGeneralLedgerBucketConfig::OPERATIONAL_COST);
        $invBucket = collect($report->buckets)->firstWhere('key', OperationalGeneralLedgerBucketConfig::INVENTORY);

        $this->assertNotNull($arBucket);
        $this->assertNotNull($cashBucket);
        $this->assertNotNull($revBucket);
        $this->assertNotNull($costBucket);
        $this->assertNotNull($invBucket);

        $this->assertEquals(500, $arBucket->endingBalance); // 1000 Dr from sale, 500 Cr from payment
        $this->assertEquals(500, $cashBucket->endingBalance); // 500 Dr from payment
        $this->assertEquals(1000, $revBucket->endingBalance); // 1000 Cr from sale DPP
        $this->assertEquals(600, $costBucket->endingBalance); // 600 Dr from sale HPP
        $this->assertEquals(-600, $invBucket->endingBalance); // 600 Cr from sale HPP
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
            'amount' => 800, // Now matches real amount
            'date' => now()->format('Y-m-d'),
            'payment_method' => 'Cash',
            'reference' => 'PP-001',
            'status' => 'ACTIVE'
        ]);

        $service = new OperationalGeneralLedgerReportService();
        $filter = new OperationalGeneralLedgerReportFilterData(now()->format('Y-m-d'), now()->format('Y-m-d'));
        
        $report = $service->generate($this->setting->id, $filter);

        $apBucket = collect($report->buckets)->firstWhere('key', OperationalGeneralLedgerBucketConfig::ACCOUNTS_PAYABLE);
        $cashBucket = collect($report->buckets)->firstWhere('key', OperationalGeneralLedgerBucketConfig::CASH_BANK);
        $invBucket = collect($report->buckets)->firstWhere('key', OperationalGeneralLedgerBucketConfig::INVENTORY);
        $costBucket = collect($report->buckets)->firstWhere('key', OperationalGeneralLedgerBucketConfig::OPERATIONAL_COST);

        $this->assertNotNull($apBucket);
        $this->assertNotNull($cashBucket);
        $this->assertNotNull($invBucket);
        $this->assertNull($costBucket, 'Purchases should not hit Operational Cost');

        $this->assertEquals(1200, $apBucket->endingBalance);
        $this->assertEquals(-800, $cashBucket->endingBalance);
        $this->assertEquals(2000, $invBucket->endingBalance);
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

        $service = new OperationalGeneralLedgerReportService();
        $filter = new OperationalGeneralLedgerReportFilterData(now()->format('Y-m-d'), now()->format('Y-m-d'));
        
        $report = $service->generate($this->setting->id, $filter);

        $cashBucket = collect($report->buckets)->firstWhere('key', OperationalGeneralLedgerBucketConfig::CASH_BANK);
        $costBucket = collect($report->buckets)->firstWhere('key', OperationalGeneralLedgerBucketConfig::OPERATIONAL_COST);

        $this->assertNotNull($cashBucket);
        $this->assertNotNull($costBucket);

        $this->assertEquals(-300, $cashBucket->endingBalance);
        $this->assertEquals(300, $costBucket->endingBalance);
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

        $service = new OperationalGeneralLedgerReportService();
        $filter = new OperationalGeneralLedgerReportFilterData(now()->format('Y-m-d'), now()->format('Y-m-d'));
        
        $report = $service->generate($this->setting->id, $filter);

        $arBucket = collect($report->buckets)->firstWhere('key', OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE);
        $cashBucket = collect($report->buckets)->firstWhere('key', OperationalGeneralLedgerBucketConfig::CASH_BANK);
        $returnBucket = collect($report->buckets)->firstWhere('key', OperationalGeneralLedgerBucketConfig::RETURNS_AND_ADJUSTMENTS);

        $this->assertNotNull($arBucket);
        $this->assertNotNull($cashBucket);
        $this->assertNull($returnBucket, 'Sale Return revenue reversal is no longer tracked in GL');

        // SRP: Cash Cr 100, AR Dr 100
        // Net AR = (Dr 100) = 100 balance
        $this->assertEquals(100, $arBucket->endingBalance); // Debit 100
        $this->assertEquals(-100, $cashBucket->endingBalance); // Credit 100
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

        $service = new OperationalGeneralLedgerReportService();
        $filter = new OperationalGeneralLedgerReportFilterData(now()->format('Y-m-d'), now()->format('Y-m-d'));
        
        $report = $service->generate($this->setting->id, $filter);

        $apBucket = collect($report->buckets)->firstWhere('key', OperationalGeneralLedgerBucketConfig::ACCOUNTS_PAYABLE);
        $cashBucket = collect($report->buckets)->firstWhere('key', OperationalGeneralLedgerBucketConfig::CASH_BANK);
        $inventoryBucket = collect($report->buckets)->firstWhere('key', OperationalGeneralLedgerBucketConfig::INVENTORY);

        $this->assertNotNull($apBucket);
        $this->assertNotNull($cashBucket);
        $this->assertNotNull($inventoryBucket);

        // PR: AP Dr 600, Inventory Cr 600
        // PRP: Cash Dr 200, AP Cr 200
        // Net AP = Dr 600 - Cr 200 = Dr 400 = -400 balance (AP is Credit Normal)
        $this->assertEquals(-400, $apBucket->endingBalance);
        $this->assertEquals(200, $cashBucket->endingBalance); // Cash Debit 200
        $this->assertEquals(-600, $inventoryBucket->endingBalance); // Inventory Credit 600 = -600 balance (Inventory is Debit Normal)
    }

    public function test_it_filters_buckets_correctly()
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

        $service = new OperationalGeneralLedgerReportService();
        // Only request Cash Bank bucket, which should have no events here, but let's test if we can filter by AR
        $filter = new OperationalGeneralLedgerReportFilterData(now()->format('Y-m-d'), now()->format('Y-m-d'), [OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE]);
        
        $report = $service->generate($this->setting->id, $filter);

        $this->assertCount(1, $report->buckets);
        $this->assertEquals(OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE, $report->buckets[0]->key);
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

        $service = new OperationalGeneralLedgerReportService();
        $filter = new OperationalGeneralLedgerReportFilterData($today, $today);
        
        $report = $service->generate($this->setting->id, $filter);

        $arBucket = collect($report->buckets)->firstWhere('key', OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE);

        $this->assertNotNull($arBucket);
        $this->assertEquals(1000, $arBucket->beginningBalance); // From yesterday
        $this->assertEquals(500, $arBucket->periodDebit); // From today
        $this->assertEquals(0, $arBucket->periodCredit);
        $this->assertEquals(1500, $arBucket->endingBalance); // Total
        
        // Ensure only today's row is included in rows
        $this->assertCount(1, $arBucket->rows);
        $this->assertEquals(500, $arBucket->rows[0]->debit);
        $this->assertEquals(1500, $arBucket->rows[0]->balance); // Running balance
    }

    public function test_empty_state_returns_empty_buckets()
    {
        $service = new OperationalGeneralLedgerReportService();
        $filter = new OperationalGeneralLedgerReportFilterData(now()->format('Y-m-d'), now()->format('Y-m-d'));
        
        $report = $service->generate($this->setting->id, $filter);

        $this->assertEmpty($report->buckets);
    }
}
