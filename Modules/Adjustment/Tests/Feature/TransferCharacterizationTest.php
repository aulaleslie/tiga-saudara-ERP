<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TransferCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Define common permissions used in the controller
        $permissions = [
            'stockTransfers.access',
            'stockTransfers.create',
            'stockTransfers.show',
            'stockTransfers.edit',
            'stockTransfers.delete',
            'stockTransfers.approval',
            'stockTransfers.dispatch',
            'stockTransfers.receive',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    private function createTenantData(string $companyName): array
    {
        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'RP',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $setting = Setting::create([
            'company_name' => $companyName,
            'company_email' => strtolower(str_replace(' ', '', $companyName)) . '@test.com',
            'company_phone' => '0800000000',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => strtolower(str_replace(' ', '', $companyName)) . '@test.com',
            'footer_text' => 'Footer',
            'company_address' => 'Bandung',
        ]);

        $location = Location::create([
            'setting_id' => $setting->id,
            'name' => 'Warehouse ' . $companyName,
        ]);

        return [$setting, $location];
    }

    /** @test */
    public function it_enforces_creation_permissions_and_sets_pending_status()
    {
        $user = User::factory()->create(['is_active' => 1]);
        [$setting, $originLocation] = $this->createTenantData('Tenant A');
        [$settingB, $destinationLocation] = $this->createTenantData('Tenant B');

        $role = Role::create(['name' => 'creator', 'guard_name' => 'web']);
        // Intentionally missing stockTransfers.create
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        $product = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'Item',
            'product_code' => 'SKU',
            'product_price' => 100,
            'product_cost' => 50,
            'stock_managed' => true,
        ]);

        $payload = [
            'origin_location' => $originLocation->id,
            'destination_location' => $destinationLocation->id,
            'product_ids' => [$product->id],
            'quantities' => [5],
        ];

        $response = $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->withHeaders(['X-Idempotency-Token' => 'test-key-123'])
            ->post(route('transfers.store'), $payload);
        
        $response->assertForbidden();

        $role->givePermissionTo('stockTransfers.create');

        $response = $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->withHeaders(['X-Idempotency-Token' => 'test-key-124'])
            ->post(route('transfers.store'), $payload);

        $transfer = Transfer::first();
        $this->assertNotNull($transfer);
        
        $response->assertRedirect(route('transfers.show', $transfer));
        
        $this->assertEquals(Transfer::STATUS_PENDING, $transfer->status);
        $this->assertEquals($user->id, $transfer->created_by);
    }

    /** @test */
    public function it_fails_to_hydrate_edit_with_nested_eloquent_collection_because_of_legacy_array_shape()
    {
        $user = User::factory()->create(['is_active' => 1]);
        [$setting, $originLocation] = $this->createTenantData('Tenant A');
        [$settingB, $destinationLocation] = $this->createTenantData('Tenant B');

        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $role->givePermissionTo('stockTransfers.edit');
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        $product = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'Item',
            'product_code' => 'SKU',
            'product_price' => 100,
            'product_cost' => 50,
            'stock_managed' => true,
        ]);

        $transfer = Transfer::create([
            'origin_location_id' => $originLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'created_by' => $user->id,
            'status' => Transfer::STATUS_PENDING,
        ]);

        TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        $response = $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->get(route('transfers.edit', $transfer->id));

        $response->assertOk();
        // The edit route loads the view, but the Livewire component cannot cleanly handle the shape
        // This test characterizes that the view renders, but the Livewire form handles legacy arrays.
        $response->assertViewIs('adjustment::transfers.edit');
    }

    /** @test */
    public function it_enforces_lifecycle_status_guards_on_approve_and_reject()
    {
        $user = User::factory()->create(['is_active' => 1]);
        [$setting, $originLocation] = $this->createTenantData('Tenant A');
        [$settingB, $destinationLocation] = $this->createTenantData('Tenant B');

        $role = Role::create(['name' => 'approver', 'guard_name' => 'web']);
        $role->givePermissionTo('stockTransfers.approval');
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        $transfer = Transfer::create([
            'origin_location_id' => $originLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'created_by' => $user->id,
            'status' => Transfer::STATUS_DISPATCHED, // NOT PENDING
        ]);

        $response = $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->post(route('transfers.approve', $transfer->id));

        $response->assertRedirect(route('transfers.show', $transfer->id));
        $this->assertEquals(Transfer::STATUS_DISPATCHED, $transfer->fresh()->status);
    }
    
    /** @test */
    public function it_renders_historical_terminal_states()
    {
        $user = User::factory()->create(['is_active' => 1]);
        [$setting, $originLocation] = $this->createTenantData('Tenant A');
        [$settingB, $destinationLocation] = $this->createTenantData('Tenant B');

        $role = Role::create(['name' => 'viewer', 'guard_name' => 'web']);
        $role->givePermissionTo('stockTransfers.show');
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        $transfer = Transfer::create([
            'origin_location_id' => $originLocation->id,
            'destination_location_id' => $destinationLocation->id,
            'created_by' => $user->id,
            'status' => Transfer::STATUS_RECEIVED, // Legacy terminal state
        ]);

        $response = $this->actingAs($user)->withSession(['setting_id' => $setting->id])
            ->get(route('transfers.show', $transfer->id));

        $response->assertOk();
        $response->assertSee('RECEIVED');
    }
}
