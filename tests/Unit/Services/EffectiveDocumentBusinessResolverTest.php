<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\EffectiveDocumentBusinessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EffectiveDocumentBusinessResolverTest extends TestCase
{
    use RefreshDatabase;

    private EffectiveDocumentBusinessResolver $resolver;
    private Setting $setting1;
    private Setting $setting2;
    private Setting $setting3;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(EffectiveDocumentBusinessResolver::class);

        // Create settings using factory
        $this->setting1 = Setting::factory()->create(['is_pkp' => false]);
        $this->setting2 = Setting::factory()->create(['is_pkp' => true]);
        $this->setting3 = Setting::factory()->create(['is_pkp' => false]);

        // Create override permission
        Permission::create(['name' => 'documents.business.override']);

        // Create roles
        $role = Role::create(['name' => 'User']);
        $adminRole = Role::create(['name' => 'Super Admin']);

        // Create and authenticate user
        $this->user = User::factory()->create();
        $this->user->assignRole($role);
        $this->user->settings()->attach($this->setting1->id, ['role_id' => $role->id]);
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting1->id]);
    }

    public function test_returns_active_setting_without_permission()
    {
        $result = $this->resolver->resolve();

        $this->assertEquals($this->setting1->id, $result['setting_id']);
        $this->assertFalse($result['is_pkp']);
    }

    public function test_returns_active_setting_when_explicitly_requested()
    {
        // User has permission but requests active setting
        $this->user->givePermissionTo('documents.business.override');

        $result = $this->resolver->resolve($this->setting1->id);

        $this->assertEquals($this->setting1->id, $result['setting_id']);
        $this->assertFalse($result['is_pkp']);
    }

    public function test_authorized_user_can_select_accessible_business()
    {
        $role = Role::findByName('User');
        $this->user->givePermissionTo('documents.business.override');
        $this->user->settings()->attach($this->setting2->id, ['role_id' => $role->id]);

        $result = $this->resolver->resolve($this->setting2->id);

        $this->assertEquals($this->setting2->id, $result['setting_id']);
        $this->assertTrue($result['is_pkp']);
    }

    public function test_unprivileged_user_cannot_override_to_different_business()
    {
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $this->resolver->resolve($this->setting2->id);
    }

    public function test_privileged_user_cannot_access_unassigned_business()
    {
        $role = Role::findByName('User');
        $this->user->givePermissionTo('documents.business.override');
        $this->user->settings()->attach($this->setting2->id, ['role_id' => $role->id]);
        // User does not have access to setting3

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $this->resolver->resolve($this->setting3->id);
    }

    public function test_super_admin_can_access_all_businesses()
    {
        $adminRole = Role::findByName('Super Admin');
        $this->user->assignRole($adminRole);

        $result = $this->resolver->resolve($this->setting3->id);

        $this->assertEquals($this->setting3->id, $result['setting_id']);
        $this->assertFalse($result['is_pkp']);
    }

    public function test_returns_correct_pkp_state()
    {
        $role = Role::findByName('User');
        $this->user->givePermissionTo('documents.business.override');
        $this->user->settings()->attach($this->setting2->id, ['role_id' => $role->id]);

        // Setting 1 is non-PKP
        $result1 = $this->resolver->resolve($this->setting1->id);
        $this->assertFalse($result1['is_pkp']);

        // Setting 2 is PKP
        $result2 = $this->resolver->resolve($this->setting2->id);
        $this->assertTrue($result2['is_pkp']);
    }

    public function test_forged_request_without_permission_fails()
    {
        // User tries to forge a cross-business request without permission
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $this->resolver->resolve($this->setting2->id);
    }

    public function test_forged_setting_id_fails_even_with_permission()
    {
        $this->user->givePermissionTo('documents.business.override');

        // First check that user can't access an invalid setting
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $this->resolver->resolve(99999);
    }
}
