<?php

namespace Tests\Feature\Livewire\Reports;

use App\Services\Reports\SaleByProductReportFilterData;
use App\Services\Reports\SaleByProductReportQueryService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SaleByProductEffectiveDateTest extends TestCase
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
    }

    private function createSale(string $originalDate, ?string $reportingDate = null, int $qty = 2, int $amount = 1000): Sale
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
            'total_amount' => $amount,
            'paid_amount' => 0,
            'due_amount' => $amount,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->product_code,
            'product_name' => $this->product->product_name,
            'quantity' => $qty,
            'price' => $amount / $qty,
            'unit_price' => $amount / $qty,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'sub_total' => $amount,
        ]);

        return $sale;
    }

    private function createSaleReturn(Sale $sale, string $returnDate, int $qty = 1, int $amount = 500): SaleReturn
    {
        $return = SaleReturn::create([
            'setting_id' => $this->setting->id,
            'sale_id' => $sale->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Test Customer',
            'reference' => 'TEST-RET-' . uniqid(),
            'date' => Carbon::parse($returnDate),
            'status' => 'Completed',
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => $amount,
            'paid_amount' => 0,
            'due_amount' => $amount,
        ]);

        SaleReturnDetail::create([
            'sale_return_id' => $return->id,
            'product_id' => $this->product->id,
            'product_code' => $this->product->product_code,
            'product_name' => $this->product->product_name,
            'quantity' => $qty,
            'price' => $amount / $qty,
            'unit_price' => $amount / $qty,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'sub_total' => $amount,
        ]);

        return $return;
    }

    public function test_sale_by_product_uses_effective_date_for_sales_and_actual_date_for_returns()
    {
        // Sale originally in January, but overridden to February
        $sale1 = $this->createSale('2026-01-20', '2026-02-15', qty: 5, amount: 5000);
        
        // Sale in February without override
        $sale2 = $this->createSale('2026-02-10', null, qty: 3, amount: 3000);

        // Return for sale1, happens in March
        $this->createSaleReturn($sale1, '2026-03-05', qty: 2, amount: 2000);

        // Return for sale2, happens in February
        $this->createSaleReturn($sale2, '2026-02-25', qty: 1, amount: 1000);

        // Filter for February
        $filter = new SaleByProductReportFilterData(
            startDate: '2026-02-01',
            endDate: '2026-02-28',
            scopeSettingIds: [$this->setting->id]
        );
        $service = new SaleByProductReportQueryService();
        $query = $service->build($filter);

        $data = $query->get();
        
        $this->assertCount(1, $data); // Grouped by product, so only 1 row
        
        $row = $data[0];
        
        // sold_quantity should include both sale1 and sale2 because sale1's effective date is in Feb
        // Total sold = 5 + 3 = 8
        $this->assertEquals(8, $row->sold_quantity);
        $this->assertEquals(8000, $row->sold_value);

        // return_quantity should only include sale2's return because it happened in Feb,
        // while sale1's return happened in March (which is outside the Feb filter).
        $this->assertEquals(1, $row->return_quantity);
        $this->assertEquals(1000, $row->return_value);
    }
}
