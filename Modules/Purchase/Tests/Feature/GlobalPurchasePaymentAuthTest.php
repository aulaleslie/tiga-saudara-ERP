<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GlobalPurchasePaymentAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::findOrCreate('purchasePayments.global.access', 'web');
        Permission::findOrCreate('purchasePayments.create', 'web');
        Permission::findOrCreate('purchases.access', 'web');
        
        $this->user = \App\Models\User::factory()->create(['is_active' => 1]);
        
        $this->setting = \Modules\Setting\Entities\Setting::factory()->create();
        session(['setting_id' => $this->setting->id]);
    }

    public function test_global_routes_inaccessible_without_global_access()
    {
        $supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $supplier->id,
            'status' => 'APPROVED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 10000,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
        ]);

        $routes = [
            route('purchases.global-payments.index'),
            route('purchases.global-payments.show', $purchase->id),
            route('purchases.global-payments.history', $purchase->id),
            route('purchases.global-payments.create', $supplier->id),
        ];

        foreach ($routes as $route) {
            $response = $this->actingAs($this->user)->get($route);
            $response->assertStatus(403);
        }

        $postResponse = $this->actingAs($this->user)->post(route('purchases.global-payments.store', $supplier->id), []);
        $postResponse->assertStatus(403);
    }

    public function test_read_routes_work_without_create_permission()
    {
        $this->user->givePermissionTo('purchasePayments.global.access');

        $supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-002',
            'supplier_id' => $supplier->id,
            'status' => 'RECEIVED',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'setting_id' => $this->setting->id,
        ]);

        $this->actingAs($this->user)->get(route('purchases.global-payments.index'))->assertStatus(200);
        $this->actingAs($this->user)->get(route('purchases.global-payments.show', $purchase->id))->assertStatus(200);
        $this->actingAs($this->user)->get(route('purchases.global-payments.history', $purchase->id))->assertStatus(200);
        
        $this->actingAs($this->user)->get(route('purchases.global-payments.create', $supplier->id))->assertStatus(403);
        $this->actingAs($this->user)->post(route('purchases.global-payments.store', $supplier->id), [])->assertStatus(403);
    }

    public function test_payment_creation_allowed_with_both_permissions()
    {
        $this->user->givePermissionTo('purchasePayments.global.access');
        $this->user->givePermissionTo('purchasePayments.create');

        $supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->actingAs($this->user)->get(route('purchases.global-payments.create', $supplier->id))->assertStatus(200);
        $this->actingAs($this->user)->post(route('purchases.global-payments.store', $supplier->id), [])->assertStatus(302);
    }

    public function test_normal_purchase_routes_enforce_setting_id()
    {
        // User has global permissions
        $this->user->givePermissionTo('purchasePayments.global.access');
        $this->user->givePermissionTo('purchasePayments.create');
        
        Permission::findOrCreate('purchases.show', 'web');
        Permission::findOrCreate('purchasePayments.access', 'web');
        
        $this->user->givePermissionTo('purchases.show');
        $this->user->givePermissionTo('purchasePayments.access');
        
        $otherSetting = \Modules\Setting\Entities\Setting::factory()->create();

        $supplier = Supplier::create([
            'supplier_name' => 'Other Supplier',
            'supplier_email' => 'other@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $otherSetting->id,
        ]);
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-003',
            'supplier_id' => $supplier->id,
            'status' => 'APPROVED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 10000,
            'due_amount' => 0,
            'setting_id' => $otherSetting->id, // Belongs to other setting
        ]);

        // Attempt to access normal routes from current setting (which is $this->setting->id)
        $this->actingAs($this->user)->get(route('purchases.show', $purchase->id))->assertStatus(404);
        $this->actingAs($this->user)->get(route('purchase-payments.index', $purchase->id))->assertStatus(404);
        $this->actingAs($this->user)->get(route('purchase-payments.create', $purchase->id))->assertStatus(404);
    }
}
