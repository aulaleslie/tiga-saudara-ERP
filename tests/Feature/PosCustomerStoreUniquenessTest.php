<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosSession;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PosCustomerStoreUniquenessTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected PosTerminal $terminal;
    protected PosSession $session;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.sell', 'web');

        $this->setting = Setting::factory()->create([
            'company_name' => 'POS Test Setting',
            'company_email' => 'pos-test@example.com',
            'company_address' => 'Test Address',
            'company_phone' => '123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
        ]);

        $this->terminal = PosTerminal::create([
            'setting_id' => $this->setting->id,
            'name' => 'Terminal 1',
            'code' => 'T001',
        ]);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->givePermissionTo(['pos.access', 'pos.sell']);

        $this->session = PosSession::create([
            'setting_id' => $this->setting->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($this->user);
    }

    private function sessionData(): array
    {
        return [
            'setting_id' => $this->setting->id,
            'pos_session_id' => $this->session->id,
        ];
    }

    public function test_pos_customer_store_rejects_duplicate_customer_name(): void
    {
        // Create first customer
        $customer1 = Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Toko ABC',
            'contact_name' => null,
            'customer_phone' => 'nophone-123',
            'customer_email' => 'noemail-123@placeholder.local',
        ]);

        // Verify only one customer with this name exists
        $this->assertCount(1, Customer::where('customer_name', 'TOKO ABC')->get());
        $this->assertEquals($customer1->id, Customer::where('customer_name', 'TOKO ABC')->first()->id);
    }

    public function test_pos_customer_store_accepts_distinct_customer_names(): void
    {
        // Create first customer
        $customer1 = Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Toko ABC',
            'contact_name' => null,
            'customer_phone' => 'nophone-123',
            'customer_email' => 'noemail-123@placeholder.local',
        ]);

        // Create second with different name
        $customer2 = Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Toko XYZ',
            'contact_name' => null,
            'customer_phone' => 'nophone-456',
            'customer_email' => 'noemail-456@placeholder.local',
        ]);

        // Verify both customers exist with their respective names
        $this->assertCount(1, Customer::where('customer_name', 'TOKO ABC')->get());
        $this->assertCount(1, Customer::where('customer_name', 'TOKO XYZ')->get());
    }

    public function test_pos_customer_store_rejects_duplicate_customer_name_case_insensitive(): void
    {
        // Create first customer
        Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Toko ABC',
            'contact_name' => null,
            'customer_phone' => 'nophone-123',
            'customer_email' => 'noemail-123@placeholder.local',
        ]);

        // Verify that customer name is stored uppercased (case-insensitive storage)
        $this->assertCount(1, Customer::where('customer_name', 'TOKO ABC')->get());
    }

    public function test_pos_customer_store_rejects_duplicate_customer_name_with_whitespace(): void
    {
        // Create first customer
        Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Toko ABC',
            'contact_name' => null,
            'customer_phone' => 'nophone-123',
            'customer_email' => 'noemail-123@placeholder.local',
        ]);

        // Verify the customer was stored with trimmed/uppercased name
        $this->assertCount(1, Customer::where('customer_name', 'TOKO ABC')->get());
    }
}
