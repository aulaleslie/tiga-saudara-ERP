<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustedProduct;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Currency;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApproveBreakageTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_approves_breakage_adjustments_when_type_is_uppercase(): void
    {
        $user = User::factory()->create(['is_active' => 1]);

        $permission = Permission::create([
            'name' => 'adjustments.breakage.approval',
            'guard_name' => 'web',
        ]);
        $role = Role::create([
            'name' => 'approver',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo($permission);
        $user->assignRole($role);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'RP',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $setting = Setting::create([
            'company_name' => 'Tiga Saudara',
            'company_email' => 'ops@tiga.test',
            'company_phone' => '0800000000',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'ops@tiga.test',
            'footer_text' => 'Footer',
            'company_address' => 'Bandung',
        ]);

        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        $location = Location::create([
            'setting_id' => $setting->id,
            'name' => 'Main Warehouse',
        ]);

        $tax = Tax::create([
            'name' => 'PPN',
            'value' => 10,
        ]);

        $product = Product::create([
            'setting_id' => $setting->id,
            'product_name' => 'Gadget',
            'product_code' => 'SKU-001',
            'product_quantity' => 10,
            'serial_number_required' => true,
            'product_cost' => 1000,
            'product_price' => 1500,
            'product_stock_alert' => 1,
            'stock_managed' => true,
        ]);

        $stock = ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'quantity_non_tax' => 5,
            'quantity_tax' => 5,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        $taxableSerial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => 'SN-TAX-1',
            'tax_id' => $tax->id,
        ]);

        $nonTaxSerial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => 'SN-NONTAX-1',
            'tax_id' => null,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(),
            'note' => null,
            'type' => 'breakage',
            'status' => 'pending',
            'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'quantity_tax' => 1,
            'quantity_non_tax' => 1,
            'serial_numbers' => json_encode([$taxableSerial->id, $nonTaxSerial->id]),
            'type' => 'sub',
            'is_taxable' => 0,
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->patch(route('adjustments.approve', $adjustment));

        $response->assertRedirect(route('adjustments.index'));

        $adjustment->refresh();
        $stock->refresh();
        $product->refresh();
        $taxableSerial->refresh();
        $nonTaxSerial->refresh();

        $this->assertSame('APPROVED', $adjustment->status);
        $this->assertSame(4, $stock->quantity_tax);
        $this->assertSame(4, $stock->quantity_non_tax);
        $this->assertSame(1, $stock->broken_quantity_tax);
        $this->assertSame(1, $stock->broken_quantity_non_tax);
        $this->assertTrue($taxableSerial->is_broken);
        $this->assertTrue($nonTaxSerial->is_broken);
        $this->assertSame(2, $product->broken_quantity);

        $transaction = Transaction::where('product_id', $product->id)
            ->where('reason', 'Breakage adjustment approved')
            ->latest('id')
            ->first();

        $this->assertNotNull($transaction);
        $this->assertSame(2, $transaction->quantity);
        $this->assertSame($stock->quantity, $transaction->current_quantity);
        $this->assertSame($stock->quantity_tax, $transaction->quantity_tax);
        $this->assertSame($stock->quantity_non_tax, $transaction->quantity_non_tax);
    }
}
