<?php

namespace Tests\Feature\Livewire\Reports;

use App\Services\Reports\SaleByCustomerReportFilterData;
use App\Services\Reports\SaleByCustomerReportQueryService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SaleByCustomerEffectiveDateTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $customer;
    protected $product;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'sales.access']);
        Permission::firstOrCreate(['name' => 'sales.show']);
        Role::firstOrCreate(['name' => 'Staff']);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456789',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address'
        ]);

        $this->user = User::factory()->create();
        $this->user->assignRole('Staff');
        $this->user->givePermissionTo('sales.access');
        $this->user->givePermissionTo('sales.show');

        $staffRole = Role::where('name', 'Staff')->first();
        $this->user->settings()->attach($this->setting->id, ['role_id' => $staffRole->id]);
        
        session(['setting_id' => $this->setting->id]);

        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        $category = \Modules\Product\Entities\Category::create([
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
    }

    private function createSale(string $originalDate, ?string $reportingDate = null, int $amount = 1000): Sale
    {
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'reference' => 'TEST-' . uniqid(),
            'customer_name' => 'Test Customer',
            'date' => Carbon::parse($originalDate),
            'reporting_date' => $reportingDate ? Carbon::parse($reportingDate) : null,
            'due_date' => Carbon::parse($originalDate)->addDays(30),
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => $amount,
            'paid_amount' => 0,
            'due_amount' => $amount,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => $amount,
            'unit_price' => $amount,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'sub_total' => $amount,
        ]);

        return $sale;
    }

    public function test_sale_by_customer_uses_effective_date_for_filtering()
    {
        $this->createSale('2026-01-15', null);
        $this->createSale('2026-01-20', '2026-02-15');
        $this->createSale('2026-03-10', '2026-02-20');

        $filter = new SaleByCustomerReportFilterData(
            startDate: '2026-02-01',
            endDate: '2026-02-28',
            scopeSettingId: $this->setting->id
        );
        $service = new SaleByCustomerReportQueryService();
        $query = $service->build($filter);

        $this->assertCount(2, $query->get());
    }

    public function test_sale_by_customer_sorting_uses_effective_date()
    {
        $sale1 = $this->createSale('2026-01-20', '2026-02-15');
        $sale2 = $this->createSale('2026-01-10', '2026-02-20');
        $sale3 = $this->createSale('2026-01-25', '2026-02-05');

        $filter = new SaleByCustomerReportFilterData(
            startDate: '2026-02-01',
            endDate: '2026-02-28',
            scopeSettingId: $this->setting->id
        );
        $service = new SaleByCustomerReportQueryService();
        $query = $service->build($filter);
        $service->applySort($query, 'date', 'asc');

        $data = $query->get();
        
        $this->assertEquals($sale3->id, $data[0]->sale_id);
        $this->assertEquals($sale1->id, $data[1]->sale_id);
        $this->assertEquals($sale2->id, $data[2]->sale_id);
    }

    public function test_sale_by_customer_rendered_date_uses_effective_date()
    {
        $sale = $this->createSale('2026-01-20', '2026-02-15');

        $filter = new SaleByCustomerReportFilterData(
            startDate: '2026-02-01',
            endDate: '2026-02-28',
            scopeSettingId: $this->setting->id
        );
        $service = new SaleByCustomerReportQueryService();
        $query = $service->build($filter);

        $data = $query->get();
        $this->assertCount(1, $data);

        $mappedRows = \App\Services\Reports\SaleByCustomerReportQueryService::mapRowsForExport($data[0], 0);
        
        $this->assertEquals('2026-02-15 00:00:00', $mappedRows[0]['Tanggal']);
    }

    public function test_sale_by_customer_cleared_override_falls_back_to_original_date()
    {
        $sale = $this->createSale('2026-01-20', '2026-02-15');

        $filter = new SaleByCustomerReportFilterData(
            startDate: '2026-02-01',
            endDate: '2026-02-28',
            scopeSettingId: $this->setting->id
        );
        $service = new SaleByCustomerReportQueryService();
        $this->assertCount(1, $service->build($filter)->get());

        $sale->update(['reporting_date' => null]);

        $this->assertCount(0, $service->build($filter)->get());

        $filterJan = new SaleByCustomerReportFilterData(
            startDate: '2026-01-01',
            endDate: '2026-01-31',
            scopeSettingId: $this->setting->id
        );
        $this->assertCount(1, $service->build($filterJan)->get());
    }
}
