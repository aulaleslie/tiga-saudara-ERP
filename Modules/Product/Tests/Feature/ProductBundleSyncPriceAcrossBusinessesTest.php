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

class ProductBundleSyncPriceAcrossBusinessesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $settingA;
    private Setting $settingB;
    private Product $parentProduct;
    private Product $compA;

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

        $suffix = \Illuminate\Support\Str::random(6);
        $this->settingA = Setting::create([
            'company_name' => 'Company A ' . $suffix,
            'company_email' => 'a_' . $suffix . '@example.com',
            'company_phone' => '111',
            'notification_email' => 'notifya_' . $suffix . '@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'company_address' => 'Addr A',
            'footer_text' => 'Footer A',
            'is_pkp' => true,
        ]);

        $this->settingB = Setting::create([
            'company_name' => 'Company B ' . $suffix,
            'company_email' => 'b_' . $suffix . '@example.com',
            'company_phone' => '222',
            'notification_email' => 'notifyb_' . $suffix . '@example.com',
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

        ProductPrice::create([
            'product_id' => $this->compA->id,
            'setting_id' => $this->settingA->id,
            'sale_price' => 20000.00,
        ]);
        ProductPrice::create([
            'product_id' => $this->compA->id,
            'setting_id' => $this->settingB->id,
            'sale_price' => 20000.00,
        ]);
    }

    /**
     * 4.1 Migration and model tests.
     */
    public function test_replica_group_uuid_persistence_on_creation_and_legacy_null_records(): void
    {
        // Legacy record manually created without replica_group_uuid
        $legacyBundle = ProductBundle::create([
            'setting_id' => $this->settingA->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'Legacy Bundle',
            'bundle_sale_price' => 50000,
        ]);
        $this->assertNull($legacyBundle->replica_group_uuid);

        // First creation operation
        $payload1 = [
            'name' => 'New Group 1',
            'bundle_sale_price' => 100000,
            'items' => [['product_id' => $this->compA->id, 'quantity' => 1]],
        ];
        $this->withSession(['setting_id' => $this->settingA->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), $payload1);

        $group1 = ProductBundle::where('name', 'NEW GROUP 1')->get();
        $this->assertCount(2, $group1);
        $uuid1 = $group1->first()->replica_group_uuid;
        $this->assertNotNull($uuid1);
        $this->assertEquals($uuid1, $group1->last()->replica_group_uuid);

        // Second creation operation
        $payload2 = [
            'name' => 'New Group 2',
            'bundle_sale_price' => 120000,
            'items' => [['product_id' => $this->compA->id, 'quantity' => 1]],
        ];
        $this->withSession(['setting_id' => $this->settingA->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), $payload2);

        $group2 = ProductBundle::where('name', 'NEW GROUP 2')->get();
        $this->assertCount(2, $group2);
        $uuid2 = $group2->first()->replica_group_uuid;
        $this->assertNotNull($uuid2);
        $this->assertNotEquals($uuid1, $uuid2);
    }

    /**
     * 4.2 Edit rendering, checkbox, unchecked default, validation, and historical bundle guidance.
     */
    public function test_edit_view_rendering_and_checkbox_guidance(): void
    {
        // 1. Grouped bundle
        $payload = [
            'name' => 'Grouped Bundle',
            'bundle_sale_price' => 100000,
            'items' => [['product_id' => $this->compA->id, 'quantity' => 1]],
        ];
        $this->withSession(['setting_id' => $this->settingA->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), $payload);

        $groupedBundle = ProductBundle::where('name', 'GROUPED BUNDLE')->where('setting_id', $this->settingA->id)->first();

        $resGrouped = $this->withSession(['setting_id' => $this->settingA->id])
            ->get(route('products.bundle.edit', [$this->parentProduct->id, $groupedBundle->id]));
        $resGrouped->assertStatus(200);
        $resGrouped->assertSee('Terapkan harga ke semua bisnis');
        $resGrouped->assertDontSee('Bundle lama tidak terhubung dengan salinan bisnis lainnya.');

        $html = $resGrouped->getContent();
        // Match the <input ... id="apply_price_to_all_businesses" ...> tag regardless of attribute ordering/whitespace
        $matched = preg_match('/<input\b[^>]*\bid=["\']apply_price_to_all_businesses["\'][^>]*>/i', $html, $matches);
        $this->assertEquals(1, $matched, 'Checkbox input element for apply_price_to_all_businesses should be present in HTML');
        $inputHtml = $matches[0];
        $this->assertDoesNotMatchRegularExpression('/\bchecked\b/i', $inputHtml, 'Checkbox apply_price_to_all_businesses should not have checked attribute by default');

        // 2. Legacy bundle
        $legacyBundle = ProductBundle::create([
            'setting_id' => $this->settingA->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'Legacy Bundle Edit',
            'bundle_sale_price' => 50000,
        ]);

        $resLegacy = $this->withSession(['setting_id' => $this->settingA->id])
            ->get(route('products.bundle.edit', [$this->parentProduct->id, $legacyBundle->id]));
        $resLegacy->assertStatus(200);
        $resLegacy->assertSee('Bundle lama tidak terhubung dengan salinan bisnis lainnya.');
        $resLegacy->assertDontSee('Terapkan harga ke semua bisnis');

        // 3. Validation error preserves submitted checkbox state
        $invalidPayload = [
            'name' => '', // causes validation error
            'bundle_sale_price' => 150000,
            'apply_price_to_all_businesses' => '1',
            'items' => [['product_id' => $this->compA->id, 'quantity' => 1]],
        ];
        $resVal = $this->withSession(['setting_id' => $this->settingA->id])
            ->put(route('products.bundle.update', [$this->parentProduct->id, $groupedBundle->id]), $invalidPayload);

        $resVal->assertSessionHasErrors('name');
        $this->assertEquals('1', session()->getOldInput('apply_price_to_all_businesses'));
    }

    /**
     * 4.3 Unchecked edits remain local, checked edits synchronize bundle_sale_price only.
     */
    public function test_update_price_synchronization_and_local_field_preservation(): void
    {
        $payload = [
            'name' => 'Sync Test Bundle',
            'description' => 'Original Desc',
            'bundle_sale_price' => 100000,
            'items' => [['product_id' => $this->compA->id, 'quantity' => 1]],
        ];
        $this->withSession(['setting_id' => $this->settingA->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), $payload);

        $bundleA = ProductBundle::where('name', 'SYNC TEST BUNDLE')->where('setting_id', $this->settingA->id)->first();
        $bundleB = ProductBundle::where('name', 'SYNC TEST BUNDLE')->where('setting_id', $this->settingB->id)->first();

        // 1. Unchecked update remains local
        $editPayloadUnchecked = [
            'name' => 'Local Name Change',
            'description' => 'Local Desc',
            'bundle_sale_price' => 110000,
            'apply_price_to_all_businesses' => '0',
            'items' => [['product_id' => $this->compA->id, 'quantity' => 1]],
        ];
        $this->withSession(['setting_id' => $this->settingA->id])
            ->put(route('products.bundle.update', [$this->parentProduct->id, $bundleA->id]), $editPayloadUnchecked);

        $bundleA = $bundleA->fresh();
        $bundleB = $bundleB->fresh();

        $this->assertEquals('LOCAL NAME CHANGE', $bundleA->name);
        $this->assertEquals(110000.00, (float) $bundleA->bundle_sale_price);

        $this->assertEquals('SYNC TEST BUNDLE', $bundleB->name);
        $this->assertEquals('ORIGINAL DESC', $bundleB->description);
        $this->assertEquals(100000.00, (float) $bundleB->bundle_sale_price);

        // 2. Checked update synchronizes bundle_sale_price ONLY
        $editPayloadChecked = [
            'name' => 'Synced Name Change A Only',
            'description' => 'Synced Desc A Only',
            'bundle_sale_price' => 200000,
            'apply_price_to_all_businesses' => '1',
            'items' => [['product_id' => $this->compA->id, 'quantity' => 1]],
        ];
        $this->withSession(['setting_id' => $this->settingA->id])
            ->put(route('products.bundle.update', [$this->parentProduct->id, $bundleA->id]), $editPayloadChecked);

        $bundleA = $bundleA->fresh();
        $bundleB = $bundleB->fresh();

        // Bundle A updated fully
        $this->assertEquals('SYNCED NAME CHANGE A ONLY', $bundleA->name);
        $this->assertEquals('SYNCED DESC A ONLY', $bundleA->description);
        $this->assertEquals(200000.00, (float) $bundleA->bundle_sale_price);

        // Bundle B updated price ONLY; name and description remained local
        $this->assertEquals('SYNC TEST BUNDLE', $bundleB->name);
        $this->assertEquals('ORIGINAL DESC', $bundleB->description);
        $this->assertEquals(200000.00, (float) $bundleB->bundle_sale_price);
    }

    /**
     * 4.4 Safety tests: null/different groups untouched, local deletion semantics, authorization enforced.
     */
    public function test_safety_and_group_isolation(): void
    {
        // Create Group 1
        $payload1 = [
            'name' => 'Group One',
            'bundle_sale_price' => 100000,
            'items' => [['product_id' => $this->compA->id, 'quantity' => 1]],
        ];
        $this->withSession(['setting_id' => $this->settingA->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), $payload1);

        // Create Group 2
        $payload2 = [
            'name' => 'Group Two',
            'bundle_sale_price' => 100000,
            'items' => [['product_id' => $this->compA->id, 'quantity' => 1]],
        ];
        $this->withSession(['setting_id' => $this->settingA->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), $payload2);

        // Legacy bundle
        $legacy = ProductBundle::create([
            'setting_id' => $this->settingB->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'Legacy Standalone',
            'bundle_sale_price' => 100000,
        ]);

        $group1A = ProductBundle::where('name', 'GROUP ONE')->where('setting_id', $this->settingA->id)->first();
        $group1B = ProductBundle::where('name', 'GROUP ONE')->where('setting_id', $this->settingB->id)->first();
        $group2B = ProductBundle::where('name', 'GROUP TWO')->where('setting_id', $this->settingB->id)->first();

        // 1. Forged identity test: passing another existing bundle group's replica_group_uuid in payload cannot redirect propagation.
        // Run BEFORE deleting group1B to prove real-group propagation to all existing members (group1A & group1B).
        $existingGroup2Uuid = $group2B->replica_group_uuid;
        $this->assertNotNull($existingGroup2Uuid);
        $this->assertNotEquals($group1A->replica_group_uuid, $existingGroup2Uuid);

        $forgedPayload = [
            'name' => 'Forged Group Attempt',
            'bundle_sale_price' => 500000,
            'apply_price_to_all_businesses' => '1',
            'replica_group_uuid' => $existingGroup2Uuid,
            'items' => [['product_id' => $this->compA->id, 'quantity' => 1]],
        ];
        $this->withSession(['setting_id' => $this->settingA->id])
            ->put(route('products.bundle.update', [$this->parentProduct->id, $group1A->id]), $forgedPayload);

        $group1A = $group1A->fresh();
        $group1B = $group1B->fresh();
        // Assert:
        // - The route bundle retains its persisted UUID
        $this->assertNotEquals($existingGroup2Uuid, $group1A->replica_group_uuid);
        // - The unrelated forged-target group remains unchanged
        $this->assertEquals(100000.00, (float) $group2B->fresh()->bundle_sale_price);
        $this->assertEquals('GROUP TWO', $group2B->fresh()->name);
        // - Synchronization followed route bundle's real group (group1A & group1B)
        $this->assertEquals(500000.00, (float) $group1A->bundle_sale_price);
        $this->assertEquals(500000.00, (float) $group1B->bundle_sale_price);

        // 2. Partial-group synchronization test after local deletion
        $group1B->delete();

        $partialPayload = [
            'name' => 'Group One Partial Sync',
            'bundle_sale_price' => 300000,
            'apply_price_to_all_businesses' => '1',
            'items' => [['product_id' => $this->compA->id, 'quantity' => 1]],
        ];
        $this->withSession(['setting_id' => $this->settingA->id])
            ->put(route('products.bundle.update', [$this->parentProduct->id, $group1A->id]), $partialPayload);

        $this->assertEquals(300000.00, (float) $group1A->fresh()->bundle_sale_price);
        // Group 2 and Legacy bundle remain untouched
        $this->assertEquals(100000.00, (float) $group2B->fresh()->bundle_sale_price);
        $this->assertEquals(100000.00, (float) $legacy->fresh()->bundle_sale_price);

        // Authorization check: active setting B user cannot update group1A
        $resAuth = $this->withSession(['setting_id' => $this->settingB->id])
            ->put(route('products.bundle.update', [$this->parentProduct->id, $group1A->id]), $partialPayload);
        $resAuth->assertStatus(404);
    }

    /**
     * 4.5 Forced-failure and atomic rollback test.
     */
    public function test_synchronized_update_failure_rolls_back_atomically(): void
    {
        $payload = [
            'name' => 'Rollback Bundle',
            'bundle_sale_price' => 100000,
            'items' => [['product_id' => $this->compA->id, 'quantity' => 1]],
        ];
        $this->withSession(['setting_id' => $this->settingA->id])
            ->post(route('products.bundle.store', $this->parentProduct->id), $payload);

        $bundleA = ProductBundle::where('name', 'ROLLBACK BUNDLE')->where('setting_id', $this->settingA->id)->first();
        $bundleB = ProductBundle::where('name', 'ROLLBACK BUNDLE')->where('setting_id', $this->settingB->id)->first();

        $initialItemCount = $bundleA->items()->count();
        $this->assertEquals(1, $initialItemCount);

        // Force a failure in DB::listen AFTER the cross-business UPDATE product_bundles query has executed
        \Illuminate\Support\Facades\DB::listen(function ($query) {
            if (str_contains(strtolower($query->sql), 'update') && str_contains(strtolower($query->sql), 'replica_group_uuid')) {
                throw new \Exception('Simulated post-propagation transaction failure');
            }
        });

        $editPayload = [
            'name' => 'Should Roll Back',
            'bundle_sale_price' => 250000,
            'apply_price_to_all_businesses' => '1',
            'items' => [['product_id' => $this->compA->id, 'quantity' => 1]],
        ];

        $response = $this->from(route('products.bundle.edit', [$this->parentProduct->id, $bundleA->id]))
            ->withSession(['setting_id' => $this->settingA->id])
            ->put(route('products.bundle.update', [$this->parentProduct->id, $bundleA->id]), $editPayload);

        $response->assertRedirect(route('products.bundle.edit', [$this->parentProduct->id, $bundleA->id]));
        $response->assertSessionHas('error', 'Failed to update bundle.');

        // Verify full atomic rollback:
        // 1. Active-business bundle header reverted
        $this->assertEquals('ROLLBACK BUNDLE', $bundleA->fresh()->name);
        // 2. Active-business component rows reverted (not deleted)
        $this->assertEquals(1, $bundleA->fresh()->items()->count());
        $this->assertEquals(100000.00, (float) $bundleA->fresh()->bundle_sale_price);
        // 3. Every grouped business's bundle price reverted (propagation query rolled back)
        $this->assertEquals(100000.00, (float) $bundleB->fresh()->bundle_sale_price);
    }
}
