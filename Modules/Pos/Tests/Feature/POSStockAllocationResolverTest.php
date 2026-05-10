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

    public function test_mixed_serials_across_locations_validate_against_their_own_stock_buckets(): void
    {
        $setting = $this->createSetting('BIZ-SERIAL-MIXED');
        $setting->update(['is_pkp' => true]);

        $tax = Tax::query()->create([
            'name' => 'PPN MIXED SERIAL',
            'value' => 11,
            'is_default' => true,
        ]);

        $product = $this->createProduct($setting, 'PROD-SERIAL-MIXED', 50000);

        $taxLocation = $this->createLocation($setting, 'LOC-TAX');
        $this->assignSaleLocation($setting, $taxLocation);
        $this->seedTaxedStock($product, $taxLocation, 1, $tax->id);
        $this->createSerialNumber($product, $taxLocation, 'SN-MIX-001', $tax->id);

        $serials = ['SN-MIX-001'];
        for ($index = 2; $index <= 6; $index++) {
            $location = $this->createLocation($setting, 'LOC-NON-TAX-' . $index);
            $this->assignSaleLocation($setting, $location);
            $this->seedStock($product, $location, 1);
            $serial = 'SN-MIX-00' . $index;
            $this->createSerialNumber($product, $location, $serial, null);
            $serials[] = $serial;
        }

        $resolver = app(ResolvePosStockAllocationsService::class);
        $result = $resolver->resolve($setting->id, [[
            'product_id' => $product->id,
            'qty' => 6,
            'tax_id' => $tax->id,
            'serial_number_required' => true,
            'assigned_serials' => $serials,
        ]]);

        $this->assertSame([], $result['unfulfilled_lines']);
        $this->assertCount(6, $result['allocations'][0]);
        $this->assertSame(1, collect($result['allocations'][0])->where('tax_bucket_used', true)->count());
        $this->assertSame(5, collect($result['allocations'][0])->where('tax_bucket_used', false)->count());
    }

    public function test_serial_with_null_tax_id_uses_non_tax_bucket_even_when_line_tax_exists(): void
    {
        $setting = $this->createSetting('BIZ-SERIAL-NON-TAX');
        $setting->update(['is_pkp' => true]);

        $location = $this->createLocation($setting, 'LOC-SERIAL-NON-TAX');
        $this->assignSaleLocation($setting, $location);

        $tax = Tax::query()->create([
            'name' => 'PPN SERIAL NON TAX',
            'value' => 11,
            'is_default' => true,
        ]);

        $product = $this->createProduct($setting, 'PROD-SERIAL-NON-TAX', 35000);
        $this->seedStock($product, $location, 1);
        ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->update(['quantity_tax' => 0, 'quantity_non_tax' => 1]);
        $this->createSerialNumber($product, $location, 'SN-NON-TAX-001', null);

        $resolver = app(ResolvePosStockAllocationsService::class);
        $result = $resolver->resolve($setting->id, [[
            'product_id' => $product->id,
            'qty' => 1,
            'tax_id' => $tax->id,
            'serial_number_required' => true,
            'assigned_serials' => ['SN-NON-TAX-001'],
        ]]);

        $this->assertSame([], $result['unfulfilled_lines']);
        $this->assertFalse((bool) $result['allocations'][0][0]['tax_bucket_used']);
        $this->assertNull($result['allocations'][0][0]['tax_policy_snapshot']['tax_id']);
    }

    public function test_serial_with_tax_id_fails_when_tax_bucket_is_empty(): void
    {
        $setting = $this->createSetting('BIZ-SERIAL-TAX-EMPTY');
        $setting->update(['is_pkp' => true]);

        $location = $this->createLocation($setting, 'LOC-SERIAL-TAX-EMPTY');
        $this->assignSaleLocation($setting, $location);

        $tax = Tax::query()->create([
            'name' => 'PPN SERIAL EMPTY',
            'value' => 11,
            'is_default' => true,
        ]);

        $product = $this->createProduct($setting, 'PROD-SERIAL-TAX-EMPTY', 37000);
        $this->seedStock($product, $location, 1);
        $this->createSerialNumber($product, $location, 'SN-TAX-EMPTY-001', $tax->id);

        $resolver = app(ResolvePosStockAllocationsService::class);
        $result = $resolver->resolve($setting->id, [[
            'product_id' => $product->id,
            'qty' => 1,
            'tax_id' => $tax->id,
            'serial_number_required' => true,
            'assigned_serials' => ['SN-TAX-EMPTY-001'],
        ]]);

        $this->assertSame([0], $result['unfulfilled_lines']);
        $this->assertSame('SERIAL_TAX_STOCK_UNAVAILABLE', $result['unfulfilled_details'][0]['reason_code']);
    }

    public function test_non_taxable_line_uses_non_tax_bucket_first_across_locations(): void
    {
        $setting = $this->createSetting('BIZ-NON-TAX-FIRST');
        $loc1 = $this->createLocation($setting, 'LOC-FIRST');
        $loc2 = $this->createLocation($setting, 'LOC-SECOND');
        $this->assignSaleLocation($setting, $loc1);
        $this->assignSaleLocation($setting, $loc2);

        $product = $this->createProduct($setting, 'PROD-NON-TAX-FIRST', 18000);
        $this->seedStock($product, $loc1, 1);
        $this->seedStock($product, $loc2, 2);
        ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $loc1->id)
            ->update(['quantity_tax' => 4]);
        ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $loc2->id)
            ->update(['quantity_tax' => 4]);

        $resolver = app(ResolvePosStockAllocationsService::class);
        $result = $resolver->resolve($setting->id, [
            ['product_id' => $product->id, 'qty' => 3, 'tax_id' => null],
        ]);

        $this->assertSame([], $result['unfulfilled_lines']);
        $this->assertCount(2, $result['allocations'][0]);
        $this->assertSame([1, 2], array_column($result['allocations'][0], 'allocated_qty'));
        $this->assertSame([false, false], array_column($result['allocations'][0], 'tax_bucket_used'));
    }

    public function test_non_taxable_line_falls_back_to_tax_bucket_when_non_tax_is_exhausted(): void
    {
        $setting = $this->createSetting('BIZ-NON-TAX-FALLBACK');
        $location = $this->createLocation($setting, 'LOC-FALLBACK');
        $this->assignSaleLocation($setting, $location);

        $product = $this->createProduct($setting, 'PROD-NON-TAX-FALLBACK', 19000);
        $this->seedStock($product, $location, 3);
        ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->update([
                'quantity_non_tax' => 1,
                'quantity_tax' => 2,
            ]);

        $resolver = app(ResolvePosStockAllocationsService::class);
        $result = $resolver->resolve($setting->id, [
            ['product_id' => $product->id, 'qty' => 3, 'tax_id' => null],
        ]);

        $this->assertSame([], $result['unfulfilled_lines']);
        $this->assertCount(2, $result['allocations'][0]);
        $this->assertSame(1, $result['allocations'][0][0]['allocated_qty']);
        $this->assertFalse((bool) $result['allocations'][0][0]['tax_bucket_used']);
        $this->assertSame(2, $result['allocations'][0][1]['allocated_qty']);
        $this->assertTrue((bool) $result['allocations'][0][1]['tax_bucket_used']);
        $this->assertNull($result['allocations'][0][1]['tax_policy_snapshot']['tax_id']);
    }

    public function test_quantity_tax_fallback_snapshot_uses_default_tax_when_metadata_is_missing(): void
    {
        $setting = $this->createSetting('BIZ-TAX-BUCKET-FALLBACK');
        $setting->update(['is_pkp' => false]);

        $location = $this->createLocation($setting, 'LOC-TAX-BUCKET-FALLBACK');
        $this->assignSaleLocation($setting, $location);

        $defaultTax = Tax::query()->create([
            'name' => 'PPN DEFAULT',
            'value' => 11,
            'is_default' => true,
        ]);

        $product = $this->createProduct($setting, 'PROD-TAX-BUCKET-FALLBACK', 21000);
        ProductPrice::query()->create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => 21000,
            'sale_tax_id' => null,
        ]);

        ProductStock::query()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 2,
            'quantity_non_tax' => 0,
            'quantity_tax' => 2,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'tax_id' => null,
        ]);
        $product->increment('product_quantity', 2);

        $resolver = app(ResolvePosStockAllocationsService::class);
        $result = $resolver->resolve($setting->id, [
            ['product_id' => $product->id, 'qty' => 1, 'tax_id' => null],
        ]);

        $this->assertSame([], $result['unfulfilled_lines']);
        $this->assertCount(1, $result['allocations'][0]);
        $this->assertTrue((bool) $result['allocations'][0][0]['tax_bucket_used']);
        $this->assertSame($defaultTax->id, $result['allocations'][0][0]['tax_policy_snapshot']['tax_id']);
        $this->assertSame('PPN DEFAULT', $result['allocations'][0][0]['tax_policy_snapshot']['tax_name']);
        $this->assertSame(11.0, $result['allocations'][0][0]['tax_policy_snapshot']['tax_rate']);
    }

    public function test_non_pkp_non_tax_allocation_keeps_non_tax_snapshot_even_with_tax_candidates(): void
    {
        $setting = $this->createSetting('BIZ-NON-PKP-SNAPSHOT');
        $setting->update(['is_pkp' => false]);

        $location = $this->createLocation($setting, 'LOC-NON-PKP-SNAPSHOT');
        $this->assignSaleLocation($setting, $location);

        $tax = Tax::query()->create([
            'name' => 'PPN CANDIDATE',
            'value' => 11,
            'is_default' => true,
        ]);

        $product = $this->createProduct($setting, 'PROD-NON-PKP-SNAPSHOT', 22000);
        $product->update(['sale_tax_id' => $tax->id]);
        ProductPrice::query()->create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => 22000,
            'sale_tax_id' => $tax->id,
        ]);

        $this->seedStock($product, $location, 2);
        ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->update(['tax_id' => $tax->id]);

        $resolver = app(ResolvePosStockAllocationsService::class);
        $result = $resolver->resolve($setting->id, [
            ['product_id' => $product->id, 'qty' => 1, 'tax_id' => null],
        ]);

        $this->assertSame([], $result['unfulfilled_lines']);
        $this->assertCount(1, $result['allocations'][0]);
        $this->assertFalse((bool) $result['allocations'][0][0]['tax_bucket_used']);
        $this->assertNull($result['allocations'][0][0]['tax_policy_snapshot']['tax_id']);
        $this->assertNull($result['allocations'][0][0]['tax_policy_snapshot']['tax_name']);
        $this->assertSame(0.0, $result['allocations'][0][0]['tax_policy_snapshot']['tax_rate']);
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
