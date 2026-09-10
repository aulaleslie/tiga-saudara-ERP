<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Adjustment\Entities\AdjustedProduct;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentStatus;
use Modules\Adjustment\Services\BreakageApprovalService;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BreakageApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeApprover(Setting $setting): User
    {
        $user = User::factory()->create(['is_active' => 1]);

        $permission = Permission::firstOrCreate(['name' => 'adjustments.breakage.approval', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'approver-' . $setting->id, 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function makeSetting(bool $isPkp): Setting
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

    /** @test */
    public function it_moves_good_to_broken_and_persists_immutable_approval_result(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $user = $this->makeApprover($setting);

        $product = Product::create([
            'setting_id' => $setting->id, 'product_name' => 'Kabel', 'product_code' => 'SKU-1',
            'product_quantity' => 10, 'serial_number_required' => false,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 1, 'stock_managed' => true,
        ]);

        $stock = ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 10, 'quantity_tax' => 10, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 2, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 2,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 3, 'quantity_tax' => 3, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        $approved = app(BreakageApprovalService::class)->approve($adjustment->fresh(), $user, $setting->id);

        $stock->refresh();
        $this->assertSame(AdjustmentStatus::Approved, $approved->status);
        $this->assertEquals(7, (int) $stock->quantity_tax);
        $this->assertEquals(5, (int) $stock->broken_quantity_tax);

        $result = $approved->approval_result;
        $this->assertSame(1, $result['schema_version']);
        $this->assertSame($user->id, $result['approved_by']);
        $this->assertSame(3, $result['products'][0]['movement']);
        $this->assertSame(10, $result['products'][0]['before']['good']);
        $this->assertSame(7, $result['products'][0]['after']['good']);
    }

    /** @test */
    public function it_rolls_back_entirely_on_shortage_leaving_pending(): void
    {
        $setting = $this->makeSetting(false);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $user = $this->makeApprover($setting);

        $product = Product::create([
            'setting_id' => $setting->id, 'product_name' => 'Kabel', 'product_code' => 'SKU-2',
            'product_quantity' => 2, 'serial_number_required' => false,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 1, 'stock_managed' => true,
        ]);

        $stock = ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 2, 'quantity_tax' => 0, 'quantity_non_tax' => 2,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 5, 'quantity_tax' => 0, 'quantity_non_tax' => 5,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        try {
            app(BreakageApprovalService::class)->approve($adjustment->fresh(), $user, $setting->id);
            $this->fail('Expected ValidationException for insufficient stock.');
        } catch (ValidationException $e) {
            // expected
        }

        $adjustment->refresh();
        $stock->refresh();

        $this->assertSame(AdjustmentStatus::Pending, $adjustment->status);
        $this->assertEquals(2, (int) $stock->quantity_non_tax);
        $this->assertEquals(0, (int) $stock->broken_quantity_non_tax);
        $this->assertNull($adjustment->approval_result);
    }

    /** @test */
    public function it_marks_serials_broken_without_changing_location_or_tax(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $tax = Tax::create(['name' => 'PPN', 'value' => 10]);
        $user = $this->makeApprover($setting);

        $product = Product::create([
            'setting_id' => $setting->id, 'product_name' => 'Gadget', 'product_code' => 'SKU-3',
            'product_quantity' => 1, 'serial_number_required' => true,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 1, 'stock_managed' => true,
        ]);

        $stock = ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 1, 'quantity_tax' => 1, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'serial_number' => 'SN-OK', 'tax_id' => $tax->id,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 1, 'quantity_tax' => 1, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([$serial->id]), 'type' => 'sub',
        ]);

        app(BreakageApprovalService::class)->approve($adjustment->fresh(), $user, $setting->id);

        $serial->refresh();
        $stock->refresh();

        $this->assertTrue($serial->is_broken);
        $this->assertSame($location->id, $serial->location_id);
        $this->assertSame($tax->id, $serial->tax_id);
        $this->assertEquals(0, (int) $stock->quantity_tax);
        $this->assertEquals(1, (int) $stock->broken_quantity_tax);
    }

    /** @test */
    public function it_is_idempotent_when_already_approved(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $user = $this->makeApprover($setting);

        $product = Product::create([
            'setting_id' => $setting->id, 'product_name' => 'Kabel', 'product_code' => 'SKU-4',
            'product_quantity' => 10, 'serial_number_required' => false,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 1, 'stock_managed' => true,
        ]);

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
            'quantity' => 3, 'quantity_tax' => 3, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        $service = app(BreakageApprovalService::class);
        $first = $service->approve($adjustment->fresh(), $user, $setting->id);
        $resultAfterFirst = $first->approval_result;

        $second = $service->approve($first->fresh(), $user, $setting->id);

        $this->assertSame(AdjustmentStatus::Approved, $second->status);
        $this->assertSame($resultAfterFirst['approved_at'], $second->approval_result['approved_at']);
    }

    /** @test */
    public function it_rejects_a_document_with_duplicate_product_rows(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $user = $this->makeApprover($setting);

        $product = Product::create([
            'setting_id' => $setting->id, 'product_name' => 'Kabel', 'product_code' => 'SKU-5',
            'product_quantity' => 10, 'serial_number_required' => false,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 1, 'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 10, 'quantity_tax' => 10, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        // Two rows for the SAME product -- must be rejected as a document-level conflict.
        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 2, 'quantity_tax' => 2, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);
        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 1, 'quantity_tax' => 1, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        $this->expectException(ValidationException::class);
        app(BreakageApprovalService::class)->approve($adjustment->fresh(), $user, $setting->id);
    }

    /** @test */
    public function it_rejects_the_same_serial_claimed_by_two_rows(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $tax = Tax::create(['name' => 'PPN', 'value' => 10]);
        $user = $this->makeApprover($setting);

        $productA = Product::create([
            'setting_id' => $setting->id, 'product_name' => 'Gadget A', 'product_code' => 'SKU-6A',
            'product_quantity' => 1, 'serial_number_required' => true,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 1, 'stock_managed' => true,
        ]);
        $productB = Product::create([
            'setting_id' => $setting->id, 'product_name' => 'Gadget B', 'product_code' => 'SKU-6B',
            'product_quantity' => 1, 'serial_number_required' => true,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 1, 'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $productA->id, 'location_id' => $location->id,
            'quantity' => 1, 'quantity_tax' => 1, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);
        ProductStock::create([
            'product_id' => $productB->id, 'location_id' => $location->id,
            'quantity' => 1, 'quantity_tax' => 1, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        // Serial actually belongs to product A, but is referenced by BOTH rows.
        $serial = ProductSerialNumber::create([
            'product_id' => $productA->id, 'location_id' => $location->id,
            'serial_number' => 'SN-SHARED', 'tax_id' => $tax->id,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $productA->id,
            'quantity' => 1, 'quantity_tax' => 1, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([$serial->id]), 'type' => 'sub',
        ]);
        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $productB->id,
            'quantity' => 1, 'quantity_tax' => 1, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([$serial->id]), 'type' => 'sub',
        ]);

        $this->expectException(ValidationException::class);
        app(BreakageApprovalService::class)->approve($adjustment->fresh(), $user, $setting->id);
    }

    /** @test */
    public function it_rejects_a_serialized_row_whose_persisted_quantity_does_not_match_its_serial_count(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $tax = Tax::create(['name' => 'PPN', 'value' => 10]);
        $user = $this->makeApprover($setting);

        $product = Product::create([
            'setting_id' => $setting->id, 'product_name' => 'Gadget', 'product_code' => 'SKU-7',
            'product_quantity' => 2, 'serial_number_required' => true,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 1, 'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 2, 'quantity_tax' => 2, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'serial_number' => 'SN-1', 'tax_id' => $tax->id,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        // Tampered: quantity_tax says 2, but only 1 serial is actually referenced.
        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 2, 'quantity_tax' => 2, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([$serial->id]), 'type' => 'sub',
        ]);

        $this->expectException(ValidationException::class);
        app(BreakageApprovalService::class)->approve($adjustment->fresh(), $user, $setting->id);
    }

    /** @test */
    public function it_approves_a_globally_shared_product_whose_legacy_setting_id_differs_from_the_destination(): void
    {
        // The product catalogue is global: Product::setting_id is a legacy
        // column and must never gate approval eligibility (see
        // StockOpnameApprovalService::approve()'s documented rule). A
        // product created under a different setting must still approve
        // normally as long as it is active, stock-managed, and has real
        // stock at the destination location -- ownership of the operation
        // is enforced entirely through the destination Location/Setting,
        // never by filtering products to that setting's own catalogue.
        $setting = $this->makeSetting(true);
        $otherSetting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $user = $this->makeApprover($setting);

        $sharedProduct = Product::create([
            'setting_id' => $otherSetting->id, 'product_name' => 'Produk Bersama', 'product_code' => 'SKU-8',
            'product_quantity' => 10, 'serial_number_required' => false,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 1, 'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $sharedProduct->id, 'location_id' => $location->id,
            'quantity' => 10, 'quantity_tax' => 10, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $sharedProduct->id,
            'quantity' => 1, 'quantity_tax' => 1, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        $approved = app(BreakageApprovalService::class)->approve($adjustment->fresh(), $user, $setting->id);

        $this->assertSame(AdjustmentStatus::Approved, $approved->status);

        $stock = ProductStock::where('product_id', $sharedProduct->id)->where('location_id', $location->id)->first();
        $this->assertSame(9, (int) $stock->quantity_tax);
        $this->assertSame(1, (int) $stock->broken_quantity_tax);
    }

    /** @test */
    public function it_rejects_a_product_with_no_stock_row_at_the_destination_location(): void
    {
        // Ownership of the approval operation is enforced through the
        // destination location, not the product's legacy setting_id: a
        // product that is otherwise eligible but has never had a
        // ProductStock row created at THIS location still blocks approval,
        // because there is simply nothing to move.
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $user = $this->makeApprover($setting);

        $product = Product::create([
            'setting_id' => $setting->id, 'product_name' => 'Tanpa Stok', 'product_code' => 'SKU-NOSTOCK',
            'product_quantity' => 0, 'serial_number_required' => false,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 1, 'stock_managed' => true,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 1, 'quantity_tax' => 1, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        $this->expectException(ValidationException::class);
        app(BreakageApprovalService::class)->approve($adjustment->fresh(), $user, $setting->id);
    }

    /** @test */
    public function it_rejects_a_pkp_serialized_row_whose_quantity_is_wrongly_bucketed_as_non_tax(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $tax = Tax::create(['name' => 'PPN', 'value' => 10]);
        $user = $this->makeApprover($setting);

        $product = Product::create([
            'setting_id' => $setting->id, 'product_name' => 'Gadget', 'product_code' => 'SKU-9',
            'product_quantity' => 1, 'serial_number_required' => true,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 1, 'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 1, 'quantity_tax' => 1, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'serial_number' => 'SN-BUCKET', 'tax_id' => $tax->id,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        // Location is PKP, serial IS taxable, but the row's own bucket
        // fields disagree: quantity_tax=0/quantity_non_tax=1 even though
        // quantity_tax + quantity_non_tax == serial count (1). Must be
        // rejected -- summing the buckets is not enough, each bucket must
        // individually agree with the destination's PKP setting.
        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 1, 'quantity_tax' => 0, 'quantity_non_tax' => 1,
            'serial_numbers' => json_encode([$serial->id]), 'type' => 'sub',
        ]);

        $this->expectException(ValidationException::class);
        app(BreakageApprovalService::class)->approve($adjustment->fresh(), $user, $setting->id);
    }

    /** @test */
    public function idempotent_reapproval_still_enforces_active_setting_ownership(): void
    {
        $ownSetting = $this->makeSetting(true);
        $otherSetting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $ownSetting->id, 'name' => 'Gudang']);
        $user = $this->makeApprover($ownSetting);

        $product = Product::create([
            'setting_id' => $ownSetting->id, 'product_name' => 'Kabel', 'product_code' => 'SKU-10',
            'product_quantity' => 10, 'serial_number_required' => false,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 1, 'stock_managed' => true,
        ]);

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
            'quantity' => 3, 'quantity_tax' => 3, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        $service = app(BreakageApprovalService::class);
        $approved = $service->approve($adjustment->fresh(), $user, $ownSetting->id);
        $this->assertSame(AdjustmentStatus::Approved, $approved->status);

        // Re-approving the SAME already-approved document from a DIFFERENT
        // active setting must still be rejected -- the idempotent no-op path
        // must not bypass the active-setting ownership boundary.
        $this->expectException(ValidationException::class);
        $service->approve($approved->fresh(), $user, $otherSetting->id);
    }

    /** @test */
    public function it_rejects_a_legacy_normal_adjustment_sharing_the_pending_status(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $user = $this->makeApprover($setting);

        $product = Product::create([
            'setting_id' => $setting->id, 'product_name' => 'Kabel', 'product_code' => 'SKU-11',
            'product_quantity' => 10, 'serial_number_required' => false,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 1, 'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 10, 'quantity_tax' => 10, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        // A 'normal' adjustment carrying the same legacy 'pending' status
        // breakage uses -- calling BreakageApprovalService directly on it
        // (bypassing the controller's own dispatch-by-type logic) must be
        // rejected rather than mutate stock as if it were a breakage
        // document.
        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'normal', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 3, 'quantity_tax' => 3, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        $this->expectException(ValidationException::class);
        app(BreakageApprovalService::class)->approve($adjustment->fresh(), $user, $setting->id);
    }
}
