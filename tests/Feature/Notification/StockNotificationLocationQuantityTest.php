<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\StockNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockNotificationLocationQuantityTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function low_stock_notification_displays_the_sellable_quantity_actually_used_for_the_threshold_decision(): void
    {
        $currency = Currency::create([
            'currency_name' => 'Rupiah', 'code' => 'IDR', 'symbol' => 'RP',
            'thousand_separator' => '.', 'decimal_separator' => ',', 'exchange_rate' => 1,
        ]);
        $setting = Setting::create([
            'company_name' => 'CV Tiga Computer ' . uniqid(),
            'company_email' => 'ops@tiga.test',
            'company_phone' => '0800000000',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'ops@tiga.test',
            'footer_text' => 'Footer',
            'company_address' => 'Bandung',
        ]);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);

        $recipient = User::factory()->create(['is_active' => 1]);
        $permission = Permission::firstOrCreate(['name' => 'notifications.lowStock', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'low-stock-recipient', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $recipient->assignRole($role);
        $recipient->settings()->attach($setting->id, ['role_id' => $role->id]);

        // Physical total (quantity) stays high (12) -- as breakage holds it
        // invariant -- while good/sellable stock crosses the alert threshold.
        $product = Product::create([
            'setting_id' => $setting->id, 'product_name' => 'Kabel', 'product_code' => 'SKU-NOTIF',
            'product_quantity' => 12, 'serial_number_required' => false,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 5, 'stock_managed' => true,
        ]);

        $stock = ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 12, 'quantity_tax' => 4, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 8, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 8,
        ]);
        $stock->setRelation('product', $product);
        $stock->setRelation('location', $location);

        // Good stock crossed the alert threshold (10 -> 4), even though
        // $stock->quantity (physical total) is 12 throughout.
        app(StockNotificationService::class)->checkLocationStock($stock, previousQuantity: 10, currentQuantity: 4);

        $notification = Notification::where('user_id', $recipient->id)
            ->where('type', 'location_low_stock')
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('(4 / 5)', $notification->message);
        $this->assertStringNotContainsString('(12 / 5)', $notification->message);
        $this->assertEquals(4, $notification->metadata['current_quantity']);
    }
}
