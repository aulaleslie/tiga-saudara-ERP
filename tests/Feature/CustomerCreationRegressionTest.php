<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerCreationRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['customers.create', 'customers.edit', 'customers.access'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_admin_form_allows_multiple_customers_without_email(): void
    {
        $setting = $this->createSetting('Customer Null Email Test');
        $user = $this->createUserWithCustomerPermissions();

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        $firstResponse = $this->post(route('customers.store'), [
            'customer_name' => 'Dummy Name', 'contact_name' => 'Pelanggan Satu',
            'customer_phone' => '081230000001',
            'customer_email' => '',
            'identity_number' => '',
            'npwp' => '',
        ]);

        $firstResponse->assertRedirect(route('customers.index'));

        $secondResponse = $this->post(route('customers.store'), [
            'customer_name' => 'Dummy Name', 'contact_name' => 'Pelanggan Dua',
            'customer_phone' => '081230000002',
            'customer_email' => '',
            'identity_number' => '',
            'npwp' => '',
        ]);

        $secondResponse->assertRedirect(route('customers.index'));

        $customers = Customer::query()
            ->where('setting_id', $setting->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $customers);
        $this->assertNull($customers[0]->customer_email);
        $this->assertNull($customers[0]->identity_number);
        $this->assertNull($customers[0]->npwp);
        $this->assertNull($customers[1]->customer_email);
        $this->assertNull($customers[1]->identity_number);
        $this->assertNull($customers[1]->npwp);
    }

    public function test_duplicate_phone_constraint_violation_returns_field_error(): void
    {
        $setting = $this->createSetting('Customer Duplicate Phone Test');
        $user = $this->createUserWithCustomerPermissions();

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);
        Log::spy();

        Customer::saving(function () {
            $previous = new \PDOException(
                "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '081230000003' for key 'customers_setting_phone_unique'",
                '23000'
            );
            $previous->errorInfo = [
                '23000',
                1062,
                "Duplicate entry '081230000003' for key 'customers_setting_phone_unique'",
            ];

            throw new QueryException(
                'sqlite',
                'insert into "customers"',
                [],
                $previous
            );
        });

        $response = $this->from(route('customers.create'))->post(route('customers.store'), [
            'customer_name' => 'Dummy Name', 'contact_name' => 'Pelanggan Tiga',
            'customer_phone' => '081230000003',
            'customer_email' => '',
        ]);

        $response
            ->assertRedirect(route('customers.create'))
            ->assertSessionHasErrors([
                'customer_phone' => 'Nomor telepon sudah digunakan.',
            ])
            ->assertSessionMissing('alert.config');

        Log::shouldHaveReceived('error')->withArgs(function ($message, $context) {
            return str_contains($message, 'Customer creation failed')
                && isset($context['payload']['customer_phone'])
                && $context['payload']['customer_phone'] === '081230000003';
        })->once();
    }

    private function createUserWithCustomerPermissions(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['customers.create', 'customers.edit', 'customers.access']);

        return $user;
    }

    private function createSetting(string $name): Setting
    {
        return Setting::create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
        ]);
    }
}
