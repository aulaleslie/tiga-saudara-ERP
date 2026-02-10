<?php

namespace Modules\Sale\Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StandardSaleLocationScopeRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Config::set('scout.driver', null);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createSetting(string $name): Setting
    {
        // Ensure a currency exists
        if (\Modules\Currency\Entities\Currency::count() === 0) {
             \Modules\Currency\Entities\Currency::create([
                'currency_name' => 'Rupiah',
                'code' => 'IDR',
                'symbol' => 'Rp',
                'thousand_separator' => '.',
                'decimal_separator' => ',',
                'exchange_rate' => 1,
            ]);
        }
        
        return Setting::create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'company_address' => '123 Testing Lane',
            'footer_text' => 'Default Footer Text', // Added missing required field
        ]);
    }

    public function test_standard_sale_dispatch_only_shows_owned_locations(): void
    {
        $this->withoutMiddleware(CheckUserRoleForSetting::class);

        // 1. Setup two settings
        $ownerSetting = $this->createSetting('Owner Company');
        $borrowedSetting = $this->createSetting('Borrowed Company');

        // 2. Create locations
        $ownedLocation1 = Location::create([
            'setting_id' => $ownerSetting->id,
            'name' => 'Owned Location 1', 
        ]);
        
        $ownedLocation2 = Location::create([
            'setting_id' => $ownerSetting->id,
            'name' => 'Owned Location 2',
        ]);

        $borrowedLocation = Location::create([
            'setting_id' => $borrowedSetting->id,
            'name' => 'Borrowed Location',
        ]);

        // 3. Assign borrowedLocation to ownerSetting (Simulate POS sharing)
        // Must delete default assignment first due to unique constraint
        SettingSaleLocation::where('location_id', $borrowedLocation->id)->delete();
        
        SettingSaleLocation::create([
            'setting_id' => $ownerSetting->id,
            'location_id' => $borrowedLocation->id,
            'position' => 1
        ]);

        // 4. Create User and Permissions
        Permission::firstOrCreate(['name' => 'sales.dispatch']);
        $user = User::factory()->create();
        $user->givePermissionTo('sales.dispatch');

        // 5. Create Customer and Sale
        $customer = Customer::create([
            'customer_name' => 'Guest',
            'customer_email' => 'guest@example.com',
            'customer_phone' => '00000',
            'setting_id' => $ownerSetting->id,
            'address' => 'Address'
        ]);

        $sale = Sale::create([
            'setting_id' => $ownerSetting->id,
            'date' => now(),
            'reference' => 'SALE-001',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash'
        ]);

        // 6. Act: Visit Dispatch Page
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $ownerSetting->id])
            ->get(route('sales.dispatch', $sale));

        // 7. Assert: Only owned locations are visible in the view data
        $response->assertStatus(200);
        
        $viewLocations = $response->viewData('locations');
        
        $this->assertTrue($viewLocations->contains('id', $ownedLocation1->id));
        $this->assertTrue($viewLocations->contains('id', $ownedLocation2->id));
        $this->assertFalse($viewLocations->contains('id', $borrowedLocation->id), 'Borrowed location should NOT be visible in standard sale dispatch');
    }

    public function test_standard_sale_dispatch_submitting_borrowed_location_fails_validation(): void
    {
        $this->withoutMiddleware(CheckUserRoleForSetting::class);

        // 1. Setup two settings
        $ownerSetting = $this->createSetting('Owner Company');
        $borrowedSetting = $this->createSetting('Borrowed Company');

        // 2. Create locations and Product
        $ownedLocation = Location::create([
            'setting_id' => $ownerSetting->id,
            'name' => 'Owned Location',
        ]);

        $borrowedLocation = Location::create([
            'setting_id' => $borrowedSetting->id,
            'name' => 'Borrowed Location',
        ]);

        // 3. Assign borrowedLocation to ownerSetting (Simulate POS sharing)
        SettingSaleLocation::where('location_id', $borrowedLocation->id)->delete();

        SettingSaleLocation::create([
            'setting_id' => $ownerSetting->id,
            'location_id' => $borrowedLocation->id,
            'position' => 1
        ]);
        
        
        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'P001',
            'product_quantity' => 10,
            'product_cost' => 50,
            'product_price' => 100,
            'product_unit' => 'pcs',
            'product_stock_alert' => 5,
            'setting_id' => $ownerSetting->id,
        ]);

        // 3. User & Permissions
        Permission::firstOrCreate(['name' => 'sales.dispatch']);
        $user = User::factory()->create();
        $user->settings()->attach($ownerSetting->id, ['role_id' => 1]); // Assuming role_id 1
        $user->givePermissionTo('sales.dispatch');

        // 4. Create Sale with Detail
        $customer = Customer::create([
            'customer_name' => 'Guest',
            'customer_email' => 'guest@example.com',
            'customer_phone' => '00000',
            'setting_id' => $ownerSetting->id,
            'address' => 'Address'
        ]);

        $sale = Sale::create([
            'setting_id' => $ownerSetting->id,
            'date' => now(),
            'reference' => 'SALE-002',
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash'
        ]);
        
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 100,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        // 5. Act: Attempt to dispatch using Borrowed Location
        // Composite key format: product_id-tax_id-bundle_id
        $compositeKey = $product->id . '--0'; 

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $ownerSetting->id])
            ->post(route('sales.storeDispatch', $sale), [
                'dispatch_date' => now()->toDateString(),
                'dispatchedQuantities' => [
                    $compositeKey => 1
                ],
                'selectedLocations' => [
                    $compositeKey => $borrowedLocation->id // <--- TRYING TO USE BORROWED LOCATION
                ],
                'selectedSerialNumbers' => [],
                'serialNumberLocations' => [],
            ]);

        // 6. Assert: Should fail validation
        $response->assertSessionHasErrors(["selectedLocations.$compositeKey" => "Lokasi tidak valid untuk bisnis ini."]);
    }
}
