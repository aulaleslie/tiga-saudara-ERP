<?php

namespace Modules\Purchase\Tests\Feature;

use Tests\TestCase;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class PurchaseDetailDisplayProductNameTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $purchase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '1234567890',
            'company_address' => 'Test Address',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notification@test.com',
            'footer_text' => 'Test Footer',
        ]);

        $supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@supplier.com',
            'supplier_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->purchase = Purchase::create([
            'date' => now(),
            'due_date' => now(),
            'reference' => 'PUR-001',
            'supplier_id' => $supplier->id,
            'payment_method' => 'Cash',
            'status' => 'Received',
            'payment_status' => 'Unpaid',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'setting_id' => $this->setting->id,
        ]);

        $user = \App\Models\User::factory()->create();

        Category::create([
            'id' => 1,
            'category_name' => 'Cat',
            'category_code' => 'C',
            'created_by' => $user->id,
            'setting_id' => $this->setting->id,
        ]);
    }

    private function makeProduct(int $id, string $name): Product
    {
        return Product::create([
            'id' => $id,
            'product_name' => $name,
            'product_code' => "P{$id}",
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 1000,
            'product_unit' => 'PC',
            'product_stock_alert' => 10,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'product_note' => '',
            'category_id' => 1,
            'setting_id' => $this->setting->id,
        ]);
    }

    private function makeDetail(?int $productId, string $persistedName): PurchaseDetail
    {
        return PurchaseDetail::create([
            'purchase_id' => $this->purchase->id,
            'product_id' => $productId,
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'sub_total' => 10000,
            'product_name' => $persistedName,
            'product_code' => 'P001',
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
    }

    public function test_display_name_uses_current_product_name_when_product_renamed(): void
    {
        $product = $this->makeProduct(1, 'Old Name At Purchase Time');
        $detail = $this->makeDetail($product->id, 'Old Name At Purchase Time');

        $product->update(['product_name' => 'Renamed Product']);
        $detail->refresh();

        $this->assertSame('Renamed Product', $detail->display_product_name);
        $this->assertSame('OLD NAME AT PURCHASE TIME', $detail->fresh()->product_name);
    }

    public function test_display_name_falls_back_to_persisted_snapshot_when_product_unresolvable(): void
    {
        $detail = $this->makeDetail(null, 'Persisted Snapshot Name');

        $this->assertSame('PERSISTED SNAPSHOT NAME', $detail->display_product_name);
    }

    public function test_display_name_falls_back_to_persisted_snapshot_when_current_name_blank(): void
    {
        $product = $this->makeProduct(2, 'Will Be Blanked');
        $detail = $this->makeDetail($product->id, 'Persisted Snapshot Name');

        DB::table('products')->where('id', $product->id)->update(['product_name' => '']);
        $detail->refresh();

        $this->assertSame('PERSISTED SNAPSHOT NAME', $detail->display_product_name);
    }
}
