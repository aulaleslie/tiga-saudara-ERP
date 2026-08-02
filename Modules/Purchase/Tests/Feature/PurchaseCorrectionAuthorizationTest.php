<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseCorrectionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $superAdmin;
    private Setting $setting;
    private Setting $otherSetting;
    private Purchase $receivedPurchase;
    private Purchase $receivedPartiallyPurchase;
    private Purchase $draftedPurchase;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::findOrCreate('purchases.received.correct', 'web');

        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $userRole = Role::firstOrCreate(['name' => 'Finance', 'guard_name' => 'web']);
        $userRole->givePermissionTo('purchases.received.correct');

        $this->setting = Setting::factory()->create();
        $this->otherSetting = Setting::factory()->create();

        $this->user = User::factory()->create(['is_active' => 1]);
        $this->user->assignRole($userRole);
        $this->user->settings()->attach($this->setting->id, ['role_id' => $userRole->id]);

        $this->superAdmin = User::factory()->create(['is_active' => 1]);
        $this->superAdmin->assignRole($superAdminRole);
        $this->superAdmin->settings()->attach($this->setting->id, ['role_id' => $superAdminRole->id]);

        $supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->receivedPurchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 10000,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
        ]);

        $this->receivedPartiallyPurchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-002',
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_RECEIVED_PARTIALLY,
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'total_amount' => 20000,
            'paid_amount' => 10000,
            'due_amount' => 10000,
            'setting_id' => $this->setting->id,
        ]);

        $this->draftedPurchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-003',
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 30000,
            'paid_amount' => 0,
            'due_amount' => 30000,
            'setting_id' => $this->setting->id,
        ]);

        session(['setting_id' => $this->setting->id]);
    }

    public function test_unauthorized_user_cannot_correct_purchase(): void
    {
        $normalRole = Role::firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web']);
        $unauthorized = User::factory()->create(['is_active' => 1]);
        $unauthorized->settings()->attach($this->setting->id, ['role_id' => $normalRole->id]);

        $this->assertFalse($this->authorize('correct', $this->receivedPurchase, $unauthorized));
    }

    public function test_user_with_permission_can_correct_received_purchase(): void
    {
        $this->assertTrue($this->authorize('correct', $this->receivedPurchase, $this->user));
    }

    public function test_user_with_permission_can_correct_received_partially_purchase(): void
    {
        $this->assertTrue($this->authorize('correct', $this->receivedPartiallyPurchase, $this->user));
    }

    public function test_user_cannot_correct_non_received_purchase(): void
    {
        $this->assertFalse($this->authorize('correct', $this->draftedPurchase, $this->user));
    }

    public function test_super_admin_can_correct_received_purchase(): void
    {
        $this->assertTrue($this->authorize('correct', $this->receivedPurchase, $this->superAdmin));
    }

    public function test_user_cannot_correct_purchase_in_other_setting(): void
    {
        // Move purchase to other setting
        $this->receivedPurchase->update(['setting_id' => $this->otherSetting->id]);

        $this->assertFalse($this->authorize('correct', $this->receivedPurchase, $this->user));
    }

    public function test_permission_in_role_management(): void
    {
        $role = Role::where('name', 'Finance')->first();

        $this->assertTrue($role->hasPermissionTo('purchases.received.correct'));
    }

    private function authorize(string $ability, Purchase $purchase, User $user): bool
    {
        return $user->can($ability, $purchase);
    }
}
