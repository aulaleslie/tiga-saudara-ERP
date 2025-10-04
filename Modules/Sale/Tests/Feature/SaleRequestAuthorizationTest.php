<?php

namespace Modules\Sale\Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SaleRequestAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return Setting
     */
    private function createSetting(): Setting
    {
        return Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '1234567890',
            'site_logo' => null,
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => '123 Testing Lane',
            'document_prefix' => null,
            'purchase_prefix_document' => null,
            'sale_prefix_document' => null,
        ]);
    }

    private function createPaymentTerm(Setting $setting): PaymentTerm
    {
        return PaymentTerm::create([
            'name' => 'Net 30',
            'longevity' => 30,
        ]);
    }

    private function createCustomer(Setting $setting, PaymentTerm $paymentTerm): Customer
    {
        return Customer::factory()->create([
            'setting_id' => $setting->id,
            'payment_term_id' => $paymentTerm->id,
        ]);
    }

    public function test_user_with_sales_create_permission_can_submit_sale_store_request(): void
    {
        $this->withoutMiddleware(CheckUserRoleForSetting::class);

        $setting = $this->createSetting();
        $paymentTerm = $this->createPaymentTerm($setting);
        $customer = $this->createCustomer($setting, $paymentTerm);

        Permission::firstOrCreate(['name' => 'sales.create']);

        $user = User::factory()->create();
        $user->givePermissionTo('sales.create');

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.store'), [
                'customer_id' => $customer->id,
                'reference' => 'TEMP-REF',
                'date' => now()->toDateString(),
                'due_date' => now()->addDay()->toDateString(),
                'tax_id' => null,
                'discount_percentage' => 5,
                'discount_amount' => null,
                'shipping_amount' => 0,
                'total_amount' => 1000,
                'payment_term_id' => $paymentTerm->id,
                'note' => 'Test note',
            ]);

        $this->assertNotEquals(403, $response->status(), 'Authorized users should not receive a forbidden response when storing sales.');
    }

    public function test_user_with_sales_edit_permission_can_submit_sale_update_request(): void
    {
        $this->withoutMiddleware(CheckUserRoleForSetting::class);

        $setting = $this->createSetting();
        $paymentTerm = $this->createPaymentTerm($setting);
        $customer = $this->createCustomer($setting, $paymentTerm);

        Permission::firstOrCreate(['name' => 'sales.edit']);

        $user = User::factory()->create();
        $user->givePermissionTo('sales.edit');

        session(['setting_id' => $setting->id]);

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
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'Drafted',
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'note' => null,
            'payment_term_id' => $paymentTerm->id,
            'tax_id' => null,
            'setting_id' => $setting->id,
            'is_tax_included' => false,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->put(route('sales.update', $sale), [
                'customer_id' => $customer->id,
                'reference' => $sale->reference,
                'date' => now()->toDateString(),
                'tax_percentage' => 0,
                'discount_percentage' => 0,
                'shipping_amount' => 0,
                'total_amount' => 1000,
                'paid_amount' => 0,
                'status' => 'Drafted',
                'payment_method' => 'cash',
                'note' => 'Updated note',
            ]);

        $this->assertNotEquals(403, $response->status(), 'Authorized users should not receive a forbidden response when updating sales.');
    }
}
