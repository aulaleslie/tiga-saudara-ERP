<?php

namespace Modules\Setting\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class RowTotalRoundingSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create([
            'company_name' => 'Tiga Saudara Utama',
            'row_total_rounding_increment' => 100.00,
        ]);

        $this->user = User::factory()->create();
        
        // Grant settings permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        \Spatie\Permission\Models\Permission::findOrCreate('settings.access', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('settings.edit', 'web');
        $this->user->givePermissionTo(['settings.access', 'settings.edit']);

        session([
            'setting_id' => $this->setting->id,
            'user_settings' => collect([$this->setting]),
        ]);
    }

    public function test_setting_defaults_to_100_rounding_increment(): void
    {
        $newSetting = Setting::factory()->create();
        $this->assertEquals(100.00, $newSetting->row_total_rounding_increment);
    }

    public function test_authorized_user_can_update_rounding_increment(): void
    {
        $payload = [
            'company_name' => $this->setting->company_name,
            'company_email' => $this->setting->company_email,
            'company_phone' => $this->setting->company_phone,
            'document_prefix' => 'TS',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'company_address' => $this->setting->company_address,
            'row_total_rounding_increment' => 500.00,
        ];

        $response = $this->actingAs($this->user)->patch(route('settings.update'), $payload);

        $response->assertRedirect(route('settings.index'));
        $this->setting->refresh();
        $this->assertEquals(500.00, $this->setting->row_total_rounding_increment);
    }

    public function test_user_can_set_zero_increment_to_disable_rounding(): void
    {
        $payload = [
            'company_name' => $this->setting->company_name,
            'company_email' => $this->setting->company_email,
            'company_phone' => $this->setting->company_phone,
            'document_prefix' => 'TS',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'company_address' => $this->setting->company_address,
            'row_total_rounding_increment' => 0.00,
        ];

        $response = $this->actingAs($this->user)->patch(route('settings.update'), $payload);

        $response->assertRedirect(route('settings.index'));
        $this->setting->refresh();
        $this->assertEquals(0.00, $this->setting->row_total_rounding_increment);
    }

    public function test_negative_rounding_increment_is_rejected(): void
    {
        $payload = [
            'company_name' => $this->setting->company_name,
            'company_email' => $this->setting->company_email,
            'company_phone' => $this->setting->company_phone,
            'document_prefix' => 'TS',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'company_address' => $this->setting->company_address,
            'row_total_rounding_increment' => -50.00,
        ];

        $response = $this->actingAs($this->user)->patch(route('settings.update'), $payload);

        $response->assertSessionHasErrors('row_total_rounding_increment');
    }

    public function test_business_configuration_isolation(): void
    {
        $setting2 = Setting::factory()->create([
            'company_name' => 'Tiga Saudara Cabang',
            'row_total_rounding_increment' => 50.00,
        ]);

        $this->assertEquals(100.00, $this->setting->fresh()->row_total_rounding_increment);
        $this->assertEquals(50.00, $setting2->fresh()->row_total_rounding_increment);
    }
}
