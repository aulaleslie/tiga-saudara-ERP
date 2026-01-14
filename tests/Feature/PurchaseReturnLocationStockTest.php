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
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Tests\TestCase;
use App\Livewire\PurchaseReturn\LocationSearchDropdownPerLine;
use App\Livewire\PurchaseReturn\PurchaseReturnCreateForm;

class PurchaseReturnLocationStockTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Currency $currency;
    private Supplier $supplier;
    private Location $locationWithStock;
    private Location $locationNoStock;
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
        
        $this->locationWithStock = Location::create([
            'name' => 'Location 1',
            'setting_id' => $this->setting->id
        ]);

        $this->locationNoStock = Location::create([
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

        // Update product to include price info
        $this->product->update([
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
            'sale_price' => 10000,
        ]);

        // Add stock to location 1, but not location 2
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locationWithStock->id,
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
     * Scenario: Locations filtered by positive stock
     */
    public function test_location_dropdown_filters_by_positive_stock(): void
    {
        Livewire::test(LocationSearchDropdownPerLine::class, [
                'index' => 0,
                'product_id' => $this->product->id
            ])
            ->assertSet('product_id', $this->product->id)
            ->assertViewHas('locations', function ($locations) {
                return $locations->count() === 1 && 
                       $locations->first()['id'] === $this->locationWithStock->id;
            });
    }

    /**
     * Scenario: Label format is correct (Tenant Name - Location Name)
     */
    public function test_location_dropdown_label_format(): void
    {
        Livewire::test(LocationSearchDropdownPerLine::class, [
                'index' => 0,
                'product_id' => $this->product->id
            ])
            ->assertViewHas('locations', function ($locations) {
                return $locations->first()['label'] === 'TENANT A - LOCATION 1';
            });
    }

    /**
     * Scenario: No positive stock locations
     */
    public function test_location_dropdown_empty_when_no_stock(): void
    {
        // Remove stock
        ProductStock::where('product_id', $this->product->id)->delete();

        Livewire::test(LocationSearchDropdownPerLine::class, [
                'index' => 0,
                'product_id' => $this->product->id
            ])
            ->assertViewHas('locations', function ($locations) {
                return $locations->isEmpty();
            });
    }

    /**
     * Scenario: Stock becomes unavailable before submit
     */
    public function test_submission_fails_if_stock_reaches_zero(): void
    {
        // Setup form with valid location (at first)
        $component = Livewire::actingAs($this->user)
            ->test(PurchaseReturnCreateForm::class)
            ->set('supplier_id', $this->supplier->id)
            ->set('rows', [
                [
                    'product_id' => $this->product->id,
                    'product_name' => $this->product->product_name,
                    'product_code' => $this->product->product_code,
                    'quantity' => 2,
                    'location_id' => $this->locationWithStock->id,
                    'purchase_price' => 10000,
                    'total' => 20000,
                    'serial_number_required' => false,
                ]
            ]);

        // Simulate stock disappearing
        ProductStock::where('product_id', $this->product->id)
            ->where('location_id', $this->locationWithStock->id)
            ->update(['quantity' => 0]);

        $component->call('submit')
            ->assertHasErrors(['rows.0.location_id']);
    }
}
