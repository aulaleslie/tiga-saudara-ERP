<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ProductBundleReplicatedPricingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $settingA;
    private Setting $settingB;
    private Product $parentProduct;
    private Product $compA;
    private Product $compB;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $currency = Currency::create([
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
            'notification_email' => 'notifya@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'company_address' => 'Addr A',
            'footer_text' => 'Footer A',
            'is_pkp' => true,
        ]);

        $this->settingB = Setting::create([
            'company_name' => 'Company B',
            'company_email' => 'b@example.com',
            'company_phone' => '222',
            'notification_email' => 'notifyb@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'company_address' => 'Addr B',
            'footer_text' => 'Footer B',
            'is_pkp' => true,
        ]);

        Session::put('setting_id', $this->settingA->id);

        $this->parentProduct = Product::create([
            'setting_id' => $this->settingA->id,
            'product_name' => 'Parent Product',
            'product_code' => 'PARENT-01',
            'product_unit' => 'pc',
            'product_cost' => 10000,
            'product_price' => 50000,
            'product_quantity' => 10,
        ]);

        $this->compA = Product::create([
            'setting_id' => $this->settingA->id,
            'product_name' => 'Component Product A',
            'product_code' => 'COMP-A',
            'product_unit' => 'pc',
            'product_cost' => 5000,
            'product_price' => 20000,
            'product_quantity' => 10,
        ]);

        $this->compB = Product::create([
            'setting_id' => $this->settingA->id,
            'product_name' => 'Component Product B',
            'product_code' => 'COMP-B',
            'product_unit' => 'pc',
            'product_cost' => 6000,
            'product_price' => 30000,
            'product_quantity' => 10,
        ]);
    }

    /**
     * 1. Replicated creation derives each target-setting component snapshot independently.
     */
    public function test_replicated_creation_persists_independent_per_setting_component_prices(): void
    {
        // Setting A prices
        ProductPrice::create([
            'product_id' => $this->compA->id,
            'setting_id' => $this->settingA->id,
            'sale_price' => 20000.00,
        ]);
        ProductPrice::create([
            'product_id' => $this->compB->id,
            'setting_id' => $this->settingA->id,
            'sale_price' => 30000.00,
        ]);

        // Setting B prices (different)
        ProductPrice::create([
            'product_id' => $this->compA->id,
            'setting_id' => $this->settingB->id,
            'sale_price' => 22000.00,
        ]);
        ProductPrice::create([
            'product_id' => $this->compB->id,
            'setting_id' => $this->settingB->id,
            'sale_price' => 33000.00,
        ]);

        $payload = [
            'name' => 'Gaming Bundle',
            'bundle_sale_price' => 100000,
            'items' => [
                ['product_id' => $this->compA->id, 'quantity' => 1],
                ['product_id' => $this->compB->id, 'quantity' => 1],
            ],
        ];

        $response = $this->withSession(['setting_id' => $this->settingA->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), $payload);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('products.show', $this->parentProduct->id));

        $bundles = ProductBundle::with('items')->where('name', 'GAMING BUNDLE')->get();
        $this->assertCount(2, $bundles);

        $bundleA = $bundles->firstWhere('setting_id', $this->settingA->id);
        $itemA_compA = $bundleA->items->firstWhere('product_id', $this->compA->id);
        $itemA_compB = $bundleA->items->firstWhere('product_id', $this->compB->id);
        $this->assertEquals(20000.00, (float) $itemA_compA->informational_item_price);
        $this->assertEquals(30000.00, (float) $itemA_compB->informational_item_price);

        $bundleB = $bundles->firstWhere('setting_id', $this->settingB->id);
        $itemB_compA = $bundleB->items->firstWhere('product_id', $this->compA->id);
        $itemB_compB = $bundleB->items->firstWhere('product_id', $this->compB->id);
        $this->assertEquals(22000.00, (float) $itemB_compA->informational_item_price);
        $this->assertEquals(33000.00, (float) $itemB_compB->informational_item_price);
    }

    /**
     * 2. Missing target-setting price uses active-setting fallback.
     */
    public function test_replicated_creation_falls_back_to_active_setting_price_when_target_setting_price_missing(): void
    {
        // Setting A has prices (active setting)
        ProductPrice::create([
            'product_id' => $this->compA->id,
            'setting_id' => $this->settingA->id,
            'sale_price' => 20000.00,
        ]);
        ProductPrice::create([
            'product_id' => $this->compB->id,
            'setting_id' => $this->settingA->id,
            'sale_price' => 30000.00,
        ]);

        // Setting B has NO ProductPrice for compA or compB

        $payload = [
            'name' => 'Fallback Bundle',
            'bundle_sale_price' => 100000,
            'items' => [
                ['product_id' => $this->compA->id, 'quantity' => 1],
                ['product_id' => $this->compB->id, 'quantity' => 1],
            ],
        ];

        $response = $this->withSession(['setting_id' => $this->settingA->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), $payload);

        $response->assertSessionHasNoErrors();

        $bundleB = ProductBundle::with('items')->where('name', 'FALLBACK BUNDLE')->where('setting_id', $this->settingB->id)->first();
        $this->assertNotNull($bundleB);

        $itemB_compA = $bundleB->items->firstWhere('product_id', $this->compA->id);
        $itemB_compB = $bundleB->items->firstWhere('product_id', $this->compB->id);
        // Fallback from Setting A
        $this->assertEquals(20000.00, (float) $itemB_compA->informational_item_price);
        $this->assertEquals(30000.00, (float) $itemB_compB->informational_item_price);
    }

    /**
     * 3. Missing target and active-setting prices reject creation atomically.
     */
    public function test_missing_target_and_active_setting_prices_fail_validation_atomically(): void
    {
        // Neither setting A nor setting B has price for compB
        ProductPrice::create([
            'product_id' => $this->compA->id,
            'setting_id' => $this->settingA->id,
            'sale_price' => 20000.00,
        ]);

        $payload = [
            'name' => 'Invalid Price Bundle',
            'bundle_sale_price' => 100000,
            'items' => [
                ['product_id' => $this->compA->id, 'quantity' => 1],
                ['product_id' => $this->compB->id, 'quantity' => 1],
            ],
        ];

        $response = $this->withSession(['setting_id' => $this->settingA->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), $payload);

        $response->assertSessionHasErrors();

        // Ensure atomic rollback: zero bundles created in ANY setting
        $this->assertEquals(0, ProductBundle::withoutGlobalScopes()->where('name', 'INVALID PRICE BUNDLE')->count());
    }

    /**
     * 4. Tampered create request with client informational prices cannot override server-derived prices.
     */
    public function test_tampered_informational_price_request_is_overridden_by_server_derived_snapshot(): void
    {
        ProductPrice::create([
            'product_id' => $this->compA->id,
            'setting_id' => $this->settingA->id,
            'sale_price' => 20000.00,
        ]);

        // Client attempts to pass 999999 as informational_item_price
        $payload = [
            'name' => 'Tampered Bundle',
            'bundle_sale_price' => 100000,
            'items' => [
                [
                    'product_id' => $this->compA->id,
                    'quantity' => 1,
                    'informational_item_price' => 999999.00,
                ],
            ],
        ];

        $response = $this->withSession(['setting_id' => $this->settingA->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), $payload);

        $response->assertSessionHasNoErrors();

        $bundleA = ProductBundle::with('items')->where('name', 'TAMPERED BUNDLE')->where('setting_id', $this->settingA->id)->first();
        $this->assertNotNull($bundleA);

        $item = $bundleA->items->first();
        $this->assertEquals(20000.00, (float) $item->informational_item_price);
    }

    /**
     * 5. Setting-scoped bundle edit/save refreshes only that setting copy and preserves others.
     */
    public function test_edit_save_refreshes_snapshots_for_only_that_setting_copy(): void
    {
        // Initial prices
        ProductPrice::create([
            'product_id' => $this->compA->id,
            'setting_id' => $this->settingA->id,
            'sale_price' => 20000.00,
        ]);
        ProductPrice::create([
            'product_id' => $this->compA->id,
            'setting_id' => $this->settingB->id,
            'sale_price' => 25000.00,
        ]);

        // Create bundle in both settings
        $payload = [
            'name' => 'Initial Bundle',
            'bundle_sale_price' => 100000,
            'items' => [
                ['product_id' => $this->compA->id, 'quantity' => 1],
            ],
        ];
        $this->withSession(['setting_id' => $this->settingA->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), $payload);

        $bundleA = ProductBundle::where('name', 'INITIAL BUNDLE')->where('setting_id', $this->settingA->id)->first();
        $bundleB = ProductBundle::where('name', 'INITIAL BUNDLE')->where('setting_id', $this->settingB->id)->first();

        $this->assertEquals(20000.00, (float) $bundleA->items()->first()->informational_item_price);
        $this->assertEquals(25000.00, (float) $bundleB->items()->first()->informational_item_price);

        // Update Setting A's ProductPrice to 27000
        ProductPrice::where('product_id', $this->compA->id)
            ->where('setting_id', $this->settingA->id)
            ->update(['sale_price' => 27000.00]);

        // Update Setting B's ProductPrice to 35000
        ProductPrice::where('product_id', $this->compA->id)
            ->where('setting_id', $this->settingB->id)
            ->update(['sale_price' => 35000.00]);

        // Edit and save Bundle A only
        $editPayload = [
            'name' => 'Initial Bundle',
            'bundle_sale_price' => 100000,
            'items' => [
                ['product_id' => $this->compA->id, 'quantity' => 1],
            ],
        ];

        $response = $this->withSession(['setting_id' => $this->settingA->id])
            ->put(route('products.bundle.update', [$this->parentProduct->id, $bundleA->id]), $editPayload);

        $response->assertSessionHasNoErrors();

        // Bundle A refreshed to 27000
        $this->assertEquals(27000.00, (float) $bundleA->fresh()->items()->first()->informational_item_price);

        // Bundle B remains untouched at 25000 (even though Setting B's ProductPrice is now 35000)
        $this->assertEquals(25000.00, (float) $bundleB->fresh()->items()->first()->informational_item_price);
    }

    /**
     * 6. Regression coverage proving saved bundle snapshots remain unchanged after product price changes
     * until the relevant bundle copy is saved.
     */
    public function test_saved_bundle_snapshots_remain_unchanged_after_product_price_changes_until_saved(): void
    {
        ProductPrice::create([
            'product_id' => $this->compA->id,
            'setting_id' => $this->settingA->id,
            'sale_price' => 20000.00,
        ]);

        $payload = [
            'name' => 'Stable Snapshot Bundle',
            'bundle_sale_price' => 100000,
            'items' => [
                ['product_id' => $this->compA->id, 'quantity' => 1],
            ],
        ];
        $this->withSession(['setting_id' => $this->settingA->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), $payload);

        $bundleA = ProductBundle::where('name', 'STABLE SNAPSHOT BUNDLE')->where('setting_id', $this->settingA->id)->first();
        $this->assertEquals(20000.00, (float) $bundleA->items()->first()->informational_item_price);

        // Change ProductPrice
        ProductPrice::where('product_id', $this->compA->id)
            ->where('setting_id', $this->settingA->id)
            ->update(['sale_price' => 99000.00]);

        // Reload bundle from DB and assert snapshot is still 20000
        $this->assertEquals(20000.00, (float) $bundleA->fresh()->items()->first()->informational_item_price);
    }
}
