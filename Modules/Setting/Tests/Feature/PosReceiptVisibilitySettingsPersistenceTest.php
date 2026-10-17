<?php

namespace Modules\Setting\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PosReceiptVisibilitySettingsPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create([
            'company_name' => 'Tiga Saudara POS Test',
            'pos_receipt_show_note' => false,
            'pos_receipt_show_return_history' => false,
            'pos_receipt_show_price_adjustments' => false,
            'pos_receipt_show_ppn' => false,
            'pos_receipt_show_signature_space' => true,
        ]);

        $this->user = User::factory()->create();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        \Spatie\Permission\Models\Permission::findOrCreate('settings.access', 'web');
        \Spatie\Permission\Models\Permission::findOrCreate('settings.edit', 'web');
        $this->user->givePermissionTo(['settings.access', 'settings.edit']);

        session([
            'setting_id' => $this->setting->id,
            'user_settings' => collect([$this->setting]),
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => $this->setting->company_name,
            'company_email' => $this->setting->company_email,
            'company_phone' => $this->setting->company_phone,
            'document_prefix' => 'TS',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'company_address' => $this->setting->company_address,
            'row_total_rounding_increment' => 100.00,
            'pos_enabled' => 1,
            'pos_receipt_show_note' => 1,
            'pos_receipt_show_return_history' => 1,
            'pos_receipt_show_price_adjustments' => 1,
            'pos_receipt_show_ppn' => 1,
            'pos_receipt_show_signature_space' => 1,
        ], $overrides);
    }

    public function test_authorized_user_can_save_receipt_visibility_settings_and_sync_session(): void
    {
        Cache::put('settings_' . $this->setting->id, 'cached_value', 60);

        $payload = $this->validPayload();

        $response = $this->actingAs($this->user)->patch(route('settings.update'), $payload);

        $response->assertRedirect(route('settings.index'));

        $fresh = $this->setting->fresh();
        $this->assertTrue($fresh->pos_receipt_show_note);
        $this->assertTrue($fresh->pos_receipt_show_return_history);
        $this->assertTrue($fresh->pos_receipt_show_price_adjustments);
        $this->assertTrue($fresh->pos_receipt_show_ppn);
        $this->assertTrue($fresh->pos_receipt_show_signature_space);

        // Check session sync
        $userSettings = session('user_settings');
        $syncedItem = collect($userSettings)->firstWhere('id', $this->setting->id);
        $this->assertNotNull($syncedItem);
        $this->assertTrue((bool) data_get($syncedItem, 'pos_receipt_show_note'));
        $this->assertTrue((bool) data_get($syncedItem, 'pos_receipt_show_return_history'));
        $this->assertTrue((bool) data_get($syncedItem, 'pos_receipt_show_price_adjustments'));
        $this->assertTrue((bool) data_get($syncedItem, 'pos_receipt_show_ppn'));
        $this->assertTrue((bool) data_get($syncedItem, 'pos_receipt_show_signature_space'));

        // Cache invalidated
        $this->assertFalse(Cache::has('settings_' . $this->setting->id));
    }

    public function test_unchecked_signature_space_saves_false_rather_than_reverting_to_true(): void
    {
        $this->setting->update(['pos_receipt_show_signature_space' => true]);

        // When a checkbox is unchecked in browser, it is absent from the POST request
        $payload = $this->validPayload();
        unset($payload['pos_receipt_show_signature_space']);

        $response = $this->actingAs($this->user)->patch(route('settings.update'), $payload);

        $response->assertRedirect(route('settings.index'));

        $fresh = $this->setting->fresh();
        $this->assertFalse($fresh->pos_receipt_show_signature_space);

        $userSettings = session('user_settings');
        $syncedItem = collect($userSettings)->firstWhere('id', $this->setting->id);
        $this->assertFalse((bool) data_get($syncedItem, 'pos_receipt_show_signature_space'));
    }

    public function test_invalid_boolean_input_is_rejected(): void
    {
        $payload = $this->validPayload([
            'pos_receipt_show_note' => 'invalid-not-a-bool',
        ]);

        $response = $this->actingAs($this->user)->patch(route('settings.update'), $payload);

        $response->assertSessionHasErrors('pos_receipt_show_note');
        $this->assertFalse($this->setting->fresh()->pos_receipt_show_note);
    }

    public function test_unauthorized_user_cannot_update_settings(): void
    {
        $unauthorizedUser = User::factory()->create();

        $payload = $this->validPayload();

        $response = $this->actingAs($unauthorizedUser)->patch(route('settings.update'), $payload);

        $response->assertStatus(403);
        $this->assertFalse($this->setting->fresh()->pos_receipt_show_note);
    }

    public function test_isolation_between_two_businesses(): void
    {
        $setting2 = Setting::factory()->create([
            'company_name' => 'Bisnis Cabang',
            'pos_receipt_show_note' => false,
            'pos_receipt_show_return_history' => false,
            'pos_receipt_show_price_adjustments' => false,
            'pos_receipt_show_ppn' => false,
            'pos_receipt_show_signature_space' => true,
        ]);

        $payload = $this->validPayload([
            'pos_receipt_show_note' => 1,
            'pos_receipt_show_ppn' => 1,
            'pos_receipt_show_signature_space' => 0,
        ]);

        $response = $this->actingAs($this->user)->patch(route('settings.update'), $payload);
        $response->assertRedirect(route('settings.index'));

        // Setting 1 updated
        $this->assertTrue($this->setting->fresh()->pos_receipt_show_note);
        $this->assertTrue($this->setting->fresh()->pos_receipt_show_ppn);
        $this->assertFalse($this->setting->fresh()->pos_receipt_show_signature_space);

        // Setting 2 completely unchanged
        $fresh2 = $setting2->fresh();
        $this->assertFalse($fresh2->pos_receipt_show_note);
        $this->assertFalse($fresh2->pos_receipt_show_ppn);
        $this->assertTrue($fresh2->pos_receipt_show_signature_space);
    }

    public function test_receipt_visibility_settings_editable_when_pos_disabled(): void
    {
        $payload = $this->validPayload([
            'pos_enabled' => 0,
            'pos_receipt_show_note' => 1,
            'pos_receipt_show_ppn' => 1,
        ]);

        $response = $this->actingAs($this->user)->patch(route('settings.update'), $payload);
        $response->assertRedirect(route('settings.index'));

        $fresh = $this->setting->fresh();
        $this->assertFalse($fresh->pos_enabled);
        $this->assertTrue($fresh->pos_receipt_show_note);
        $this->assertTrue($fresh->pos_receipt_show_ppn);
    }

    public function test_settings_index_displays_pengaturan_pos_grouping_and_default_receipt_switches(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession([
                'setting_id' => $this->setting->id,
                'user_settings' => collect([$this->setting]),
            ])
            ->get(route('settings.index'));

        $response->assertStatus(200);
        $response->assertSee('Pengaturan POS');
        $response->assertSee('Tampilan Struk POS');
        $response->assertSee('pos_receipt_show_note');
        $response->assertSee('pos_receipt_show_return_history');
        $response->assertSee('pos_receipt_show_price_adjustments');
        $response->assertSee('pos_receipt_show_ppn');
        $response->assertSee('pos_receipt_show_signature_space');

        $content = $response->getContent();
        // Signature space should be checked by default
        $this->assertMatchesRegularExpression('/id="pos_receipt_show_signature_space"[^>]*checked/', $content);
        // Note should not be checked by default
        $this->assertDoesNotMatchRegularExpression('/id="pos_receipt_show_note"[^>]*checked/', $content);
    }

    public function test_settings_index_displays_saved_values(): void
    {
        $this->setting->update([
            'pos_receipt_show_note' => true,
            'pos_receipt_show_signature_space' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession([
                'setting_id' => $this->setting->id,
                'user_settings' => collect([$this->setting]),
            ])
            ->get(route('settings.index'));

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertMatchesRegularExpression('/id="pos_receipt_show_note"[^>]*checked/', $content);
        $this->assertDoesNotMatchRegularExpression('/id="pos_receipt_show_signature_space"[^>]*checked/', $content);
    }

    public function test_validation_failure_redisplays_old_switch_inputs(): void
    {
        // Submit invalid payload (e.g. company_name is missing)
        $payload = $this->validPayload([
            'company_name' => '',
            'pos_receipt_show_note' => '1',
            'pos_receipt_show_signature_space' => '0',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession([
                'setting_id' => $this->setting->id,
                'user_settings' => collect([$this->setting]),
            ])
            ->from(route('settings.index'))
            ->patch(route('settings.update'), $payload);

        $response->assertRedirect(route('settings.index'));
        $response->assertSessionHasErrors('company_name');

        // Follow redirect to inspect rendered page with old input
        $redirectResponse = $this->actingAs($this->user)
            ->withSession([
                'setting_id' => $this->setting->id,
                'user_settings' => collect([$this->setting]),
            ])
            ->get(route('settings.index'));

        $content = $redirectResponse->getContent();
        $this->assertMatchesRegularExpression('/id="pos_receipt_show_note"[^>]*checked/', $content);
        $this->assertDoesNotMatchRegularExpression('/id="pos_receipt_show_signature_space"[^>]*checked/', $content);
    }

    public function test_customer_search_preserves_behavior_under_grouped_settings(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession([
                'setting_id' => $this->setting->id,
            ])
            ->getJson(route('settings.customers.search', ['q' => 'Test']));

        $response->assertStatus(200);
        $response->assertJsonStructure(['results']);
    }
}
