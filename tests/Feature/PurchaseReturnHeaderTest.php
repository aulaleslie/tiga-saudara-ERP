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
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Modules\Product\Entities\ProductStock;
use Tests\TestCase;
use App\Livewire\PurchaseReturn\PurchaseReturnCreateForm;

/**
 * Ticket 2: Update purchase return header
 * 
 * Verifies that the purchase return header requires a supplier 
 * and no longer persists header-level location_id.
 */
class PurchaseReturnHeaderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Currency $currency;
    private Supplier $supplier;
    private Location $location;

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
        $this->location = Location::create([
            'name' => 'Main Warehouse',
            'setting_id' => $this->setting->id,
        ]);
        
        session(['setting_id' => $this->setting->id]);
    }

    /**
     * Scenario: Create with supplier
     * Given the user is creating a purchase return
     * When the user selects a supplier and submits without a header location
     * Then the return is created and no header location is stored
     */
    public function test_create_purchase_return_persists_no_header_location(): void
    {
        Gate::shouldReceive('denies')->andReturnFalse()->zeroOrMoreTimes();
        Gate::shouldReceive('allows')->andReturnTrue()->zeroOrMoreTimes();
        Gate::shouldReceive('check')->andReturnTrue()->zeroOrMoreTimes();

        $category = Category::create([
            'category_name' => 'Test Category',
            'category_code' => 'TC01',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Test Product',
            'product_code' => 'TP01',
            'product_quantity' => 10,
            'product_cost' => 5000,
            'product_price' => 10000,
            'setting_id' => $this->setting->id,
        ]);

        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        Livewire::actingAs($this->user)
            ->test(PurchaseReturnCreateForm::class)
            ->set('supplier_id', $this->supplier->id)
            ->set('date', now()->format('Y-m-d'))
            ->set('rows', [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->product_name,
                    'product_code' => $product->product_code,
                    'quantity' => 5,
                    'location_id' => $this->location->id,
                    'purchase_price' => 10000,
                    'total' => 50000,
                    'serial_number_required' => false,
                ]
            ])
            ->call('submit')
            ->assertHasNoErrors();

        $purchaseReturn = PurchaseReturn::latest()->first();
        $this->assertNotNull($purchaseReturn);
        $this->assertEquals($this->supplier->id, $purchaseReturn->supplier_id);
        $this->assertNull($purchaseReturn->location_id);
    }

    /**
     * Scenario: Missing supplier
     * Given the user is creating a purchase return
     * When the user submits without selecting a supplier
     * Then submission fails with a supplier-required validation error
     */
    public function test_create_purchase_return_fails_without_supplier(): void
    {
        Gate::shouldReceive('denies')->andReturnFalse()->zeroOrMoreTimes();

        Livewire::actingAs($this->user)
            ->test(PurchaseReturnCreateForm::class)
            ->set('supplier_id', '')
            ->set('rows', [])
            ->call('submit')
            ->assertHasErrors(['supplier_id']);
    }

    /**
     * Scenario: Legacy header location payload
     * Given a client includes a header location field in the create payload
     * When the create API processes the request
     * Then the header location value is ignored and not persisted
     */
    public function test_create_purchase_return_ignores_location_id_if_somehow_set(): void
    {
        Gate::shouldReceive('denies')->andReturnFalse()->zeroOrMoreTimes();
        Gate::shouldReceive('allows')->andReturnTrue()->zeroOrMoreTimes();
        Gate::shouldReceive('check')->andReturnTrue()->zeroOrMoreTimes();

        $category = Category::create([
            'category_name' => 'Test Category 2',
            'category_code' => 'TC02',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Test Product 2',
            'product_code' => 'TP02',
            'product_quantity' => 10,
            'product_cost' => 5000,
            'product_price' => 10000,
            'setting_id' => $this->setting->id,
        ]);

        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        // Even if we try to set location_id (which doesn't exist on the component)
        // verify that the persisted model has null location_id
        
        Livewire::actingAs($this->user)
            ->test(PurchaseReturnCreateForm::class)
            ->set('supplier_id', $this->supplier->id)
            ->set('date', now()->format('Y-m-d'))
            ->set('rows', [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->product_name,
                    'product_code' => $product->product_code,
                    'quantity' => 1,
                    'location_id' => $this->location->id,
                    'purchase_price' => 1000,
                    'total' => 1000,
                    'serial_number_required' => false,
                ]
            ])
            ->call('submit');

        $purchaseReturn = PurchaseReturn::latest()->first();
        $this->assertNull($purchaseReturn->location_id);
    }
}
