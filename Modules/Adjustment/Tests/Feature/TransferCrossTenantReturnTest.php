<?php

namespace Modules\Adjustment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Entities\TransferReturnObligation;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TransferCrossTenantReturnTest extends TestCase
{
    use RefreshDatabase;

    protected User $creator;
    protected Setting $originSetting;
    protected Setting $destSetting;
    protected Location $originLocation;
    protected Location $destLocation;
    protected Transfer $transfer;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        $this->withoutMiddleware([\App\Http\Middleware\CheckUserRoleForSetting::class]);

        $permission = Permission::firstOrCreate(['name' => 'stockTransfers.receive']);
        $permissionDispatch = Permission::firstOrCreate(['name' => 'stockTransfers.dispatch']);
        
        $role = Role::firstOrCreate(['name' => 'CreatorRole']);
        $role->givePermissionTo($permission, $permissionDispatch);

        $this->originSetting = Setting::factory()->create(['company_name' => 'Origin Tenant']);
        $this->destSetting = Setting::factory()->create(['company_name' => 'Dest Tenant']);

        $this->originLocation = Location::create(['name' => 'Origin Loc', 'setting_id' => $this->originSetting->id]);
        $this->destLocation = Location::create(['name' => 'Dest Loc', 'setting_id' => $this->destSetting->id]);

        $this->creator = User::factory()->create();
        $this->creator->assignRole('CreatorRole');

        $this->transfer = Transfer::create([
            'origin_location_id' => $this->originLocation->id,
            'destination_location_id' => $this->destLocation->id,
            'created_by' => $this->creator->id,
            'status' => Transfer::STATUS_DISPATCHED,
        ]);
    }

    public function test_same_tenant_completion()
    {
        // Change to same tenant
        $this->transfer->update(['destination_location_id' => $this->originLocation->id]);
        
        $productId = \Illuminate\Support\Facades\DB::table('products')->insertGetId([
            'product_name' => 'Test',
            'product_code' => 'TEST-01',
            'product_price' => 0,
            'product_cost' => 0,
            'product_unit' => 'pc',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 0,
            'setting_id' => $this->originSetting->id,
        ]);

        $tp = TransferProduct::create([
            'transfer_id' => $this->transfer->id,
            'product_id' => $productId,
            'quantity' => 10,
            'quantity_tax' => 10,
            'dispatched_quantity_tax' => 10,
        ]);

        session(['setting_id' => $this->originSetting->id]);
        
        $response = $this->actingAs($this->creator)->post(route('transfers.receive', $this->transfer));
        $response->assertRedirect();

        $this->assertDatabaseHas('transfers', [
            'id' => $this->transfer->id,
            'status' => Transfer::STATUS_COMPLETED,
        ]);
        
        $this->assertDatabaseMissing('transfer_return_obligations', [
            'transfer_id' => $this->transfer->id,
        ]);
    }

    public function test_cross_tenant_non_tax_completion()
    {
        $productId = \Illuminate\Support\Facades\DB::table('products')->insertGetId([
            'product_name' => 'Test',
            'product_code' => 'TEST-01',
            'product_price' => 0,
            'product_cost' => 0,
            'product_unit' => 'pc',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 0,
            'setting_id' => $this->originSetting->id,
        ]);

        TransferProduct::create([
            'transfer_id' => $this->transfer->id,
            'product_id' => $productId,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'dispatched_quantity_non_tax' => 10,
        ]);

        session(['setting_id' => $this->destSetting->id]);
        
        $response = $this->actingAs($this->creator)->post(route('transfers.receive', $this->transfer));
        $response->assertRedirect();

        $this->assertDatabaseHas('transfers', [
            'id' => $this->transfer->id,
            'status' => Transfer::STATUS_COMPLETED,
        ]);
        
        $this->assertDatabaseMissing('transfer_return_obligations', [
            'transfer_id' => $this->transfer->id,
        ]);
    }

    public function test_cross_tenant_mixed_tax_obligation()
    {
        $productId = \Illuminate\Support\Facades\DB::table('products')->insertGetId([
            'product_name' => 'Test',
            'product_code' => 'TEST-01',
            'product_price' => 0,
            'product_cost' => 0,
            'product_unit' => 'pc',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 0,
            'setting_id' => $this->originSetting->id,
        ]);

        $tp = TransferProduct::create([
            'transfer_id' => $this->transfer->id,
            'product_id' => $productId,
            'quantity' => 10,
            'quantity_tax' => 5,
            'quantity_non_tax' => 5,
            'dispatched_quantity_tax' => 5,
            'dispatched_quantity_non_tax' => 5,
        ]);

        session(['setting_id' => $this->destSetting->id]);
        
        $response = $this->actingAs($this->creator)->post(route('transfers.receive', $this->transfer));
        $response->assertRedirect();

        $this->assertDatabaseHas('transfers', [
            'id' => $this->transfer->id,
            'status' => Transfer::STATUS_AWAITING_RETURN,
        ]);
        
        $this->assertDatabaseHas('transfer_return_obligations', [
            'transfer_id' => $this->transfer->id,
            'transfer_product_id' => $tp->id,
            'required_quantity_tax' => 5,
            'required_quantity_broken_tax' => 0,
        ]);
    }

    public function test_historical_received_compatibility()
    {
        $this->transfer->update(['status' => Transfer::STATUS_RECEIVED]);

        $productId = \Illuminate\Support\Facades\DB::table('products')->insertGetId([
            'product_name' => 'Test',
            'product_code' => 'TEST-01',
            'product_price' => 0,
            'product_cost' => 0,
            'product_unit' => 'pc',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 0,
            'setting_id' => $this->originSetting->id,
        ]);

        $tp = TransferProduct::create([
            'transfer_id' => $this->transfer->id,
            'product_id' => $productId,
            'quantity' => 10,
            'quantity_tax' => 10, // A taxed historical transfer
        ]);

        // requiresReturn should be false because no obligations exist and it's RECEIVED
        $this->assertFalse($this->transfer->requiresReturn());
    }
}
