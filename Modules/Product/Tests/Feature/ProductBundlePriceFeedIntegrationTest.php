<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductPriceFeedEvent;
use Modules\Product\Services\CrossBusinessPriceService;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ProductBundlePriceFeedIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Setting $settingA;
    private Setting $settingB;
    private User $user;
    private Product $parentProduct;
    private Product $compProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingA = Setting::create([
            'company_name' => 'Business Alpha',
            'company_email' => 'alpha@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'alpha@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Alpha',
            'company_address' => 'Alpha St',
        ]);

        $this->settingB = Setting::create([
            'company_name' => 'Business Beta',
            'company_email' => 'beta@example.com',
            'company_phone' => '08123456788',
            'notification_email' => 'beta@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Beta',
            'company_address' => 'Beta St',
        ]);

        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'products.bundle.create']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'products.bundle.edit']);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['products.bundle.create', 'products.bundle.edit']);
        $this->actingAs($this->user);

        session(['setting_id' => $this->settingA->id]);

        $this->parentProduct = Product::create([
            'product_name' => 'Parent Bundle Product',
            'product_code' => 'PBP-001',
            'setting_id' => $this->settingA->id,
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => 0,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'profit_percentage' => 0,
        ]);

        $this->compProduct = Product::create([
            'product_name' => 'Comp Item',
            'product_code' => 'CMP-001',
            'setting_id' => $this->settingA->id,
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => 0,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'profit_percentage' => 0,
        ]);

        ProductPrice::create(['product_id' => $this->compProduct->id, 'setting_id' => $this->settingA->id, 'sale_price' => 10000]);
        ProductPrice::create(['product_id' => $this->compProduct->id, 'setting_id' => $this->settingB->id, 'sale_price' => 10000]);
    }

    public function test_bundle_creation_records_grouped_bundle_created_event(): void
    {
        $response = $this->post(route('products.bundle.store', $this->parentProduct->id), [
            'name' => 'Super Saver Bundle',
            'bundle_sale_price' => 25000,
            'items' => [
                ['product_id' => $this->compProduct->id, 'quantity' => 2],
            ],
        ]);

        $response->assertRedirect();

        $event = ProductPriceFeedEvent::where('event_type', ProductPriceFeedEvent::TYPE_BUNDLE_CREATED)->first();
        $this->assertNotNull($event);
        $this->assertEquals('Parent Bundle Product — Super Saver Bundle', $event->subject_name);
        $this->assertEquals('PBP-001', $event->subject_code);
        $this->assertCount(2, $event->snapshots);
    }

    public function test_bundle_price_update_records_event_and_metadata_only_suppresses(): void
    {
        $response = $this->post(route('products.bundle.store', $this->parentProduct->id), [
            'name' => 'Test Bundle',
            'bundle_sale_price' => 20000,
            'items' => [
                ['product_id' => $this->compProduct->id, 'quantity' => 1],
            ],
        ]);

        $bundleA = ProductBundle::where('setting_id', $this->settingA->id)->first();

        // 1) Metadata-only update (name change, price unchanged) -> no new bundle_price_updated event
        $this->put(route('products.bundle.update', [$this->parentProduct->id, $bundleA->id]), [
            'name' => 'Updated Bundle Name',
            'bundle_sale_price' => 20000,
            'items' => [
                ['product_id' => $this->compProduct->id, 'quantity' => 1],
            ],
        ]);

        $this->assertDatabaseMissing('product_price_feed_events', [
            'event_type' => ProductPriceFeedEvent::TYPE_BUNDLE_PRICE_UPDATED,
        ]);

        // 2) Price change -> bundle_price_updated event created
        $this->put(route('products.bundle.update', [$this->parentProduct->id, $bundleA->id]), [
            'name' => 'Updated Bundle Name',
            'bundle_sale_price' => 22000,
            'items' => [
                ['product_id' => $this->compProduct->id, 'quantity' => 1],
            ],
        ]);

        $updateEvent = ProductPriceFeedEvent::where('event_type', ProductPriceFeedEvent::TYPE_BUNDLE_PRICE_UPDATED)->first();
        $this->assertNotNull($updateEvent);
        $this->assertEquals('Parent Bundle Product — Updated Bundle Name', $updateEvent->subject_name);
        $this->assertEquals('PBP-001', $updateEvent->subject_code);
        $this->assertCount(1, $updateEvent->snapshots);
        $this->assertEquals(['bundle_sale_price' => 20000.0], $updateEvent->snapshots->first()->before_snapshot);
        $this->assertEquals(['bundle_sale_price' => 22000.0], $updateEvent->snapshots->first()->after_snapshot);
    }

    public function test_cross_business_bundle_price_update_shares_operation_uuid_across_groups(): void
    {
        $groupUuid1 = (string) \Illuminate\Support\Str::uuid();
        $groupUuid2 = (string) \Illuminate\Support\Str::uuid();

        // Group 1 bundle copies
        $bundle1A = ProductBundle::create([
            'parent_product_id' => $this->parentProduct->id,
            'setting_id' => $this->settingA->id,
            'replica_group_uuid' => $groupUuid1,
            'name' => 'Bundle Group 1',
            'bundle_sale_price' => 30000,
            'is_active' => true,
        ]);
        $bundle1B = ProductBundle::create([
            'parent_product_id' => $this->parentProduct->id,
            'setting_id' => $this->settingB->id,
            'replica_group_uuid' => $groupUuid1,
            'name' => 'Bundle Group 1',
            'bundle_sale_price' => 32000,
            'is_active' => true,
        ]);

        // Group 2 bundle copies
        $bundle2A = ProductBundle::create([
            'parent_product_id' => $this->parentProduct->id,
            'setting_id' => $this->settingA->id,
            'replica_group_uuid' => $groupUuid2,
            'name' => 'Bundle Group 2',
            'bundle_sale_price' => 50000,
            'is_active' => true,
        ]);
        $bundle2B = ProductBundle::create([
            'parent_product_id' => $this->parentProduct->id,
            'setting_id' => $this->settingB->id,
            'replica_group_uuid' => $groupUuid2,
            'name' => 'Bundle Group 2',
            'bundle_sale_price' => 52000,
            'is_active' => true,
        ]);

        $service = app(CrossBusinessPriceService::class);
        $convSnapshot = $service->generateConversionSnapshot($this->parentProduct);
        $bundleSnapshot = $service->generateBundleSnapshot($this->parentProduct);

        $payload = [
            'prices' => [
                ['setting_id' => $this->settingA->id, 'sale_price' => 10000, 'tier_1_price' => 9000, 'tier_2_price' => 8000, 'last_purchase_price' => 5000, 'version' => null],
                ['setting_id' => $this->settingB->id, 'sale_price' => 11000, 'tier_1_price' => 9500, 'tier_2_price' => 8500, 'last_purchase_price' => 5500, 'version' => null],
            ],
            'conversions' => [],
            'conversion_snapshot' => $convSnapshot['data'],
            'conversion_snapshot_signature' => $convSnapshot['signature'],
            'bundles' => [
                ['bundle_id' => $bundle1A->id, 'setting_id' => $this->settingA->id, 'replica_group_uuid' => $groupUuid1, 'bundle_sale_price' => 35000, 'version' => $bundle1A->updated_at->format('Y-m-d H:i:s.u')],
                ['bundle_id' => $bundle1B->id, 'setting_id' => $this->settingB->id, 'replica_group_uuid' => $groupUuid1, 'bundle_sale_price' => 36000, 'version' => $bundle1B->updated_at->format('Y-m-d H:i:s.u')],
                ['bundle_id' => $bundle2A->id, 'setting_id' => $this->settingA->id, 'replica_group_uuid' => $groupUuid2, 'bundle_sale_price' => 55000, 'version' => $bundle2A->updated_at->format('Y-m-d H:i:s.u')],
                ['bundle_id' => $bundle2B->id, 'setting_id' => $this->settingB->id, 'replica_group_uuid' => $groupUuid2, 'bundle_sale_price' => 56000, 'version' => $bundle2B->updated_at->format('Y-m-d H:i:s.u')],
            ],
            'bundle_snapshot' => $bundleSnapshot['data'],
            'bundle_snapshot_signature' => $bundleSnapshot['signature'],
        ];

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'products.manage_cross_business_prices', 'guard_name' => 'web']);
        $this->user->givePermissionTo($perm);
        $this->user->load('permissions');

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->settingA->id])
            ->put(route('products.cross-business-prices.update', $this->parentProduct->id), $payload);

        if ($response->getSession()->has('error')) {
            $this->fail("PUT failed with session error: " . $response->getSession()->get('error'));
        }
        if ($response->getSession()->has('errors')) {
            $this->fail("PUT failed with validation errors: " . json_encode($response->getSession()->get('errors')->all()));
        }

        $response->assertRedirect(route('products.cross-business-prices.edit', $this->parentProduct->id));
        $response->assertSessionHas('success');

        $feedEvents = ProductPriceFeedEvent::where('event_type', ProductPriceFeedEvent::TYPE_BUNDLE_PRICE_UPDATED)
            ->whereIn('subject_id', [$bundle1A->id, $bundle2A->id])
            ->get();

        $this->assertCount(2, $feedEvents);
        $this->assertNotEmpty($feedEvents[0]->operation_uuid);
        $this->assertEquals($feedEvents[0]->operation_uuid, $feedEvents[1]->operation_uuid, 'Both bundle groups must share the exact same operation UUID from the combined save');
    }
}
