<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class ProductBundleDefinitionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $settingA;
    private Setting $settingB;
    private Product $parentProduct;
    private Product $componentProductA;
    private Product $componentProductB;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn() => true);

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

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

        $this->componentProductA = Product::create([
            'setting_id' => $this->settingA->id,
            'product_name' => 'Component Product A',
            'product_code' => 'COMP-A',
            'product_unit' => 'pc',
            'product_cost' => 5000,
            'product_price' => 20000,
            'product_quantity' => 10,
        ]);

        $this->componentProductB = Product::create([
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
     * 4.1 Test creation produces one identical, enabled copy per existing setting,
     * and a failure in any copy rolls back all headers and items.
     */
    public function test_creation_produces_identical_enabled_copies_for_all_settings_atomically(): void
    {
        $payload = [
            'name' => 'Bundle Replicated',
            'description' => 'Replicated to all settings',
            'bundle_sale_price' => 90000,
            'items' => [
                [
                    'product_id' => $this->componentProductA->id,
                    'quantity' => 2,
                    'informational_item_price' => 20000,
                ],
                [
                    'product_id' => $this->componentProductB->id,
                    'quantity' => 1,
                    'informational_item_price' => 30000,
                ],
            ],
        ];

        $response = $this->withSession(['setting_id' => $this->settingA->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), $payload);

        $response->assertRedirect(route('products.show', $this->parentProduct->id));
        $response->assertSessionHasNoErrors();

        // Check both setting copies were created
        $bundles = ProductBundle::with('items')->where('name', 'BUNDLE REPLICATED')->get();
        $this->assertCount(2, $bundles);

        $bundleA = $bundles->firstWhere('setting_id', $this->settingA->id);
        $bundleB = $bundles->firstWhere('setting_id', $this->settingB->id);

        $this->assertNotNull($bundleA);
        $this->assertNotNull($bundleB);
        $this->assertTrue($bundleA->is_active);
        $this->assertTrue($bundleB->is_active);
        $this->assertEquals(90000, $bundleA->bundle_sale_price);
        $this->assertEquals(90000, $bundleB->bundle_sale_price);

        $this->assertCount(2, $bundleA->items);
        $this->assertCount(2, $bundleB->items);
    }

    /**
     * 4.2 Test create/update for distinct components, duplicate rejection with prior-state preservation,
     * and database enforcement of unique bundle/component identity.
     */
    public function test_duplicate_components_are_rejected_and_preserve_prior_state(): void
    {
        // 1) Creation with duplicates fails
        $payloadDuplicate = [
            'name' => 'Duplicate Bundle',
            'bundle_sale_price' => 50000,
            'items' => [
                ['product_id' => $this->componentProductA->id, 'quantity' => 1, 'informational_item_price' => 20000],
                ['product_id' => $this->componentProductA->id, 'quantity' => 2, 'informational_item_price' => 20000],
            ],
        ];

        $response = $this->withSession(['setting_id' => $this->settingA->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), $payloadDuplicate);

        $response->assertSessionHasErrors('items.0.product_id');
        $this->assertEquals(0, ProductBundle::count());

        // 2) Valid creation
        $validPayload = [
            'name' => 'Valid Bundle',
            'bundle_sale_price' => 60000,
            'items' => [
                ['product_id' => $this->componentProductA->id, 'quantity' => 1, 'informational_item_price' => 20000],
            ],
        ];
        $this->withSession(['setting_id' => $this->settingA->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), $validPayload);

        $bundleA = ProductBundle::where('setting_id', $this->settingA->id)->first();
        $this->assertNotNull($bundleA);

        // 3) Update with duplicate components fails and preserves prior state
        $updateDuplicate = [
            'name' => 'Updated Valid Bundle',
            'bundle_sale_price' => 80000,
            'items' => [
                ['product_id' => $this->componentProductA->id, 'quantity' => 1, 'informational_item_price' => 20000],
                ['product_id' => $this->componentProductA->id, 'quantity' => 3, 'informational_item_price' => 20000],
            ],
        ];

        $updateResponse = $this->withSession(['setting_id' => $this->settingA->id])
            ->put(route('products.bundle.update', [$this->parentProduct->id, $bundleA->id]), $updateDuplicate);

        $updateResponse->assertSessionHasErrors('items.0.product_id');

        $bundleA->refresh();
        $this->assertEquals('VALID BUNDLE', $bundleA->name);
        $this->assertCount(1, $bundleA->items);

        // 4) Database unique constraint rejects duplicate insertion
        $this->expectException(\Illuminate\Database\QueryException::class);
        ProductBundleItem::create([
            'bundle_id' => $bundleA->id,
            'product_id' => $this->componentProductA->id,
            'quantity' => 5,
            'informational_item_price' => 20000,
        ]);
    }

    /**
     * 4.3 Test route-tampering: update rejects a bundle belonging to another product or setting without mutating.
     */
    public function test_route_tampering_fails_with_404_and_preserves_state(): void
    {
        $bundleA = ProductBundle::create([
            'setting_id' => $this->settingA->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'Original Bundle A',
            'bundle_sale_price' => 50000,
            'is_active' => true,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundleA->id,
            'product_id' => $this->componentProductA->id,
            'quantity' => 1,
            'informational_item_price' => 20000,
        ]);

        $otherProduct = Product::create([
            'setting_id' => $this->settingA->id,
            'product_name' => 'Other Product',
            'product_code' => 'OTHER-01',
            'product_unit' => 'pc',
            'product_cost' => 5000,
            'product_price' => 20000,
            'product_quantity' => 10,
        ]);

        $tamperPayload = [
            'name' => 'Hacked Bundle',
            'bundle_sale_price' => 1000,
            'items' => [
                ['product_id' => $this->componentProductB->id, 'quantity' => 5, 'informational_item_price' => 30000],
            ],
        ];

        // Tampering via wrong parent product
        $resProductMismatch = $this->withSession(['setting_id' => $this->settingA->id])
            ->put(route('products.bundle.update', [$otherProduct->id, $bundleA->id]), $tamperPayload);
        $resProductMismatch->assertNotFound();

        // Tampering via wrong active setting
        $resSettingMismatch = $this->withSession(['setting_id' => $this->settingB->id])
            ->put(route('products.bundle.update', [$this->parentProduct->id, $bundleA->id]), $tamperPayload);
        $resSettingMismatch->assertNotFound();

        // Verify bundleA is unchanged
        $bundleA->refresh();
        $this->assertEquals('ORIGINAL BUNDLE A', $bundleA->name);
        $this->assertCount(1, $bundleA->items);
        $this->assertEquals($this->componentProductA->id, $bundleA->items->first()->product_id);
    }

    /**
     * 4.4 Test edit, enable/disable, and delete affect only the selected setting copy and settings added later receive no copy.
     */
    public function test_per_setting_independence_for_edit_disable_delete_and_later_settings(): void
    {
        // Create initial bundle via store to replicate to setting A and B
        $this->withSession(['setting_id' => $this->settingA->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), [
                'name' => 'Independent Bundle',
                'bundle_sale_price' => 70000,
                'items' => [
                    ['product_id' => $this->componentProductA->id, 'quantity' => 1, 'informational_item_price' => 20000],
                ],
            ]);

        $bundleA = ProductBundle::where('setting_id', $this->settingA->id)->first();
        $bundleB = ProductBundle::where('setting_id', $this->settingB->id)->first();

        $this->assertNotNull($bundleA);
        $this->assertNotNull($bundleB);

        // 1) Disable setting A copy
        $this->withSession(['setting_id' => $this->settingA->id])
            ->put(route('products.bundle.update', [$this->parentProduct->id, $bundleA->id]), [
                'name' => 'Disabled In A',
                'bundle_sale_price' => 70000,
                'is_active' => false,
                'items' => [
                    ['product_id' => $this->componentProductA->id, 'quantity' => 1, 'informational_item_price' => 20000],
                ],
            ]);

        $bundleA->refresh();
        $bundleB->refresh();

        $this->assertFalse($bundleA->is_active);
        $this->assertTrue($bundleB->is_active);
        $this->assertEquals('INDEPENDENT BUNDLE', $bundleB->name);

        // 2) Delete setting A copy
        $this->withSession(['setting_id' => $this->settingA->id])
            ->delete(route('products.bundle.destroy', [$this->parentProduct->id, $bundleA->id]));

        $this->assertNull(ProductBundle::find($bundleA->id));
        $this->assertNotNull(ProductBundle::find($bundleB->id));

        // 3) Create Setting C later - does not receive bundle copies automatically
        $settingC = Setting::create([
            'company_name' => 'Company C',
            'company_email' => 'c@example.com',
            'company_phone' => '333',
            'notification_email' => 'notifyc@example.com',
            'default_currency_id' => $this->settingA->default_currency_id,
            'default_currency_position' => 'left',
            'company_address' => 'Addr C',
            'footer_text' => 'Footer C',
            'is_pkp' => true,
        ]);

        $bundlesInC = ProductBundle::where('setting_id', $settingC->id)->count();
        $this->assertEquals(0, $bundlesInC);
    }

    /**
     * 4.5a Test direct database deletion is blocked for parent products referencing bundles.
     */
    public function test_direct_database_deletion_blocked_for_parent_product(): void
    {
        ProductBundle::create([
            'setting_id' => $this->settingA->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'Guard Parent Bundle',
            'bundle_sale_price' => 50000,
            'is_active' => true,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->parentProduct->delete();
    }

    /**
     * 4.5b Test direct database deletion is blocked for component products referencing bundle items.
     */
    public function test_direct_database_deletion_blocked_for_component_product(): void
    {
        $bundle = ProductBundle::create([
            'setting_id' => $this->settingA->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'Guard Component Bundle',
            'bundle_sale_price' => 50000,
            'is_active' => true,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $this->componentProductA->id,
            'quantity' => 1,
            'informational_item_price' => 20000,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->componentProductA->delete();
    }

    /**
     * 4.5c Test controller application-level deletion guards for parent and component products.
     */
    public function test_application_level_deletion_guards_block_parent_and_component(): void
    {
        $bundle = ProductBundle::create([
            'setting_id' => $this->settingA->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'Guard Bundle',
            'bundle_sale_price' => 50000,
            'is_active' => true,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $this->componentProductA->id,
            'quantity' => 1,
            'informational_item_price' => 20000,
        ]);

        // 1) Attempt to delete parent product via controller -> blocked
        $resParent = $this->delete(route('products.destroy', $this->parentProduct->id));
        $resParent->assertRedirect();
        $this->assertNotNull(Product::find($this->parentProduct->id));

        // 2) Attempt to delete component product via controller -> blocked
        $resComp = $this->delete(route('products.destroy', $this->componentProductA->id));
        $resComp->assertRedirect();
        $this->assertNotNull(Product::find($this->componentProductA->id));
    }

    /**
     * Test successful product deletion after references are removed.
     */
    public function test_product_deletion_succeeds_after_bundle_references_are_removed(): void
    {
        $bundle = ProductBundle::create([
            'setting_id' => $this->settingA->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'Guard Bundle',
            'bundle_sale_price' => 50000,
            'is_active' => true,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $this->componentProductA->id,
            'quantity' => 1,
            'informational_item_price' => 20000,
        ]);

        // Explicitly delete bundle
        $bundle->delete();

        // Product deletion now succeeds directly and via controller
        $resParent = $this->delete(route('products.destroy', $this->parentProduct->id));
        $resParent->assertRedirect(route('products.index'));
        $this->assertNull(Product::find($this->parentProduct->id));

        $resComp = $this->delete(route('products.destroy', $this->componentProductA->id));
        $resComp->assertRedirect(route('products.index'));
        $this->assertNull(Product::find($this->componentProductA->id));
    }

    /**
     * 3. Real all-setting rollback test on failure during multi-setting bundle creation.
     */
    public function test_atomic_rollback_cleans_all_settings_when_persistence_fails(): void
    {
        $payload = [
            'name' => 'Failing Bundle',
            'bundle_sale_price' => 75000,
            'items' => [
                ['product_id' => $this->componentProductA->id, 'quantity' => 1, 'informational_item_price' => 20000],
            ],
        ];

        // Register a temporary creating listener on ProductBundle to fail on setting B
        ProductBundle::creating(function ($bundle) {
            if ((int) $bundle->setting_id === (int) $this->settingB->id) {
                throw new \RuntimeException('Simulated persistence failure on setting B');
            }
        });

        try {
            $this->withSession(['setting_id' => $this->settingA->id])
                ->post(route('products.bundle.store', $this->parentProduct->id), $payload);
        } catch (\Throwable $e) {
            // Controller rethrow or unhandled exception
        } finally {
            ProductBundle::flushEventListeners();
        }

        // Verify zero headers and zero items exist in setting A and setting B
        $this->assertEquals(0, ProductBundle::where('name', 'Failing Bundle')->count());
        $this->assertEquals(0, ProductBundle::where('setting_id', $this->settingA->id)->count());
        $this->assertEquals(0, ProductBundle::where('setting_id', $this->settingB->id)->count());
        $this->assertEquals(0, ProductBundleItem::count());
    }
}
