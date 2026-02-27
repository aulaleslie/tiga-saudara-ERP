<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Pos\Services\ResolvePosStockAllocationsService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class POSStockAllocationResolverTest extends TestCase
{
    use RefreshDatabase;

    private int $serial = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['pos.access', 'pos.sell', 'pos.sessions.open'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_single_location_allocation_preferred_only(): void
    {
        $setting = $this->createSetting('BIZ-L1');
        $location = $this->createLocation($setting, 'LOC-A');
        $this->assignSaleLocation($setting, $location, 1);

        $product = $this->createProduct($setting, 'PROD-001', 10000);
        $this->seedStock($product, $location, 10);

        $resolver = app(ResolvePosStockAllocationsService::class);
        $result = $resolver->resolve($setting->id, [
            ['product_id' => $product->id, 'qty' => 5, 'tax_id' => null]
        ]);

        $this->assertEmpty($result['unfulfilled_lines']);
        $this->assertEquals($location->id, $result['allocations'][0][0]['source_location_id']);
        $this->assertEquals(5, $result['allocations'][0][0]['allocated_qty']);
    }

    public function test_fallback_to_second_configured_location(): void
    {
        $setting = $this->createSetting('BIZ-L2-FALLBACK');
        $loc1 = $this->createLocation($setting, 'LOC-1');
        $loc2 = $this->createLocation($setting, 'LOC-2');
        
        $this->assignSaleLocation($setting, $loc1, 1);
        $this->assignSaleLocation($setting, $loc2, 2);

        $product = $this->createProduct($setting, 'PROD-002', 15000);
        $this->seedStock($product, $loc1, 0); 
        $this->seedStock($product, $loc2, 10);

        $resolver = app(ResolvePosStockAllocationsService::class);
        $result = $resolver->resolve($setting->id, [
            ['product_id' => $product->id, 'qty' => 5, 'tax_id' => null]
        ]);

        $this->assertEmpty($result['unfulfilled_lines']);
        $this->assertEquals($loc2->id, $result['allocations'][0][0]['source_location_id']);
        $this->assertEquals(5, $result['allocations'][0][0]['allocated_qty']);
    }

    public function test_split_across_two_locations(): void
    {
        $setting = $this->createSetting('BIZ-L2-SPLIT');
        $loc1 = $this->createLocation($setting, 'LOC-A');
        $loc2 = $this->createLocation($setting, 'LOC-B');
        
        $this->assignSaleLocation($setting, $loc1, 1);
        $this->assignSaleLocation($setting, $loc2, 2);

        $product = $this->createProduct($setting, 'PROD-003', 20000);
        $this->seedStock($product, $loc1, 3);
        $this->seedStock($product, $loc2, 10);

        $resolver = app(ResolvePosStockAllocationsService::class);
        $result = $resolver->resolve($setting->id, [
            ['product_id' => $product->id, 'qty' => 5, 'tax_id' => null]
        ]);

        $this->assertEmpty($result['unfulfilled_lines']);
        $this->assertCount(2, $result['allocations'][0]);
        $this->assertEquals(3, $result['allocations'][0][0]['allocated_qty']);
        $this->assertEquals(2, $result['allocations'][0][1]['allocated_qty']);
    }

    public function test_borrowed_location_used_when_configured(): void
    {
        $ownerBusiness = $this->createSetting('OWNER-BIZ');
        $borrowerBusiness = $this->createSetting('BORROWER-BIZ');
        $borrowedLoc = $this->createLocation($ownerBusiness, 'BORROWED-LOC');
        
        $this->assignSaleLocation($borrowerBusiness, $borrowedLoc, 1);

        $product = $this->createProduct($borrowerBusiness, 'PROD-004', 25000);
        $this->seedStock($product, $borrowedLoc, 10);

        $resolver = app(ResolvePosStockAllocationsService::class);
        $result = $resolver->resolve($borrowerBusiness->id, [
            ['product_id' => $product->id, 'qty' => 5, 'tax_id' => null]
        ]);

        $this->assertEmpty($result['unfulfilled_lines']);
        $this->assertEquals($borrowedLoc->id, $result['allocations'][0][0]['source_location_id']);
        $this->assertEquals($ownerBusiness->id, $result['allocations'][0][0]['source_setting_id']);
    }

    public function test_insufficient_stock_blocks_resolution(): void
    {
        $setting = $this->createSetting('BIZ-EMPTY');
        $loc1 = $this->createLocation($setting, 'LOC-1');
        $this->assignSaleLocation($setting, $loc1, 1);

        $product = $this->createProduct($setting, 'PROD-005', 30000);
        $this->seedStock($product, $loc1, 2);

        $resolver = app(ResolvePosStockAllocationsService::class);
        $result = $resolver->resolve($setting->id, [
            ['product_id' => $product->id, 'qty' => 5, 'tax_id' => null]
        ]);

        $this->assertNotEmpty($result['unfulfilled_lines']);
    }

    // --- Helpers using withoutEvents to stay clean ---

    private function createSetting(string $name): Setting
    {
        return Setting::withoutEvents(fn() => Setting::create([
            'company_name' => $name,
            'company_email' => $name . '@example.com',
            'company_phone' => '0800',
            'company_address' => 'X',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => $name . '@example.com',
            'footer_text' => 'F',
            'document_prefix' => 'D',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
        ]));
    }

    private function createLocation(Setting $setting, string $name): Location
    {
        return Location::withoutEvents(fn() => Location::create([
            'name' => $name,
            'setting_id' => $setting->id,
        ]));
    }

    private function assignSaleLocation(Setting $setting, Location $location, int $position): void
    {
        SettingSaleLocation::withoutEvents(function() use ($setting, $location, $position) {
            SettingSaleLocation::where('location_id', $location->id)->delete();
            SettingSaleLocation::create([
                'setting_id' => $setting->id,
                'location_id' => $location->id,
                'position' => $position,
            ]);
        });
    }

    private function createProduct(Setting $setting, string $code, float $price): Product
    {
        return Product::withoutEvents(function() use ($setting, $code, $price) {
            $cat = Category::create([
                'category_code' => $code . 'C',
                'category_name' => $code . 'C',
                'setting_id' => $setting->id,
                'created_by' => 1,
            ]);

            $unit = Unit::firstOrCreate(['name' => 'PC', 'short_name' => 'PC']);

            return Product::create([
                'setting_id' => $setting->id,
                'category_id' => $cat->id,
                'unit_id' => $unit->id,
                'product_name' => $code,
                'product_code' => $code,
                'product_quantity' => 0,
                'product_price' => $price,
                'product_cost' => $price * 0.5,
                'product_unit' => 'PC',
                'stock_managed' => true,
            ]);
        });
    }

    private function seedStock(Product $product, Location $location, int $qty): void
    {
        ProductStock::withoutEvents(fn() => ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $qty,
            'quantity_non_tax' => $qty,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]));
        $product->increment('product_quantity', $qty);
    }
}
