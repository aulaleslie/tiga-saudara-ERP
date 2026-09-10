<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\AdjustedProduct;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Services\BreakageApprovalService;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 5.5: Focused view/permission tests for the breakage detail view --
 * pending live-preview projection, blocking conflicts, approval controls,
 * approved immutable evidence, legacy fallback, and stock-visibility
 * permission scrubbing (design.md "Add a Bahasa Indonesia review
 * experience" / AdjustmentController@buildBreakageViewModel).
 */
class BreakageShowViewTest extends TestCase
{
    use RefreshDatabase;

    private function makeSetting(bool $isPkp = true): Setting
    {
        $currency = Currency::create([
            'currency_name' => 'Rupiah', 'code' => 'IDR', 'symbol' => 'RP',
            'thousand_separator' => '.', 'decimal_separator' => ',', 'exchange_rate' => 1,
        ]);

        return Setting::create([
            'company_name' => 'CV Tiga Computer ' . uniqid(),
            'company_email' => 'ops@tiga.test',
            'company_phone' => '0800000000',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'ops@tiga.test',
            'footer_text' => 'Footer',
            'company_address' => 'Bandung',
            'is_pkp' => $isPkp,
        ]);
    }

    private function makeUserWithPermissions(Setting $setting, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => 1]);
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $role = Role::firstOrCreate(['name' => 'role-' . uniqid(), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function makeProduct(Setting $setting, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'setting_id' => $setting->id,
            'product_name' => 'Kabel',
            'product_code' => 'SKU-' . uniqid(),
            'product_quantity' => 10,
            'serial_number_required' => false,
            'product_cost' => 1000,
            'product_price' => 1500,
            'product_stock_alert' => 1,
            'stock_managed' => true,
        ], $overrides));
    }

    /** @test */
    public function pending_document_shows_the_live_planner_preview_with_approve_gated_on_conflicts(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $product = $this->makeProduct($setting);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 10, 'quantity_tax' => 3, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        // Requests 5 but only 3 available -- shortage conflict, approval must be gated.
        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 5, 'quantity_tax' => 5, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        $user = $this->makeUserWithPermissions($setting, [
            'adjustments.access', 'adjustments.show', 'adjustments.breakage.approval', 'adjustments.view-system-stock',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('adjustments.show', $adjustment));

        $response->assertOk();
        $response->assertSee('Pratinjau Persetujuan');
        $response->assertSee('tidak mencukupi');
        $response->assertSee('disabled', false);
    }

    /** @test */
    public function pending_document_without_conflicts_allows_approval(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $product = $this->makeProduct($setting);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 10, 'quantity_tax' => 10, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 4, 'quantity_tax' => 4, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        $user = $this->makeUserWithPermissions($setting, [
            'adjustments.access', 'adjustments.show', 'adjustments.breakage.approval', 'adjustments.view-system-stock',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('adjustments.show', $adjustment));

        $response->assertOk();
        $response->assertSee('Siap Disetujui');
        $response->assertDontSee('konflik yang harus diselesaikan');
    }

    /** @test */
    public function approved_document_renders_only_from_immutable_approval_result(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $product = $this->makeProduct($setting);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 10, 'quantity_tax' => 10, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 4, 'quantity_tax' => 4, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        $approver = $this->makeUserWithPermissions($setting, ['adjustments.breakage.approval']);
        app(BreakageApprovalService::class)->approve($adjustment->fresh(), $approver, $setting->id);

        // Move stock further after approval to prove the view never
        // recomputes from current stock for an approved document.
        ProductStock::where('product_id', $product->id)->where('location_id', $location->id)
            ->update(['quantity_tax' => 999, 'broken_quantity_tax' => 999]);

        $viewer = $this->makeUserWithPermissions($setting, [
            'adjustments.access', 'adjustments.show', 'adjustments.view-system-stock',
        ]);

        $response = $this->actingAs($viewer)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('adjustments.show', $adjustment->fresh()));

        $response->assertOk();
        $response->assertSee('Perubahan yang Diterapkan');
        $response->assertDontSee('999');
        $response->assertSee('4'); // the movement actually applied at approval time
    }

    /** @test */
    public function stock_figures_are_hidden_without_view_system_stock_permission(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $product = $this->makeProduct($setting);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 10, 'quantity_tax' => 10, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 4, 'quantity_tax' => 4, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        $user = $this->makeUserWithPermissions($setting, [
            'adjustments.access', 'adjustments.show',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('adjustments.show', $adjustment));

        $response->assertOk();
        // Current/projected stock columns are not rendered for this permission level.
        $response->assertDontSee('Stok Saat Ini');
        $response->assertDontSee('Proyeksi');
    }

    /** @test */
    public function legacy_approved_document_without_stored_result_shows_fallback_notice(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $product = $this->makeProduct($setting);

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'approved', 'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 2, 'quantity_tax' => 2, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        $user = $this->makeUserWithPermissions($setting, [
            'adjustments.access', 'adjustments.show', 'adjustments.view-system-stock',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('adjustments.show', $adjustment));

        $response->assertOk();
        $response->assertSee('sebelum pencatatan bukti persetujuan');
        $response->assertSee('Fallback Lama');
    }

    /**
     * BreakageMovementPlanner's shortage conflict message embeds the exact
     * available-stock figure (e.g. "stok baik tersedia (3) tidak
     * mencukupi..."), which is exactly the number the current/projected
     * columns are scrubbed for. A user without adjustments.view-system-stock
     * must still see that the document is blocked and why in general terms,
     * but never the precise quantity.
     */
    public function test_shortage_conflict_message_hides_exact_stock_figure_without_view_system_stock_permission(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $product = $this->makeProduct($setting);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 3, 'quantity_tax' => 3, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        // Requests 99 but only 3 are available -- shortage conflict.
        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 99, 'quantity_tax' => 99, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        $user = $this->makeUserWithPermissions($setting, [
            'adjustments.access', 'adjustments.show',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('adjustments.show', $adjustment));

        $response->assertOk();
        $response->assertSee('tidak mencukupi');
        $response->assertDontSee('tersedia (3)');
        $response->assertDontSee('(99)');
    }

    public function test_shortage_conflict_message_shows_exact_stock_figure_with_view_system_stock_permission(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $product = $this->makeProduct($setting);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 3, 'quantity_tax' => 3, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 99, 'quantity_tax' => 99, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        $user = $this->makeUserWithPermissions($setting, [
            'adjustments.access', 'adjustments.show', 'adjustments.breakage.approval', 'adjustments.view-system-stock',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('adjustments.show', $adjustment));

        $response->assertOk();
        $response->assertSee('tersedia (3)');
    }

    /**
     * adjustments.breakage.approval does not imply
     * adjustments.view-system-stock: an approval-only user can reach
     * approveBreakage() directly (e.g. re-submitting approval after the
     * scrubbed review page loaded but stock drifted below the requested
     * amount before the click landed), and BreakageApprovalService's
     * shortage ValidationException embeds the exact locked available-stock
     * figure. The flashed error on that path must be redacted the same way
     * the review page and entry-time messages are.
     */
    public function test_direct_approval_shortage_error_hides_exact_stock_figure_without_view_system_stock_permission(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $product = $this->makeProduct($setting);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 3, 'quantity_tax' => 3, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        // Requests 99 but only 3 are available at approval time.
        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 99, 'quantity_tax' => 99, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        // Approval permission WITHOUT view-system-stock.
        $approver = $this->makeUserWithPermissions($setting, [
            'adjustments.access', 'adjustments.breakage.approval',
        ]);

        $response = $this->actingAs($approver)
            ->withSession(['setting_id' => $setting->id])
            ->patch(route('adjustments.approve', $adjustment));

        $response->assertSessionHasErrors('message');
        $errorMessage = (string) session('errors')->first('message');

        $this->assertStringContainsString('tidak mencukupi', $errorMessage);
        $this->assertStringNotContainsString('tersedia (3)', $errorMessage);
        $this->assertStringNotContainsString('(99)', $errorMessage);

        $adjustment->refresh();
        $this->assertSame('PENDING', \Modules\Adjustment\Entities\AdjustmentStatus::normalize($adjustment->status)->value);
    }

    public function test_direct_approval_shortage_error_shows_exact_stock_figure_with_view_system_stock_permission(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $product = $this->makeProduct($setting);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 3, 'quantity_tax' => 3, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 99, 'quantity_tax' => 99, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        $approver = $this->makeUserWithPermissions($setting, [
            'adjustments.access', 'adjustments.breakage.approval', 'adjustments.view-system-stock',
        ]);

        $response = $this->actingAs($approver)
            ->withSession(['setting_id' => $setting->id])
            ->patch(route('adjustments.approve', $adjustment));

        $response->assertSessionHasErrors('message');
        $errorMessage = (string) session('errors')->first('message');

        $this->assertStringContainsString('tersedia (3)', $errorMessage);
    }
}
