<?php

namespace Tests\Feature\Livewire;

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
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Services\SaleNormalizer;
use Modules\Sale\Services\SaleService;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SalesBundleDiscountAndPricingTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Product $parentProduct;
    protected Product $compA;
    protected Product $compB;
    protected Product $standaloneProduct;
    protected ProductBundle $bundle;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);

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
            'company_name' => 'SalesCo',
            'company_email' => 'sales@example.com',
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
            'product_name' => 'Laptop Gamer',
            'product_code' => 'LAP-01',
            'product_unit' => 'pc',
            'product_quantity' => 10,
            'product_cost' => 5000000,
            'product_price' => 6000000,
        ]);
        ProductPrice::create([
            'product_id' => $this->parentProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 6000000,
            'tier_1_price' => 5800000,
        ]);

        $this->compA = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Mouse Gaming',
            'product_code' => 'MOU-01',
            'product_unit' => 'pc',
            'product_quantity' => 20,
            'product_cost' => 50000,
            'product_price' => 100000,
        ]);
        ProductPrice::create([
            'product_id' => $this->compA->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 100000,
        ]);

        $this->compB = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Mousepad XXL',
            'product_code' => 'PAD-01',
            'product_unit' => 'pc',
            'product_quantity' => 20,
            'product_cost' => 20000,
            'product_price' => 50000,
        ]);
        ProductPrice::create([
            'product_id' => $this->compB->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 50000,
        ]);

        $this->standaloneProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Headset Pro',
            'product_code' => 'HED-01',
            'product_unit' => 'pc',
            'product_quantity' => 10,
            'product_cost' => 200000,
            'product_price' => 500000,
        ]);
        ProductPrice::create([
            'product_id' => $this->standaloneProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 500000,
        ]);

        $this->bundle = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $this->parentProduct->id,
            'name' => 'LAPTOP BUNDLE SET',
            'bundle_sale_price' => 5900000,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $this->bundle->id,
            'product_id' => $this->compA->id,
            'quantity' => 1,
            'informational_item_price' => 100000,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $this->bundle->id,
            'product_id' => $this->compB->id,
            'quantity' => 2,
            'informational_item_price' => 50000,
        ]);

        Cart::instance('sale')->destroy();
    }

    /**
     * 2.1 Sales cart parent row price override with fixed component snapshots across quantity, customer, tax.
     */
    public function test_parent_row_price_override_keeps_component_snapshots_fixed(): void
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

        // Override parent price from 5,900,000 to 5,500,000
        $lw->set('unit_price.' . $id, 5500000)
            ->set('discount_type.' . $id, 'fixed')
            ->call('updatePrice', $rowId, $id);

        $row = Cart::instance('sale')->content()->first();
        $this->assertEquals(5500000.0, (float) $row->price);
        $this->assertEquals(5500000.0, (float) $row->options->sub_total);

        // Component snapshots remain intact and non-billable
        $bundleItems = $row->options->bundle_items;
        $this->assertCount(2, $bundleItems);
        $this->assertEquals(100000.0, (float) $bundleItems[0]['informational_item_price']);
        $this->assertEquals(0.0, (float) $bundleItems[0]['price']);
        $this->assertEquals(50000.0, (float) $bundleItems[1]['informational_item_price']);
        $this->assertEquals(0.0, (float) $bundleItems[1]['price']);

        // Changing quantity to 2 scales component quantity but does NOT reprice component snapshots or parent unit price
        $lw->set('quantity.' . $id, 2)
            ->call('updateQuantity', $rowId, $id);

        $row = Cart::instance('sale')->content()->first();
        $this->assertEquals(5500000.0, (float) $row->price);
        $this->assertEquals(11000000.0, (float) $row->options->sub_total);

        $bundleItems = $row->options->bundle_items;
        $this->assertEquals(2, $bundleItems[0]['quantity']);
        $this->assertEquals(100000.0, (float) $bundleItems[0]['informational_item_price']);
        $this->assertEquals(0.0, (float) $bundleItems[0]['price']);
        $this->assertEquals(4, $bundleItems[1]['quantity']);
        $this->assertEquals(50000.0, (float) $bundleItems[1]['informational_item_price']);
        $this->assertEquals(0.0, (float) $bundleItems[1]['price']);
    }

    /**
     * 2.2 Verify and harden Sale normalization/persistence so bundle components remain zero-priced non-billable rows.
     */
    public function test_sale_normalization_and_persistence_keeps_bundle_components_non_billable_after_override(): void
    {
        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);

        $cartData = [
            'id' => $this->parentProduct->id,
            'name' => 'Laptop Gamer',
            'qty' => 1,
            'price' => 5500000.0,
            'options' => [
                'product_id' => $this->parentProduct->id,
                'code' => 'LAP-01',
                'unit_price' => 5500000.0,
                'sub_total' => 5500000.0,
                'sub_total_before_tax' => 5500000.0,
                'product_tax_amount' => 0.0,
                'product_discount' => 0.0,
                'is_bundled_row' => true,
                'bundle_items' => [
                    [
                        'bundle_id' => $this->bundle->id,
                        'bundle_item_id' => $this->bundle->items()->first()->id,
                        'product_id' => $this->compA->id,
                        'name' => 'Mouse Gaming',
                        'quantity' => 1,
                        'quantity_per_bundle' => 1,
                        'price' => 100000.0, // submitted price should be normalized to 0
                        'sub_total' => 100000.0,
                        'informational_item_price' => 100000.0,
                    ],
                ],
            ],
        ];

        $normalizer = app(SaleNormalizer::class);
        $normalized = $normalizer->normalize(['tax_id' => null], [$cartData], true);
        $normalizedDetail = $normalized['details'][0];

        $this->assertEquals(5500000.0, $normalizedDetail['price']);
        $this->assertEquals(5500000.0, $normalizedDetail['sub_total']);
        $this->assertCount(1, $normalizedDetail['bundle_items']);
        $this->assertEquals(0.0, $normalizedDetail['bundle_items'][0]['price']);
        $this->assertEquals(0.0, $normalizedDetail['bundle_items'][0]['sub_total']);

        // Create Sale via SaleService
        $saleService = app(SaleService::class);
        $sale = $saleService->createSale([
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_id' => null,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 5500000.0,
            'paid_amount' => 5500000.0,
            'due_amount' => 0,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ], collect([(object) $cartData]));

        $this->assertEquals(5500000.0, (float) $sale->total_amount);
        $saleDetail = $sale->saleDetails()->first();
        $this->assertEquals(5500000.0, (float) $saleDetail->sub_total);

        $bundleItem = $saleDetail->bundleItems()->first();
        $this->assertEquals(0.0, (float) $bundleItem->price);
        $this->assertEquals(0.0, (float) $bundleItem->sub_total);
    }

    /**
     * 2.3 Row discount on bundled row reduces only the commercial parent row.
     */
    public function test_row_discount_reduces_only_commercial_parent_row(): void
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

        // Apply row discount of 200,000 fixed
        $lw->set('discount_type.' . $id, 'fixed')
            ->set('item_discount.' . $id, 200000)
            ->call('setProductDiscount', $rowId, $id);

        $row = Cart::instance('sale')->content()->first();
        // Price was 5,900,000; discount 200,000 -> subtotal = 5,700,000
        $this->assertEquals(5900000.0, (float) $row->price);
        $this->assertEquals(200000.0, (float) $row->options->product_discount);
        $this->assertEquals(5700000.0, (float) $row->options->sub_total);

        // Components remain non-billable with zero price/subtotal
        foreach ($row->options->bundle_items as $bItem) {
            $this->assertEquals(0.0, (float) $bItem['price']);
            $this->assertEquals(0.0, (float) $bItem['sub_total']);
        }
    }

    /**
     * 2.4 Global discount targets commercial rows only and applies bundled row's share only to its parent.
     */
    /**
     * 2.4 Global discount targets commercial rows only and applies bundled row's share only to its parent.
     */
    public function test_global_discount_applies_only_to_commercial_parent_rows_and_not_components(): void
    {
        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);

        $productArray = [
            'id' => $this->parentProduct->id,
            'product_name' => $this->parentProduct->product_name,
            'product_code' => $this->parentProduct->product_code,
            'product_quantity' => $this->parentProduct->product_quantity,
            'product_unit' => $this->parentProduct->product_unit,
        ];

        $standaloneArray = [
            'id' => $this->standaloneProduct->id,
            'product_name' => $this->standaloneProduct->product_name,
            'product_code' => $this->standaloneProduct->product_code,
            'product_quantity' => $this->standaloneProduct->product_quantity,
            'product_unit' => $this->standaloneProduct->product_unit,
        ];

        $lw = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->set('pendingProduct', $productArray)
            ->call('confirmBundleSelection', $this->bundle->id)
            ->call('addProduct', $standaloneArray);

        // Two commercial rows:
        // Row 1: Bundle parent (5,900,000) with 2 components
        // Row 2: Standalone product (500,000)
        // Total subtotal = 6,400,000

        // Apply 10% global discount
        $lw->set('global_discount_type', 'percentage')
            ->set('global_discount', 10)
            ->call('updateGlobalDiscount');

        $cart = Cart::instance('sale');
        $this->assertCount(2, $cart->content());

        // Overall Livewire grand total = 6,400,000 - 640,000 = 5,760,000
        $lw->assertViewHas('grand_total', 5760000.0);

        // Verify component rows in bundle options did not become separate rows in Cart
        $rows = $cart->content()->values();
        $bundleRow = $rows->first(fn ($item) => (bool) ($item->options->is_bundled_row ?? false));
        $this->assertNotNull($bundleRow);
        $this->assertEquals(5900000.0, (float) $bundleRow->price);
        $this->assertEquals(5900000.0, (float) $bundleRow->options->sub_total);

        foreach ($bundleRow->options->bundle_items as $bItem) {
            $this->assertEquals(0.0, (float) $bItem['price']);
            $this->assertEquals(0.0, (float) $bItem['sub_total']);
        }

        // Test authoritative SaleNormalizer proration with a rounding-sensitive global discount: 100,000.33
        // Commercial Row 1: 5,900,000 (weight 590,000,000)
        // Commercial Row 2: 500,000 (weight 50,000,000)
        // Total weight: 6,400,000 (640,000,000 minor units)
        // Total discount: 100,000.33 (10,000,033 minor units)
        // Base Share 1: intdiv(10,000,033 * 5,900,000, 6,400,000) = 9,218,780 minor (remainder: 3,433,000)
        // Base Share 2: intdiv(10,000,033 * 500,000, 6,400,000) = 781,252 minor (remainder: 4,375,000)
        // Remainder 2 (4,375,000) > Remainder 1 (3,433,000) => +1 minor unit assigned to Row 2!
        // Final Share 1: 9,218,780 minor = 92,187.80
        // Final Share 2: 781,253 minor = 7,812.53
        // Sum = 92,187.80 + 7,812.53 = 100,000.33 exactly!
        $normalizer = app(SaleNormalizer::class);
        $cartItemsArray = $cart->content()->map(fn ($item) => [
            'id' => $item->id,
            'name' => $item->name,
            'qty' => $item->qty,
            'price' => $item->price,
            'options' => $item->options->toArray(),
        ])->values()->all();

        $headerPayload = [
            'discount_percentage' => 0,
            'discount_amount' => 100000.33,
            'shipping_amount' => 50000.0,
            'paid_amount' => 6349999.67,
        ];

        $normalized = $normalizer->normalize($headerPayload, $cartItemsArray, true);
        $this->assertCount(2, $normalized['details']);
        $detail1 = $normalized['details'][0];
        $detail2 = $normalized['details'][1];

        // 1. Commercial rows only are proration targets
        $this->assertEquals(92187.80, (float) $detail1['global_discount_share']);
        $this->assertEquals(7812.53, (float) $detail2['global_discount_share']);
        $this->assertEquals(100000.33, round((float) $detail1['global_discount_share'] + (float) $detail2['global_discount_share'], 2));
        $this->assertEquals(100000.33, (float) $normalized['allocated_discount_total']);
        $this->assertEquals(100000.33, (float) $normalized['computed_discount_amount']);

        // 2. Effective subtotal reconciliation
        $this->assertEquals(round(5900000 - 92187.80, 2), (float) $detail1['effective_sub_total']);
        $this->assertEquals(round(500000 - 7812.53, 2), (float) $detail2['effective_sub_total']);
        $expectedEffectiveTotal = round((float) $detail1['effective_sub_total'] + (float) $detail2['effective_sub_total'], 2);
        $this->assertEquals(6299999.67, $expectedEffectiveTotal);
        $this->assertEquals(6299999.67, (float) $normalized['effective_commercial_total']);

        // 3. Header total derived authoritatively from effective commercial total plus shipping
        $this->assertEquals(round(6299999.67 + 50000.0, 2), (float) $normalized['header']['total_amount']);

        // 4. Components in bundle remain non-billable
        $this->assertCount(2, $detail1['bundle_items']);
        $this->assertEquals(0.0, (float) $detail1['bundle_items'][0]['price']);
        $this->assertEquals(0.0, (float) $detail1['bundle_items'][0]['sub_total']);
        $this->assertEquals(0.0, (float) $detail1['bundle_items'][1]['price']);
        $this->assertEquals(0.0, (float) $detail1['bundle_items'][1]['sub_total']);

        // 5. Persist through SaleService createSale and assert database records
        // Note: In established Normal Sales persistence, row allocation is an authoritative
        // calculation used to derive and reconcile total_amount, while sale_details.sub_total
        // retains the pre-global-discount commercial subtotal and sales retains the header global discount.
        $saleService = app(SaleService::class);
        $sale = $saleService->createSale([
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_id' => null,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 100000.33,
            'shipping_amount' => 50000.0,
            'total_amount' => 6349999.67,
            'paid_amount' => 6349999.67,
            'due_amount' => 0,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ], collect($cartItemsArray));

        $this->assertEquals(6349999.67, (float) $sale->total_amount);
        $this->assertEquals(100000.33, (float) $sale->discount_amount);
        $this->assertEquals(50000.0, (float) $sale->shipping_amount);
        $this->assertCount(2, $sale->saleDetails);

        $savedBundleDetail = $sale->saleDetails()->where('product_id', $this->parentProduct->id)->first();
        $this->assertNotNull($savedBundleDetail);
        $this->assertEquals(5900000.0, (float) $savedBundleDetail->sub_total);
        $this->assertCount(2, $savedBundleDetail->bundleItems);

        foreach ($savedBundleDetail->bundleItems as $sbi) {
            $this->assertEquals(0.0, (float) $sbi->price);
            $this->assertEquals(0.0, (float) $sbi->sub_total);
        }

        // 6. Test updateSale normalization path does not double-apply the global discount
        $updatedSale = $saleService->updateSale($sale, [
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_id' => null,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 100000.33,
            'shipping_amount' => 50000.0,
            'total_amount' => 6349999.67,
            'paid_amount' => 6349999.67,
            'due_amount' => 0,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ], collect($cartItemsArray));

        $this->assertEquals(6349999.67, (float) $updatedSale->total_amount);
        $this->assertEquals(100000.33, (float) $updatedSale->discount_amount);
        $this->assertEquals(50000.0, (float) $updatedSale->shipping_amount);
    }

    /**
     * A. Excessive fixed discount:
     * - commercial subtotal 100.00;
     * - requested discount 150.00;
     * - computed discount = 100.00;
     * - allocated discount total = 100.00;
     * - sum of row shares = 100.00;
     * - effective commercial total = 0.00;
     * - header discount amount = 100.00;
     * - header total = shipping only (e.g. 20.00) or zero;
     * - persisted Sale discount amount = 100.00;
     * - persisted Sale total matches the normalized total.
     */
    public function test_global_discount_normalization_caps_excessive_fixed_discount_and_preserves_consistency(): void
    {
        $customer = Customer::factory()->create(['setting_id' => $this->setting->id]);

        $cartData = [
            'id' => $this->parentProduct->id,
            'name' => 'Standalone Product',
            'qty' => 1,
            'price' => 100.0,
            'options' => [
                'product_id' => $this->parentProduct->id,
                'code' => 'P-100',
                'unit_price' => 100.0,
                'sub_total' => 100.0,
                'sub_total_before_tax' => 100.0,
                'product_tax_amount' => 0.0,
                'product_discount' => 0.0,
                'is_bundled_row' => false,
                'bundle_items' => [],
            ],
        ];

        $normalizer = app(SaleNormalizer::class);
        $normalized = $normalizer->normalize([
            'discount_percentage' => 0,
            'discount_amount' => 150.0, // excessive fixed discount
            'shipping_amount' => 20.0,
            'paid_amount' => 0,
        ], [$cartData], false);

        // Assert normalized results
        $this->assertEquals(100.0, (float) $normalized['computed_discount_amount']);
        $this->assertEquals(100.0, (float) $normalized['allocated_discount_total']);
        $this->assertEquals(0.0, (float) $normalized['effective_commercial_total']);
        $this->assertEquals(100.0, (float) $normalized['header']['discount_amount']);
        $this->assertEquals(0.0, (float) $normalized['header']['discount_percentage']);
        $this->assertEquals(20.0, (float) $normalized['header']['total_amount']); // shipping only
        $this->assertEquals(100.0, (float) $normalized['details'][0]['global_discount_share']);
        $this->assertEquals(0.0, (float) $normalized['details'][0]['effective_sub_total']);
        $this->assertEquals(100.0, (float) $normalized['details'][0]['sub_total']); // pre-global-discount subtotal preserved

        // Assert persisted Sale matches normalized values
        $saleService = app(SaleService::class);
        $sale = $saleService->createSale([
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_id' => null,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 150.0,
            'shipping_amount' => 20.0,
            'paid_amount' => 0,
            'due_amount' => 20.0,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ], collect([$cartData]));

        $this->assertEquals(100.0, (float) $sale->discount_amount);
        $this->assertEquals(20.0, (float) $sale->total_amount);
        $this->assertEquals(100.0, (float) $sale->saleDetails()->first()->sub_total);
    }

    /**
     * B. Negative fixed discount:
     * - normalized effective discount is zero;
     * - total remains unchanged.
     */
    public function test_global_discount_normalization_handles_negative_input(): void
    {
        $cartData = [
            'id' => $this->parentProduct->id,
            'name' => 'Standalone Product',
            'qty' => 1,
            'price' => 100.0,
            'options' => [
                'product_id' => $this->parentProduct->id,
                'code' => 'P-100',
                'unit_price' => 100.0,
                'sub_total' => 100.0,
                'sub_total_before_tax' => 100.0,
                'product_tax_amount' => 0.0,
                'product_discount' => 0.0,
                'is_bundled_row' => false,
                'bundle_items' => [],
            ],
        ];

        $normalizer = app(SaleNormalizer::class);
        $normalized = $normalizer->normalize([
            'discount_percentage' => -10,
            'discount_amount' => -50.0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
        ], [$cartData], false);

        $this->assertEquals(0.0, (float) $normalized['computed_discount_amount']);
        $this->assertEquals(0.0, (float) $normalized['allocated_discount_total']);
        $this->assertEquals(100.0, (float) $normalized['effective_commercial_total']);
        $this->assertEquals(0.0, (float) $normalized['header']['discount_amount']);
        $this->assertEquals(0.0, (float) $normalized['header']['discount_percentage']);
        $this->assertEquals(100.0, (float) $normalized['header']['total_amount']);
    }

    /**
     * C. Percentage boundary/direct normalizer safety:
     * - percentage over 100 is clamped to 100;
     * - percentage mode zeroes unrelated fixed-discount input so dual representation is avoided.
     */
    public function test_global_discount_normalization_clamps_percentage_and_ignores_fixed_input(): void
    {
        $cartData = [
            'id' => $this->parentProduct->id,
            'name' => 'Standalone Product',
            'qty' => 1,
            'price' => 200.0,
            'options' => [
                'product_id' => $this->parentProduct->id,
                'code' => 'P-200',
                'unit_price' => 200.0,
                'sub_total' => 200.0,
                'sub_total_before_tax' => 200.0,
                'product_tax_amount' => 0.0,
                'product_discount' => 0.0,
                'is_bundled_row' => false,
                'bundle_items' => [],
            ],
        ];

        $normalizer = app(SaleNormalizer::class);
        $normalized = $normalizer->normalize([
            'discount_percentage' => 150.0, // over 100%
            'discount_amount' => 50.0,      // conflicting fixed discount
            'shipping_amount' => 10.0,
            'paid_amount' => 0,
        ], [$cartData], false);

        $this->assertEquals(100.0, (float) $normalized['header']['discount_percentage']);
        $this->assertEquals(0.0, (float) $normalized['header']['discount_amount']);
        $this->assertEquals(200.0, (float) $normalized['computed_discount_amount']);
        $this->assertEquals(200.0, (float) $normalized['allocated_discount_total']);
        $this->assertEquals(0.0, (float) $normalized['effective_commercial_total']);
        $this->assertEquals(10.0, (float) $normalized['header']['total_amount']); // shipping only
    }
}

