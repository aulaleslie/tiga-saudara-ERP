<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Livewire\Sale\CreateForm;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Currency;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class SaleMonetaryValuesTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Customer $customer;
    protected Product $product;
    protected Product $bundleProduct;
    protected ProductBundle $bundle;
    protected ProductBundleItem $bundleItem;
    protected PaymentTerm $paymentTerm;
    protected PaymentMethod $paymentMethod;
    protected Tax $tax;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(function () {
            return true;
        });

        $this->withoutMiddleware(CheckUserRoleForSetting::class);

        $user = User::factory()->create();
        $this->actingAs($user);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Co',
            'company_email' => 'company@example.com',
            'company_phone' => '123456789',
            'site_logo' => null,
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        Session::put('setting_id', $this->setting->id);

        $unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $this->setting->id,
        ]);

        $this->paymentTerm = PaymentTerm::create([
            'name' => 'NET 10',
            'longevity' => 10,
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '0800000000',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Street 1',
            'company_name' => 'Customer Co',
            'contact_name' => 'Contact',
            'billing_address' => 'Billing',
            'shipping_address' => 'Shipping',
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $category = Category::create([
            'category_code' => 'CAT001',
            'category_name' => 'Category',
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Sample Product',
            'product_code' => 'PRD001',
            'product_barcode_symbology' => null,
            'product_quantity' => 100,
            'product_cost' => 5.50,
            'product_price' => 15.75,
            'product_unit' => 'PCS',
            'product_stock_alert' => 5,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'stock_managed' => true,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'sale_price' => 15.75,
            'tier_1_price' => 15.75,
            'tier_2_price' => 15.75,
        ]);

        $this->bundleProduct = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'Bundle Component',
            'product_code' => 'PRD-BND-01',
            'product_barcode_symbology' => null,
            'product_quantity' => 100,
            'product_cost' => 2.40,
            'product_price' => 6.50,
            'product_unit' => 'PCS',
            'product_stock_alert' => 5,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'stock_managed' => true,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'sale_price' => 6.50,
            'tier_1_price' => 6.50,
            'tier_2_price' => 6.50,
        ]);

        $this->bundle = ProductBundle::create([
            'parent_product_id' => $this->product->id,
            'name' => 'Starter Bundle',
            'description' => 'Bundle used for sales tests',
            'price' => 6.50,
        ]);

        $this->bundleItem = ProductBundleItem::create([
            'bundle_id' => $this->bundle->id,
            'product_id' => $this->bundleProduct->id,
            'price' => 3.25,
            'quantity' => 2,
        ]);

        $chartOfAccount = ChartOfAccount::create([
            'name' => 'Kas',
            'account_number' => '1000',
            'category' => 'Kas & Bank',
            'parent_account_id' => null,
            'tax_id' => null,
            'description' => null,
            'setting_id' => $this->setting->id,
        ]);

        $this->paymentMethod = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $chartOfAccount->id,
            'is_cash' => true,
            'is_available_in_pos' => true,
        ]);

        $this->tax = Tax::create([
            'name' => 'VAT 10%',
            'value' => 10,
        ]);
    }

    protected function addCartItem(float $price, float $discount, float $taxAmount, float $subTotal): void
    {
        Cart::instance('sale')->destroy();

        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 2,
            'price' => $price,
            'weight' => 1,
            'options' => [
                'product_id' => $this->product->id,
                'code' => $this->product->product_code,
                'unit_price' => $price,
                'product_discount' => $discount,
                'product_discount_type' => 'fixed',
                'product_tax' => $taxAmount,
                'sub_total' => $subTotal,
                'sub_total_before_tax' => $subTotal - $taxAmount,
                'bundle_items' => [],
                'stock' => 100,
            ],
        ]);
    }

    protected function addBundleCartItem(int $parentQuantity = 1): void
    {
        Cart::instance('sale')->destroy();

        $parentQuantity = max(1, $parentQuantity);
        $bundleUnitPrice = (float) $this->bundleItem->price;
        $quantityPerBundle = (int) $this->bundleItem->quantity;
        $bundleSubtotalPerUnit = round($bundleUnitPrice * $quantityPerBundle, 2);
        $bundleRowSubtotal = round($bundleUnitPrice * $quantityPerBundle * $parentQuantity, 2);

        $parentUnitPrice = (float) $this->product->sale_price;
        $finalUnitPrice = round($parentUnitPrice + $bundleSubtotalPerUnit, 2);
        $rowSubtotal = round($finalUnitPrice * $parentQuantity, 2);

        $bundleItems = [[
            'bundle_id' => $this->bundle->id,
            'bundle_item_id' => $this->bundleItem->id,
            'product_id' => $this->bundleProduct->id,
            'name' => $this->bundleProduct->product_name,
            'price' => $bundleUnitPrice,
            'quantity' => $quantityPerBundle * $parentQuantity,
            'quantity_per_bundle' => $quantityPerBundle,
            'sub_total' => $bundleRowSubtotal,
        ]];

        Cart::instance('sale')->add([
            'id' => Str::uuid()->toString(),
            'name' => $this->product->product_name,
            'qty' => $parentQuantity,
            'price' => $finalUnitPrice,
            'weight' => 1,
            'options' => [
                'product_id' => $this->product->id,
                'code' => $this->product->product_code,
                'unit_price' => $finalUnitPrice,
                'product_discount' => 0.0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'sub_total' => $rowSubtotal,
                'sub_total_before_tax' => $rowSubtotal,
                'bundle_items' => $bundleItems,
                'bundle_price' => $bundleRowSubtotal,
                'bundle_name' => $this->bundle->name,
                'stock' => 100,
                'unit' => 'PCS',
                'sale_price' => $parentUnitPrice,
                'tier_1_price' => $this->product->tier_1_price,
                'tier_2_price' => $this->product->tier_2_price,
                'quantity_non_tax' => 0,
                'quantity_tax' => 0,
            ],
        ]);
    }

    public function test_sale_store_persists_decimal_amounts(): void
    {
        $this->addCartItem(24.80, 1.20, 0.80, 48.40);

        $response = $this->post(route('sales.store'), [
            'customer_id' => $this->customer->id,
            'reference' => 'SL-TEST',
            'date' => '2024-04-01',
            'due_date' => '2024-04-11',
            'tax_id' => null,
            'discount_percentage' => 0,
            'shipping_amount' => 12.35,
            'total_amount' => 60.75,
            'payment_term_id' => $this->paymentTerm->id,
            'note' => 'Store decimals',
            'is_tax_included' => false,
        ]);

        $response->assertRedirect(route('sales.index'));

        $sale = Sale::latest('id')->with('saleDetails')->first();

        $this->assertNotNull($sale);
        $this->assertEquals(12.35, (float) $sale->shipping_amount);
        $this->assertEquals(60.75, (float) $sale->total_amount);
        $this->assertEquals(60.75, (float) $sale->due_amount);

        $detail = $sale->saleDetails->first();
        $this->assertEquals(24.80, (float) $detail->unit_price);
        $this->assertEquals(24.80, (float) $detail->price);
        $this->assertEquals(48.40, (float) $detail->sub_total);
        $this->assertEquals(1.20, (float) $detail->product_discount_amount);
        $this->assertEquals(0.80, (float) $detail->product_tax_amount);
    }

    public function test_sale_store_merges_duplicate_cart_rows(): void
    {
        Cart::instance('sale')->destroy();

        $bundle = [
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'product_id' => null,
            'name' => 'Accessory',
            'price' => 3.00,
            'quantity' => 2,
            'sub_total' => 6.00,
        ];

        for ($i = 0; $i < 2; $i++) {
            Cart::instance('sale')->add([
                'id' => (string) Str::uuid(),
                'name' => $this->product->product_name,
                'qty' => 1,
                'price' => 15.90,
                'weight' => 1,
                'options' => [
                    'product_id' => $this->product->id,
                    'code' => $this->product->product_code,
                    'unit_price' => 15.90,
                    'product_discount' => 1.00,
                    'product_discount_type' => 'fixed',
                    'product_tax' => $this->tax->id,
                    'sub_total_before_tax' => 15.00,
                    'sub_total' => 15.90,
                    'bundle_items' => [$bundle],
                    'stock' => 100,
                ],
            ]);
        }

        $response = $this->post(route('sales.store'), [
            'customer_id' => $this->customer->id,
            'reference' => 'SL-AGG',
            'date' => '2024-04-01',
            'due_date' => '2024-04-11',
            'tax_id' => $this->tax->id,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 31.80,
            'payment_term_id' => $this->paymentTerm->id,
            'note' => 'Aggregate duplicates',
            'is_tax_included' => false,
        ]);

        $response->assertRedirect(route('sales.index'));

        $sale = Sale::latest('id')->with('saleDetails.bundleItems')->first();

        $this->assertNotNull($sale);
        $this->assertCount(1, $sale->saleDetails);

        $detail = $sale->saleDetails->first();
        $this->assertEquals(2, (int) $detail->quantity);
        $this->assertEquals(2.00, (float) $detail->product_discount_amount);
        $this->assertEquals(31.80, (float) $detail->sub_total);
        $this->assertEquals(1.80, (float) $detail->product_tax_amount);

        $this->assertCount(1, $detail->bundleItems);
        $bundleItem = $detail->bundleItems->first();
        $this->assertEquals(4, (int) $bundleItem->quantity);
        $this->assertEquals(12.00, (float) $bundleItem->sub_total);
    }

    public function test_sale_store_persists_bundle_item_amounts(): void
    {
        $this->addBundleCartItem();

        $response = $this->post(route('sales.store'), [
            'customer_id' => $this->customer->id,
            'reference' => 'SL-BUNDLE',
            'date' => '2024-04-01',
            'due_date' => '2024-04-11',
            'tax_id' => null,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 22.25,
            'payment_term_id' => $this->paymentTerm->id,
            'note' => 'Store bundle amounts',
            'is_tax_included' => false,
        ]);

        $response->assertRedirect(route('sales.index'));

        $sale = Sale::latest('id')->with('saleDetails.bundleItems')->first();

        $this->assertNotNull($sale);
        $this->assertCount(1, $sale->saleDetails);

        $detail = $sale->saleDetails->first();
        $this->assertEquals(22.25, (float) $detail->sub_total);

        $bundleItem = $detail->bundleItems->first();
        $this->assertNotNull($bundleItem);
        $this->assertEquals(3.25, (float) $bundleItem->price);
        $this->assertEquals(6.50, (float) $bundleItem->sub_total);
        $this->assertEquals(2, (int) $bundleItem->quantity);
    }

    public function test_livewire_submit_persists_bundle_item_amounts(): void
    {
        $this->addBundleCartItem();

        Livewire::test(CreateForm::class)
            ->set('customerId', $this->customer->id)
            ->set('date', '2024-04-01')
            ->set('dueDate', '2024-04-11')
            ->set('paymentTermId', $this->paymentTerm->id)
            ->call('submit')
            ->assertRedirect(route('sales.index'));

        $bundleItem = SaleBundleItem::latest('id')->first();

        $this->assertNotNull($bundleItem);
        $this->assertEquals(3.25, (float) $bundleItem->price);
        $this->assertEquals(6.50, (float) $bundleItem->sub_total);
        $this->assertEquals(2, (int) $bundleItem->quantity);
    }

    public function test_sale_update_persists_decimal_amounts(): void
    {
        $sale = Sale::create([
            'date' => '2024-03-01',
            'due_date' => '2024-03-11',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 5.25,
            'total_amount' => 40.00,
            'paid_amount' => 10.00,
            'due_amount' => 30.00,
            'status' => 'Pending',
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'note' => null,
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
            'is_tax_included' => false,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 20.00,
            'unit_price' => 20.00,
            'sub_total' => 20.00,
            'product_discount_amount' => 0.00,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0.00,
        ]);

        $this->addCartItem(25.45, 1.50, 0.75, 49.40);

        $response = $this->put(route('sales.update', $sale), [
            'customer_id' => $this->customer->id,
            'reference' => $sale->reference,
            'tax_percentage' => 5,
            'discount_percentage' => 0,
            'shipping_amount' => 11.65,
            'total_amount' => 61.50,
            'paid_amount' => 21.40,
            'status' => 'Pending',
            'payment_method' => 'Cash',
            'note' => 'Update decimals',
        ]);

        $response->assertRedirect(route('sales.index'));

        $sale->refresh();
        $this->assertEquals(11.65, (float) $sale->shipping_amount);
        $this->assertEquals(61.50, (float) $sale->total_amount);
        $this->assertEquals(40.10, (float) $sale->due_amount);
        $this->assertEquals(21.40, (float) $sale->paid_amount);

        $detail = $sale->saleDetails()->first();
        $this->assertEquals(25.45, (float) $detail->price);
        $this->assertEquals(25.45, (float) $detail->unit_price);
        $this->assertEquals(49.40, (float) $detail->sub_total);
        $this->assertEquals(1.50, (float) $detail->product_discount_amount);
        $this->assertEquals(0.75, (float) $detail->product_tax_amount);
    }

    public function test_sale_payment_store_updates_sale_totals(): void
    {
        $sale = Sale::create([
            'date' => '2024-02-01',
            'due_date' => '2024-02-11',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 120.00,
            'paid_amount' => 40.00,
            'due_amount' => 80.00,
            'status' => 'Pending',
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'note' => null,
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
            'is_tax_included' => false,
        ]);

        $response = $this->post(route('sale-payments.store'), [
            'date' => '2024-02-15',
            'reference' => 'PAY-01',
            'amount' => 30.75,
            'note' => 'Store payment',
            'sale_id' => $sale->id,
            'payment_method_id' => $this->paymentMethod->id,
        ]);

        $response->assertRedirect(route('sales.index'));

        $sale->refresh();
        $payment = SalePayment::latest('id')->first();

        $this->assertEquals(30.75, (float) $payment->amount);
        $this->assertEquals(70.75, (float) $sale->paid_amount);
        $this->assertEquals(49.25, (float) $sale->due_amount);
        $this->assertEquals('Partial', $sale->payment_status);
    }

    public function test_sale_payment_update_adjusts_sale_totals(): void
    {
        $sale = Sale::create([
            'date' => '2024-01-05',
            'due_date' => '2024-01-15',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100.00,
            'paid_amount' => 20.00,
            'due_amount' => 80.00,
            'status' => 'Pending',
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
            'note' => null,
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
            'is_tax_included' => false,
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'date' => '2024-01-06',
            'reference' => 'PAY-02',
            'amount' => 20.00,
            'payment_method_id' => $this->paymentMethod->id,
            'payment_method' => 'Cash',
            'note' => null,
        ]);

        $response = $this->patch(route('sale-payments.update', $payment), [
            'date' => '2024-01-07',
            'reference' => 'PAY-02',
            'amount' => 35.50,
            'note' => 'Update payment',
            'sale_id' => $sale->id,
            'payment_method_id' => $this->paymentMethod->id,
        ]);

        $response->assertRedirect(route('sales.index'));

        $sale->refresh();
        $payment->refresh();

        $this->assertEquals(35.50, (float) $payment->amount);
        $this->assertEquals(35.50, (float) $sale->paid_amount);
        $this->assertEquals(64.50, (float) $sale->due_amount);
        $this->assertEquals('Partial', $sale->payment_status);
    }

    public function test_sales_index_returns_unpaid_badge_for_new_sale(): void
    {
        $this->addCartItem(24.80, 1.20, 0.80, 48.40);

        $this->post(route('sales.store'), [
            'customer_id' => $this->customer->id,
            'reference' => 'SL-BADGE',
            'date' => '2024-05-01',
            'due_date' => '2024-05-11',
            'tax_id' => null,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 48.40,
            'payment_term_id' => $this->paymentTerm->id,
            'note' => null,
            'is_tax_included' => false,
        ])->assertRedirect(route('sales.index'));

        $sale = Sale::latest('id')->first();
        $this->assertNotNull($sale);

        $query = http_build_query([
            'draw' => 1,
            'columns' => [
                ['data' => 'reference', 'name' => 'reference', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'payment_status', 'name' => 'payment_status', 'searchable' => 'true', 'orderable' => 'true'],
            ],
            'start' => 0,
            'length' => 10,
        ]);

        $response = $this->get(
            route('sales.index') . '?' . $query,
            ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']
        );

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertStringContainsString('badge badge-danger', $data[0]['payment_status']);
        $this->assertStringContainsString('Unpaid', $data[0]['payment_status']);
    }
}
