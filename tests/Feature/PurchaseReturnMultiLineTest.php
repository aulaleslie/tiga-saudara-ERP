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
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Tests\TestCase;
use App\Livewire\PurchaseReturn\PurchaseReturnCreateForm;

class PurchaseReturnMultiLineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Currency $currency;
    private Supplier $supplier;
    private Location $location1;
    private Location $location2;
    private Product $product;

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
            'company_name'              => 'Test Company',
            'company_email'             => 'test@example.com',
            'company_phone'             => '1234567890',
            'default_currency_id'       => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email'        => 'test@example.com',
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

        $this->product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Test Product',
            'product_code' => 'TP01',
            'product_quantity' => 10,
            'product_cost' => 5000,
            'product_price' => 10000,
            'setting_id' => $this->setting->id,
        ]);

        // Add stock data for both locations to pass validation
        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location1->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location2->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        session(['setting_id' => $this->setting->id]);
        Gate::shouldReceive('denies')->andReturnFalse()->zeroOrMoreTimes();
        Gate::shouldReceive('allows')->andReturnTrue()->zeroOrMoreTimes();
        Gate::shouldReceive('check')->andReturnTrue()->zeroOrMoreTimes();
    }

    /**
     * Scenario: Multiple valid lines
     * Given a return with multiple product lines including quantity and location
     * When the user submits the return
     * Then the return is created with all lines stored as submitted
     */
    public function test_create_purchase_return_with_multiple_locations(): void
    {
        Livewire::actingAs($this->user)
            ->test(PurchaseReturnCreateForm::class)
            ->set('supplier_id', $this->supplier->id)
            ->set('rows', [
                [
                    'product_id' => $this->product->id,
                    'product_name' => $this->product->product_name,
                    'product_code' => $this->product->product_code,
                    'quantity' => 2,
                    'location_id' => $this->location1->id,
                    'purchase_price' => 10000,
                    'total' => 20000,
                    'serial_number_required' => false,
                ],
                [
                    'product_id' => $this->product->id,
                    'product_name' => $this->product->product_name,
                    'product_code' => $this->product->product_code,
                    'quantity' => 3,
                    'location_id' => $this->location2->id,
                    'purchase_price' => 10000,
                    'total' => 30000,
                    'serial_number_required' => false,
                ]
            ])
            ->call('submit')
            ->assertHasNoErrors();

        $purchaseReturn = PurchaseReturn::latest()->first();
        $this->assertNotNull($purchaseReturn);
        $this->assertEquals(50000, $purchaseReturn->total_amount);
        
        $details = $purchaseReturn->purchaseReturnDetails;
        $this->assertCount(2, $details);
        
        $this->assertEquals($this->location1->id, $details[0]->location_id);
        $this->assertEquals(2, $details[0]->quantity);
        
        $this->assertEquals($this->location2->id, $details[1]->location_id);
        $this->assertEquals(3, $details[1]->quantity);
    }

    /**
     * Scenario: Duplicate product with same location
     * Given a return contains the same product on two lines with the same location
     * When the user submits the return
     * Then submission fails with a duplicate-line validation error
     */
    public function test_create_purchase_return_blocks_duplicate_product_and_location(): void
    {
        Livewire::actingAs($this->user)
            ->test(PurchaseReturnCreateForm::class)
            ->set('supplier_id', $this->supplier->id)
            ->set('rows', [
                [
                    'product_id' => $this->product->id,
                    'product_name' => $this->product->product_name,
                    'product_code' => $this->product->product_code,
                    'quantity' => 2,
                    'location_id' => $this->location1->id,
                    'purchase_price' => 10000,
                    'total' => 20000,
                    'serial_number_required' => false,
                ],
                [
                    'product_id' => $this->product->id,
                    'product_name' => $this->product->product_name,
                    'product_code' => $this->product->product_code,
                    'quantity' => 3,
                    'location_id' => $this->location1->id,
                    'purchase_price' => 10000,
                    'total' => 30000,
                    'serial_number_required' => false,
                ]
            ])
            ->call('submit')
            ->assertHasErrors(['rows.1.product_id']);
    }

    /**
     * Scenario: Missing location
     * Given a return line without a location
     * When the user submits the return
     * Then submission fails with a location-required validation error
     */
    public function test_create_purchase_return_requires_location_per_line(): void
    {
        Livewire::actingAs($this->user)
            ->test(PurchaseReturnCreateForm::class)
            ->set('supplier_id', $this->supplier->id)
            ->set('rows', [
                [
                    'product_id' => $this->product->id,
                    'product_name' => $this->product->product_name,
                    'product_code' => $this->product->product_code,
                    'quantity' => 2,
                    'location_id' => null,
                    'purchase_price' => 10000,
                    'total' => 20000,
                    'serial_number_required' => false,
                ]
            ])
            ->call('submit')
            ->assertHasErrors(['rows.0.location_id']);
    }
}
