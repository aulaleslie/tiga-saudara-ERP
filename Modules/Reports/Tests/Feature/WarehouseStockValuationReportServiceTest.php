<?php

namespace Modules\Reports\Tests\Feature;

use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Setting;
use App\Models\User;
use App\Services\Reports\WarehouseStockValuationReportFilterData;
use App\Services\Reports\WarehouseStockValuationReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Tests\TestCase;
use Spatie\Permission\Models\Role;

class WarehouseStockValuationReportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $currency;
    protected $location1;
    protected $location2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::create([
            'currency_name' => 'Indonesian Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456789',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'test@example.com',
            'footer_text' => 'Test Footer',
            'company_address' => 'Test Address'
        ]);

        $this->user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $this->user->settings()->attach($this->setting->id, ['role_id' => $role->id]);

        $this->location1 = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Warehouse A'
        ]);

        $this->location2 = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Warehouse B'
        ]);
    }

    private function makeCategory(string $name = 'General', ?int $settingId = null): Category
    {
        return Category::create([
            'setting_id' => $settingId ?? $this->setting->id,
            'category_code' => 'CAT-' . strtoupper(uniqid()),
            'category_name' => $name, 'created_by' => $this->user->id,
        ]);
    }

    private function makeProduct(Category $category, string $code, string $name, bool $stockManaged = true, float $averagePrice = 0, float $minQty = 0, ?int $settingId = null): Product
    {
        $settingId = $settingId ?? $this->setting->id;

        $product = Product::create([
            'setting_id' => $settingId,
            'category_id' => $category->id,
            'product_name' => $name,
            'product_code' => $code,
            'stock_managed' => $stockManaged,
            'average_purchase_price' => $averagePrice,
            'product_stock_alert' => $minQty, 'product_cost' => 0, 'product_price' => 0, 'product_cost' => 0, 'product_price' => 0
        ]);


        if ($averagePrice > 0) {
            ProductPrice::create([
                'setting_id' => $settingId,
                'product_id' => $product->id,
                'average_purchase_price' => $averagePrice,
                'last_purchase_price' => $averagePrice,
                'sale_price' => $averagePrice * 1.5,
            ]);
        }

        return $product;
    }

                private function makeTransaction(Product $product, Location $location, string $type, float $qty, string $date, string $reason = ''): Transaction
    {
        $trx = Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $product->setting_id,
            'location_id' => $location->id,
            'user_id' => $this->user->id ?? 1,
            'type' => $type,
            'quantity' => $qty,
            'quantity_non_tax' => $qty,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'previous_quantity' => 0,
            'after_quantity' => $qty,
            'current_quantity' => $qty,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => $qty,
            'current_quantity_at_location' => $qty,
            'reason' => $reason,
        ]);
        $trx->created_at = \Carbon\Carbon::parse($date);
        $trx->updated_at = \Carbon\Carbon::parse($date);
        $trx->save(['timestamps' => false]);
        return $trx;
    }

    /** @test */
    public function it_calculates_stock_value_with_average_cost_source_and_as_of_cutoff()
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category, 'P1', 'Product A', true, 15000);

        // Transaction before cutoff
        $this->makeTransaction($product, $this->location1, 'init_stock', 10, '2023-01-01 10:00:00');
        
        // Transaction after cutoff (should be ignored)
        $this->makeTransaction($product, $this->location1, 'buy', 5, '2023-01-15 10:00:00', 'Buy #B1');

        $filter = new WarehouseStockValuationReportFilterData(
            asOfDate: '2023-01-10',
            scopeSettingId: $this->setting->id,
            warehouseIds: [$this->location1->id]
        );

        $service = new WarehouseStockValuationReportQueryService();
        $results = $service->build($filter);

        $this->assertCount(1, $results);
        $row = $results->first();

        $this->assertEquals(10, $row->qty);
        $this->assertEquals(15000, $row->average_cost);
        $this->assertEquals(150000, $row->stock_value);
    }

    /** @test */
    public function it_excludes_non_stock_managed_products()
    {
        $category = $this->makeCategory();
        $this->makeProduct($category, 'P1', 'Managed', true, 100);
        $this->makeProduct($category, 'P2', 'Service', false, 500);

        $filter = new WarehouseStockValuationReportFilterData(
            asOfDate: '2023-01-10',
            scopeSettingId: $this->setting->id,
            warehouseIds: [$this->location1->id]
        );

        $service = new WarehouseStockValuationReportQueryService();
        $results = $service->build($filter);

        $this->assertCount(1, $results);
        $this->assertEquals('P1', $results->first()->product_code);
    }

    /** @test */
    public function it_applies_product_status_filters_correctly()
    {
        $category = $this->makeCategory();
        
        // out_of_stock
        $p0 = $this->makeProduct($category, 'P0', 'Zero', true, 10, 5);
        $this->makeTransaction($p0, $this->location1, 'init', 0, '2023-01-01 10:00:00');

        // below_minimum (qty: 3, min: 5)
        $pMin = $this->makeProduct($category, 'PMIN', 'Below Min', true, 10, 5);
        $this->makeTransaction($pMin, $this->location1, 'init', 3, '2023-01-01 10:00:00');

        // available
        $pAvail = $this->makeProduct($category, 'PAVAIL', 'Available', true, 10, 5);
        $this->makeTransaction($pAvail, $this->location1, 'init', 10, '2023-01-01 10:00:00');

        $service = new WarehouseStockValuationReportQueryService();

        // 1. out_of_stock
        $filterOut = new WarehouseStockValuationReportFilterData(
            scopeSettingId: $this->setting->id,
            warehouseIds: [$this->location1->id],
            productStockStatus: 'out_of_stock'
        );
        $resultsOut = $service->build($filterOut);
        $this->assertCount(1, $resultsOut);
        $this->assertEquals('P0', $resultsOut->first()->product_code);

        // 2. below_minimum (should include negative and zero too if min >= 0)
        $filterBelow = new WarehouseStockValuationReportFilterData(
            scopeSettingId: $this->setting->id,
            warehouseIds: [$this->location1->id],
            productStockStatus: 'below_minimum'
        );
        $resultsBelow = $service->build($filterBelow);
        $this->assertCount(2, $resultsBelow); // P0 (0 <= 5) and PMIN (3 <= 5)

        // 3. available
        $filterAvail = new WarehouseStockValuationReportFilterData(
            scopeSettingId: $this->setting->id,
            warehouseIds: [$this->location1->id],
            productStockStatus: 'available'
        );
        $resultsAvail = $service->build($filterAvail);
        $this->assertCount(2, $resultsAvail); // PMIN (3 > 0) and PAVAIL (10 > 0)
    }

    /** @test */
    public function it_applies_category_filters()
    {
        $cat1 = $this->makeCategory('Cat 1');
        $cat2 = $this->makeCategory('Cat 2');
        
        $p1 = $this->makeProduct($cat1, 'P1', 'Product 1');
        $p2 = $this->makeProduct($cat2, 'P2', 'Product 2');
        
        $p3 = $this->makeProduct($cat1, 'P3', 'Product 3');

        $filterAny = new WarehouseStockValuationReportFilterData(
            scopeSettingId: $this->setting->id,
            warehouseIds: [$this->location1->id],
            categoryIds: [$cat1->id, $cat2->id],
            categoryMatchMode: 'any'
        );

        $service = new WarehouseStockValuationReportQueryService();
        $resultsAny = $service->build($filterAny);
        $this->assertCount(3, $resultsAny);

        $filterAll = new WarehouseStockValuationReportFilterData(
            scopeSettingId: $this->setting->id,
            warehouseIds: [$this->location1->id],
            categoryIds: [$cat1->id, $cat2->id],
            categoryMatchMode: 'all'
        );

        $resultsAll = $service->build($filterAll);
        $this->assertCount(0, $resultsAll);
    }
}
