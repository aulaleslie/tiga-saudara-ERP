<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductPriceFeedEvent;
use Modules\Product\Services\ProductPriceFeedQueryService;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductPriceFeedQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private Setting $settingA;
    private Setting $settingB;
    private ProductPriceFeedQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductPriceFeedQueryService::class);

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
    }

    public function test_super_admin_unrestricted_access(): void
    {
        $superAdminRole = Role::firstOrCreate(['name' => 'Super Admin']);
        $user = User::factory()->create();
        $user->assignRole($superAdminRole);

        // Record a product created event
        app(\Modules\Product\Services\ProductPriceFeedRecorder::class)->record(
            ProductPriceFeedEvent::TYPE_PRODUCT_CREATED,
            ProductPriceFeedEvent::SUBJECT_PRODUCT,
            1,
            'Super Admin Item',
            'SA-001',
            [
                ['setting_id' => $this->settingA->id, 'after' => ['sale_price' => 10000, 'last_purchase_price' => 5000]],
                ['setting_id' => $this->settingB->id, 'after' => ['sale_price' => 10000, 'last_purchase_price' => 5000]],
            ]
        );

        $events = $this->service->getFeedEvents($user, ['paginate' => false]);
        $this->assertCount(1, $events);
        $this->assertCount(2, $events->first()['sections']);
    }

    public function test_purchase_only_user_field_masking(): void
    {
        Permission::firstOrCreate(['name' => 'purchases.create']);
        $role = Role::firstOrCreate(['name' => 'Purchaser']);
        $role->givePermissionTo('purchases.create');

        $user = User::factory()->create();
        $user->settings()->attach($this->settingA->id, ['role_id' => $role->id]);

        app(\Modules\Product\Services\ProductPriceFeedRecorder::class)->record(
            ProductPriceFeedEvent::TYPE_PRODUCT_CREATED,
            ProductPriceFeedEvent::SUBJECT_PRODUCT,
            1,
            'Purchaser Item',
            'PUR-001',
            [
                ['setting_id' => $this->settingA->id, 'after' => ['sale_price' => 10000, 'last_purchase_price' => 5000]],
            ]
        );

        $events = $this->service->getFeedEvents($user, ['paginate' => false]);
        $this->assertCount(1, $events);

        $afterSnapshot = $events->first()['sections'][0]['after'];
        $this->assertArrayHasKey('last_purchase_price', $afterSnapshot);
        $this->assertArrayNotHasKey('sale_price', $afterSnapshot);
    }

    public function test_query_filtering_occurs_before_pagination_and_limit(): void
    {
        Permission::firstOrCreate(['name' => 'purchases.create']);
        $purchaserRole = Role::firstOrCreate(['name' => 'PurchaserOnly']);
        $purchaserRole->givePermissionTo('purchases.create');

        $user = User::factory()->create();
        $user->settings()->attach($this->settingA->id, ['role_id' => $purchaserRole->id]);

        // Record a bundle event in settingA (which purchaser cannot see)
        app(\Modules\Product\Services\ProductPriceFeedRecorder::class)->record(
            ProductPriceFeedEvent::TYPE_BUNDLE_CREATED,
            ProductPriceFeedEvent::SUBJECT_BUNDLE,
            1,
            'Hidden Bundle',
            null,
            [['setting_id' => $this->settingA->id, 'after' => ['bundle_sale_price' => 50000]]]
        );

        // Record a product created event in settingA (which purchaser can see last_purchase_price)
        app(\Modules\Product\Services\ProductPriceFeedRecorder::class)->record(
            ProductPriceFeedEvent::TYPE_PRODUCT_CREATED,
            ProductPriceFeedEvent::SUBJECT_PRODUCT,
            2,
            'Visible Product',
            'VP-001',
            [['setting_id' => $this->settingA->id, 'after' => ['last_purchase_price' => 10000]]]
        );

        // Limit to 1 item - should return the visible product, NOT limit the hidden bundle first
        $events = $this->service->getFeedEvents($user, ['limit' => 1]);
        $this->assertCount(1, $events);
        $this->assertEquals('Visible Product', $events->first()['subject_name']);
    }

    public function test_unassigned_settings_excluded_from_feed(): void
    {
        Permission::firstOrCreate(['name' => 'purchases.create']);
        $role = Role::firstOrCreate(['name' => 'PurchaserSettingA']);
        $role->givePermissionTo('purchases.create');

        $user = User::factory()->create();
        // User attached ONLY to settingA
        $user->settings()->attach($this->settingA->id, ['role_id' => $role->id]);

        // Event in settingB
        app(\Modules\Product\Services\ProductPriceFeedRecorder::class)->record(
            ProductPriceFeedEvent::TYPE_PRODUCT_CREATED,
            ProductPriceFeedEvent::SUBJECT_PRODUCT,
            10,
            'Setting B Product',
            'SBP-001',
            [['setting_id' => $this->settingB->id, 'after' => ['last_purchase_price' => 20000]]]
        );

        $events = $this->service->getFeedEvents($user, ['paginate' => false]);
        $this->assertCount(0, $events);
    }

    public function test_unauthorized_or_unknown_setting_id_filter_returns_empty_results(): void
    {
        Permission::firstOrCreate(['name' => 'purchases.create']);
        $role = Role::firstOrCreate(['name' => 'PurchaserSettingAOnly']);
        $role->givePermissionTo('purchases.create');

        $user = User::factory()->create();
        $user->settings()->attach($this->settingA->id, ['role_id' => $role->id]);

        // Event in settingA
        app(\Modules\Product\Services\ProductPriceFeedRecorder::class)->record(
            ProductPriceFeedEvent::TYPE_PRODUCT_CREATED,
            ProductPriceFeedEvent::SUBJECT_PRODUCT,
            1,
            'Setting A Product',
            'SAP-001',
            [['setting_id' => $this->settingA->id, 'after' => ['last_purchase_price' => 15000]]]
        );

        // Querying for settingB (unassigned to user) or setting 999999 (non-existent) should return 0 results
        $resultsSettingB = $this->service->getFeedEvents($user, ['setting_id' => $this->settingB->id, 'paginate' => false]);
        $this->assertCount(0, $resultsSettingB);

        $resultsUnknown = $this->service->getFeedEvents($user, ['setting_id' => 999999, 'paginate' => false]);
        $this->assertCount(0, $resultsUnknown);
    }
}
