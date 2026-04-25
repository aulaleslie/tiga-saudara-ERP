<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Sale\EditForm;
use App\Livewire\Sale\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductPrice;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleBundlePricingImplementationTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Product $parentProduct;
    protected Product $bundleItemProduct;
    protected ProductBundle $bundle;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn() => true);

        $this->actingAs(User::factory()->create());

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'TestCo',
            'company_email' => 'test@example.com',
            'company_phone' => '12345',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'company_address' => 'Addr',
            'footer_text' => 'Footer',
            'is_pkp' => true,
        ]);

        session(['setting_id' => $this->setting->id]);

        $this->parentProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'PARENT',
            'product_code' => 'P1',
            'product_unit' => 'pc',
            'product_quantity' => 10,
            'product_cost' => 50000,
            'product_price' => 100000,
        ]);

        ProductPrice::create([
            'product_id' => $this->parentProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 100000,
            'tier_1_price' => 90000,
        ]);

        $this->bundleItemProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'ITEM',
            'product_code' => 'I1',
            'product_unit' => 'pc',
            'product_quantity' => 10,
            'product_cost' => 10000,
            'product_price' => 25000,
        ]);

        $this->bundle = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'TEST BUNDLE',
            'bundle_sale_price' => 95000,
            'price' => 5000, // Legacy
        ]);

        ProductBundleItem::create([
            'bundle_id' => $this->bundle->id,
            'product_id' => $this->bundleItemProduct->id,
            'quantity' => 1,
            'informational_item_price' => 20000,
        ]);

        Cart::instance('sale')->destroy();
    }

    public function test_bundle_selection_initializes_row_price_from_bundle_sale_price()
    {
        $productArray = [
            'id' => $this->parentProduct->id,
            'product_name' => $this->parentProduct->product_name,
            'product_code' => $this->parentProduct->product_code,
            'product_quantity' => $this->parentProduct->product_quantity,
            'product_unit' => $this->parentProduct->product_unit,
        ];

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->set('pendingProduct', $productArray)
            ->call('confirmBundleSelection', $this->bundle->id);

        $row = Cart::instance('sale')->content()->first();
        $this->assertEquals(95000.0, (float) $row->price);
        $this->assertEquals(0.0, (float) $row->options->bundle_price);
        $this->assertTrue($row->options->is_bundled_row);
    }

    public function test_bundled_row_skips_customer_tier_repricing()
    {
        $productArray = [
            'id' => $this->parentProduct->id,
            'product_name' => $this->parentProduct->product_name,
            'product_code' => $this->parentProduct->product_code,
            'product_quantity' => $this->parentProduct->product_quantity,
            'product_unit' => $this->parentProduct->product_unit,
        ];

        $customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
            'tier' => 'WHOLESALER', // Should trigger tier_1_price (90000) for normal products
        ]);

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->set('pendingProduct', $productArray)
            ->call('confirmBundleSelection', $this->bundle->id)
            ->call('customerSelected', $customer->toArray());

        $row = Cart::instance('sale')->content()->first();
        // Should STILL be 95000 (bundle price), NOT 90000 (tier price)
        $this->assertEquals(95000.0, (float) $row->price);
    }

    public function test_bundled_row_skips_cascading_quantity_repricing()
    {
        $productArray = [
            'id' => $this->parentProduct->id,
            'product_name' => $this->parentProduct->product_name,
            'product_code' => $this->parentProduct->product_code,
            'product_quantity' => $this->parentProduct->product_quantity,
            'product_unit' => $this->parentProduct->product_unit,
        ];

        $lw = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->set('pendingProduct', $productArray)
            ->call('confirmBundleSelection', $this->bundle->id);

        $id = Cart::instance('sale')->content()->first()->id;

        // Simulate forcing a tier that would normally reprice if it wasn't a bundle
        // We can just call updateQuantity and verify it doesn't trigger repricing.
        $lw->set('quantity.' . $id, 5)
            ->call('updateQuantity', 'dummy', $id);

        $row = Cart::instance('sale')->content()->first();
        // Should STILL be 95000
        $this->assertEquals(95000.0, (float) $row->price);
    }

    public function test_manual_bundle_row_price_edit_is_preserved()
    {
        $productArray = [
            'id' => $this->parentProduct->id,
            'product_name' => $this->parentProduct->product_name,
            'product_code' => $this->parentProduct->product_code,
            'product_quantity' => $this->parentProduct->product_quantity,
            'product_unit' => $this->parentProduct->product_unit,
        ];

        $lw = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->set('pendingProduct', $productArray)
            ->call('confirmBundleSelection', $this->bundle->id);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;
        $id = $cartItem->id;

        // Manually edit price to 110000
        $lw->set('unit_price.' . $id, 110000)
            ->set('discount_type.' . $id, 'fixed')
            ->call('updatePrice', $rowId, $id);

        $row = Cart::instance('sale')->content()->first();
        $this->assertEquals(110000.0, (float) $row->price);

        // Change quantity, price should remain 110000
        $lw->set('quantity.' . $id, 2)
            ->call('updateQuantity', $rowId, $id);

        $row = Cart::instance('sale')->content()->first();
        $this->assertEquals(110000.0, (float) $row->price);
    }

    public function test_sales_edit_hydration_normalizes_bundle_components()
    {
        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        $sale = Sale::create([
            'date' => now(), 'due_date' => now(), 'reference' => 'S1',
            'customer_id' => $customer->id, 'customer_name' => $customer->customer_name,
            'tax_id' => null, 'tax_amount' => 0, 'total_amount' => 100000,
            'paid_amount' => 0, 'due_amount' => 100000,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_DRAFTED, 'payment_status' => 'Unpaid',
            'setting_id' => $this->setting->id,
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id, 'product_id' => $this->parentProduct->id,
            'product_name' => 'PARENT', 'product_code' => 'P1',
            'quantity' => 1, 'unit_price' => 100000, 'price' => 100000, 'sub_total' => 100000,
            'product_discount_amount' => 0, 'product_discount_type' => 'fixed', 'product_tax_amount' => 0,
        ]);

        // Create legacy sale bundle item with billable price
        \Modules\Sale\Entities\SaleBundleItem::create([
            'sale_detail_id' => $detail->id, 'sale_id' => $sale->id,
            'bundle_id' => $this->bundle->id,
            'bundle_item_id' => $this->bundle->items()->first()->id,
            'product_id' => $this->bundleItemProduct->id,
            'name' => 'ITEM', 'price' => 5000, 'quantity' => 1, 'sub_total' => 5000,
        ]);

        Livewire::test(EditForm::class, ['sale' => $sale]);

        $row = Cart::instance('sale')->content()->first();
        // Components should be normalized to 0.0 during hydration
        $this->assertEquals(0.0, (float) $row->options->bundle_items[0]['price']);
        $this->assertEquals(0.0, (float) $row->options->bundle_items[0]['sub_total']);
        $this->assertTrue($row->options->is_bundled_row);
    }
}
