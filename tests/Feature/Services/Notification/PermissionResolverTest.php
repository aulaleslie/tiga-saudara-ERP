<?php

namespace Tests\Feature\Services\Notification;

use App\Models\User;
use App\Services\Notification\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PermissionResolverTest extends TestCase
{
    use RefreshDatabase;

    protected PermissionResolver $resolver;
    protected Setting $setting1;
    protected Setting $setting2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new PermissionResolver();

        $this->setting1 = Setting::factory()->create([
            'company_name' => 'Test Business 1',
        ]);

        $this->setting2 = Setting::factory()->create([
            'company_name' => 'Test Business 2',
        ]);

        // Create the permission and role we will test
        Permission::firstOrCreate(['name' => 'notifications.lowStock']);
        Permission::firstOrCreate(['name' => 'purchases.approval']);

        $roleA = Role::firstOrCreate(['name' => 'Role A']); // Has low stock
        $roleA->givePermissionTo('notifications.lowStock');

        $roleB = Role::firstOrCreate(['name' => 'Role B']); // Has both
        $roleB->givePermissionTo('notifications.lowStock');
        $roleB->givePermissionTo('purchases.approval');

        Role::firstOrCreate(['name' => 'Super Admin']);
    }

    public function test_super_admin_always_has_permission()
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');

        $this->assertTrue($this->resolver->hasPermissionInSetting($superAdmin, $this->setting1->id, 'notifications.lowStock'));
        $this->assertTrue($this->resolver->hasPermissionInSetting($superAdmin, $this->setting2->id, 'notifications.lowStock'));

        $recipients1 = $this->resolver->getLowStockRecipients($this->setting1->id);
        $this->assertTrue($recipients1->contains('id', $superAdmin->id));

        $recipients2 = $this->resolver->getLowStockRecipients($this->setting2->id);
        $this->assertTrue($recipients2->contains('id', $superAdmin->id));
    }

    public function test_user_has_permission_only_in_assigned_settings()
    {
        $user = User::factory()->create();
        $roleA = Role::where('name', 'Role A')->first();

        // Assign user to Setting 1 with Role A
        $user->settings()->attach($this->setting1->id, ['role_id' => $roleA->id]);

        $this->assertTrue($this->resolver->hasPermissionInSetting($user, $this->setting1->id, 'notifications.lowStock'));
        $this->assertFalse($this->resolver->hasPermissionInSetting($user, $this->setting2->id, 'notifications.lowStock'));

        // Recipients test
        $recipients1 = $this->resolver->getLowStockRecipients($this->setting1->id);
        $this->assertTrue($recipients1->contains('id', $user->id));

        $recipients2 = $this->resolver->getLowStockRecipients($this->setting2->id);
        $this->assertFalse($recipients2->contains('id', $user->id));
    }

    public function test_user_with_role_without_permission()
    {
        $user = User::factory()->create();
        $roleWithoutPermission = Role::firstOrCreate(['name' => 'No Perm Role']);

        $user->settings()->attach($this->setting1->id, ['role_id' => $roleWithoutPermission->id]);

        $this->assertFalse($this->resolver->hasPermissionInSetting($user, $this->setting1->id, 'notifications.lowStock'));

        $recipients1 = $this->resolver->getLowStockRecipients($this->setting1->id);
        $this->assertFalse($recipients1->contains('id', $user->id));
    }

    public function test_user_can_have_different_permissions_across_settings()
    {
        $user = User::factory()->create();
        $roleA = Role::where('name', 'Role A')->first(); // Only lowStock
        $roleB = Role::where('name', 'Role B')->first(); // Has lowStock AND purchases.approval

        // Setting 1: Role A
        $user->settings()->attach($this->setting1->id, ['role_id' => $roleA->id]);
        
        // Setting 2: Role B
        $user->settings()->attach($this->setting2->id, ['role_id' => $roleB->id]);

        // Assert hasPermissionInSetting
        $this->assertTrue($this->resolver->hasPermissionInSetting($user, $this->setting1->id, 'notifications.lowStock'));
        $this->assertFalse($this->resolver->hasPermissionInSetting($user, $this->setting1->id, 'purchases.approval'));

        $this->assertTrue($this->resolver->hasPermissionInSetting($user, $this->setting2->id, 'notifications.lowStock'));
        $this->assertTrue($this->resolver->hasPermissionInSetting($user, $this->setting2->id, 'purchases.approval'));

        // Assert resolveRecipients
        $approvalRecipients1 = $this->resolver->getApprovalRecipients($this->setting1->id, 'purchases.approval');
        $this->assertFalse($approvalRecipients1->contains('id', $user->id));

        $approvalRecipients2 = $this->resolver->getApprovalRecipients($this->setting2->id, 'purchases.approval');
        $this->assertTrue($approvalRecipients2->contains('id', $user->id));
    }
}
