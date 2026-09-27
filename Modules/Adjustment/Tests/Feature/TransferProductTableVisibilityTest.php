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
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Task 5.1: Livewire-level payload leakage coverage for the transfer
 * product table using distinctive sentinel stock quantities. A blind user's
 * public component state/snapshot must never contain the sentinel bucket
 * values or a "stock" key; a privileged user must retain them.
 */
class TransferProductTableVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Location $origin;
    private Product $product;

    // Distinctive sentinel quantities chosen to be detectable via string
    // search and unlikely to occur by coincidence in unrelated output.
    private const SENTINEL_TAX = 8811;
    private const SENTINEL_NON_TAX = 8822;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create();
        $this->origin = Location::factory()->create(['setting_id' => $this->setting->id]);

        $category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'CAT-' . uniqid(),
            'category_name' => 'Category',
            'created_by' => 1,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Sentinel Product',
            'product_code' => 'P-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->origin->id,
            'quantity' => self::SENTINEL_TAX + self::SENTINEL_NON_TAX,
            'quantity_tax' => self::SENTINEL_TAX,
            'quantity_non_tax' => self::SENTINEL_NON_TAX,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        session(['setting_id' => $this->setting->id]);

        Permission::firstOrCreate(['name' => TransferStockVisibility::PERMISSION, 'guard_name' => 'web']);

        // TransferProductTable enforces stockTransfers.create/edit surface
        // authorization on its own (it is an independently callable
        // Livewire endpoint, not secured only by the route/parent form), so
        // every acting user in this suite needs it regardless of whether
        // they also hold TransferStockVisibility::PERMISSION.
        Permission::firstOrCreate(['name' => 'stockTransfers.create', 'guard_name' => 'web']);
    }

    private function actingUser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('stockTransfers.create');

        return $user;
    }

    private function scanPayload(int $multiplier = 1): array
    {
        $payload = [
            'id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'serial_number_required' => false,
            'is_broken_mode' => false,
        ];

        if ($multiplier > 1) {
            $unit = \Modules\Setting\Entities\Unit::firstOrCreate(
                ['name' => 'Box ' . $multiplier],
                ['short_name' => 'BX' . $multiplier, 'operator' => '*', 'operation_value' => $multiplier, 'is_active' => true]
            );
            $conversion = \Modules\Product\Entities\ProductUnitConversion::firstOrCreate(
                ['product_id' => $this->product->id, 'conversion_factor' => $multiplier],
                ['unit_id' => $unit->id, 'base_unit_id' => $unit->id]
            );
            $payload['conversion_id'] = $conversion->id;
        }

        return $payload;
    }

    /** @test */
    public function blind_user_never_receives_sentinel_stock_in_component_state()
    {
        $user = $this->actingUser();

        $component = Livewire::actingAs($user)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => null,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('productSelected', $this->scanPayload(5));

        $html = $component->html();
        $serialized = json_encode($component->get('products'));

        $this->assertStringNotContainsString((string) self::SENTINEL_TAX, $html);
        $this->assertStringNotContainsString((string) self::SENTINEL_NON_TAX, $html);
        $this->assertStringNotContainsString((string) self::SENTINEL_TAX, $serialized);
        $this->assertStringNotContainsString((string) self::SENTINEL_NON_TAX, $serialized);
        $this->assertStringNotContainsString('"stock"', $serialized);
        $this->assertStringNotContainsString('quantity_tax', $serialized);
        $this->assertStringNotContainsString('quantity_non_tax', $serialized);

        // operator intent is retained
        $this->assertSame(5, $component->get('products')[0]['requested_quantity']);
    }

    /** @test */
    public function privileged_user_retains_full_stock_and_allocation_state()
    {
        $user = $this->actingUser();
        $user->givePermissionTo(TransferStockVisibility::PERMISSION);

        $component = Livewire::actingAs($user)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => null,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('productSelected', $this->scanPayload(5));

        $serialized = json_encode($component->get('products'));

        $this->assertStringContainsString((string) self::SENTINEL_TAX, $serialized);
        $this->assertStringContainsString((string) self::SENTINEL_NON_TAX, $serialized);
        $this->assertArrayHasKey('stock', $component->get('products')[0]);
    }

    /** @test */
    public function super_admin_retains_full_stock_and_allocation_state()
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        $component = Livewire::actingAs($user)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => null,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('productSelected', $this->scanPayload(5));

        $serialized = json_encode($component->get('products'));

        $this->assertStringContainsString((string) self::SENTINEL_TAX, $serialized);
        $this->assertArrayHasKey('stock', $component->get('products')[0]);
    }

    /** @test */
    public function blind_insufficient_stock_message_is_neutral()
    {
        $user = $this->actingUser();

        $component = Livewire::actingAs($user)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => null,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('productSelected', $this->scanPayload(1))
            // set requested quantity far exceeding sentinel stock
            ->set('products.0.requested_quantity', 999999);

        $errors = $component->get('tableValidationErrors');
        $message = collect($errors)->first();

        $this->assertNotEmpty($errors);
        $this->assertStringNotContainsString((string) self::SENTINEL_TAX, $message);
        $this->assertStringNotContainsString((string) self::SENTINEL_NON_TAX, $message);
    }

    /** @test */
    public function view_system_stock_alone_cannot_drive_the_table_without_create_or_edit_authority()
    {
        // Holding only stockTransfers.view-system-stock -- without transfer
        // create or edit surface authority -- must not be enough to query
        // stock through this independently callable Livewire endpoint. The
        // permission governs *what* is shown for an authorized origin, not
        // *whether* this table may be used at all.
        $user = User::factory()->create();
        $user->givePermissionTo(TransferStockVisibility::PERMISSION);

        $component = Livewire::actingAs($user)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => null,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('productSelected', $this->scanPayload(5));

        $this->assertSame([], $component->get('products'));

        $serialized = json_encode($component->get('products'));
        $this->assertStringNotContainsString((string) self::SENTINEL_TAX, $serialized);
        $this->assertStringNotContainsString((string) self::SENTINEL_NON_TAX, $serialized);
    }

    /** @test */
    public function search_product_dispatches_focus_restoration_events_on_scan_and_selection()
    {
        $user = $this->actingUser();

        // Successful scan dispatches restore-scanner-focus
        Livewire::actingAs($user)
            ->test(\App\Livewire\Transfer\SearchProduct::class, [
                'locationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('scanBarcode', 'NON-EXISTENT-CODE')
            ->assertDispatched('select-scan-input');

        // Text selection dispatches restore-scanner-focus
        Livewire::actingAs($user)
            ->test(\App\Livewire\Transfer\SearchProduct::class, [
                'locationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('selectProduct', $this->product)
            ->assertDispatched('restore-scanner-focus');
    }

    /** @test */
    public function serial_derived_quantity_and_duplicate_handling_are_enforced()
    {
        $user = $this->actingUser();

        $serialProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Serialized Product',
            'product_code' => 'SP-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => true,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $serialProduct->id,
            'location_id' => $this->origin->id,
            'quantity' => 10,
            'quantity_tax' => 5,
            'quantity_non_tax' => 5,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $sn1 = ProductSerialNumber::create([
            'product_id' => $serialProduct->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'SN-SEQ-001',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        $sn2 = ProductSerialNumber::create([
            'product_id' => $serialProduct->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'SN-SEQ-002',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        $component = Livewire::actingAs($user)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => null,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('productSelected', [
                'id' => $serialProduct->id,
                'product_name' => $serialProduct->product_name,
                'product_code' => $serialProduct->product_code,
                'serial_number_required' => true,
                'is_broken_mode' => false,
            ]);

        // Initially requested_quantity is 1 from scanMultiplier default
        // Now select serial 1 -> derives quantity 1
        $component->call('serialNumberSelected', [
            'productCompositeKey' => 0,
            'serialNumber' => ['id' => $sn1->id],
        ]);
        $this->assertEquals(1, $component->get('products')[0]['requested_quantity']);

        // Select serial 2 -> derives quantity 2
        $component->call('serialNumberSelected', [
            'productCompositeKey' => 0,
            'serialNumber' => ['id' => $sn2->id],
        ]);
        $this->assertEquals(2, $component->get('products')[0]['requested_quantity']);

        // Duplicate selection of serial 1 is rejected and quantity remains 2
        $component->call('serialNumberSelected', [
            'productCompositeKey' => 0,
            'serialNumber' => ['id' => $sn1->id],
        ]);
        $this->assertEquals(2, $component->get('products')[0]['requested_quantity']);
        $this->assertEquals('Nomor seri sudah dipilih.', $component->get('serialNumberErrors')[0]);
    }

    /** @test */
    public function sequential_scans_accumulate_requested_quantity_for_non_serialized_product()
    {
        $user = $this->actingUser();

        $component = Livewire::actingAs($user)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => null,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('productSelected', $this->scanPayload(2))
            ->call('productSelected', $this->scanPayload(3));

        $products = $component->get('products');
        $this->assertCount(1, $products);
        $this->assertEquals(5, $products[0]['requested_quantity']);
    }

    /** @test */
    public function repeated_delivery_of_same_operation_token_applies_row_effect_at_most_once()
    {
        $user = $this->actingUser();

        $token = 'txscan-token-12345';
        $payload = array_merge($this->scanPayload(2), [
            'operation_token' => $token,
        ]);

        $component = Livewire::actingAs($user)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => null,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('productSelected', $payload)
            // Deliver identical operation token again
            ->call('productSelected', $payload);

        $products = $component->get('products');
        $this->assertCount(1, $products);
        // Remains 2, not 4
        $this->assertEquals(2, $products[0]['requested_quantity']);

        // A subsequent scan with a new operation token applies normally
        $component->call('productSelected', array_merge($this->scanPayload(3), [
            'operation_token' => 'txscan-token-67890',
        ]));

        $products = $component->get('products');
        $this->assertEquals(5, $products[0]['requested_quantity']);
    }

    /** @test */
    public function repeated_delivery_of_same_operation_token_for_serial_selection_applies_at_most_once()
    {
        $user = $this->actingUser();

        $serialProduct = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->product->category_id,
            'product_name' => 'Serial Deduplication Product',
            'product_code' => 'P-SN-DEDUP-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => true,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $serialProduct->id,
            'location_id' => $this->origin->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $sn = ProductSerialNumber::create([
            'product_id' => $serialProduct->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'SN-TOKEN-001',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        $token = 'sn-token-abc';
        $component = Livewire::actingAs($user)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => null,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('productSelected', [
                'id' => $serialProduct->id,
                'is_broken_mode' => false,
            ])
            ->call('serialNumberSelected', [
                'productCompositeKey' => 0,
                'serialNumber' => ['id' => $sn->id],
                'operation_token' => $token,
            ]);

        $this->assertEquals(1, $component->get('products')[0]['requested_quantity']);
        $this->assertCount(1, $component->get('products')[0]['serial_numbers']);

        // Deliver identical operation token again
        $component->call('serialNumberSelected', [
            'productCompositeKey' => 0,
            'serialNumber' => ['id' => $sn->id],
            'operation_token' => $token,
        ]);

        $this->assertEquals(1, $component->get('products')[0]['requested_quantity']);
        $this->assertCount(1, $component->get('products')[0]['serial_numbers']);
    }

    /** @test */
    public function mixed_accepted_and_rejected_scans_in_sequence_leave_valid_rows_intact()
    {
        $user = $this->actingUser();

        // Product with limited stock = 3
        $limitedProduct = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->product->category_id,
            'product_name' => 'Limited Stock Product',
            'product_code' => 'P-LTD-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $limitedProduct->id,
            'location_id' => $this->origin->id,
            'quantity' => 3,
            'quantity_tax' => 3,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $ltdUnit = \Modules\Setting\Entities\Unit::firstOrCreate(
            ['name' => 'Pair 2'],
            ['short_name' => 'PR2', 'operator' => '*', 'operation_value' => 2, 'is_active' => true]
        );
        $ltdConversion = \Modules\Product\Entities\ProductUnitConversion::firstOrCreate(
            ['product_id' => $limitedProduct->id, 'conversion_factor' => 2],
            ['unit_id' => $ltdUnit->id, 'base_unit_id' => $ltdUnit->id]
        );

        $component = Livewire::actingAs($user)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => null,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            // 1. Valid scan: adds sentinel product
            ->call('productSelected', $this->scanPayload(2))
            // 2. Valid scan: adds limited product (qty 2 via conversion)
            ->call('productSelected', [
                'id' => $limitedProduct->id,
                'conversion_id' => $ltdConversion->id,
            ])
            // 3. Rejected scan: limited product attempts +2 more, exceeds available 3 (needs 4)
            ->call('productSelected', [
                'id' => $limitedProduct->id,
                'conversion_id' => $ltdConversion->id,
            ])
            // 4. Valid scan: sentinel product +1 more
            ->call('productSelected', $this->scanPayload(1));

        $products = $component->get('products');
        $this->assertCount(2, $products);

        // Sentinel product accumulated 2 + 1 = 3
        $this->assertEquals(3, $products[0]['requested_quantity']);
        // Limited product remained at 2 (rejected scan didn't corrupt state)
        $this->assertEquals(2, $products[1]['requested_quantity']);
    }

    /** @test */
    public function queued_item_becoming_ineligible_due_to_stock_change_is_rejected_without_affecting_prior_rows()
    {
        $user = $this->actingUser();

        $depletedProduct = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->product->category_id,
            'product_name' => 'Depleting Product',
            'product_code' => 'P-DEP-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);

        $depStock = ProductStock::create([
            'product_id' => $depletedProduct->id,
            'location_id' => $this->origin->id,
            'quantity' => 1,
            'quantity_tax' => 1,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $component = Livewire::actingAs($user)
            ->test(TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'destinationLocationId' => null,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            // Prior accepted scan
            ->call('productSelected', $this->scanPayload(1));

        // External mutation: stock depleted to 0 before the next queued scan arrives
        $depStock->update([
            'quantity' => 0,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
        ]);

        // Attempting to select depleted product is rejected
        $component->call('productSelected', [
            'id' => $depletedProduct->id,
            'is_broken_mode' => false,
            'scan_quantity_multiplier' => 1,
        ]);

        // Only the prior accepted row remains
        $products = $component->get('products');
        $this->assertCount(1, $products);
        $this->assertEquals($this->product->id, $products[0]['id']);
        $this->assertEquals(1, $products[0]['requested_quantity']);
    }
}
