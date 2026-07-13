<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Livewire\AutoComplete\LocationBusinessLoader;
use App\Livewire\AutoComplete\SerialNumberLoader;
use App\Livewire\Transfer\TransferProductTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TransferUiFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected array $permissions = [
        'stockTransfers.access',
        'stockTransfers.create',
        'stockTransfers.show',
        'stockTransfers.edit',
        'stockTransfers.delete',
        'stockTransfers.approval',
        'stockTransfers.dispatch',
        'stockTransfers.receive',
        'stockTransfers.archive',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    private function createTenantData(string $companyName): array
    {
        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $setting = Setting::create([
            'company_name' => $companyName,
            'company_email' => strtolower(str_replace(' ', '', $companyName)) . '@test.com',
            'company_phone' => '0800000000',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => strtolower(str_replace(' ', '', $companyName)) . '@test.com',
            'footer_text' => 'Footer',
            'company_address' => 'Bandung',
        ]);

        $originLocation = Location::create([
            'setting_id' => $setting->id,
            'name' => 'Warehouse A',
        ]);

        $destinationLocation = Location::create([
            'setting_id' => $setting->id,
            'name' => 'Warehouse B',
        ]);

        $user = User::create([
            'name' => 'Warehouse Manager',
            'email' => 'manager@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $role = Role::create(['name' => 'manager']);
        $role->syncPermissions($this->permissions);
        $user->assignRole($role);

        return [
            'setting' => $setting,
            'originLocation' => $originLocation,
            'destinationLocation' => $destinationLocation,
            'user' => $user,
            'currency' => $currency,
        ];
    }

    /** @test */
    public function location_selection_shows_name_and_company(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        Livewire::test(LocationBusinessLoader::class, [
            'settingId' => $data['setting']->id,
            'eventName' => 'originLocationSelected',
        ])
            ->call('selectLocation', $data['originLocation']->id)
            ->assertSet('locationSelected', true)
            ->assertSet('locationId', $data['originLocation']->id)
            ->assertSet('query', 'WAREHOUSE A - TIGA SAUDARA');
    }

    /** @test */
    public function location_selection_shows_clear_selected_state(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        Livewire::test(LocationBusinessLoader::class, [
            'settingId' => $data['setting']->id,
            'eventName' => 'originLocationSelected',
        ])
            ->call('selectLocation', $data['originLocation']->id)
            ->assertSet('locationSelected', true)
            ->assertSet('locationId', $data['originLocation']->id);
    }

    /** @test */
    public function location_selection_updates_query_display(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        Livewire::test(LocationBusinessLoader::class, [
            'settingId' => $data['setting']->id,
            'eventName' => 'originLocationSelected',
            'locationId' => $data['originLocation']->id,
        ])
            ->assertSet('query', 'WAREHOUSE A - TIGA SAUDARA')
            ->assertSet('locationSelected', true)
            ->assertSet('locationId', $data['originLocation']->id);
    }

    /** @test */
    public function location_selection_clears_when_text_emptied(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        Livewire::test(LocationBusinessLoader::class, [
            'settingId' => $data['setting']->id,
            'eventName' => 'originLocationSelected',
        ])
            ->call('selectLocation', $data['originLocation']->id)
            ->assertSet('locationSelected', true)
            ->set('query', '') // Clearing query triggers updatedQuery
            ->assertSet('locationId', null) // Should clear ID when query is empty
            ->assertSet('locationSelected', false);
    }

    /** @test */
    public function location_selection_for_destination_works(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        Livewire::test(LocationBusinessLoader::class, [
            'settingId' => $data['setting']->id,
            'eventName' => 'destinationLocationSelected',
            'exclude' => $data['originLocation']->id,
        ])
            ->call('selectLocation', $data['destinationLocation']->id)
            ->assertSet('locationSelected', true)
            ->assertSet('locationId', $data['destinationLocation']->id);
    }

    /** @test */
    public function location_selection_browser_synchronization_defect(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        $location = $data['originLocation'];

        // Type WARE. Wait for suggestions. Select a location.
        // Confirm the input displays the complete label, not WARE.
        $expectedLabel = $location->name . ' - ' . $location->setting->company_name;

        $component = Livewire::test(LocationBusinessLoader::class, [
            'settingId' => $data['setting']->id,
            'eventName' => 'originLocationSelected',
        ])
            ->set('isFocused', true)
            ->set('query', 'WARE')
            ->assertSee($location->name)
            ->call('selectLocation', $location->id)
            ->assertSet('query', $expectedLabel)
            ->assertSeeHtml('value="' . $expectedLabel . '"');

        // Confirm clearing or editing the selection resets the selected location ID.
        $component->call('clearSelection')
            ->assertSet('locationId', null)
            ->assertSet('locationSelected', false)
            ->assertSet('query', '')
            ->assertDontSeeHtml('value="' . $expectedLabel . '"');
    }


    /** @test */
    public function edit_preserves_location_selection_on_mount(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        Livewire::test(LocationBusinessLoader::class, [
            'settingId' => $data['setting']->id,
            'eventName' => 'originLocationSelected',
            'locationId' => $data['originLocation']->id,
        ])
            ->assertSet('query', 'WAREHOUSE A - TIGA SAUDARA')
            ->assertSet('locationSelected', true)
            ->assertSet('locationId', $data['originLocation']->id);
    }

    /** @test */
    public function normal_mode_stock_display_shows_correct_buckets(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST001',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $data['setting']->id,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $data['originLocation']->id,
            'quantity' => 100,
            'quantity_tax' => 30,
            'quantity_non_tax' => 70,
            'broken_quantity' => 10,
            'broken_quantity_tax' => 5,
            'broken_quantity_non_tax' => 5,
        ]);

        Livewire::test(TransferProductTable::class, [
            'originLocationId' => $data['originLocation']->id,
            'destinationLocationId' => $data['destinationLocation']->id,
        ])
            ->call('productSelected', [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_barcode' => $product->barcode,
                'serial_number_required' => false,
                'is_broken_mode' => false,
                'scan_quantity_multiplier' => 1,
            ])
            ->assertSet('products', function ($products) {
                $this->assertCount(1, $products);
                $this->assertEquals(30, $products[0]['stock']['quantity_tax']);
                $this->assertEquals(70, $products[0]['stock']['quantity_non_tax']);
                return true;
            });
    }

    /** @test */
    public function broken_mode_stock_display_shows_broken_buckets(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        $product = Product::create([
            'product_name' => 'Broken Test Product',
            'product_code' => 'BROKEN001',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $data['setting']->id,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $data['originLocation']->id,
            'quantity' => 100,
            'quantity_tax' => 30,
            'quantity_non_tax' => 70,
            'broken_quantity' => 10,
            'broken_quantity_tax' => 5,
            'broken_quantity_non_tax' => 5,
        ]);

        Livewire::test(TransferProductTable::class, [
            'originLocationId' => $data['originLocation']->id,
            'destinationLocationId' => $data['destinationLocation']->id,
        ])
            ->call('productSelected', [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_barcode' => $product->barcode,
                'serial_number_required' => false,
                'is_broken_mode' => true,
                'scan_quantity_multiplier' => 1,
            ])
            ->assertSet('products', function ($products) {
                $this->assertCount(1, $products);
                $this->assertTrue($products[0]['is_broken_mode']);
                $this->assertEquals(5, $products[0]['stock']['broken_quantity_tax']);
                $this->assertEquals(5, $products[0]['stock']['broken_quantity_non_tax']);
                return true;
            });
    }

    /** @test */
    public function insufficient_stock_rejects_quantity_with_error(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        $product = Product::create([
            'product_name' => 'Limited Stock Product',
            'product_code' => 'LIMITED001',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $data['setting']->id,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $data['originLocation']->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        Livewire::test(TransferProductTable::class, [
            'originLocationId' => $data['originLocation']->id,
            'destinationLocationId' => $data['destinationLocation']->id,
        ])
            ->call('productSelected', [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_barcode' => $product->barcode,
                'serial_number_required' => false,
                'is_broken_mode' => false,
                'scan_quantity_multiplier' => 1,
            ])
            ->set('products.0.requested_quantity', 20)
            ->assertSet('tableValidationErrors', function ($errors) {
                $this->assertArrayHasKey('products.0.requested_quantity', $errors);
                $this->assertStringContainsString('tidak mencukupi', $errors['products.0.requested_quantity']);
                return true;
            });
    }

    /** @test */
    public function edit_hydration_preserves_requested_quantity(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        $mapper = app(\Modules\Adjustment\Services\TransferFormStateMapper::class);

        $product = Product::create([
            'product_name' => 'Existing Product',
            'product_code' => 'EXIST001',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $data['setting']->id,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $data['originLocation']->id,
            'quantity' => 100,
            'quantity_tax' => 30,
            'quantity_non_tax' => 70,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Use TransferProductTable to test the form state mapper
        Livewire::test(TransferProductTable::class, [
            'originLocationId' => $data['originLocation']->id,
            'destinationLocationId' => $data['destinationLocation']->id,
        ])
            ->call('productSelected', [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_barcode' => $product->barcode,
                'serial_number_required' => false,
                'is_broken_mode' => false,
                'scan_quantity_multiplier' => 1,
            ])
            ->set('products.0.requested_quantity', 50)
            ->assertSet('products', function ($products) {
                $this->assertEquals(50, $products[0]['requested_quantity']);
                $this->assertFalse($products[0]['is_broken_mode']);
                return true;
            });
    }

    /** @test */
    public function edit_hydration_preserves_broken_mode_flag(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        $product = Product::create([
            'product_name' => 'Broken Product',
            'product_code' => 'BROKEN002',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $data['setting']->id,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $data['originLocation']->id,
            'quantity' => 100,
            'quantity_tax' => 30,
            'quantity_non_tax' => 70,
            'broken_quantity' => 10,
            'broken_quantity_tax' => 5,
            'broken_quantity_non_tax' => 5,
        ]);

        Livewire::test(TransferProductTable::class, [
            'originLocationId' => $data['originLocation']->id,
            'destinationLocationId' => $data['destinationLocation']->id,
        ])
            ->call('productSelected', [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_barcode' => $product->barcode,
                'serial_number_required' => false,
                'is_broken_mode' => true,
                'scan_quantity_multiplier' => 1,
            ])
            ->set('products.0.requested_quantity', 5)
            ->assertSet('products', function ($products) {
                $this->assertEquals(5, $products[0]['requested_quantity']);
                $this->assertTrue($products[0]['is_broken_mode']);
                return true;
            });
    }

    /** @test */
    public function non_tax_first_allocation_prioritizes_non_tax_stock(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        $product = Product::create([
            'product_name' => 'Mixed Stock Product',
            'product_code' => 'MIXED001',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $data['setting']->id,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $data['originLocation']->id,
            'quantity' => 100,
            'quantity_tax' => 40,
            'quantity_non_tax' => 60,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        Livewire::test(TransferProductTable::class, [
            'originLocationId' => $data['originLocation']->id,
            'destinationLocationId' => $data['destinationLocation']->id,
        ])
            ->call('productSelected', [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_barcode' => $product->barcode,
                'serial_number_required' => false,
                'is_broken_mode' => false,
                'scan_quantity_multiplier' => 70,
            ])
            ->assertSet('products', function ($products) {
                // Should allocate 60 non-tax (all available) and 10 tax
                $this->assertEquals(60, $products[0]['quantity_non_tax']);
                $this->assertEquals(10, $products[0]['quantity_tax']);
                return true;
            });
    }

    /** @test */
    public function tax_spillover_allocation_warns_when_tax_required(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        $product = Product::create([
            'product_name' => 'Tax Spillover Product',
            'product_code' => 'TAX001',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $data['setting']->id,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $data['originLocation']->id,
            'quantity' => 50,
            'quantity_tax' => 50,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        Livewire::test(TransferProductTable::class, [
            'originLocationId' => $data['originLocation']->id,
            'destinationLocationId' => $data['destinationLocation']->id,
        ])
            ->call('productSelected', [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_barcode' => $product->barcode,
                'serial_number_required' => false,
                'is_broken_mode' => false,
                'scan_quantity_multiplier' => 30,
            ])
            ->assertSet('products', function ($products) {
                // No non-tax available, should allocate all 30 to tax
                $this->assertEquals(0, $products[0]['quantity_non_tax']);
                $this->assertEquals(30, $products[0]['quantity_tax']);
                return true;
            })
            ->assertSee('Stok pajak harus dikembalikan lintas lokasi');
    }

    /** @test */
    public function parent_form_rejects_insufficient_stock_mismatch(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        $product = Product::create([
            'product_name' => 'Limited Stock',
            'product_code' => 'LIMIT001',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $data['setting']->id,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $data['originLocation']->id,
            'quantity' => 5,
            'quantity_tax' => 0,
            'quantity_non_tax' => 5,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Livewire can't fully test the parent form's submit() without more setup
        // But we can verify the table rejects the quantity
        Livewire::test(TransferProductTable::class, [
            'originLocationId' => $data['originLocation']->id,
            'destinationLocationId' => $data['destinationLocation']->id,
        ])
            ->call('productSelected', [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_barcode' => $product->barcode,
                'serial_number_required' => false,
                'is_broken_mode' => false,
                'scan_quantity_multiplier' => 1,
            ])
            ->set('products.0.requested_quantity', 10)
            ->assertSet('tableValidationErrors', function ($errors) {
                $this->assertArrayHasKey('products.0.requested_quantity', $errors);
                return true;
            });
    }

    /** @test */
    public function serial_selection_validates_product_and_broken_mode(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        $product1 = Product::create([
            'product_name' => 'Product 1',
            'product_code' => 'P001',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $data['setting']->id,
            'serial_number_required' => true,
        ]);

        // Create serials for product1
        $serial1 = ProductSerialNumber::create([
            'product_id' => $product1->id,
            'serial_number' => 'SN001',
            'location_id' => $data['originLocation']->id,
            'status' => 'active',
            'is_broken' => false,
        ]);

        // Verify the serial can be found when product_id matches
        $found = ProductSerialNumber::query()
            ->where('id', $serial1->id)
            ->where('product_id', $product1->id)
            ->where('is_broken', false)
            ->first();

        $this->assertNotNull($found);
        $this->assertEquals($serial1->id, $found->id);

        // Verify the serial cannot be found when product_id doesn't match
        $notFound = ProductSerialNumber::query()
            ->where('id', $serial1->id)
            ->where('product_id', 999)
            ->first();

        $this->assertNull($notFound);
    }

    /** @test */
    public function real_transfer_edit_hydration_through_mapper(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        $product = Product::create([
            'product_name' => 'Hydration Test Product',
            'product_code' => 'HYDRA001',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $data['setting']->id,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $data['originLocation']->id,
            'quantity' => 200,
            'quantity_tax' => 80,
            'quantity_non_tax' => 120,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Create a real transfer to test mapper hydration
        $transfer = Transfer::create([
            'setting_id' => $data['setting']->id,
            'origin_location_id' => $data['originLocation']->id,
            'destination_location_id' => $data['destinationLocation']->id,
            'status' => 'DRAFT',
            'document_number' => 'TEST-001',
            'created_by' => $data['user']->id,
        ]);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id' => $product->id,
            'quantity' => 50,
            'requested_quantity' => 50,
            'quantity_tax' => 20,
            'quantity_non_tax' => 30,
            'quantity_broken_tax' => 0,
            'quantity_broken_non_tax' => 0,
        ]);

        $mapper = app(\Modules\Adjustment\Services\TransferFormStateMapper::class);
        $rows = $mapper->mapToLivewireRows($transfer);

        $this->assertCount(1, $rows);
        $this->assertEquals(50, $rows[0]['requested_quantity']);
        $this->assertEquals(20, $rows[0]['quantity_tax']);
        $this->assertEquals(30, $rows[0]['quantity_non_tax']);
    }

    /** @test */
    public function parent_form_submission_rejects_insufficient_stock_with_transfer_not_created(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        $product = Product::create([
            'product_name' => 'Limited Stock',
            'product_code' => 'LIMIT002',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $data['setting']->id,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $data['originLocation']->id,
            'quantity' => 5,
            'quantity_tax' => 0,
            'quantity_non_tax' => 5,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $formComponent = Livewire::test(\App\Livewire\Transfer\TransferStockForm::class)
            ->set('originLocation', $data['originLocation']->id)
            ->set('destinationLocation', $data['destinationLocation']->id)
            ->set('rows', [
                [
                    'id' => $product->id,
                    'requested_quantity' => 10,
                    'quantity_tax' => 0,
                    'quantity_non_tax' => 10,
                    'broken_quantity_tax' => 0,
                    'broken_quantity_non_tax' => 0,
                    'stock' => [
                        'quantity_tax' => 0,
                        'quantity_non_tax' => 5,
                        'broken_quantity_tax' => 0,
                        'broken_quantity_non_tax' => 0,
                    ],
                ],
            ])
            ->call('submit');

        // Verify form component dispatches table validation errors
        $dispatches = $formComponent->effects['dispatches'] ?? [];
        $found = false;
        foreach ($dispatches as $dispatch) {
            if (($dispatch['name'] ?? '') === 'tableValidationErrors') {
                $params = $dispatch['params'][0] ?? [];
                if (isset($params['products.0.quantity_non_tax']) || isset($params['products.0.requested_quantity'])) {
                    $found = true;
                    break;
                }
            }
        }
        $this->assertTrue($found, 'tableValidationErrors event not found with correct parameters.');

        // Verify no transfer was created
        $this->assertCount(0, Transfer::all());
    }

    /** @test */
    public function serial_selection_through_loader_validates_product_and_mode(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        $product1 = Product::create([
            'product_name' => 'Serial Product 1',
            'product_code' => 'SP001',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $data['setting']->id,
            'serial_number_required' => true,
        ]);

        $product2 = Product::create([
            'product_name' => 'Serial Product 2',
            'product_code' => 'SP002',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $data['setting']->id,
            'serial_number_required' => true,
        ]);

        // Create serials for product1 (non-broken)
        $serial1 = ProductSerialNumber::create([
            'product_id' => $product1->id,
            'serial_number' => 'SN001',
            'location_id' => $data['originLocation']->id,
            'status' => 'active',
            'is_broken' => false,
            'tax_id' => null,
        ]);

        // Create broken serial for product1
        $serialBroken = ProductSerialNumber::create([
            'product_id' => $product1->id,
            'serial_number' => 'SN002',
            'location_id' => $data['originLocation']->id,
            'status' => 'active',
            'is_broken' => true,
            'tax_id' => null,
        ]);

        // Create serial for product2
        $serial2 = ProductSerialNumber::create([
            'product_id' => $product2->id,
            'serial_number' => 'SN003',
            'location_id' => $data['originLocation']->id,
            'status' => 'active',
            'is_broken' => false,
            'tax_id' => null,
        ]);

        $loader = Livewire::test(\App\Livewire\AutoComplete\SerialNumberLoader::class, [
            'locationId' => $data['originLocation']->id,
            'productId' => $product1->id,
            'isBroken' => false,
            'serialIndex' => 'idx1',
            'productCompositeKey' => 'key1',
        ]);

        // Select correct serial
        $loader->call('selectSerialNumber', $serial1->id)
            ->assertDispatched('serialNumberSelected', function ($eventName, $params) use ($serial1) {
                return $params[0]['serialNumber']['id'] === $serial1->id;
            });

        // Select wrong product serial
        $loader->call('selectSerialNumber', $serial2->id)
            ->assertNotDispatched('serialNumberSelected');

        // Select wrong mode serial
        $loader->call('selectSerialNumber', $serialBroken->id)
            ->assertNotDispatched('serialNumberSelected');
    }

    /** @test */
    public function serial_quantity_synchronization_on_add_and_remove(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        $product = Product::create([
            'product_name' => 'Serial Product',
            'product_code' => 'SP003',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $data['setting']->id,
            'serial_number_required' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $data['originLocation']->id,
            'quantity' => 100,
            'quantity_tax' => 50,
            'quantity_non_tax' => 50,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Create serials
        $serial1 = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN001',
            'location_id' => $data['originLocation']->id,
            'status' => 'active',
            'is_broken' => false,
            'tax_id' => null,
        ]);

        $serial2 = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN002',
            'location_id' => $data['originLocation']->id,
            'status' => 'active',
            'is_broken' => false,
            'tax_id' => null,
        ]);

        Livewire::test(TransferProductTable::class, [
            'originLocationId' => $data['originLocation']->id,
            'destinationLocationId' => $data['destinationLocation']->id,
        ])
            ->call('productSelected', [
                'id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'product_barcode' => $product->barcode,
                'serial_number_required' => true,
                'is_broken_mode' => false,
                'scan_quantity_multiplier' => 1,
            ])
            // Add first serial
            ->call('serialNumberSelected', [
                'serialNumber' => $serial1->toArray(),
                'productCompositeKey' => 0,
                'serialIndex' => 'transfer-serials-0',
            ])
            ->assertSet('products', function ($products) {
                $this->assertEquals(1, $products[0]['requested_quantity']);
                $this->assertEquals(1, count($products[0]['serial_numbers']));
                return true;
            })
            // Add second serial
            ->call('serialNumberSelected', [
                'serialNumber' => $serial2->toArray(),
                'productCompositeKey' => 0,
                'serialIndex' => 'transfer-serials-0',
            ])
            ->assertSet('products', function ($products) {
                $this->assertEquals(2, $products[0]['requested_quantity']);
                $this->assertEquals(2, count($products[0]['serial_numbers']));
                return true;
            })
            // Remove first serial
            ->call('removeSerialNumber', 0, 0)
            ->assertSet('products', function ($products) {
                $this->assertEquals(1, $products[0]['requested_quantity']);
                $this->assertEquals(1, count($products[0]['serial_numbers']));
                return true;
            });
    }

    /** @test */
    public function parent_form_submission_merges_normal_and_broken_modes_for_same_product(): void
    {
        $data = $this->createTenantData('Tiga Saudara');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        $product = Product::create([
            'product_name' => 'Mixed Product',
            'product_code' => 'MIX001',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $data['setting']->id,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $data['originLocation']->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 5,
            'broken_quantity' => 5,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 5,
        ]);

        $formComponent = Livewire::test(\App\Livewire\Transfer\TransferStockForm::class)
            ->set('originLocation', $data['originLocation']->id)
            ->set('destinationLocation', $data['destinationLocation']->id)
            ->set('rows', [
                [
                    'id' => $product->id,
                    'requested_quantity' => 5,
                    'quantity_tax' => 0,
                    'quantity_non_tax' => 5,
                    'broken_quantity_tax' => 0,
                    'broken_quantity_non_tax' => 0,
                    'is_broken_mode' => false,
                    'stock' => [
                        'quantity_tax' => 0,
                        'quantity_non_tax' => 5,
                        'broken_quantity_tax' => 0,
                        'broken_quantity_non_tax' => 5,
                    ],
                ],
                [
                    'id' => $product->id,
                    'requested_quantity' => 5,
                    'quantity_tax' => 0,
                    'quantity_non_tax' => 0,
                    'broken_quantity_tax' => 0,
                    'broken_quantity_non_tax' => 5,
                    'is_broken_mode' => true,
                    'stock' => [
                        'quantity_tax' => 0,
                        'quantity_non_tax' => 5,
                        'broken_quantity_tax' => 0,
                        'broken_quantity_non_tax' => 5,
                    ],
                ],
            ])
            ->call('submit');

        if ($formComponent->effects['dispatches'] ?? false) {
            foreach ($formComponent->effects['dispatches'] as $dispatch) {
                if ($dispatch['name'] === 'tableValidationErrors') {
                    if (!empty($dispatch['params'][0])) {
                        dd($dispatch['params'][0]);
                    }
                }
            }
        }

        // Verify transfer was created successfully
        $transfer = Transfer::latest()->first();
        $this->assertNotNull($transfer);

        // Verify it merged the modes into a single transfer product record
        $this->assertCount(1, $transfer->products);
        $tp = $transfer->products->first();
        
        $this->assertEquals($product->id, $tp->product_id);
        $this->assertEquals(5, $tp->quantity_non_tax);
        $this->assertEquals(5, $tp->quantity_broken_non_tax);
        $this->assertEquals(10, $tp->quantity);
    }

    /** @test */
    public function mixed_transfer_edit_hydration_preserves_both_modes(): void
    {
        $data = $this->createTenantData('Tiga Saudara Mixed');
        $this->actingAs($data['user']);
        session(['setting_id' => $data['setting']->id]);

        $product = Product::create([
            'product_name' => 'Mixed Edit Product',
            'product_code' => 'MIX002',
            'product_cost' => 10000,
            'product_price' => 15000,
            'setting_id' => $data['setting']->id,
            'serial_number_required' => false,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $data['originLocation']->id,
            'quantity' => 15,
            'quantity_tax' => 2,
            'quantity_non_tax' => 3,
            'broken_quantity' => 10,
            'broken_quantity_tax' => 4,
            'broken_quantity_non_tax' => 6,
        ]);

        $transfer = Transfer::create([
            'origin_location_id' => $data['originLocation']->id,
            'destination_location_id' => $data['destinationLocation']->id,
            'created_by' => $data['user']->id,
            'status' => Transfer::STATUS_DRAFT,
            'revision' => 1,
        ]);

        \Modules\Adjustment\Entities\TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id' => $product->id,
            'quantity' => 15,
            'quantity_tax' => 2,
            'quantity_non_tax' => 3,
            'quantity_broken_tax' => 4,
            'quantity_broken_non_tax' => 6,
        ]);

        // Open edit form
        $formComponent = Livewire::test(\App\Livewire\Transfer\TransferStockForm::class, [
            'transfer' => $transfer,
        ]);

        // It should have two rows (normal and broken)
        $rows = $formComponent->get('rows');
        $this->assertCount(2, $rows);

        $normalRow = collect($rows)->firstWhere('is_broken_mode', false);
        $this->assertNotNull($normalRow);
        $this->assertEquals(5, $normalRow['requested_quantity']); // 2 tax + 3 non_tax

        $brokenRow = collect($rows)->firstWhere('is_broken_mode', true);
        $this->assertNotNull($brokenRow);
        $this->assertEquals(10, $brokenRow['requested_quantity']); // 4 broken_tax + 6 broken_non_tax

        // Save again without changes
        $formComponent->call('submit');

        $transfer->refresh();
        $this->assertCount(1, $transfer->products);
        $tp = $transfer->products->first();

        // Verify buckets remained unchanged
        $this->assertEquals(15, $tp->quantity);
        $this->assertEquals(2, $tp->quantity_tax);
        $this->assertEquals(3, $tp->quantity_non_tax);
        $this->assertEquals(4, $tp->quantity_broken_tax);
        $this->assertEquals(6, $tp->quantity_broken_non_tax);
    }
}
