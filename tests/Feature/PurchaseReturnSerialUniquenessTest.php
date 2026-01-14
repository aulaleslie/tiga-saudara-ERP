<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Tests\TestCase;
use App\Livewire\PurchaseReturn\PurchaseReturnCreateForm;

class PurchaseReturnSerialUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Currency $currency;
    private Supplier $supplier;
    private Location $location1;
    private Location $location2;
    private Product $serialProduct;
    private Product $nonSerialProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            CheckUserRoleForSetting::class,
            VerifyCsrfToken::class,
        ]);

        $this->currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $this->setting = Setting::create([
            'company_name'              => 'Tenant A',
            'company_email'             => 'tenant_a@example.com',
            'company_phone'             => '1234567890',
            'default_currency_id'       => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email'        => 'tenant_a@example.com',
            'footer_text'               => 'Footer',
            'company_address'           => '123 Street',
        ]);

        $this->user = User::factory()->create();
        $this->supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);
        
        $this->location1 = Location::create([
            'name' => 'Location 1',
            'setting_id' => $this->setting->id
        ]);

        $this->location2 = Location::create([
            'name' => 'Location 2',
            'setting_id' => $this->setting->id
        ]);

        $category = Category::create([
            'category_name' => 'Test Category',
            'category_code' => 'TC01',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $this->serialProduct = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Serial Product',
            'product_code' => 'SP01',
            'product_quantity' => 20,
            'product_cost' => 5000,
            'product_price' => 10000,
            'setting_id' => $this->setting->id,
            'serial_number_required' => true,
        ]);

        $this->nonSerialProduct = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Non Serial Product',
            'product_code' => 'NSP01',
            'product_quantity' => 20,
            'product_cost' => 3000,
            'product_price' => 6000,
            'setting_id' => $this->setting->id,
            'serial_number_required' => false,
        ]);

        // Create stock for both locations
        foreach ([$this->location1, $this->location2] as $location) {
            ProductStock::create([
                'product_id' => $this->serialProduct->id,
                'location_id' => $location->id,
                'quantity' => 10,
                'quantity_non_tax' => 10,
                'quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
                'broken_quantity_tax' => 0,
                'broken_quantity' => 0,
            ]);

            ProductStock::create([
                'product_id' => $this->nonSerialProduct->id,
                'location_id' => $location->id,
                'quantity' => 10,
                'quantity_non_tax' => 10,
                'quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
                'broken_quantity_tax' => 0,
                'broken_quantity' => 0,
            ]);
        }

        // Create serial numbers for location 1
        ProductSerialNumber::create([
            'product_id' => $this->serialProduct->id,
            'location_id' => $this->location1->id,
            'serial_number' => 'SN001',
            'status' => 'active',
        ]);

        ProductSerialNumber::create([
            'product_id' => $this->serialProduct->id,
            'location_id' => $this->location1->id,
            'serial_number' => 'SN002',
            'status' => 'active',
        ]);

        // Create serial numbers for location 2
        ProductSerialNumber::create([
            'product_id' => $this->serialProduct->id,
            'location_id' => $this->location2->id,
            'serial_number' => 'SN003',
            'status' => 'active',
        ]);

        session(['setting_id' => $this->setting->id]);
        Gate::shouldReceive('denies')->andReturnFalse()->zeroOrMoreTimes();
        Gate::shouldReceive('allows')->andReturnTrue()->zeroOrMoreTimes();
        Gate::shouldReceive('check')->andReturnTrue()->zeroOrMoreTimes();
    }

    /**
     * Scenario: Unique serials succeed
     * Given a return with serial-tracked lines using distinct serials
     * When the user submits the return
     * Then submission succeeds
     */
    public function test_unique_serials_across_rows_succeed(): void
    {
        $serial1 = ProductSerialNumber::where('serial_number', 'SN001')->first();
        $serial2 = ProductSerialNumber::where('serial_number', 'SN002')->first();

        $rows = [
            [
                'product_id' => $this->serialProduct->id,
                'product_name' => $this->serialProduct->product_name,
                'quantity' => 1,
                'location_id' => $this->location1->id,
                'location_name' => 'Location 1',
                'location_locked' => true,
                'purchase_order_id' => null,
                'purchase_price' => 5000,
                'serial_numbers' => [
                    ['id' => $serial1->id, 'serial_number' => 'SN001']
                ],
                'serial_number_required' => true,
                'total' => 5000,
            ],
            [
                'product_id' => $this->serialProduct->id,
                'product_name' => $this->serialProduct->product_name,
                'quantity' => 1,
                'location_id' => $this->location1->id,
                'location_name' => 'Location 1',
                'location_locked' => true,
                'purchase_order_id' => null,
                'purchase_price' => 5000,
                'serial_numbers' => [
                    ['id' => $serial2->id, 'serial_number' => 'SN002']
                ],
                'serial_number_required' => true,
                'total' => 5000,
            ],
        ];

        // Validate using the trait's validation logic
        $component = Livewire::test(PurchaseReturnCreateForm::class)
            ->set('supplier_id', $this->supplier->id)
            ->set('date', now()->format('Y-m-d'))
            ->set('rows', $rows);

        // Trigger validation by attempting to submit
        $component->call('submit');

        // Should not have serial-related errors
        $component->assertHasNoErrors(['rows.0.serial_numbers', 'rows.1.serial_numbers']);
    }

    /**
     * Scenario: Duplicate serials with casing differences fail
     * Given a return includes serials "abc123" and "ABC123"
     * When the user submits the return
     * Then submission fails with a duplicate-serial validation error
     */
    public function test_duplicate_serials_case_insensitive_fail(): void
    {
        // Create serials with different casing (simulating registry)
        $serial1 = ProductSerialNumber::create([
            'product_id' => $this->serialProduct->id,
            'location_id' => $this->location1->id,
            'serial_number' => 'ABC123',
            'status' => 'active',
        ]);

        $rows = [
            [
                'product_id' => $this->serialProduct->id,
                'product_name' => $this->serialProduct->product_name,
                'quantity' => 1,
                'location_id' => $this->location1->id,
                'location_name' => 'Location 1',
                'location_locked' => true,
                'purchase_order_id' => null,
                'purchase_price' => 5000,
                'serial_numbers' => [
                    ['id' => $serial1->id, 'serial_number' => 'ABC123']
                ],
                'serial_number_required' => true,
                'total' => 5000,
            ],
            [
                'product_id' => $this->serialProduct->id,
                'product_name' => $this->serialProduct->product_name,
                'quantity' => 1,
                'location_id' => $this->location1->id,
                'location_name' => 'Location 1',
                'location_locked' => true,
                'purchase_order_id' => null,
                'purchase_price' => 5000,
                'serial_numbers' => [
                    ['id' => $serial1->id, 'serial_number' => 'abc123'] // Same serial, different casing
                ],
                'serial_number_required' => true,
                'total' => 5000,
            ],
        ];

        $component = Livewire::test(PurchaseReturnCreateForm::class)
            ->set('supplier_id', $this->supplier->id)
            ->set('date', now()->format('Y-m-d'))
            ->set('rows', $rows);

        $component->call('submit');

        // Should have duplicate serial error on the second row
        $component->assertHasErrors(['rows.1.serial_numbers']);
    }

    /**
     * Scenario: Serial entered on non-serial product fails
     * Given a product that is not serial-tracked
     * When a serial is submitted on that line
     * Then submission fails with a serial-not-allowed error
     */
    public function test_serial_on_non_serial_product_fails(): void
    {
        $rows = [
            [
                'product_id' => $this->nonSerialProduct->id,
                'product_name' => $this->nonSerialProduct->product_name,
                'quantity' => 1,
                'location_id' => $this->location1->id,
                'location_name' => 'Location 1',
                'location_locked' => false,
                'purchase_order_id' => null,
                'purchase_price' => 3000,
                'serial_numbers' => [
                    ['id' => 999, 'serial_number' => 'BOGUS-SERIAL']
                ],
                'serial_number_required' => false,
                'total' => 3000,
            ],
        ];

        $component = Livewire::test(PurchaseReturnCreateForm::class)
            ->set('supplier_id', $this->supplier->id)
            ->set('date', now()->format('Y-m-d'))
            ->set('rows', $rows);

        $component->call('submit');

        // Should have error for serial on non-serial product
        $component->assertHasErrors(['rows.0.serial_numbers']);
    }
}
