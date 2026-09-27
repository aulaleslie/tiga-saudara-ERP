<?php

namespace Tests\Feature\Livewire\Reports;

use App\Services\Reports\SalesTaxReportFilterData;
use App\Services\Reports\SalesTaxReportQueryService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Setting\Entities\Tax;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesTaxEffectiveDateTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $customer;
    protected $product;
    protected $tax;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create();

        $this->user = User::factory()->create();
        Role::firstOrCreate(['name' => 'Staff']);
        $this->user->assignRole('Staff');
        $staffRole = Role::where('name', 'Staff')->first();
        $this->user->settings()->attach($this->setting->id, ['role_id' => $staffRole->id]);
        
        session(['setting_id' => $this->setting->id]);

        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        
        $category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'CAT-01',
            'category_name' => 'Category',
            'created_by' => $this->user->id,
        ]);
        
        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-PROD-01',
            'product_price' => 1000,
            'product_cost' => 500,
            'product_quantity' => 10,
            'stock_managed' => true,
        ]);

        $this->tax = Tax::create([
            'name' => 'VAT 10%',
            'value' => 10,
        ]);
    }

    private function createSale(string $originalDate, ?string $reportingDate = null, int $amount = 1000, int $taxAmount = 100): Sale
    {
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'reference' => 'TEST-SALE-' . uniqid(),
            'customer_name' => 'Test Customer',
            'date' => Carbon::parse($originalDate),
            'reporting_date' => $reportingDate ? Carbon::parse($reportingDate) : null,
            'due_date' => Carbon::parse($originalDate)->addDays(30),
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => $amount + $taxAmount,
            'paid_amount' => 0,
            'due_amount' => $amount + $taxAmount,
            'is_tax_included' => false,
            'tax_amount' => $taxAmount,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->product_code,
            'product_name' => $this->product->product_name,
            'quantity' => 1,
            'price' => $amount,
            'unit_price' => $amount,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => $taxAmount,
            'tax_id' => $this->tax->id,
            'sub_total' => $amount + $taxAmount,
        ]);

        return $sale;
    }

    public function test_sales_tax_report_uses_effective_date()
    {
        // Originally in Jan, but overridden to Feb
        $sale1 = $this->createSale('2026-01-15', '2026-02-15', 1000, 100);
        
        // Originally in March, overridden to Feb
        $sale2 = $this->createSale('2026-03-10', '2026-02-20', 2000, 200);

        // In Feb, without override
        $sale3 = $this->createSale('2026-02-10', null, 3000, 300);

        // In Jan, without override
        $sale4 = $this->createSale('2026-01-20', null, 4000, 400);

        // Fetch for Feb
        $filter = new SalesTaxReportFilterData(
            startDate: '2026-02-01',
            endDate: '2026-02-28',
            scopeSettingId: $this->setting->id
        );
        $service = new SalesTaxReportQueryService();
        $results = $service->build($filter);

        // It should group all sales tax into one row per tax for Penjualan
        $this->assertCount(1, $results);

        $row = $results->first();
        $this->assertEquals('VAT 10%', $row->tax_name);
        $this->assertEquals('Penjualan', $row->transaction_type);

        // DPP = 1000 + 2000 + 3000 = 6000
        $this->assertEquals(6000, $row->dpp);

        // Tax = 100 + 200 + 300 = 600
        $this->assertEquals(600, $row->total_tax);
    }
}
