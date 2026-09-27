<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductCrossBusinessBundleDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private Setting $settingA;
    private Setting $settingB;
    private Product $parentProductA;
    private Product $compProductA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'products.show',
            'products.bundle.access',
            'products.bundle.create',
            'products.bundle.edit',
            'products.bundle.delete',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $suffix = Str::random(6);
        $this->settingA = Setting::create([
            'company_name' => 'Company A ' . $suffix,
            'company_email' => 'a_' . $suffix . '@example.com',
            'company_phone' => '111',
            'notification_email' => 'notifya_' . $suffix . '@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
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
            'default_currency_position' => 'prefix',
            'company_address' => 'Addr B',
            'footer_text' => 'Footer B',
            'is_pkp' => true,
        ]);

        $role = Role::create(['name' => 'ADMIN-' . $suffix]);
        $role->syncPermissions([
            'products.show',
            'products.bundle.access',
            'products.bundle.create',
            'products.bundle.edit',
            'products.bundle.delete',
        ]);

        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole($role);
        $this->adminUser->settings()->attach($this->settingA->id, ['role_id' => $role->id]);
        $this->adminUser->settings()->attach($this->settingB->id, ['role_id' => $role->id]);

        $this->parentProductA = Product::create([
            'setting_id' => $this->settingA->id,
            'product_name' => 'Parent Product',
            'product_code' => 'PARENT-01',
            'product_unit' => 'pc',
            'product_cost' => 10000,
            'product_price' => 50000,
            'product_quantity' => 10,
        ]);

        $this->compProductA = Product::create([
            'setting_id' => $this->settingA->id,
            'product_name' => 'Comp Product',
            'product_code' => 'COMP-01',
            'product_unit' => 'pc',
            'product_cost' => 5000,
            'product_price' => 20000,
            'product_quantity' => 10,
        ]);
    }

    private function createBundle(array $attributes = [], array $items = []): ProductBundle
    {
        $bundle = ProductBundle::create(array_merge([
            'parent_product_id' => $this->parentProductA->id,
            'setting_id' => $this->settingA->id,
            'name' => 'Test Bundle',
            'bundle_sale_price' => 45000,
            'is_active' => true,
        ], $attributes));

        if (empty($items)) {
            $items = [
                ['product_id' => $this->compProductA->id, 'quantity' => 1, 'informational_item_price' => 20000],
            ];
        }

        foreach ($items as $item) {
            ProductBundleItem::create(array_merge(['bundle_id' => $bundle->id], $item));
        }

        return $bundle;
    }

    /**
     * 3.1 Rendering tests: native browser confirm is absent, reusable modal identifies bundle, grouped controls unchecked by default, historical guidance shown.
     */
    public function test_product_detail_page_renders_modal_deletion_controls(): void
    {
        $groupUuid = Str::uuid()->toString();
        $groupedBundle = $this->createBundle(['name' => 'Grouped Bundle', 'setting_id' => $this->settingA->id, 'replica_group_uuid' => $groupUuid]);
        $historicalBundle = $this->createBundle(['name' => 'Historical Bundle', 'setting_id' => $this->settingA->id, 'replica_group_uuid' => null]);

        $response = $this->actingAs($this->adminUser)
            ->withSession(['setting_id' => $this->settingA->id])
            ->get(route('products.show', $this->parentProductA->id));

        $response->assertStatus(200);
        $response->assertDontSee("onclick=\"return confirm('Yakin ingin menghapus?');\"", false);

        $response->assertSee('id="bundleDeleteModal"', false);
        $response->assertSee('Hapus Paket Penjualan');
        $response->assertSee('Hapus paket ini dari semua bisnis');
        $response->assertSee('Bundle lama tidak terhubung dengan salinan bisnis lainnya dan hanya akan dihapus dari bisnis ini.');

        $content = $response->getContent();
        $this->assertStringContainsString('data-target="#bundleDeleteModal"', $content);
        $this->assertStringContainsString('data-destroy-url="' . route('products.bundle.destroy', [$this->parentProductA->id, $groupedBundle->id]) . '"', $content);
        $this->assertStringContainsString('data-destroy-url="' . route('products.bundle.destroy', [$this->parentProductA->id, $historicalBundle->id]) . '"', $content);
        $this->assertStringContainsString('GROUPED BUNDLE', $content);
        $this->assertStringContainsString('HISTORICAL BUNDLE', $content);
        $this->assertStringContainsString('data-is-grouped="1"', $content);
        $this->assertStringContainsString('data-is-grouped="0"', $content);
    }

    /**
     * 3.2 Request tests: Default deletion removes only active-setting copy.
     */
    public function test_default_deletion_removes_only_active_setting_bundle_copy(): void
    {
        $groupUuid = Str::uuid()->toString();
        $bundleA = $this->createBundle(['setting_id' => $this->settingA->id, 'replica_group_uuid' => $groupUuid]);
        $bundleB = $this->createBundle(['setting_id' => $this->settingB->id, 'replica_group_uuid' => $groupUuid]);

        $response = $this->actingAs($this->adminUser)
            ->withSession(['setting_id' => $this->settingA->id])
            ->delete(route('products.bundle.destroy', [$this->parentProductA->id, $bundleA->id]), [
                'delete_from_all_businesses' => 0,
            ]);

        $response->assertRedirect(route('products.show', $this->parentProductA->id));
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('product_bundles', ['id' => $bundleA->id]);
        $this->assertDatabaseHas('product_bundles', ['id' => $bundleB->id]);
    }

    /**
     * 3.2 Request tests: Selected deletion removes every surviving group member.
     */
    public function test_selected_cross_business_deletion_removes_all_group_members(): void
    {
        $groupUuid = Str::uuid()->toString();
        $bundleA = $this->createBundle(['setting_id' => $this->settingA->id, 'replica_group_uuid' => $groupUuid]);
        $bundleB = $this->createBundle(['setting_id' => $this->settingB->id, 'replica_group_uuid' => $groupUuid]);

        $response = $this->actingAs($this->adminUser)
            ->withSession(['setting_id' => $this->settingA->id])
            ->delete(route('products.bundle.destroy', [$this->parentProductA->id, $bundleA->id]), [
                'delete_from_all_businesses' => 1,
            ]);

        $response->assertRedirect(route('products.show', $this->parentProductA->id));
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('product_bundles', ['id' => $bundleA->id]);
        $this->assertDatabaseMissing('product_bundles', ['id' => $bundleB->id]);
    }

    /**
     * 3.3 Safety tests: Forged UUID input cannot redirect deletion and unrelated groups survive.
     */
    public function test_forged_uuid_input_cannot_redirect_deletion_and_unrelated_groups_survive(): void
    {
        $group1 = Str::uuid()->toString();
        $group2 = Str::uuid()->toString();

        $bundle1A = $this->createBundle(['name' => 'G1A', 'setting_id' => $this->settingA->id, 'replica_group_uuid' => $group1]);
        $bundle1B = $this->createBundle(['name' => 'G1B', 'setting_id' => $this->settingB->id, 'replica_group_uuid' => $group1]);

        $bundle2A = $this->createBundle(['name' => 'G2A', 'setting_id' => $this->settingA->id, 'replica_group_uuid' => $group2]);
        $bundle2B = $this->createBundle(['name' => 'G2B', 'setting_id' => $this->settingB->id, 'replica_group_uuid' => $group2]);

        $response = $this->actingAs($this->adminUser)
            ->withSession(['setting_id' => $this->settingA->id])
            ->delete(route('products.bundle.destroy', [$this->parentProductA->id, $bundle1A->id]), [
                'delete_from_all_businesses' => 1,
                'replica_group_uuid' => $group2, // Forged payload input
            ]);

        $response->assertRedirect(route('products.show', $this->parentProductA->id));

        $this->assertDatabaseMissing('product_bundles', ['id' => $bundle1A->id]);
        $this->assertDatabaseMissing('product_bundles', ['id' => $bundle1B->id]);

        $this->assertDatabaseHas('product_bundles', ['id' => $bundle2A->id]);
        $this->assertDatabaseHas('product_bundles', ['id' => $bundle2B->id]);
    }

    /**
     * 3.3 Safety tests: Null lineage requests cannot fan out.
     */
    public function test_null_lineage_requests_cannot_fan_out(): void
    {
        $historical1 = $this->createBundle(['name' => 'Hist1', 'setting_id' => $this->settingA->id, 'replica_group_uuid' => null]);
        $historical2 = $this->createBundle(['name' => 'Hist2', 'setting_id' => $this->settingB->id, 'replica_group_uuid' => null]);

        $response = $this->actingAs($this->adminUser)
            ->withSession(['setting_id' => $this->settingA->id])
            ->delete(route('products.bundle.destroy', [$this->parentProductA->id, $historical1->id]), [
                'delete_from_all_businesses' => 1,
            ]);

        $response->assertRedirect(route('products.show', $this->parentProductA->id));

        $this->assertDatabaseMissing('product_bundles', ['id' => $historical1->id]);
        $this->assertDatabaseHas('product_bundles', ['id' => $historical2->id]);
    }

    /**
     * 3.3 Safety tests: Route/setting/permission authorization remains enforced.
     */
    public function test_authorization_and_ownership_checks_are_enforced(): void
    {
        $bundleA = $this->createBundle(['setting_id' => $this->settingA->id]);
        $bundleB = $this->createBundle(['setting_id' => $this->settingB->id]);

        // 1. Attempting to delete settingB's bundle while active session is settingA -> 404
        $response = $this->actingAs($this->adminUser)
            ->withSession(['setting_id' => $this->settingA->id])
            ->delete(route('products.bundle.destroy', [$this->parentProductA->id, $bundleB->id]), [
                'delete_from_all_businesses' => 1,
            ]);
        $response->assertStatus(404);
        $this->assertDatabaseHas('product_bundles', ['id' => $bundleB->id]);

        // 2. Mismatched parent product in route -> 404
        $otherParentProduct = Product::create([
            'setting_id' => $this->settingA->id,
            'product_name' => 'Other Parent Product',
            'product_code' => 'PARENT-02',
            'product_unit' => 'pc',
            'product_cost' => 10000,
            'product_price' => 50000,
            'product_quantity' => 10,
        ]);

        $responseMismatch = $this->actingAs($this->adminUser)
            ->withSession(['setting_id' => $this->settingA->id])
            ->delete(route('products.bundle.destroy', [$otherParentProduct->id, $bundleA->id]), [
                'delete_from_all_businesses' => 1,
            ]);
        $responseMismatch->assertStatus(404);
        $this->assertDatabaseHas('product_bundles', ['id' => $bundleA->id]);

        // 3. User without delete permission -> 403
        $unprivilegedRole = Role::create(['name' => 'NO-DEL-' . Str::random(4)]);
        $unprivilegedRole->syncPermissions(['products.show']);
        $unprivilegedUser = User::factory()->create();
        $unprivilegedUser->assignRole($unprivilegedRole);

        $responsePerm = $this->actingAs($unprivilegedUser)
            ->withSession(['setting_id' => $this->settingA->id])
            ->delete(route('products.bundle.destroy', [$this->parentProductA->id, $bundleA->id]));

        $responsePerm->assertStatus(403);
        $this->assertDatabaseHas('product_bundles', ['id' => $bundleA->id]);
    }

    /**
     * 3.4 Mid-group failure test proving rollback restores all copies and items.
     */
    public function test_mid_group_failure_rolls_back_entire_transaction(): void
    {
        $groupUuid = Str::uuid()->toString();
        // Ensure deterministic ID ordering (bundleA id < bundleB id)
        $bundleA = $this->createBundle(['name' => 'Bundle A', 'setting_id' => $this->settingA->id, 'replica_group_uuid' => $groupUuid]);
        $bundleB = $this->createBundle(['name' => 'Bundle B', 'setting_id' => $this->settingB->id, 'replica_group_uuid' => $groupUuid]);

        $deletedFirstBundleId = null;

        // Mock deleting event on ProductBundle model to track first deletion and throw on second
        ProductBundle::deleting(function (ProductBundle $bundle) use ($bundleA, $bundleB, &$deletedFirstBundleId) {
            if ($bundle->id === $bundleA->id) {
                $deletedFirstBundleId = $bundle->id;
            } elseif ($bundle->id === $bundleB->id) {
                throw new \RuntimeException('Simulated deletion error on second group copy');
            }
        });

        $response = $this->actingAs($this->adminUser)
            ->withSession(['setting_id' => $this->settingA->id])
            ->delete(route('products.bundle.destroy', [$this->parentProductA->id, $bundleA->id]), [
                'delete_from_all_businesses' => 1,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Failed to delete bundle.');

        // Prove the first bundle attempted deletion before failure on second
        $this->assertEquals($bundleA->id, $deletedFirstBundleId);

        // Both headers and items should still exist due to rollback
        $this->assertDatabaseHas('product_bundles', ['id' => $bundleA->id]);
        $this->assertDatabaseHas('product_bundles', ['id' => $bundleB->id]);
        $this->assertDatabaseHas('product_bundle_items', ['bundle_id' => $bundleA->id]);
        $this->assertDatabaseHas('product_bundle_items', ['bundle_id' => $bundleB->id]);
    }
}
