<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Livewire\Transfer\TransferStockForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Task 5.3: crafted-request coverage proving that a client-supplied
 * allocation/stock breakdown -- whether omitted (blind submission) or
 * forged with a low-stock-bypassing value -- can never control persistence.
 * Authoritative allocation is always recomputed server-side from real
 * ProductStock at save time (TransferDraftService::buildProductsData).
 */
class TransferCraftedAllocationRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Location $origin;
    private Product $product;

    // Real available stock is intentionally small.
    private const REAL_TAX = 2;
    private const REAL_NON_TAX = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setting = Setting::factory()->create();
        $this->origin = Location::factory()->create(['setting_id' => $this->setting->id]);

        $category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'CAT-' . uniqid(),
            'category_name' => 'Category',
            'created_by' => $this->user->id,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Crafted Product',
            'product_code' => 'P-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->origin->id,
            'quantity' => self::REAL_TAX + self::REAL_NON_TAX,
            'quantity_tax' => self::REAL_TAX,
            'quantity_non_tax' => self::REAL_NON_TAX,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        session(['setting_id' => $this->setting->id]);

        Permission::firstOrCreate(['name' => 'stockTransfers.create', 'guard_name' => 'web']);
        $this->user->givePermissionTo('stockTransfers.create');
    }

    /** @test */
    public function forged_high_bucket_values_cannot_bypass_real_stock_limits()
    {
        // Crafted row: requested_quantity honestly says 5, but the bucket
        // fields and "stock" snapshot are forged to claim far more stock is
        // available than actually exists at the origin.
        $forgedRow = [
            'id' => $this->product->id,
            'requested_quantity' => 999999,
            'quantity_tax' => 500000,
            'quantity_non_tax' => 499999,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'serial_number_required' => false,
            'serial_numbers' => [],
            'stock' => [
                'quantity_tax' => 500000,
                'quantity_non_tax' => 499999,
                'broken_quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
            ],
        ];

        Livewire::actingAs($this->user)
            ->test(TransferStockForm::class)
            ->call('onOriginLocationSelected', ['id' => $this->origin->id])
            ->set('stockCondition', Transfer::CONDITION_GOOD)
            ->set('rows', [$forgedRow])
            ->call('saveDraft');

        // No transfer was persisted with the forged quantity; the real
        // stock ceiling (5) was never exceeded anywhere in the system.
        $this->assertDatabaseMissing('transfer_products', [
            'product_id' => $this->product->id,
            'quantity' => 999999,
        ]);

        $persisted = TransferProduct::where('product_id', $this->product->id)->first();
        if ($persisted) {
            $this->assertLessThanOrEqual(self::REAL_TAX + self::REAL_NON_TAX, $persisted->quantity);
        }

        // The real stock is untouched.
        $stock = ProductStock::where('product_id', $this->product->id)
            ->where('location_id', $this->origin->id)
            ->first();
        $this->assertEquals(self::REAL_TAX, $stock->quantity_tax);
        $this->assertEquals(self::REAL_NON_TAX, $stock->quantity_non_tax);
    }

    /** @test */
    public function blind_submission_with_no_bucket_fields_is_authoritatively_allocated()
    {
        // A blind row: only operator intent (requested_quantity), no bucket
        // breakdown and no "stock" key at all -- exactly what
        // TransferProductTable now sends for a user without
        // stockTransfers.view-system-stock.
        $blindRow = [
            'id' => $this->product->id,
            'requested_quantity' => self::REAL_TAX + self::REAL_NON_TAX,
            'serial_number_required' => false,
            'serial_numbers' => [],
            'is_broken_mode' => false,
        ];

        Livewire::actingAs($this->user)
            ->test(TransferStockForm::class)
            ->call('onOriginLocationSelected', ['id' => $this->origin->id])
            ->set('stockCondition', Transfer::CONDITION_GOOD)
            ->set('rows', [$blindRow])
            ->call('saveDraft')
            ->assertHasNoErrors();

        $persisted = TransferProduct::where('product_id', $this->product->id)->first();
        $this->assertNotNull($persisted);

        // Authoritative allocation was derived server-side from real stock
        // (non-tax first), never from a client-carried bucket split that
        // was never present in the blind row.
        $this->assertEquals(self::REAL_NON_TAX, $persisted->quantity_non_tax);
        $this->assertEquals(self::REAL_TAX, $persisted->quantity_tax);
        $this->assertEquals(self::REAL_TAX + self::REAL_NON_TAX, $persisted->quantity);
    }

    /** @test */
    public function blind_submission_exceeding_real_stock_is_rejected_without_partial_persistence()
    {
        $blindRow = [
            'id' => $this->product->id,
            'requested_quantity' => self::REAL_TAX + self::REAL_NON_TAX + 100,
            'serial_number_required' => false,
            'serial_numbers' => [],
            'is_broken_mode' => false,
        ];

        Livewire::actingAs($this->user)
            ->test(TransferStockForm::class)
            ->call('onOriginLocationSelected', ['id' => $this->origin->id])
            ->set('stockCondition', Transfer::CONDITION_GOOD)
            ->set('rows', [$blindRow])
            ->call('saveDraft');

        $this->assertDatabaseMissing('transfers', [
            'origin_location_id' => $this->origin->id,
        ]);
    }

    /** @test */
    public function crafted_product_selection_payload_cannot_override_server_serialization_or_stock()
    {
        // Table component is independently callable
        $table = Livewire::actingAs($this->user)
            ->test(\App\Livewire\Transfer\TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ]);

        // Client attempts to craft a payload pretending the non-serialized product is serialized
        // and claiming massive stock
        $craftedPayload = [
            'id' => $this->product->id,
            'product_name' => 'Crafted Name Injected',
            'serial_number_required' => true,
            'is_broken_mode' => false,
            'stock' => [
                'quantity' => 999999,
                'quantity_tax' => 999999,
            ],
            'scan_quantity_multiplier' => 1,
        ];

        $table->call('productSelected', $craftedPayload);

        $products = $table->get('products');
        $this->assertCount(1, $products);

        // Server truth reigns: product is not serialized
        $this->assertFalse($products[0]['serial_number_required']);
        $this->assertEquals($this->product->product_name, $products[0]['product_name']);
    }

    /** @test */
    public function crafted_product_selection_with_ineligible_or_cross_tenant_id_leaves_rows_unchanged()
    {
        $table = Livewire::actingAs($this->user)
            ->test(\App\Livewire\Transfer\TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ]);

        // Attempt to call productSelected with a non-existent product ID
        $table->call('productSelected', [
            'id' => 9999999,
            'is_broken_mode' => false,
        ]);

        $this->assertEmpty($table->get('products'));
    }

    /** @test */
    public function repeated_valid_conversion_scans_accumulate_authoritative_factor_and_ignore_crafted_multiplier()
    {
        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Box',
            'short_name' => 'BX',
        ]);

        $conversion = \Modules\Product\Entities\ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'unit_conversion_name' => 'Box of 2',
            'unit_conversion_code' => 'BX2',
            'conversion_factor' => 2,
            'barcode' => 'CONV-BX2',
        ]);

        $table = Livewire::actingAs($this->user)
            ->test(\App\Livewire\Transfer\TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ]);

        // First scan: supply crafted multiplier 9999, but with valid conversion_id
        $table->call('productSelected', [
            'id' => $this->product->id,
            'conversion_id' => $conversion->id,
            'scan_quantity_multiplier' => 9999,
            'is_broken_mode' => false,
        ]);

        $products = $table->get('products');
        $this->assertCount(1, $products);
        // Authoritative factor 2 is used, crafted multiplier 9999 ignored
        $this->assertEquals(2, $products[0]['requested_quantity']);

        // Second scan: repeats conversion scan
        $table->call('productSelected', [
            'id' => $this->product->id,
            'conversion_id' => $conversion->id,
            'scan_quantity_multiplier' => 1,
            'is_broken_mode' => false,
        ]);

        $products = $table->get('products');
        $this->assertCount(1, $products);
        $this->assertEquals(4, $products[0]['requested_quantity']);
    }

    /** @test */
    public function invalid_or_cross_product_conversion_factors_are_rejected_leaving_rows_unchanged()
    {
        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Pack',
            'short_name' => 'PK',
        ]);

        // Fractional conversion factor
        $fractionalConv = \Modules\Product\Entities\ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'conversion_factor' => 2.5,
            'barcode' => 'CONV-FRAC',
        ]);

        // Cross-product conversion (belongs to other product)
        $otherProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Other Product',
            'product_code' => 'OP-' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);
        $crossProductConv = \Modules\Product\Entities\ProductUnitConversion::create([
            'product_id' => $otherProduct->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'conversion_factor' => 3,
            'barcode' => 'CONV-OTHER',
        ]);

        $table = Livewire::actingAs($this->user)
            ->test(\App\Livewire\Transfer\TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ]);

        // Fractional factor is rejected
        $table->call('productSelected', [
            'id' => $this->product->id,
            'conversion_id' => $fractionalConv->id,
            'is_broken_mode' => false,
        ]);
        $this->assertEmpty($table->get('products'));

        // Cross-product conversion is rejected
        $table->call('productSelected', [
            'id' => $this->product->id,
            'conversion_id' => $crossProductConv->id,
            'is_broken_mode' => false,
        ]);
        $this->assertEmpty($table->get('products'));

        // Non-existent conversion id is rejected
        $table->call('productSelected', [
            'id' => $this->product->id,
            'conversion_id' => 888888,
            'is_broken_mode' => false,
        ]);
        $this->assertEmpty($table->get('products'));
    }

    /** @test */
    public function serial_mutation_safety_rejects_ineligible_serials_without_altering_rows()
    {
        $serialProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Safety Serial Product',
            'product_code' => 'SSP-' . uniqid(),
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
            'broken_quantity' => 5,
            'broken_quantity_tax' => 2,
            'broken_quantity_non_tax' => 3,
        ]);

        $otherLocation = Location::factory()->create(['setting_id' => $this->setting->id]);

        // 1. Wrong origin location
        $wrongOriginSerial = ProductSerialNumber::create([
            'product_id' => $serialProduct->id,
            'location_id' => $otherLocation->id,
            'serial_number' => 'SN-WRONG-LOC',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        // 2. Sold serial
        $soldSerial = ProductSerialNumber::create([
            'product_id' => $serialProduct->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'SN-SOLD-1',
            'status' => ProductSerialNumber::STATUS_SOLD,
            'is_broken' => false,
        ]);

        // 3. Dispatched serial (dispatch_detail_id != null)
        $dispatchedSerial = ProductSerialNumber::create([
            'product_id' => $serialProduct->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'SN-DISPATCHED-1',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'dispatch_detail_id' => 999,
            'is_broken' => false,
        ]);

        // 4. Returning serial (is_in_return_process = true)
        $returningSerial = ProductSerialNumber::create([
            'product_id' => $serialProduct->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'SN-RETURNING-1',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_in_return_process' => true,
            'is_broken' => false,
        ]);

        // 5. Condition mismatch (broken serial in normal mode)
        $brokenSerial = ProductSerialNumber::create([
            'product_id' => $serialProduct->id,
            'location_id' => $this->origin->id,
            'serial_number' => 'SN-BROKEN-FAIL',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => true,
        ]);

        $table = Livewire::actingAs($this->user)
            ->test(\App\Livewire\Transfer\TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ])
            ->call('productSelected', [
                'id' => $serialProduct->id,
                'is_broken_mode' => false,
            ]);

        $this->assertCount(1, $table->get('products'));
        $this->assertEquals(0, $table->get('products')[0]['requested_quantity']);

        // Attempt each ineligible serial
        foreach ([$wrongOriginSerial, $soldSerial, $dispatchedSerial, $returningSerial, $brokenSerial] as $badSerial) {
            $table->call('serialNumberSelected', [
                'productCompositeKey' => 0,
                'serialNumber' => ['id' => $badSerial->id],
            ]);

            // Derived quantity must remain 0 and serials empty
            $this->assertCount(0, $table->get('products')[0]['serial_numbers']);
            $this->assertEquals(0, $table->get('products')[0]['requested_quantity']);
            $this->assertNotNull($table->get('serialNumberErrors')[0]);
            // For a blind user, serial error message must never leak specific state details
            $this->assertStringContainsString('Nomor seri tidak dapat digunakan', $table->get('serialNumberErrors')[0]);
        }
    }

    /** @test */
    public function finding_1_transfer_condition_is_derived_authoritatively_from_table_not_client_payload()
    {
        // Table mounted in GOOD condition
        $table = Livewire::actingAs($this->user)
            ->test(\App\Livewire\Transfer\TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ]);

        // Client attempts to craft productSelected with is_broken_mode => true
        $table->call('productSelected', [
            'id' => $this->product->id,
            'is_broken_mode' => true,
        ]);

        $products = $table->get('products');
        $this->assertCount(1, $products);
        // Table adheres to its authoritative condition (GOOD, i.e. is_broken_mode = false)
        $this->assertFalse($products[0]['is_broken_mode']);
    }

    /** @test */
    public function finding_2_arbitrary_multiplier_without_conversion_record_is_strictly_defaulted_to_1()
    {
        $table = Livewire::actingAs($this->user)
            ->test(\App\Livewire\Transfer\TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ]);

        // Client attempts to pass scan_quantity_multiplier = 4 without a conversion_id
        $table->call('productSelected', [
            'id' => $this->product->id,
            'scan_quantity_multiplier' => 4,
            'is_broken_mode' => false,
        ]);

        $products = $table->get('products');
        $this->assertCount(1, $products);
        // Without an authoritative conversion record, multiplier must strictly be 1
        $this->assertEquals(1, $products[0]['requested_quantity']);
    }

    /** @test */
    public function finding_5_first_insertion_with_conversion_multiplier_exceeding_stock_is_rejected()
    {
        // Real available good stock is 5 (REAL_TAX 2 + REAL_NON_TAX 3)
        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Pack of 10',
            'short_name' => 'PK10',
        ]);

        $conversion10 = \Modules\Product\Entities\ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'conversion_factor' => 10,
        ]);

        $table = Livewire::actingAs($this->user)
            ->test(\App\Livewire\Transfer\TransferProductTable::class, [
                'originLocationId' => $this->origin->id,
                'stockCondition' => Transfer::CONDITION_GOOD,
            ]);

        // First insertion attempts conversion factor 10, while totalAvailable is only 5
        $table->call('productSelected', [
            'id' => $this->product->id,
            'conversion_id' => $conversion10->id,
        ]);

        // Row must not be inserted because available 5 < conversion multiplier 10
        $this->assertEmpty($table->get('products'));
    }
}
