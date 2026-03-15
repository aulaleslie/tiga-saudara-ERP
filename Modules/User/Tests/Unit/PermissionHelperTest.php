<?php

namespace Modules\User\Tests\Unit;

use Modules\User\Helpers\PermissionHelper;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PermissionHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Run migrations for tests
        $this->artisan('migrate:fresh');
    }

    /**
     * Test getGroupsForForm returns all permission groups
     */
    public function test_get_groups_for_form_returns_all_groups()
    {
        $groups = PermissionHelper::getGroupsForForm();

        $this->assertIsArray($groups);
        $this->assertNotEmpty($groups);

        // Check that groups exist
        $this->assertArrayHasKey('Adjustments', $groups);
        $this->assertArrayHasKey('Penjualan', $groups);
    }

    /**
     * Test getGroupsForForm structure without role
     */
    public function test_get_groups_for_form_structure()
    {
        $groups = PermissionHelper::getGroupsForForm();

        foreach ($groups as $groupName => $permissions) {
            $this->assertIsArray($permissions);

            foreach ($permissions as $permKey => $data) {
                $this->assertIsArray($data);
                $this->assertArrayHasKey('label', $data);
                $this->assertArrayHasKey('checked', $data);
                $this->assertIsBool($data['checked']);
                // Without a role, all should be unchecked
                $this->assertFalse($data['checked']);
            }
        }
    }

    /**
     * Test getGroupsForForm with role pre-checks permissions
     */
    public function test_get_groups_for_form_with_role_pre_checks()
    {
        $role = Role::create(['name' => 'test-role']);

        // Get permissions from config
        $allPerms = PermissionHelper::getAllPermissionKeys();
        $permsToAssign = array_slice($allPerms, 0, 3);

        // Assign some permissions to role
        foreach ($permsToAssign as $perm) {
            $permission = \Spatie\Permission\Models\Permission::findOrCreate($perm);
            $role->givePermissionTo($permission);
        }

        $groups = PermissionHelper::getGroupsForForm($role);

        // Check that assigned permissions are marked as checked
        $checkedCount = 0;
        foreach ($groups as $permissions) {
            foreach ($permissions as $permKey => $data) {
                if (in_array($permKey, $permsToAssign)) {
                    $this->assertTrue($data['checked'], "Permission {$permKey} should be checked");
                    $checkedCount++;
                } else {
                    $this->assertFalse($data['checked'], "Permission {$permKey} should not be checked");
                }
            }
        }

        $this->assertEquals($checkedCount, count($permsToAssign));

        $role->delete();
    }

    /**
     * Test getGroups returns raw configuration
     */
    public function test_get_groups_returns_raw_config()
    {
        $groups = PermissionHelper::getGroups();

        $this->assertIsArray($groups);
        $this->assertNotEmpty($groups);

        // Check structure - should be group => [permission => label]
        foreach ($groups as $groupName => $permissions) {
            $this->assertIsArray($permissions);
            foreach ($permissions as $permKey => $label) {
                $this->assertIsString($label);
            }
        }
    }

    /**
     * Test getAllPermissions flattens groups
     */
    public function test_get_all_permissions_flattens_groups()
    {
        $permissions = PermissionHelper::getAllPermissions();

        $this->assertIsArray($permissions);
        $this->assertNotEmpty($permissions);

        // Should have permission keys as keys and labels as values
        foreach ($permissions as $key => $label) {
            $this->assertIsString($key);
            $this->assertIsString($label);
        }
    }

    /**
     * Test getAllPermissionKeys returns only keys
     */
    public function test_get_all_permission_keys()
    {
        $keys = PermissionHelper::getAllPermissionKeys();

        $this->assertIsArray($keys);
        $this->assertNotEmpty($keys);

        // Should have specific permissions
        $this->assertContains('adjustments.access', $keys);
        $this->assertContains('adjustments.create', $keys);
    }

    /**
     * Test isValidPermission checks existence
     */
    public function test_is_valid_permission()
    {
        $this->assertTrue(PermissionHelper::isValidPermission('adjustments.access'));
        $this->assertFalse(PermissionHelper::isValidPermission('nonexistent.permission'));
    }

    /**
     * Test getPermissionLabel retrieves correct label
     */
    public function test_get_permission_label()
    {
        $label = PermissionHelper::getPermissionLabel('adjustments.access');

        $this->assertNotNull($label);
        $this->assertIsString($label);
    }

    /**
     * Test getPermissionLabel returns null for invalid permission
     */
    public function test_get_permission_label_invalid()
    {
        $label = PermissionHelper::getPermissionLabel('nonexistent.permission');

        $this->assertNull($label);
    }

    /**
     * Test getPermissionGroup finds correct group
     */
    public function test_get_permission_group()
    {
        $group = PermissionHelper::getPermissionGroup('adjustments.access');

        $this->assertEquals('Adjustments', $group);
    }

    /**
     * Test getPermissionGroup returns null for invalid permission
     */
    public function test_get_permission_group_invalid()
    {
        $group = PermissionHelper::getPermissionGroup('nonexistent.permission');

        $this->assertNull($group);
    }
}
