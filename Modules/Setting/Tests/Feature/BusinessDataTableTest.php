<?php

namespace Modules\Setting\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BusinessDataTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'currency_name'      => 'Rupiah',
            'code'               => 'IDR',
            'symbol'             => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator'  => ',',
            'exchange_rate'      => 1,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::findOrCreate('businesses.access', 'web');
        Role::findOrCreate('Super Admin', 'web');
        Role::findOrCreate('Staff', 'web');
    }

    private function createSetting(string $name): Setting
    {
        return Setting::create([
            'company_name'              => $name,
            'company_email'             => strtolower(str_replace(' ', '', $name)) . '@example.com',
            'company_phone'             => '0800000000',
            'default_currency_id'       => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email'        => 'notify@example.com',
            'footer_text'               => 'Footer',
            'company_address'           => 'Address',
        ]);
    }

    private function createSuperAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Super Admin');
        $user->givePermissionTo('businesses.access');

        return $user;
    }

    private function createStandardUser(Setting $setting): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Staff');
        $user->givePermissionTo('businesses.access');
        $user->settings()->attach($setting->id, ['role_id' => Role::findByName('Staff')->id]);

        return $user;
    }

    public function test_super_admin_can_retrieve_all_businesses_via_ajax(): void
    {
        $settingA = $this->createSetting('Biz Super A');
        $settingB = $this->createSetting('Biz Super B');

        $superAdmin = $this->createSuperAdmin();

        $response = $this->actingAs($superAdmin)
            ->withSession(['setting_id' => $settingA->id])
            ->get(route('businesses.index'), [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertStatus(200);
        $content = $response->json();

        $this->assertArrayHasKey('data', $content);
        $companyNames = collect($content['data'])->pluck('company_name')->all();

        $this->assertContains('BIZ SUPER A', $companyNames);
        $this->assertContains('BIZ SUPER B', $companyNames);
    }

    public function test_non_super_admin_receives_only_assigned_businesses_via_ajax(): void
    {
        $settingAssigned = $this->createSetting('Assigned Biz');
        $settingUnassigned = $this->createSetting('Unassigned Biz');

        $user = $this->createStandardUser($settingAssigned);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $settingAssigned->id])
            ->get(route('businesses.index'), [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertStatus(200);
        $content = $response->json();

        $this->assertArrayHasKey('data', $content);
        $companyNames = collect($content['data'])->pluck('company_name')->all();

        $this->assertContains('ASSIGNED BIZ', $companyNames);
        $this->assertNotContains('UNASSIGNED BIZ', $companyNames);
    }

    public function test_default_sorting_by_id_succeeds_without_sql_ambiguity_error(): void
    {
        $setting1 = $this->createSetting('Biz First');
        $setting2 = $this->createSetting('Biz Second');

        $user = $this->createStandardUser($setting1);
        $user->settings()->attach($setting2->id, ['role_id' => Role::findByName('Staff')->id]);

        // Simulating DataTables ordering by column 0 ('id') asc
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting1->id])
            ->get(route('businesses.index', [
                'draw' => 1,
                'order' => [
                    ['column' => 0, 'dir' => 'asc'],
                ],
                'columns' => [
                    ['data' => 'id', 'name' => 'id', 'orderable' => 'true'],
                    ['data' => 'company_name', 'name' => 'company_name', 'orderable' => 'true'],
                    ['data' => 'action', 'name' => 'action', 'orderable' => 'false'],
                    ['data' => 'created_at', 'name' => 'created_at', 'orderable' => 'true'],
                ],
            ]), [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertStatus(200);
        $content = $response->json();

        $this->assertArrayHasKey('data', $content);
        $this->assertCount(2, $content['data']);
        $this->assertEquals('BIZ FIRST', $content['data'][0]['company_name']);
        $this->assertEquals('BIZ SECOND', $content['data'][1]['company_name']);
    }

    public function test_unassigned_business_is_not_returned(): void
    {
        $setting1 = $this->createSetting('Accessible Biz');
        $setting2 = $this->createSetting('Inaccessible Biz');

        $user = $this->createStandardUser($setting1);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting1->id])
            ->get(route('businesses.index'), [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertStatus(200);
        $content = $response->json();

        $ids = collect($content['data'])->pluck('id')->all();
        $this->assertContains($setting1->id, $ids);
        $this->assertNotContains($setting2->id, $ids);
    }
}
