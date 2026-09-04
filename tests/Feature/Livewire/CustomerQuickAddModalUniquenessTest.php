<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Modules\People\Modals\CustomerQuickAddModal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class CustomerQuickAddModalUniquenessTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
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

        $this->setting = Setting::factory()->create([
            'company_name' => 'Test Setting',
            'company_email' => 'test@example.com',
            'company_address' => 'Test Address',
            'company_phone' => '123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
        ]);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->actingAs($this->user)->withSession(['setting_id' => $this->setting->id]);
    }

    public function test_customer_quick_add_modal_rejects_duplicate_customer_name(): void
    {
        // Create first customer
        $customer = Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Toko ABC',
            'contact_name' => 'Contact One',
            'customer_phone' => 'nophone-123',
            'customer_email' => 'noemail-123@placeholder.local',
        ]);

        // Try to create another with same name via Livewire
        Livewire::test(CustomerQuickAddModal::class)
            ->set('customer_name', 'Toko ABC')
            ->set('contact_name', 'Contact Two')
            ->call('save')
            ->assertHasErrors(['customer_name']);

        $this->assertCount(1, Customer::where('id', $customer->id)->get());
    }

    public function test_customer_quick_add_modal_accepts_distinct_customer_names(): void
    {
        // Create first customer
        $customerA = Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Toko ABC',
            'contact_name' => 'Contact One',
            'customer_phone' => 'nophone-123',
            'customer_email' => 'noemail-123@placeholder.local',
        ]);

        // Create second with different name via Livewire
        Livewire::test(CustomerQuickAddModal::class)
            ->set('customer_name', 'Toko XYZ')
            ->set('contact_name', 'Contact Two')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertCount(1, Customer::where('id', $customerA->id)->get());
        $this->assertCount(2, Customer::all());
    }

    public function test_customer_quick_add_modal_rejects_duplicate_customer_name_case_insensitive(): void
    {
        // Create first customer
        Customer::create([
            'setting_id' => $this->setting->id,
            'customer_name' => 'Toko ABC',
            'contact_name' => 'Contact One',
            'customer_phone' => 'nophone-123',
            'customer_email' => 'noemail-123@placeholder.local',
        ]);

        // Try with different casing
        Livewire::test(CustomerQuickAddModal::class)
            ->set('customer_name', 'toko abc')
            ->set('contact_name', 'Contact Two')
            ->call('save')
            ->assertHasErrors(['customer_name']);
    }
}
