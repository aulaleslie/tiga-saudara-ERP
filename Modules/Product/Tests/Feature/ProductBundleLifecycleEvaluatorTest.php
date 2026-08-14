<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Services\BundleLifecycle\BundleLifecycleReason;
use Modules\Product\Services\BundleLifecycle\ProductBundleLifecycleEvaluator;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ProductBundleLifecycleEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private Setting $settingA;
    private Setting $settingB;
    private Product $parentProduct;
    private Product $component1;
    private Product $component2;
    private ProductBundleLifecycleEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn() => true);

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->settingA = Setting::create([
            'company_name' => 'Company A',
            'company_email' => 'a@example.com',
            'company_phone' => '111',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notifya@example.com',
            'footer_text' => 'Footer A',
            'company_address' => 'Addr A',
            'is_pkp' => true,
        ]);

        $this->settingB = Setting::create([
            'company_name' => 'Company B',
            'company_email' => 'b@example.com',
            'company_phone' => '222',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notifyb@example.com',
            'footer_text' => 'Footer B',
            'company_address' => 'Addr B',
            'is_pkp' => false,
        ]);

        $this->parentProduct = Product::create([
            'setting_id' => $this->settingA->id,
            'product_name' => 'Parent Product',
            'product_code' => 'PRD-PARENT',
            'product_quantity' => 10,
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_unit' => 'pcs',
            'stock_managed' => true,
            'is_sold' => true,
        ]);

        $this->component1 = Product::create([
            'setting_id' => $this->settingA->id,
            'product_name' => 'Component 1',
            'product_code' => 'PRD-COMP1',
            'product_quantity' => 10,
            'product_cost' => 500,
            'product_price' => 1000,
            'product_unit' => 'pcs',
            'stock_managed' => true,
            'is_sold' => true,
        ]);

        $this->component2 = Product::create([
            'setting_id' => $this->settingA->id,
            'product_name' => 'Component 2',
            'product_code' => 'PRD-COMP2',
            'product_quantity' => 10,
            'product_cost' => 500,
            'product_price' => 1000,
            'product_unit' => 'pcs',
            'stock_managed' => true,
            'is_sold' => true,
        ]);

        $this->evaluator = app(ProductBundleLifecycleEvaluator::class);
    }

    public function test_active_bundle_with_open_ended_dates_is_eligible(): void
    {
        $bundle = ProductBundle::create([
            'setting_id' => $this->settingA->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'Active Bundle',
            'is_active' => true,
            'active_from' => null,
            'active_to' => null,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $this->component1->id,
            'quantity' => 2,
        ]);

        $result = $this->evaluator->evaluateForSelection($bundle, $this->settingA->id, $this->parentProduct->id);
        $this->assertTrue($result->isEligible);
        $this->assertEmpty($result->reasons);
        $this->assertEmpty($result->warnings);
    }

    public function test_disabled_bundle_is_ineligible_with_disabled_reason(): void
    {
        $bundle = ProductBundle::create([
            'setting_id' => $this->settingA->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'Disabled Bundle',
            'is_active' => false,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $this->component1->id,
            'quantity' => 1,
        ]);

        $result = $this->evaluator->evaluateForSelection($bundle, $this->settingA->id, $this->parentProduct->id);
        $this->assertFalse($result->isEligible);
        $this->assertContains(BundleLifecycleReason::DISABLED, $result->reasons);
    }

    public function test_date_boundaries_are_inclusive_in_application_timezone(): void
    {
        $today = Carbon::now(config('app.timezone'))->format('Y-m-d');
        $yesterday = Carbon::now(config('app.timezone'))->subDay()->format('Y-m-d');
        $tomorrow = Carbon::now(config('app.timezone'))->addDay()->format('Y-m-d');

        // Bundle starting today and ending today (inclusive boundary)
        $bundleTodayOnly = ProductBundle::create([
            'setting_id' => $this->settingA->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'Today Only Bundle',
            'is_active' => true,
            'active_from' => $today,
            'active_to' => $today,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundleTodayOnly->id,
            'product_id' => $this->component1->id,
            'quantity' => 1,
        ]);

        $resultToday = $this->evaluator->evaluateForSelection($bundleTodayOnly, $this->settingA->id, $this->parentProduct->id, $today);
        $this->assertTrue($resultToday->isEligible, 'Inclusive on both start and end date');

        // Expired bundle (active_to was yesterday)
        $bundleExpired = ProductBundle::create([
            'setting_id' => $this->settingA->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'Expired Bundle',
            'is_active' => true,
            'active_from' => $yesterday,
            'active_to' => $yesterday,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundleExpired->id,
            'product_id' => $this->component1->id,
            'quantity' => 1,
        ]);

        $resultExpired = $this->evaluator->evaluateForSelection($bundleExpired, $this->settingA->id, $this->parentProduct->id, $today);
        $this->assertFalse($resultExpired->isEligible);
        $this->assertContains(BundleLifecycleReason::EXPIRED, $resultExpired->reasons);

        // Future bundle (active_from is tomorrow)
        $bundleFuture = ProductBundle::create([
            'setting_id' => $this->settingA->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'Future Bundle',
            'is_active' => true,
            'active_from' => $tomorrow,
            'active_to' => null,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundleFuture->id,
            'product_id' => $this->component1->id,
            'quantity' => 1,
        ]);

        $resultFuture = $this->evaluator->evaluateForSelection($bundleFuture, $this->settingA->id, $this->parentProduct->id, $today);
        $this->assertFalse($resultFuture->isEligible);
        $this->assertContains(BundleLifecycleReason::NOT_STARTED, $resultFuture->reasons);
    }

    public function test_empty_composition_or_missing_inactive_component_is_ineligible(): void
    {
        // 1. Empty composition
        $bundleEmpty = ProductBundle::create([
            'setting_id' => $this->settingA->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'Empty Bundle',
            'is_active' => true,
        ]);

        $resultEmpty = $this->evaluator->evaluateForSelection($bundleEmpty, $this->settingA->id, $this->parentProduct->id);
        $this->assertFalse($resultEmpty->isEligible);
        $this->assertContains(BundleLifecycleReason::EMPTY_COMPOSITION, $resultEmpty->reasons);

        // 2. Inactive / merged component
        $inactiveComponent = Product::create([
            'setting_id' => $this->settingA->id,
            'product_name' => 'Inactive Component',
            'product_code' => 'PRD-INACTIVE',
            'product_quantity' => 10,
            'product_cost' => 500,
            'product_price' => 1000,
            'product_unit' => 'pcs',
            'stock_managed' => true,
            'is_sold' => true,
            'merged_into_id' => $this->component1->id,
        ]);

        $bundleWithInactive = ProductBundle::create([
            'setting_id' => $this->settingA->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'Bundle with Inactive Component',
            'is_active' => true,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundleWithInactive->id,
            'product_id' => $inactiveComponent->id,
            'quantity' => 1,
        ]);

        $resultInactive = $this->evaluator->evaluateForSelection($bundleWithInactive, $this->settingA->id, $this->parentProduct->id);
        $this->assertFalse($resultInactive->isEligible);
        $this->assertContains(BundleLifecycleReason::INACTIVE_COMPONENT, $resultInactive->reasons);
    }

    public function test_cross_setting_bundle_is_ineligible(): void
    {
        $bundleB = ProductBundle::create([
            'setting_id' => $this->settingB->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'Setting B Bundle',
            'is_active' => true,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundleB->id,
            'product_id' => $this->component1->id,
            'quantity' => 1,
        ]);

        $result = $this->evaluator->evaluateForSelection($bundleB, $this->settingA->id, $this->parentProduct->id);
        $this->assertFalse($result->isEligible);
        $this->assertContains(BundleLifecycleReason::SETTING_MISMATCH, $result->reasons);
    }
}
