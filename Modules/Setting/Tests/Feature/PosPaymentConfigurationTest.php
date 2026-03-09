<?php

namespace Modules\Setting\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingPosPaymentMethod;
use Tests\TestCase;

class PosPaymentConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        \Spatie\Permission\Models\Permission::findOrCreate('paymentMethods.access', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('paymentMethods.edit', 'web');

        $this->user = User::factory()->create();
        $this->user->givePermissionTo('paymentMethods.access');
        $this->user->givePermissionTo('paymentMethods.edit');
        
        $this->setting = Setting::factory()->create();
        $this->actingAs($this->user);
        
        // Mock session for setting_id
        session(['setting_id' => $this->setting->id]);
        
        $coa = \Modules\Setting\Entities\ChartOfAccount::create([
            'account_number' => '1101',
            'name' => 'Cash on Hand',
            'category' => 'Kas & Bank',
            'setting_id' => $this->setting->id,
        ]);
        
        $this->paymentMethod = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $coa->id,
            'is_cash' => true,
            'requires_reference' => false,
        ]);
        
        // Ensure the pivot record exists (it should be created via model hook)
        SettingPosPaymentMethod::updateOrCreate(
            ['setting_id' => $this->setting->id, 'payment_method_id' => $this->paymentMethod->id],
            ['is_enabled' => false]
        );
    }

    /** @test */
    public function it_can_access_the_index_page()
    {
        $response = $this->get(route('pos-payment-configurations.index'));

        $response->assertStatus(200);
        $response->assertSee('Konfigurasi Pembayaran POS');
        $response->assertSee('CASH');
    }

    /** @test */
    public function it_can_toggle_payment_method_status()
    {
        $response = $this->patch(route('pos-payment-configurations.toggle', $this->paymentMethod->id), [
            'is_enabled' => 1
        ]);

        $response->assertRedirect(route('pos-payment-configurations.index'));
        $this->assertDatabaseHas('setting_pos_payment_methods', [
            'setting_id' => $this->setting->id,
            'payment_method_id' => $this->paymentMethod->id,
            'is_enabled' => true
        ]);

        $response = $this->patch(route('pos-payment-configurations.toggle', $this->paymentMethod->id), [
            'is_enabled' => 0
        ]);

        $response->assertRedirect(route('pos-payment-configurations.index'));
        $this->assertDatabaseHas('setting_pos_payment_methods', [
            'setting_id' => $this->setting->id,
            'payment_method_id' => $this->paymentMethod->id,
            'is_enabled' => false
        ]);
    }

    /** @test */
    public function it_can_bulk_enable_and_disable()
    {
        $coa = \Modules\Setting\Entities\ChartOfAccount::first();
        PaymentMethod::create(['name' => 'Bank Transfer', 'coa_id' => $coa->id, 'is_cash' => false]);

        $response = $this->post(route('pos-payment-configurations.bulkEnable'));
        $response->assertRedirect(route('pos-payment-configurations.index'));
        
        $this->assertEquals(2, SettingPosPaymentMethod::where('setting_id', $this->setting->id)->where('is_enabled', true)->count());

        $response = $this->post(route('pos-payment-configurations.bulkDisable'));
        $response->assertRedirect(route('pos-payment-configurations.index'));
        
        $this->assertEquals(0, SettingPosPaymentMethod::where('setting_id', $this->setting->id)->where('is_enabled', true)->count());
    }

    /** @test */
    public function it_denies_access_without_permission()
    {
        $unauthorizedUser = User::factory()->create();
        $this->actingAs($unauthorizedUser);

        $response = $this->get(route('pos-payment-configurations.index'));
        $response->assertStatus(403);
    }
}
