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
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 5.4 (supplement): physical-total invariant -- approval must move the
 * requested amount from good to broken within the SAME tax bucket while
 * leaving ProductStock::quantity (the physical total: quantity_tax +
 * quantity_non_tax + broken_quantity_tax + broken_quantity_non_tax)
 * unchanged, for both non-serialized and serialized products (design.md
 * "Preserve physical totals and serial identity").
 */
class BreakageApprovalPhysicalTotalInvariantTest extends TestCase
{
    use RefreshDatabase;

    private function makeApprover(Setting $setting): User
    {
        $user = User::factory()->create(['is_active' => 1]);

        $permission = Permission::firstOrCreate(['name' => 'adjustments.breakage.approval', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'approver-' . uniqid(), 'guard_name' => 'web']);
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
    public function physical_total_is_unchanged_for_a_non_serialized_product(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $user = $this->makeApprover($setting);

        $product = Product::create([
            'setting_id' => $setting->id, 'product_name' => 'Kabel', 'product_code' => 'SKU-PT-1',
            'product_quantity' => 10, 'serial_number_required' => false,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 1, 'stock_managed' => true,
        ]);

        $stock = ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 15, 'quantity_tax' => 10, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 5, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 5,
        ]);

        $physicalTotalBefore = $stock->quantity_tax + $stock->quantity_non_tax
            + $stock->broken_quantity_tax + $stock->broken_quantity_non_tax;

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 4, 'quantity_tax' => 4, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        app(BreakageApprovalService::class)->approve($adjustment->fresh(), $user, $setting->id);

        $stock->refresh();
        $physicalTotalAfter = $stock->quantity_tax + $stock->quantity_non_tax
            + $stock->broken_quantity_tax + $stock->broken_quantity_non_tax;

        $this->assertSame($physicalTotalBefore, $physicalTotalAfter);
        $this->assertSame(6, (int) $stock->quantity_tax);
        $this->assertSame(9, (int) $stock->broken_quantity_tax);
        // The other (non-tax) bucket must never be touched by a PKP-bucket movement.
        $this->assertSame(0, (int) $stock->quantity_non_tax);
        $this->assertSame(0, (int) $stock->broken_quantity_non_tax);
    }

    /** @test */
    public function physical_total_is_unchanged_and_serial_identity_preserved_for_a_serialized_product(): void
    {
        $setting = $this->makeSetting(false);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $user = $this->makeApprover($setting);

        $product = Product::create([
            'setting_id' => $setting->id, 'product_name' => 'Laptop', 'product_code' => 'SKU-PT-2',
            'product_quantity' => 2, 'serial_number_required' => true,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 1, 'stock_managed' => true,
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'serial_number' => 'SN-PT-1', 'status' => 'ACTIVE', 'tax_id' => null,
        ]);

        $stock = ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 2, 'quantity_tax' => 0, 'quantity_non_tax' => 2,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $physicalTotalBefore = $stock->quantity_tax + $stock->quantity_non_tax
            + $stock->broken_quantity_tax + $stock->broken_quantity_non_tax;

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 1, 'quantity_tax' => 0, 'quantity_non_tax' => 1,
            'serial_numbers' => json_encode([$serial->id]), 'type' => 'sub',
        ]);

        app(BreakageApprovalService::class)->approve($adjustment->fresh(), $user, $setting->id);

        $stock->refresh();
        $serial->refresh();

        $physicalTotalAfter = $stock->quantity_tax + $stock->quantity_non_tax
            + $stock->broken_quantity_tax + $stock->broken_quantity_non_tax;

        $this->assertSame($physicalTotalBefore, $physicalTotalAfter);
        $this->assertSame(1, (int) $stock->quantity_non_tax);
        $this->assertSame(1, (int) $stock->broken_quantity_non_tax);

        // Serial identity preserved: only condition flips, never location/tax/lifecycle.
        $this->assertTrue((bool) $serial->is_broken);
        $this->assertSame($location->id, $serial->location_id);
        $this->assertNull($serial->tax_id);
        $this->assertNull($serial->dispatch_detail_id);
    }
}
