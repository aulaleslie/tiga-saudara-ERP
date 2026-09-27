<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class SaleDetailDisplayProductNameTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Sale $sale;
    protected Category $category;
    protected Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => '123 Testing Lane',
        ]);

        $user = User::factory()->create();

        $this->category = Category::create([
            'category_name' => 'Category',
            'category_code' => 'CAT',
            'setting_id' => $this->setting->id,
            'created_by' => $user->id,
        ]);

        $this->unit = Unit::create([
            'name' => 'Unit',
            'short_name' => 'U',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $this->setting->id,
        ]);

        $customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_phone' => '123',
            'customer_email' => 'cust@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->sale = Sale::create([
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'tax_amount' => 0,
            'tax_percentage' => 0,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'setting_id' => $this->setting->id,
            'reference' => 'SO-DISPLAY-NAME',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
    }

    private function makeProduct(string $name): Product
    {
        return Product::create([
            'product_name' => $name,
            'product_code' => 'CODE-' . uniqid(),
            'setting_id' => $this->setting->id,
            'product_quantity' => 10,
            'product_cost' => 100,
            'product_price' => 200,
            'category_id' => $this->category->id,
            'product_unit' => $this->unit->id,
            'stock_managed' => true,
        ]);
    }

    public function test_sale_detail_display_name_uses_current_product_name_when_renamed(): void
    {
        $product = $this->makeProduct('Old Name At Sale Time');

        $detail = SaleDetails::create([
            'sale_id' => $this->sale->id,
            'product_id' => $product->id,
            'product_name' => 'Old Name At Sale Time',
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $product->update(['product_name' => 'Renamed Product']);
        $detail->refresh();

        $this->assertSame('Renamed Product', $detail->display_product_name);
        $this->assertSame('OLD NAME AT SALE TIME', $detail->fresh()->product_name);
    }

    public function test_sale_detail_display_name_falls_back_to_persisted_snapshot_when_product_unresolvable(): void
    {
        $detail = SaleDetails::create([
            'sale_id' => $this->sale->id,
            'product_id' => null,
            'product_name' => 'Persisted Snapshot Name',
            'product_code' => 'CODE-X',
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $this->assertSame('PERSISTED SNAPSHOT NAME', $detail->display_product_name);
    }

    public function test_sale_detail_display_name_falls_back_to_persisted_snapshot_when_current_name_blank(): void
    {
        $product = $this->makeProduct('Will Be Blanked');

        $detail = SaleDetails::create([
            'sale_id' => $this->sale->id,
            'product_id' => $product->id,
            'product_name' => 'Persisted Snapshot Name',
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        DB::table('products')->where('id', $product->id)->update(['product_name' => '']);
        $detail->refresh();

        $this->assertSame('PERSISTED SNAPSHOT NAME', $detail->display_product_name);
    }

    public function test_bundle_item_display_name_uses_current_product_name_when_renamed(): void
    {
        $parentProduct = $this->makeProduct('Bundle Parent');
        $componentProduct = $this->makeProduct('Old Component Name');

        $detail = SaleDetails::create([
            'sale_id' => $this->sale->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $bundleItem = SaleBundleItem::create([
            'sale_id' => $this->sale->id,
            'sale_detail_id' => $detail->id,
            'product_id' => $componentProduct->id,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'name' => 'Old Component Name',
            'price' => 0,
            'quantity' => 1,
            'sub_total' => 0,
        ]);

        $componentProduct->update(['product_name' => 'Renamed Component']);
        $bundleItem->refresh();

        $this->assertSame('Renamed Component', $bundleItem->display_product_name);
        $this->assertSame('OLD COMPONENT NAME', $bundleItem->fresh()->name);
    }

    public function test_bundle_item_display_name_falls_back_to_persisted_name_when_product_unresolvable(): void
    {
        $parentProduct = $this->makeProduct('Bundle Parent');

        $detail = SaleDetails::create([
            'sale_id' => $this->sale->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $bundleItem = SaleBundleItem::create([
            'sale_id' => $this->sale->id,
            'sale_detail_id' => $detail->id,
            'product_id' => null,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'name' => 'Persisted Bundle Name',
            'price' => 0,
            'quantity' => 1,
            'sub_total' => 0,
        ]);

        $this->assertSame('PERSISTED BUNDLE NAME', $bundleItem->display_product_name);
    }
}
