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
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
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
        $this->assignSaleLocation($setting, $location);

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
        
        $this->assignSaleLocation($setting, $loc1);
        $this->assignSaleLocation($setting, $loc2);

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
        
        $this->assignSaleLocation($setting, $loc1);
        $this->assignSaleLocation($setting, $loc2);

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
        
        $this->assignSaleLocation($borrowerBusiness, $borrowedLoc);

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
        $this->assignSaleLocation($setting, $loc1);

        $product = $this->createProduct($setting, 'PROD-005', 30000);
        $this->seedStock($product, $loc1, 2);

        $resolver = app(ResolvePosStockAllocationsService::class);
        $result = $resolver->resolve($setting->id, [
            ['product_id' => $product->id, 'qty' => 5, 'tax_id' => null]
        ]);

        $this->assertNotEmpty($result['unfulfilled_lines']);
    }

    public function test_serial_line_with_taxed_assigned_serial_resolves_when_line_tax_is_null(): void
    {
        $setting = $this->createSetting('BIZ-SERIAL-TAX');
        $setting->update(['is_pkp' => true]);

        $location = $this->createLocation($setting, 'LOC-SERIAL');
        $this->assignSaleLocation($setting, $location);

        $tax = Tax::query()->create([
            'name' => 'PPN SERIAL',
            'value' => 11,
            'is_default' => true,
        ]);

        $product = $this->createProduct($setting, 'PROD-SERIAL-TAX', 40000);
        $this->seedTaxedStock($product, $location, 5, $tax->id);
        $this->createSerialNumber($product, $location, 'SN-TAX-ALLOW-001', $tax->id);

        $resolver = app(ResolvePosStockAllocationsService::class);
        $result = $resolver->resolve($setting->id, [[
            'product_id' => $product->id,
            'qty' => 1,
            'tax_id' => null,
            'serial_number_required' => true,
            'assigned_serials' => ['SN-TAX-ALLOW-001'],
        ]]);

        $this->assertSame([], $result['unfulfilled_lines']);
        $this->assertSame([], $result['unfulfilled_details']);
        $this->assertSame(1, $result['allocations'][0][0]['allocated_qty']);
        $this->assertTrue((bool) $result['allocations'][0][0]['tax_bucket_used']);
        $this->assertSame($tax->id, $result['allocations'][0][0]['tax_policy_snapshot']['tax_id']);
    }

    public function test_serial_line_reports_location_not_allowed_reason(): void
    {
        $setting = $this->createSetting('BIZ-SERIAL-DENY');
        $allowedLocation = $this->createLocation($setting, 'LOC-ALLOWED');
        $this->assignSaleLocation($setting, $allowedLocation);

        $blockedLocation = $this->createLocation($setting, 'LOC-BLOCKED');
        $product = $this->createProduct($setting, 'PROD-SERIAL-DENY', 42000);
        $this->seedStock($product, $blockedLocation, 3);
        $this->createSerialNumber($product, $blockedLocation, 'SN-DENY-001', null);

        $resolver = app(ResolvePosStockAllocationsService::class);
        $result = $resolver->resolve($setting->id, [[
            'product_id' => $product->id,
            'qty' => 1,
            'tax_id' => null,
            'serial_number_required' => true,
            'assigned_serials' => ['SN-DENY-001'],
        ]]);

        $this->assertSame([0], $result['unfulfilled_lines']);
        $this->assertSame('SERIAL_LOCATION_NOT_ALLOWED', $result['unfulfilled_details'][0]['reason_code']);
        $this->assertSame($product->id, $result['unfulfilled_details'][0]['product_id']);
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

    private function assignSaleLocation(Setting $setting, Location $location): void
    {
        SettingSaleLocation::withoutEvents(function() use ($setting, $location) {
            SettingSaleLocation::updateOrCreate(
                ['setting_id' => $setting->id, 'location_id' => $location->id],
                ['is_enabled' => true]
            );
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

    private function seedTaxedStock(Product $product, Location $location, int $qty, int $taxId): void
    {
        ProductStock::withoutEvents(fn() => ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $qty,
            'quantity_non_tax' => 0,
            'quantity_tax' => $qty,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'tax_id' => $taxId,
        ]));
        $product->increment('product_quantity', $qty);
    }

    private function createSerialNumber(Product $product, Location $location, string $serialNumber, ?int $taxId): ProductSerialNumber
    {
        return ProductSerialNumber::query()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => $serialNumber,
            'tax_id' => $taxId,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);
    }
}
