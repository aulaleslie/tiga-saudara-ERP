<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UpdateSaleValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createSetup()
    {
        $setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '1234567890',
            'site_logo' => null,
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => '123 Testing Lane',
        ]);

        $paymentTerm = PaymentTerm::create([
            'name' => 'Net 30',
            'longevity' => 30,
        ]);

        $customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '0800000000',
            'setting_id' => $setting->id,
            'payment_term_id' => $paymentTerm->id,
        ]);

        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TST-001',
            'setting_id' => $setting->id,
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 1500,
        ]);

        Permission::firstOrCreate(['name' => 'sales.edit']);

        $user = User::factory()->create();
        $user->givePermissionTo('sales.edit');

        $sale = Sale::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1500,
            'paid_amount' => 0,
            'due_amount' => 1500,
            'status' => 'Drafted',
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'note' => null,
            'payment_term_id' => $paymentTerm->id,
            'tax_id' => null,
            'setting_id' => $setting->id,
            'is_tax_included' => false,
        ]);

        return [$user, $sale, $setting];
    }

    /**
     * Test update passes when paid_amount equals new total_amount.
     */
    public function test_update_passes_when_paid_amount_equals_new_total_amount(): void
    {
        $this->withoutMiddleware(CheckUserRoleForSetting::class);
        [$user, $sale, $setting] = $this->createSetup();

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->put(route('sales.update', $sale), [
                'customer_id' => $sale->customer_id,
                'reference' => $sale->reference,
                'date' => now()->toDateString(),
                'tax_percentage' => 0,
                'discount_percentage' => 0,
                'shipping_amount' => 0,
                'total_amount' => 2000,
                'paid_amount' => 2000,
                'status' => 'Drafted',
                'payment_method' => 'cash',
                'note' => 'Update to 2000',
            ]);

        $response->assertRedirect(route('sales.index'));
        $sale->refresh();
        $this->assertEquals(2000, (float) $sale->total_amount);
        $this->assertEquals(2000, (float) $sale->paid_amount);
    }

    /**
     * Test update fails when paid_amount exceeds new total_amount.
     */
    public function test_update_fails_when_paid_amount_exceeds_new_total_amount(): void
    {
        $this->withoutMiddleware(CheckUserRoleForSetting::class);
        [$user, $sale, $setting] = $this->createSetup();

        // Old total was 1500. Now we set total to 1000 but paid_amount to 1200.
        // Previously this would PASS (incorrectly) because 1200 <= 1500.
        // After fix, it should FAIL because 1200 > 1000.
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->put(route('sales.update', $sale), [
                'customer_id' => $sale->customer_id,
                'reference' => $sale->reference,
                'date' => now()->toDateString(),
                'tax_percentage' => 0,
                'discount_percentage' => 0,
                'shipping_amount' => 0,
                'total_amount' => 1000,
                'paid_amount' => 1200,
                'status' => 'Drafted',
                'payment_method' => 'cash',
                'note' => 'Invalid update',
            ]);

        $response->assertSessionHasErrors(['paid_amount']);
    }
}
