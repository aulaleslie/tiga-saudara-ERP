<?php

namespace Modules\Consignment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The other consignment tests build Dispatch/DispatchDetail rows by hand. Sold-source discovery
 * consumes exactly the rows the real Sales dispatch workflow produces
 * (openspec/specs/consignment-sales-allocation: "Approved dispatch evidence governs consignment
 * sale eligibility"), so this drives the real storeDispatch + approve routes and only then runs
 * discovery. It guards against the producing code drifting away from what discovery relies on
 * (persisted source location, approved status, base quantity, inventory classification).
 */
class ConsignmentRealDispatchDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private User $user;
    private Sale $sale;
    private Product $product;
    private Location $standardLocation;
    private Location $consignmentLocation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->setting = Setting::create([
            'company_name' => 'Consignment Co',
            'company_email' => 'c@example.com',
            'company_phone' => '123',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'n@example.com',
            'footer_text' => 'F',
            'company_address' => 'Addr',
        ]);

        $term = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        $customer = Customer::factory()->create(['setting_id' => $this->setting->id, 'payment_term_id' => $term->id]);

        foreach (['sales.dispatch', 'salesDispatches.approval'] as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['sales.dispatch', 'salesDispatches.approval']);

        $category = Category::create([
            'category_name' => 'Cat', 'category_code' => 'CAT',
            'setting_id' => $this->setting->id, 'created_by' => $this->user->id,
        ]);
        $unit = Unit::create([
            'name' => 'Unit', 'short_name' => 'U', 'operator' => '*',
            'operation_value' => 1, 'setting_id' => $this->setting->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Consigned Item', 'product_code' => 'CON-001',
            'setting_id' => $this->setting->id, 'product_quantity' => 20,
            'product_cost' => 1000, 'stock_managed' => true, 'product_price' => 1500,
            'category_id' => $category->id, 'product_unit' => $unit->id,
        ]);

        $this->standardLocation = Location::create([
            'name' => 'Gudang Biasa', 'setting_id' => $this->setting->id,
            'is_active' => true, 'is_consignment' => false,
        ]);
        $this->consignmentLocation = Location::create([
            'name' => 'Gudang Konsinyasi', 'setting_id' => $this->setting->id,
            'is_active' => true, 'is_consignment' => true,
        ]);

        foreach ([$this->standardLocation, $this->consignmentLocation] as $location) {
            ProductStock::create([
                'product_id' => $this->product->id, 'location_id' => $location->id,
                'quantity' => 10, 'quantity_tax' => 0, 'quantity_non_tax' => 10,
                'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
            ]);
        }

        $this->sale = Sale::create([
            'date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'customer_id' => $customer->id, 'customer_name' => $customer->customer_name,
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0,
            'discount_amount' => 0, 'shipping_amount' => 0, 'total_amount' => 15000,
            'paid_amount' => 0, 'due_amount' => 15000, 'status' => 'Approved',
            'payment_status' => 'Unpaid', 'payment_method' => 'cash',
            'payment_term_id' => $term->id, 'setting_id' => $this->setting->id,
            'is_tax_included' => false, 'reference' => 'SO-CONS-001',
        ]);

        SaleDetails::create([
            'sale_id' => $this->sale->id, 'product_id' => $this->product->id,
            'product_name' => $this->product->product_name, 'product_code' => $this->product->product_code,
            'quantity' => 10, 'price' => 1500, 'unit_price' => 1500, 'sub_total' => 15000,
            'product_discount_amount' => 0, 'product_discount_type' => 'fixed',
            'product_tax_amount' => 0, 'tax_id' => null,
        ]);
    }

    private function dispatchAndApprove(Location $location, int $quantity): Dispatch
    {
        $key = $this->product->id . '--0';

        $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('sales.storeDispatch', $this->sale), [
                'dispatch_date' => now()->toDateString(),
                'dispatchedQuantities' => [$key => $quantity],
                'selectedLocations' => [$key => $location->id],
            ])
            ->assertRedirect();

        $dispatch = Dispatch::where('sale_id', $this->sale->id)->latest('id')->firstOrFail();
        $this->assertSame(Dispatch::STATUS_PENDING, $dispatch->status);

        $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('dispatches.approve', $dispatch))
            ->assertRedirect();

        $this->assertSame(Dispatch::STATUS_APPROVED, $dispatch->fresh()->status);

        return $dispatch;
    }

    public function test_only_the_approved_dispatch_from_the_consignment_location_becomes_a_sold_source(): void
    {
        $standardDispatch = $this->dispatchAndApprove($this->standardLocation, 3);
        $consignmentDispatch = $this->dispatchAndApprove($this->consignmentLocation, 4);

        $standardDetail = DispatchDetail::where('dispatch_id', $standardDispatch->id)->firstOrFail();
        $consignmentDetail = DispatchDetail::where('dispatch_id', $consignmentDispatch->id)->firstOrFail();

        // The real workflow must persist the source location discovery keys on.
        $this->assertSame($this->consignmentLocation->id, (int) $consignmentDetail->location_id);
        $this->assertSame($this->standardLocation->id, (int) $standardDetail->location_id);

        $result = app(ConsignmentSoldSourceDiscoveryService::class)->discoverForSetting($this->setting->id);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['blocked']);

        $this->assertDatabaseMissing('consignment_sold_sources', ['dispatch_detail_id' => $standardDetail->id]);

        $source = ConsignmentSoldSource::where('dispatch_detail_id', $consignmentDetail->id)->firstOrFail();
        $this->assertSame($this->consignmentLocation->id, (int) $source->location_id);
        $this->assertSame($this->product->id, (int) $source->product_id);
        $this->assertSame($this->sale->id, (int) $source->sale_id);
        $this->assertEquals(4, (float) $source->original_base_quantity);
    }

    public function test_rediscovery_is_idempotent_and_a_pending_dispatch_is_never_a_sold_source(): void
    {
        $approved = $this->dispatchAndApprove($this->consignmentLocation, 2);

        // A second dispatch from the consignment location that is still pending approval.
        $key = $this->product->id . '--0';
        $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('sales.storeDispatch', $this->sale), [
                'dispatch_date' => now()->toDateString(),
                'dispatchedQuantities' => [$key => 5],
                'selectedLocations' => [$key => $this->consignmentLocation->id],
            ])
            ->assertRedirect();
        $pending = Dispatch::where('sale_id', $this->sale->id)->latest('id')->firstOrFail();
        $this->assertSame(Dispatch::STATUS_PENDING, $pending->status);

        $service = app(ConsignmentSoldSourceDiscoveryService::class);
        $first = $service->discoverForSetting($this->setting->id);
        $second = $service->discoverForSetting($this->setting->id);

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, ConsignmentSoldSource::count());

        $approvedDetail = DispatchDetail::where('dispatch_id', $approved->id)->firstOrFail();
        $this->assertDatabaseHas('consignment_sold_sources', ['dispatch_detail_id' => $approvedDetail->id]);
        $this->assertDatabaseMissing('consignment_sold_sources', [
            'dispatch_detail_id' => DispatchDetail::where('dispatch_id', $pending->id)->value('id'),
        ]);
    }
}
