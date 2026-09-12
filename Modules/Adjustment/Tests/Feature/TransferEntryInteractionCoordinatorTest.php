<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Livewire\Transfer\TransferProductTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Services\TransferStockVisibility;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TransferEntryInteractionCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Location $origin;
    private Location $destination;
    private User $privilegedUser;
    private User $blindUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create();
        $this->origin = Location::factory()->create(['setting_id' => $this->setting->id]);
        $this->destination = Location::factory()->create(['setting_id' => $this->setting->id]);

        session(['setting_id' => $this->setting->id]);

        Permission::firstOrCreate(['name' => 'stockTransfers.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'stockTransfers.edit', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => TransferStockVisibility::PERMISSION, 'guard_name' => 'web']);

        $this->privilegedUser = User::factory()->create();
        $this->privilegedUser->givePermissionTo(['stockTransfers.create', 'stockTransfers.edit', TransferStockVisibility::PERMISSION]);

        $this->blindUser = User::factory()->create();
        $this->blindUser->givePermissionTo(['stockTransfers.create', 'stockTransfers.edit']);
    }

    private function createStockManagedProduct(string $name, string $code, ?string $barcode = null, bool $isSerialized = false): Product
    {
        $category = Category::firstOrCreate(
            ['category_code' => 'CAT01'],
            ['setting_id' => $this->setting->id, 'category_name' => 'General Category', 'created_by' => 1]
        );

        return Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => $name,
            'product_code' => $code,
            'barcode' => $barcode,
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => $isSerialized,
            'stock_managed' => true,
        ]);
    }

    private function setGoodStock(Product $product, int $tax, int $nonTax): ProductStock
    {
        return ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->origin->id,
            'quantity' => $tax + $nonTax,
            'quantity_tax' => $tax,
            'quantity_non_tax' => $nonTax,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
    }

    private function setBrokenStock(Product $product, int $brokenTax, int $brokenNonTax): ProductStock
    {
        return ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->origin->id,
            'quantity' => $brokenTax + $brokenNonTax,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => $brokenTax + $brokenNonTax,
            'broken_quantity_tax' => $brokenTax,
            'broken_quantity_non_tax' => $brokenNonTax,
        ]);
    }

    /** @test */
    public function exact_scan_resolves_product_and_increments_by_one(): void
    {
        $product = $this->createStockManagedProduct('Scanner Test Prod', 'STP01', 'BARCODE-EXACT-1');
        $this->setGoodStock($product, 10, 10);

        Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('processScan', 'BARCODE-EXACT-1')
            ->assertSet('products', function ($products) use ($product) {
                $this->assertCount(1, $products);
                $this->assertEquals($product->id, $products[0]['id']);
                $this->assertEquals(1, $products[0]['requested_quantity']);
                return true;
            })
            ->assertDispatched('restore-scanner-focus');
    }

    /** @test */
    public function exact_scan_with_conversion_barcode_increments_by_conversion_factor(): void
    {
        $product = $this->createStockManagedProduct('Conversion Scan Prod', 'CSP01', 'BARCODE-PRIMARY-1');
        $this->setGoodStock($product, 20, 20);

        $unit = Unit::create([
            'name' => 'Dozen',
            'short_name' => 'DZ',
            'operator' => '*',
            'operation_value' => 12,
            'is_active' => true,
        ]);

        ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'conversion_factor' => 12,
            'barcode' => 'CONV-BARCODE-12',
        ]);

        Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('processScan', 'CONV-BARCODE-12')
            ->assertSet('products', function ($products) {
                $this->assertCount(1, $products);
                $this->assertEquals(12, $products[0]['requested_quantity']);
                return true;
            });
    }

    /** @test */
    public function exact_scan_collision_triggers_ambiguity_modal_and_operator_choice(): void
    {
        $productA = $this->createStockManagedProduct('Collision Product A', 'CPA', 'COLLISION-BARCODE-001');
        $this->setGoodStock($productA, 10, 10);

        $productB = $this->createStockManagedProduct('Collision Product B', 'CPB', 'PRIMARY-B');
        $this->setGoodStock($productB, 10, 10);

        $unit = Unit::create(['name' => 'Box 5', 'short_name' => 'BX5', 'operator' => '*', 'operation_value' => 5, 'is_active' => true]);
        ProductUnitConversion::create([
            'product_id' => $productB->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'conversion_factor' => 5,
            'barcode' => 'COLLISION-BARCODE-001',
        ]);

        $component = Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('processScan', 'COLLISION-BARCODE-001')
            ->assertSet('showAmbiguityModal', true)
            ->assertSet('ambiguousCandidates', function ($candidates) {
                $this->assertCount(2, $candidates);
                return true;
            })
            ->assertDispatched('transfer-ambiguity-opened');

        // Select second candidate (Product B conversion x5)
        $component->call('selectAmbiguousCandidate', 1)
            ->assertSet('showAmbiguityModal', false)
            ->assertSet('products', function ($products) use ($productB) {
                $this->assertCount(1, $products);
                $this->assertEquals($productB->id, $products[0]['id']);
                $this->assertEquals(5, $products[0]['requested_quantity']);
                return true;
            })
            ->assertDispatched('transfer-ambiguity-closed')
            ->assertDispatched('restore-scanner-focus');
    }

    /** @test */
    public function cancelling_ambiguity_modal_leaves_rows_intact_and_restores_focus(): void
    {
        $productA = $this->createStockManagedProduct('Collision A', 'CA', 'COLLIDE-999');
        $this->setGoodStock($productA, 10, 10);

        $productB = $this->createStockManagedProduct('Collision B', 'CB', 'COLLIDE-999-ALT');
        $this->setGoodStock($productB, 10, 10);

        $unit = Unit::create(['name' => 'Pack', 'short_name' => 'PK', 'operator' => '*', 'operation_value' => 2, 'is_active' => true]);
        ProductUnitConversion::create([
            'product_id' => $productB->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'conversion_factor' => 2,
            'barcode' => 'COLLIDE-999',
        ]);

        Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('processScan', 'COLLIDE-999')
            ->assertSet('showAmbiguityModal', true)
            ->call('closeAmbiguityModal')
            ->assertSet('showAmbiguityModal', false)
            ->assertSet('products', [])
            ->assertDispatched('transfer-ambiguity-closed')
            ->assertDispatched('restore-scanner-focus');
    }

    /** @test */
    public function unknown_exact_scan_does_not_fall_through_to_fuzzy_search(): void
    {
        $product = $this->createStockManagedProduct('Laptop Lenovo ThinkPad', 'LENOVO-01', 'LENOVO-BARCODE');
        $this->setGoodStock($product, 10, 10);

        Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('processScan', 'LENOVO') // Similar to product code/name, but not exact barcode
            ->assertSet('products', [])
            ->assertSet('feedbackType', 'danger')
            ->assertDispatched('select-scan-input');
    }

    /** @test */
    public function deliberate_product_search_filters_by_multi_token_and_adds_one_base_unit_on_selection(): void
    {
        $product = $this->createStockManagedProduct('Monitor LED Dell 24 Inch', 'DELL-24', 'DELL-BARCODE-01');
        $this->setGoodStock($product, 5, 5);

        $component = Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('openSearchModal')
            ->set('searchTerm', 'Dell 24')
            ->call('searchProducts')
            ->assertSet('searchResults', function ($results) use ($product) {
                $this->assertCount(1, $results);
                $this->assertEquals($product->id, $results[0]['id']);
                return true;
            });

        // Selecting adds 1 base unit
        $component->call('selectSearchProduct', ['id' => $product->id])
            ->assertSet('showSearchModal', false)
            ->assertSet('products', function ($products) use ($product) {
                $this->assertCount(1, $products);
                $this->assertEquals(1, $products[0]['requested_quantity']);
                return true;
            })
            ->assertDispatched('restore-scanner-focus');

        // Selecting the same product again from search focuses without incrementing
        $component->call('selectSearchProduct', ['id' => $product->id])
            ->assertSet('products', function ($products) {
                $this->assertCount(1, $products);
                $this->assertEquals(1, $products[0]['requested_quantity']); // Remains 1, not 2
                return true;
            })
            ->assertSet('feedbackType', 'info');
    }

    /** @test */
    public function serialized_product_search_selection_creates_row_with_zero_quantity(): void
    {
        $serialProduct = $this->createStockManagedProduct('Serialized Scanner Item', 'SSI01', 'SSI-BARCODE', true);
        $this->setGoodStock($serialProduct, 5, 5);

        Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('selectSearchProduct', ['id' => $serialProduct->id])
            ->assertSet('products', function ($products) {
                $this->assertCount(1, $products);
                $this->assertEquals(0, $products[0]['requested_quantity']);
                $this->assertEmpty($products[0]['serial_numbers']);
                return true;
            });
    }

    /** @test */
    public function row_serial_modal_adds_and_removes_serials_with_authoritative_revalidation(): void
    {
        $serialProduct = $this->createStockManagedProduct('Serial Modal Test Item', 'SMTI01', 'SMTI-BARCODE', true);
        $this->setGoodStock($serialProduct, 5, 5);

        $sn1 = ProductSerialNumber::create([
            'product_id' => $serialProduct->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'SN-MODAL-001',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        $sn2 = ProductSerialNumber::create([
            'product_id' => $serialProduct->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'SN-MODAL-002',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        $component = Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('selectSearchProduct', ['id' => $serialProduct->id])
            ->call('openSerialModal', 0)
            ->assertSet('showSerialModal', true)
            // Add raw unregistered serial -> rejected
            ->set('rowSerialInput', 'NON-EXISTENT-RAW-SN')
            ->call('addRowSerial')
            ->assertSet('rowSerialError', function ($err) {
                $this->assertNotNull($err);
                return true;
            })
            // Add valid registered serial 1
            ->set('rowSerialInput', 'SN-MODAL-001')
            ->call('addRowSerial')
            ->assertSet('rowSerialError', null)
            ->assertSet('products', function ($products) {
                $this->assertEquals(1, $products[0]['requested_quantity']);
                $this->assertCount(1, $products[0]['serial_numbers']);
                return true;
            })
            // Add valid registered serial 2
            ->set('rowSerialInput', 'SN-MODAL-002')
            ->call('addRowSerial')
            ->assertSet('products', function ($products) {
                $this->assertEquals(2, $products[0]['requested_quantity']);
                $this->assertCount(2, $products[0]['serial_numbers']);
                return true;
            })
            // Duplicate serial 1 -> rejected
            ->set('rowSerialInput', 'SN-MODAL-001')
            ->call('addRowSerial')
            ->assertSet('rowSerialError', 'Nomor seri sudah dipilih.')
            // Remove serial index 0
            ->call('removeRowSerial', 0)
            ->assertSet('products', function ($products) {
                $this->assertEquals(1, $products[0]['requested_quantity']);
                $this->assertCount(1, $products[0]['serial_numbers']);
                return true;
            })
            ->call('closeSerialModal')
            ->assertSet('showSerialModal', false)
            ->assertDispatched('restore-scanner-focus');
    }

    /** @test */
    public function blind_operator_search_and_serial_projections_expose_no_stock_or_provenance(): void
    {
        $product = $this->createStockManagedProduct('Blind Search Product', 'BSP01', 'BSP-BARCODE');
        $this->setGoodStock($product, 777, 888);

        $component = Livewire::actingAs($this->blindUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('openSearchModal')
            ->set('searchTerm', 'Blind Search')
            ->call('searchProducts');

        $results = $component->get('searchResults');
        $this->assertCount(1, $results);
        $this->assertArrayNotHasKey('stock_quantity', $results[0]);
        $this->assertArrayNotHasKey('quantity_tax', $results[0]);
        $this->assertArrayNotHasKey('quantity_non_tax', $results[0]);

        // Add row via search
        $component->call('selectSearchProduct', ['id' => $product->id]);
        $products = $component->get('products');
        $this->assertCount(1, $products);
        $this->assertArrayNotHasKey('stock', $products[0]);
        $this->assertArrayNotHasKey('quantity_tax', $products[0]);
        $this->assertArrayNotHasKey('quantity_non_tax', $products[0]);

        $html = $component->html();
        $this->assertStringNotContainsString('777', $html);
        $this->assertStringNotContainsString('888', $html);
    }

    /** @test */
    public function blind_operator_cannot_search_eligible_serials_at_origin(): void
    {
        $serialProduct = $this->createStockManagedProduct('Blind Serial Search Prod', 'BSSP01', 'BSSP-BARCODE', true);
        $this->setGoodStock($serialProduct, 5, 5);

        ProductSerialNumber::create([
            'product_id' => $serialProduct->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'SECRET-SN-999',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        $component = Livewire::actingAs($this->blindUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('selectSearchProduct', ['id' => $serialProduct->id])
            ->call('openSerialModal', 0)
            ->set('serialSearchTerm', 'SECRET')
            ->call('searchEligibleSerials')
            ->assertSet('serialSearchResults', []);

        // Assert blade does not render search serial input for blind user
        $component->assertDontSee('Cari Nomor Seri Tersedia di Lokasi Asal');
        $component->assertDontSee('SECRET-SN-999');
    }

    /** @test */
    public function serial_management_strictly_uses_locked_stock_condition_not_mutated_row_mode(): void
    {
        $serialProduct = $this->createStockManagedProduct('Condition Tamper Prod', 'CTP01', 'CTP-BARCODE', true);
        $this->setGoodStock($serialProduct, 5, 5);

        $brokenSerial = ProductSerialNumber::create([
            'product_id' => $serialProduct->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'SN-BROKEN-TAMPER',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => true,
        ]);

        $component = Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD, // Component condition is GOOD
            ])
            ->call('selectSearchProduct', ['id' => $serialProduct->id])
            // Client mutates row is_broken_mode to true
            ->set('products.0.is_broken_mode', true)
            ->call('openSerialModal', 0)
            ->set('rowSerialInput', 'SN-BROKEN-TAMPER')
            ->call('addRowSerial')
            // Must be rejected because locked stockCondition is GOOD, not broken
            ->assertSet('rowSerialError', 'Nomor seri tidak aktif atau tidak siap jual.')
            ->assertSet('products.0.serial_numbers', []);
    }

    /** @test */
    public function parent_form_locks_origin_and_stock_condition_properties(): void
    {
        $component = Livewire::actingAs($this->privilegedUser)
            ->test(\App\Livewire\Transfer\TransferStockForm::class);

        // Attempting to set locked properties throws Exception: Cannot update locked property
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot update locked property: [originLocation]');
        $component->set('originLocation', 999);
    }

    /**
     * Regression Coverage 1: Product from setting 6 with good stock at setting-1 origin appears in transfer product search under setting 1.
     * @test
     */
    public function product_from_different_setting_with_stock_at_active_tenant_origin_appears_in_search(): void
    {
        $otherSetting = Setting::factory()->create();
        $otherCategory = Category::firstOrCreate(
            ['category_code' => 'CAT06'],
            ['setting_id' => $otherSetting->id, 'category_name' => 'Setting 6 Category', 'created_by' => 1]
        );

        $product3215 = Product::create([
            'setting_id' => $otherSetting->id,
            'category_id' => $otherCategory->id,
            'product_name' => 'ADAPTOR ASUS 19V 3.42A CL VIVO 4,5 X 3.0',
            'product_code' => 'AD-ASUS-3215',
            'barcode' => '321500000001',
            'product_cost' => 50000,
            'product_price' => 75000,
            'serial_number_required' => false,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        // Stock at setting 1's origin location
        ProductStock::create([
            'product_id' => $product3215->id,
            'location_id' => $this->origin->id,
            'quantity' => 8,
            'quantity_tax' => 8,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('openSearchModal')
            ->set('searchTerm', 'ADAPTOR ASUS')
            ->call('searchProducts')
            ->assertSet('searchResults', function ($results) use ($product3215) {
                $this->assertCount(1, $results);
                $this->assertEquals($product3215->id, $results[0]['id']);
                $this->assertEquals('ADAPTOR ASUS 19V 3.42A CL VIVO 4,5 X 3.0', $results[0]['product_name']);
                return true;
            });
    }

    /**
     * Regression Coverage 2: Its exact product barcode resolves and mutates correctly.
     * @test
     */
    public function exact_barcode_resolves_and_mutates_for_product_from_different_setting(): void
    {
        $otherSetting = Setting::factory()->create();
        $otherCategory = Category::firstOrCreate(
            ['category_code' => 'CAT06B'],
            ['setting_id' => $otherSetting->id, 'category_name' => 'Setting 6 Category', 'created_by' => 1]
        );

        $product3215 = Product::create([
            'setting_id' => $otherSetting->id,
            'category_id' => $otherCategory->id,
            'product_name' => 'ADAPTOR ASUS 19V 3.42A CL VIVO 4,5 X 3.0',
            'product_code' => 'AD-ASUS-3215-EXACT',
            'barcode' => '321500000002',
            'product_cost' => 50000,
            'product_price' => 75000,
            'serial_number_required' => false,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product3215->id,
            'location_id' => $this->origin->id,
            'quantity' => 8,
            'quantity_tax' => 8,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('processScan', '321500000002')
            ->assertSet('products', function ($products) use ($product3215) {
                $this->assertCount(1, $products);
                $this->assertEquals($product3215->id, $products[0]['id']);
                $this->assertEquals(1, $products[0]['requested_quantity']);
                return true;
            })
            ->assertSet('feedbackType', 'success');
    }

    /**
     * Regression Coverage 3: Its conversion barcode resolves correctly.
     * @test
     */
    public function conversion_barcode_resolves_for_product_from_different_setting(): void
    {
        $otherSetting = Setting::factory()->create();
        $otherCategory = Category::firstOrCreate(
            ['category_code' => 'CAT06C'],
            ['setting_id' => $otherSetting->id, 'category_name' => 'Setting 6 Category', 'created_by' => 1]
        );

        $product3215 = Product::create([
            'setting_id' => $otherSetting->id,
            'category_id' => $otherCategory->id,
            'product_name' => 'ADAPTOR ASUS 19V 3.42A CL VIVO 4,5 X 3.0',
            'product_code' => 'AD-ASUS-3215-CONV',
            'barcode' => '321500000003',
            'product_cost' => 50000,
            'product_price' => 75000,
            'serial_number_required' => false,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product3215->id,
            'location_id' => $this->origin->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $unit = Unit::create([
            'name' => 'Box of 5',
            'short_name' => 'BX5',
            'operator' => '*',
            'operation_value' => 5,
            'is_active' => true,
        ]);

        ProductUnitConversion::create([
            'product_id' => $product3215->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'conversion_factor' => 5,
            'barcode' => 'CONV-3215-BOX5',
        ]);

        Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('processScan', 'CONV-3215-BOX5')
            ->assertSet('products', function ($products) use ($product3215) {
                $this->assertCount(1, $products);
                $this->assertEquals($product3215->id, $products[0]['id']);
                $this->assertEquals(5, $products[0]['requested_quantity']);
                return true;
            })
            ->assertSet('feedbackType', 'success');
    }

    /**
     * Regression Coverage 4: Its eligible serial at the setting-1 origin resolves and can be selected.
     * @test
     */
    public function eligible_serial_at_origin_resolves_and_can_be_selected_for_product_from_different_setting(): void
    {
        $otherSetting = Setting::factory()->create();
        $otherCategory = Category::firstOrCreate(
            ['category_code' => 'CAT06D'],
            ['setting_id' => $otherSetting->id, 'category_name' => 'Setting 6 Category', 'created_by' => 1]
        );

        $product3215 = Product::create([
            'setting_id' => $otherSetting->id,
            'category_id' => $otherCategory->id,
            'product_name' => 'ADAPTOR ASUS SERIALIZED',
            'product_code' => 'AD-ASUS-3215-SER',
            'barcode' => '321500000004',
            'product_cost' => 50000,
            'product_price' => 75000,
            'serial_number_required' => true,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product3215->id,
            'location_id' => $this->origin->id,
            'quantity' => 3,
            'quantity_tax' => 3,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $product3215->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'SN-3215-ASUS-001',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        // 1. Scan exact serial
        Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('processScan', 'SN-3215-ASUS-001')
            ->assertSet('products', function ($products) use ($product3215, $serial) {
                $this->assertCount(1, $products);
                $this->assertEquals($product3215->id, $products[0]['id']);
                $this->assertEquals(1, $products[0]['requested_quantity']);
                $this->assertCount(1, $products[0]['serial_numbers']);
                $this->assertEquals($serial->id, $products[0]['serial_numbers'][0]['id']);
                return true;
            });

        // 2. Open serial modal and select via search
        Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('selectSearchProduct', ['id' => $product3215->id])
            ->call('openSerialModal', 0)
            ->set('serialSearchTerm', '3215')
            ->call('searchEligibleSerials')
            ->assertSet('serialSearchResults', function ($results) use ($serial) {
                $this->assertCount(1, $results);
                $this->assertEquals($serial->id, $results[0]['id']);
                return true;
            })
            ->call('selectEligibleSerial', $serial->id)
            ->assertSet('products.0.serial_numbers', function ($serials) use ($serial) {
                $this->assertCount(1, $serials);
                $this->assertEquals($serial->id, $serials[0]['id']);
                return true;
            });
    }

    /**
     * Regression Coverage 5: The same product is rejected when it has no eligible stock or serial at the selected origin.
     * @test
     */
    public function product_from_different_setting_is_rejected_when_no_stock_at_selected_origin(): void
    {
        $otherSetting = Setting::factory()->create();
        $otherCategory = Category::firstOrCreate(
            ['category_code' => 'CAT06E'],
            ['setting_id' => $otherSetting->id, 'category_name' => 'Setting 6 Category', 'created_by' => 1]
        );

        $productZeroStock = Product::create([
            'setting_id' => $otherSetting->id,
            'category_id' => $otherCategory->id,
            'product_name' => 'ADAPTOR NO STOCK AT ORIGIN',
            'product_code' => 'AD-ASUS-NO-STOCK',
            'barcode' => '321500000005',
            'product_cost' => 50000,
            'product_price' => 75000,
            'serial_number_required' => false,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        // Stock at origin is 0
        ProductStock::create([
            'product_id' => $productZeroStock->id,
            'location_id' => $this->origin->id,
            'quantity' => 0,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Search must not return it
        Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('openSearchModal')
            ->set('searchTerm', 'ADAPTOR NO STOCK')
            ->call('searchProducts')
            ->assertSet('searchResults', []);

        // Direct scan must report not found / insufficient stock
        Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('processScan', '321500000005')
            ->assertSet('products', [])
            ->assertSet('feedbackType', 'danger');
    }

    /**
     * Regression Coverage 6: Stock or serials at another setting's location are not exposed or used.
     * @test
     */
    public function stock_or_serials_at_another_setting_location_are_not_exposed_or_used(): void
    {
        $otherSetting = Setting::factory()->create();
        $otherLocation = Location::factory()->create(['setting_id' => $otherSetting->id]);

        $productOtherLoc = Product::create([
            'setting_id' => $otherSetting->id,
            'product_name' => 'ADAPTOR AT OTHER SETTING LOCATION',
            'product_code' => 'AD-ASUS-OTHER-LOC',
            'barcode' => '321500000006',
            'product_cost' => 50000,
            'product_price' => 75000,
            'serial_number_required' => true,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        // Stock exists at OTHER location, but 0 at setting 1's origin
        ProductStock::create([
            'product_id' => $productOtherLoc->id,
            'location_id' => $otherLocation->id,
            'quantity' => 50,
            'quantity_tax' => 50,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $otherSerial = ProductSerialNumber::create([
            'product_id' => $productOtherLoc->id,
            'location_id' => $otherLocation->id,
            'serial_number' => 'SN-AT-OTHER-LOCATION',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        // Search under setting 1 must not find product
        Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('openSearchModal')
            ->set('searchTerm', 'OTHER SETTING LOCATION')
            ->call('searchProducts')
            ->assertSet('searchResults', []);

        // Scanning otherSerial under setting 1 origin must fail
        Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('processScan', 'SN-AT-OTHER-LOCATION')
            ->assertSet('products', [])
            ->assertSet('feedbackType', 'danger');
    }

    /**
     * Regression Coverage 7: Search selection and crafted direct Livewire calls revalidate the selected origin.
     * @test
     */
    public function search_selection_and_direct_livewire_calls_revalidate_selected_origin(): void
    {
        $otherSetting = Setting::factory()->create();
        $otherOrigin = Location::factory()->create(['setting_id' => $otherSetting->id]);

        $product = Product::create([
            'setting_id' => $otherSetting->id,
            'product_name' => 'UNAUTHORIZED ORIGIN ATTEMPT',
            'product_code' => 'UOA-01',
            'barcode' => '321500000007',
            'product_cost' => 50000,
            'product_price' => 75000,
            'serial_number_required' => false,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $otherOrigin->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Attempt direct Livewire call when component was mounted with otherOrigin (not owned by setting 1)
        Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $otherOrigin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('selectSearchProduct', ['id' => $product->id])
            ->assertSet('products', []);

        // Attempt backward-compatible productSelected with forged unowned origin
        Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $otherOrigin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('productSelected', ['id' => $product->id])
            ->assertSet('products', []);
    }

    /**
     * Regression Coverage 8: Blind-user visibility guarantees remain intact for cross-setting products.
     * @test
     */
    public function blind_user_visibility_guarantees_remain_intact_for_cross_setting_products(): void
    {
        $otherSetting = Setting::factory()->create();
        $product3215 = Product::create([
            'setting_id' => $otherSetting->id,
            'product_name' => 'BLIND VISIBILITY TEST ADAPTOR',
            'product_code' => 'BLIND-AD-3215',
            'barcode' => '321500000008',
            'product_cost' => 50000,
            'product_price' => 75000,
            'serial_number_required' => false,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product3215->id,
            'location_id' => $this->origin->id,
            'quantity' => 888,
            'quantity_tax' => 555,
            'quantity_non_tax' => 333,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $component = Livewire::actingAs($this->blindUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('openSearchModal')
            ->set('searchTerm', 'BLIND VISIBILITY')
            ->call('searchProducts');

        $results = $component->get('searchResults');
        $this->assertCount(1, $results);
        $this->assertArrayNotHasKey('stock_quantity', $results[0]);
        $this->assertArrayNotHasKey('quantity_tax', $results[0]);
        $this->assertArrayNotHasKey('quantity_non_tax', $results[0]);

        $component->call('selectSearchProduct', ['id' => $product3215->id]);
        $products = $component->get('products');
        $this->assertCount(1, $products);
        $this->assertArrayNotHasKey('stock', $products[0]);
        $this->assertArrayNotHasKey('quantity_tax', $products[0]);
        $this->assertArrayNotHasKey('quantity_non_tax', $products[0]);

        $html = $component->html();
        $this->assertStringNotContainsString('888', $html);
        $this->assertStringNotContainsString('555', $html);
        $this->assertStringNotContainsString('333', $html);
    }

    /**
     * Regression Coverage 9: Cross-setting product draft creation, submission, and foreign location rejection.
     * @test
     */
    public function cross_setting_product_draft_saves_submits_and_rejects_foreign_location_stock(): void
    {
        $otherSetting = Setting::factory()->create();
        $otherLocation = Location::factory()->create(['setting_id' => $otherSetting->id]);

        $crossProduct = Product::create([
            'setting_id' => $otherSetting->id,
            'product_name' => 'CROSS SETTING DRAFT PROD',
            'product_code' => 'CSDP-01',
            'barcode' => '321500000009',
            'product_cost' => 50000,
            'product_price' => 75000,
            'serial_number_required' => false,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        // Stock at setting 1 origin location: 10 units (tax 6, non_tax 4)
        ProductStock::create([
            'product_id' => $crossProduct->id,
            'location_id' => $this->origin->id,
            'quantity' => 10,
            'quantity_tax' => 6,
            'quantity_non_tax' => 4,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $rowPayload = [
            'id' => $crossProduct->id,
            'product_name' => $crossProduct->product_name,
            'product_code' => $crossProduct->product_code,
            'requested_quantity' => 3,
            'quantity_tax' => 0,
            'quantity_non_tax' => 3,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'stock' => [
                'quantity_tax' => 6,
                'quantity_non_tax' => 4,
                'broken_quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
            ],
            'serial_number_required' => false,
            'serial_numbers' => [],
            'is_broken_mode' => false,
        ];

        // 1. Save Draft with cross-setting product
        $form = Livewire::actingAs($this->privilegedUser)
            ->test(\App\Livewire\Transfer\TransferStockForm::class)
            ->call('onOriginLocationSelected', ['id' => $this->origin->id])
            ->call('onDestinationLocationSelected', ['id' => $this->destination->id])
            ->call('selectStockCondition', Transfer::CONDITION_GOOD)
            ->set('rows', [$rowPayload])
            ->call('saveDraft')
            ->assertHasNoErrors();

        $transfer = Transfer::where('origin_location_id', $this->origin->id)->latest()->first();
        $this->assertNotNull($transfer);
        $this->assertEquals(Transfer::STATUS_DRAFT, $transfer->status);

        $transferProduct = $transfer->products()->where('product_id', $crossProduct->id)->first();
        $this->assertNotNull($transferProduct);
        $this->assertEquals(3, $transferProduct->quantity);

        // 2. Submit for Approval
        $submitForm = Livewire::actingAs($this->privilegedUser)
            ->test(\App\Livewire\Transfer\TransferStockForm::class, ['transfer' => $transfer])
            ->call('submitForApproval')
            ->assertHasNoErrors();

        $this->assertEquals(Transfer::STATUS_PENDING, $transfer->fresh()->status);

        // 3. Reject when product only has stock at foreign location (setting 6 location)
        $foreignStockProduct = Product::create([
            'setting_id' => $otherSetting->id,
            'product_name' => 'FOREIGN LOCATION ONLY PROD',
            'product_code' => 'FLOP-01',
            'barcode' => '321500000010',
            'product_cost' => 50000,
            'product_price' => 75000,
            'serial_number_required' => false,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        // Stock ONLY at otherSetting's location, none at setting 1 origin
        ProductStock::create([
            'product_id' => $foreignStockProduct->id,
            'location_id' => $otherLocation->id,
            'quantity' => 20,
            'quantity_tax' => 20,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $foreignRowPayload = [
            'id' => $foreignStockProduct->id,
            'product_name' => $foreignStockProduct->product_name,
            'product_code' => $foreignStockProduct->product_code,
            'requested_quantity' => 2,
            'quantity_tax' => 0,
            'quantity_non_tax' => 2,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'serial_number_required' => false,
            'serial_numbers' => [],
            'is_broken_mode' => false,
        ];

        Livewire::actingAs($this->privilegedUser)
            ->test(\App\Livewire\Transfer\TransferStockForm::class)
            ->call('onOriginLocationSelected', ['id' => $this->origin->id])
            ->call('onDestinationLocationSelected', ['id' => $this->destination->id])
            ->call('selectStockCondition', Transfer::CONDITION_GOOD)
            ->set('rows', [$foreignRowPayload])
            ->call('saveDraft');

        $this->assertDatabaseMissing('transfer_products', [
            'product_id' => $foreignStockProduct->id,
        ]);
    }

    /**
     * Regression Coverage 10: Backward-compatible serialNumberSelected enforces locked stockCondition and reloads product.
     * @test
     */
    public function backward_compatible_serial_selection_enforces_locked_condition_and_reloads_product(): void
    {
        $otherSetting = Setting::factory()->create();
        $serialProduct = Product::create([
            'setting_id' => $otherSetting->id,
            'product_name' => 'SERIAL CONDITION ENFORCEMENT PROD',
            'product_code' => 'SCEP-01',
            'barcode' => '321500000011',
            'product_cost' => 50000,
            'product_price' => 75000,
            'serial_number_required' => true,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $serialProduct->id,
            'location_id' => $this->origin->id,
            'quantity' => 5,
            'quantity_tax' => 5,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $brokenSerial = ProductSerialNumber::create([
            'product_id' => $serialProduct->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'SN-BC-BROKEN-01',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => true,
        ]);

        // Component condition is GOOD. Client row tampers is_broken_mode to true.
        $component = Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('selectSearchProduct', ['id' => $serialProduct->id])
            ->set('products.0.is_broken_mode', true)
            ->call('serialNumberSelected', [
                'productCompositeKey' => 0,
                'serialNumber' => ['id' => $brokenSerial->id],
            ]);

        // Must be rejected because locked stockCondition is GOOD
        $component->assertSet('serialNumberErrors.0', 'Nomor seri tidak aktif atau tidak siap jual.')
            ->assertSet('products.0.serial_numbers', []);
    }

    /**
     * Regression Coverage 11: Repeated product-barcode and conversion scans increment authoritative state.
     * @test
     */
    public function repeated_product_and_conversion_scans_increment_authoritative_state(): void
    {
        $product509 = $this->createStockManagedProduct('ADAPTOR ACER 19V 2.1A (TO)', 'AD-ACER-509', '2004938809917');
        $this->setGoodStock($product509, 10, 0);

        $unit = Unit::create([
            'name' => 'Pair',
            'short_name' => 'PR',
            'operator' => '*',
            'operation_value' => 2,
            'is_active' => true,
        ]);

        ProductUnitConversion::create([
            'product_id' => $product509->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'conversion_factor' => 2,
            'barcode' => 'CONV-ACER-2X',
        ]);

        $component = Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ]);

        // 1st scan -> quantity 1
        $component->call('processScan', '2004938809917')
            ->assertSet('products.0.requested_quantity', 1);

        // 2nd scan -> quantity 2
        $component->call('processScan', '2004938809917')
            ->assertSet('products.0.requested_quantity', 2);

        // 3rd scan -> quantity 3
        $component->call('processScan', '2004938809917')
            ->assertSet('products.0.requested_quantity', 3);

        // Conversion scan (+2) -> quantity 5
        $component->call('processScan', 'CONV-ACER-2X')
            ->assertSet('products.0.requested_quantity', 5);

        // Assert stable DOM selector and data attributes rendered in Blade
        $html = $component->html();
        $this->assertStringContainsString('data-quantity-input="transfer"', $html);
        $this->assertStringContainsString('data-row-index="0"', $html);
        $this->assertStringContainsString('syncVisibleQuantities', $html);
    }

    /**
     * Regression Coverage 12: Ambiguity candidate selection increments authoritative state and triggers transfer-ambiguity-closed sync hook.
     * @test
     */
    public function ambiguity_selection_increments_authoritative_state_and_triggers_sync_hook(): void
    {
        $productA = $this->createStockManagedProduct('AMBIGUOUS PRODUCT A', 'AMB-A', 'AMBIG-SHARED-CODE');
        $this->setGoodStock($productA, 10, 0);

        $productB = $this->createStockManagedProduct('AMBIGUOUS PRODUCT B', 'AMB-B', 'PRIMARY-B');
        $this->setGoodStock($productB, 10, 0);

        $unit = Unit::create(['name' => 'Box 2', 'short_name' => 'BX2', 'operator' => '*', 'operation_value' => 2, 'is_active' => true]);
        ProductUnitConversion::create([
            'product_id' => $productB->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'conversion_factor' => 2,
            'barcode' => 'AMBIG-SHARED-CODE',
        ]);

        $component = Livewire::actingAs($this->privilegedUser)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => $this->destination->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ]);

        // Scan ambiguous code -> opens modal
        $component->call('processScan', 'AMBIG-SHARED-CODE')
            ->assertSet('showAmbiguityModal', true)
            ->assertDispatched('transfer-ambiguity-opened');

        // Select first candidate -> dispatches transfer-ambiguity-closed and increments product A to 1
        $component->call('selectAmbiguousCandidate', 0)
            ->assertDispatched('transfer-ambiguity-closed')
            ->assertSet('products.0.id', $productA->id)
            ->assertSet('products.0.requested_quantity', 1);

        // Scan ambiguous code again
        $component->call('processScan', 'AMBIG-SHARED-CODE')
            ->assertSet('showAmbiguityModal', true);

        // Select first candidate again -> dispatches transfer-ambiguity-closed and increments product A to 2
        $component->call('selectAmbiguousCandidate', 0)
            ->assertDispatched('transfer-ambiguity-closed')
            ->assertSet('products.0.id', $productA->id)
            ->assertSet('products.0.requested_quantity', 2);

        $html = $component->html();
        $this->assertStringContainsString('transfer-ambiguity-closed', $html);
        $this->assertStringContainsString('syncVisibleQuantities', $html);
    }
}




