<?php

namespace Modules\Pos\Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Services\PosBundleComponentResolver;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Tests\TestCase;

class POSReturnOriginalBundleResolutionTest extends PosTransactionFeatureTestCase
{
    protected $user;
    protected $setting;
    protected $location;
    protected $terminal;
    protected $session;
    private PosBundleComponentResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(PosBundleComponentResolver::class);
        $this->setting = $this->createSetting('Original Bundle Test');
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        $this->user = $this->createUserForSetting($this->setting, 'Bundle Resolver User', ['pos.access', 'pos.sell']);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    /** @test */
    public function it_identifies_original_non_serial_split_owner_bundle_from_carrier_and_bundle_item_evidence(): void
    {
        // Owner 1: Camera (Parent)
        $setting1 = $this->createSetting('Store 1');
        $loc1 = Location::create(['name' => 'Loc 1', 'setting_id' => $setting1->id]);
        $customer1 = Customer::create(['customer_name' => 'Customer 1', 'customer_phone' => '08123456789']);

        // Owner 2: MicroSD (Component)
        $setting2 = $this->createSetting('Store 2');
        $loc2 = Location::create(['name' => 'Loc 2', 'setting_id' => $setting2->id]);
        $customer2 = Customer::create(['customer_name' => 'Customer 2', 'customer_phone' => '08123456789']);

        $category = Category::create(['category_code' => 'CAT', 'category_name' => 'Electronics', 'created_by' => 1, 'setting_id' => $setting1->id]);
        $parentProduct = Product::create([
            'setting_id' => $setting1->id,
            'category_id' => $category->id,
            'product_name' => 'Camera Kit',
            'product_code' => 'CAM-KIT',
            'product_price' => 890000,
            'product_unit' => 1,
            'product_cost' => 500000,
            'serial_number_required' => false,
        ]);
        $compProduct = Product::create([
            'setting_id' => $setting2->id,
            'category_id' => $category->id,
            'product_name' => 'MicroSD 64GB',
            'product_code' => 'MSD-64',
            'product_price' => 260000,
            'product_unit' => 1,
            'product_cost' => 150000,
            'serial_number_required' => false,
        ]);

        // Catalog bundle
        $bundle = ProductBundle::create([
            'parent_product_id' => $parentProduct->id,
            'name' => 'Camera Kit with MicroSD',
            'setting_id' => $setting1->id,
        ]);
        $bundleItem = ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $compProduct->id,
            'quantity' => 1,
        ]);

        // Sale 1 (Owner 1): Parent product qty 1
        $sale1 = Sale::create([
            'date' => now(),
            'customer_id' => $customer1->id,
            'customer_name' => $customer1->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 630000,
            'paid_amount' => 0,
            'due_amount' => 630000,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $setting1->id,
        ]);

        $parentDetail = $this->createSaleDetail([
            'sale_id' => $sale1->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 1,
            'price' => 630000,
            'unit_price' => 630000,
            'sub_total' => 630000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $dispatch1 = Dispatch::create([
            'sale_id' => $sale1->id,
            'status' => Dispatch::STATUS_APPROVED,
            'dispatch_date' => now(),
        ]);
        $dispatchDetail1 = DispatchDetail::create([
            'dispatch_id' => $dispatch1->id,
            'sale_id' => $sale1->id,
            'sale_detail_id' => $parentDetail->id,
            'product_id' => $parentProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $loc1->id,
        ]);

        // Sale 2 (Owner 2): Zero-quantity carrier row for parent product, carrying component SaleBundleItem
        $sale2 = Sale::create([
            'date' => now(),
            'customer_id' => $customer2->id,
            'customer_name' => $customer2->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 260000,
            'paid_amount' => 0,
            'due_amount' => 260000,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $setting2->id,
        ]);

        $carrierDetail = $this->createSaleDetail([
            'sale_id' => $sale2->id,
            'product_id' => $parentProduct->id, // Carrier keyed by PARENT product
            'product_name' => $parentProduct->product_name,
            'product_code' => $parentProduct->product_code,
            'quantity' => 0, // Zero quantity carrier row
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $sbi = SaleBundleItem::create([
            'sale_id' => $sale2->id,
            'sale_detail_id' => $carrierDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bundleItem->id,
            'product_id' => $compProduct->id,
            'name' => $compProduct->product_name,
            'quantity' => 1,
            'price' => 260000,
            'sub_total' => 260000,
        ]);

        $dispatch2 = Dispatch::create([
            'sale_id' => $sale2->id,
            'status' => Dispatch::STATUS_APPROVED,
            'dispatch_date' => now(),
        ]);
        $dispatchDetail2 = DispatchDetail::create([
            'dispatch_id' => $dispatch2->id,
            'sale_id' => $sale2->id,
            'sale_detail_id' => $carrierDetail->id,
            'product_id' => $compProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $loc2->id,
            'bundle_id' => $bundle->id,
        ]);

        // Checkout linking the two sales
        $posTxn = $this->createPosTransaction([
            'transaction_number' => 'TNC-TXN-2026-10-0638',
            'code' => 'TNC-TXN-2026-10-0638',
            'total_amount' => 890000,
            'setting_id' => $setting1->id,
        ]);
        $ptl = $this->createPosTransactionLine([
            'pos_transaction_id' => $posTxn->id,
            'product_id' => $parentProduct->id,
            'product_name' => $parentProduct->product_name,
            'qty' => 1,
            'unit_price' => 890000,
            'subtotal' => 890000,
            'line_meta' => [
                'is_bundle' => true,
                'bundle_id' => $bundle->id,
                'bundle_name' => $bundle->bundle_name,
                'bundle_items' => [
                    [
                        'product_id' => $compProduct->id,
                        'name' => $compProduct->product_name,
                        'quantity' => 1,
                        'bundle_item_id' => $bundleItem->id,
                    ],
                ],
            ],
        ]);

        $posCheckout = $this->createPosCheckout([
            'checkout_number' => 'CHK-6867',
            'pos_transaction_id' => $posTxn->id,
            'total_amount' => 890000,
            'payment_status' => 'Unpaid',
            'status' => 'Completed',
        ]);

        $cs1 = $this->createPosCheckoutSale([
            'pos_checkout_id' => $posCheckout->id,
            'sale_id' => $sale1->id,
            'setting_id' => $setting1->id,
            'subtotal' => 630000,
        ]);
        $cs2 = $this->createPosCheckoutSale([
            'pos_checkout_id' => $posCheckout->id,
            'sale_id' => $sale2->id,
            'setting_id' => $setting2->id,
            'subtotal' => 260000,
        ]);

        $checkoutSales = PosCheckoutSale::with(['sale.saleDetails.bundleItems.product'])
            ->where('pos_checkout_id', $posCheckout->id)
            ->get();

        // Resolve context on parentDetail
        $result = $this->resolver->resolveOriginalBundleContext($parentDetail, $checkoutSales, $posTxn);

        $this->assertNotNull($result);
        $this->assertTrue($result['is_bundle']);
        $this->assertFalse($result['is_ambiguous']);
        $this->assertEquals($bundle->id, $result['bundle_id']);
        $this->assertCount(1, $result['components']);

        $comp = $result['components'][0];
        $this->assertEquals($compProduct->id, $comp['product_id']);
        $this->assertEquals(1.0, (float) $comp['quantity_per_bundle']);
        $this->assertEquals($carrierDetail->id, $comp['carrier_sale_detail_id']);
        $this->assertEquals($sale2->id, $comp['sale_id']);
        $this->assertEquals($sbi->id, $comp['sale_bundle_item_id']);
        $this->assertEquals($dispatchDetail2->id, $comp['dispatch_detail_id']);
    }

    /** @test */
    public function it_returns_null_for_ordinary_non_bundle_sales_even_if_product_exists_as_bundle_in_catalog(): void
    {
        $setting = $this->createSetting('Store Non Bundle');
        $customer = Customer::create(['customer_name' => 'Customer Standalone']);

        $category = Category::create(['category_code' => 'CAT2', 'category_name' => 'Electronics', 'created_by' => 1, 'setting_id' => $setting->id]);
        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => 'Dual Persona Product',
            'product_code' => 'DUAL-01',
            'product_price' => 500000,
            'product_unit' => 1,
            'product_cost' => 300000,
            'serial_number_required' => false,
        ]);

        // Catalog bundle exists for this product!
        ProductBundle::create([
            'parent_product_id' => $product->id,
            'name' => 'Dual Persona Bundle',
            'setting_id' => $setting->id,
        ]);

        // But checkout was for ordinary standalone sale
        $sale = Sale::create([
            'date' => now(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 500000,
            'paid_amount' => 500000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $setting->id,
        ]);

        $detail = $this->createSaleDetail([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 500000,
            'unit_price' => 500000,
            'sub_total' => 500000,
        ]);

        $posTxn = $this->createPosTransaction([
            'transaction_number' => 'TXN-STANDALONE',
            'code' => 'TXN-STANDALONE',
            'total_amount' => 500000,
            'setting_id' => $setting->id,
        ]);
        $this->createPosTransactionLine([
            'pos_transaction_id' => $posTxn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'qty' => 1,
            'unit_price' => 500000,
            'subtotal' => 500000,
            'line_meta' => [], // Ordinary line: NO bundle metadata
        ]);

        $posCheckout = $this->createPosCheckout([
            'checkout_number' => 'CHK-STANDALONE',
            'pos_transaction_id' => $posTxn->id,
            'total_amount' => 500000,
            'payment_status' => 'Paid',
            'status' => 'Completed',
        ]);
        $cs = $this->createPosCheckoutSale([
            'pos_checkout_id' => $posCheckout->id,
            'sale_id' => $sale->id,
            'setting_id' => $setting->id,
            'subtotal' => 500000,
        ]);

        $checkoutSales = PosCheckoutSale::with(['sale.saleDetails.bundleItems.product'])
            ->where('pos_checkout_id', $posCheckout->id)
            ->get();

        $result = $this->resolver->resolveOriginalBundleContext($detail, $checkoutSales, $posTxn);

        // MUST be null: sold as a standalone item, not as a bundle!
        $this->assertNull($result);
    }

    /** @test */
    public function it_flags_ambiguity_when_repeated_products_cannot_be_uniquely_resolved(): void
    {
        $setting = $this->createSetting('Store Ambiguous');
        $customer = Customer::create(['customer_name' => 'Customer Ambiguous']);

        $category = Category::create(['category_code' => 'CAT3', 'category_name' => 'Electronics', 'created_by' => 1, 'setting_id' => $setting->id]);
        $parent = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => 'Repeated Bundle Item',
            'product_code' => 'REP-01',
            'product_price' => 100000,
            'product_unit' => 1,
            'product_cost' => 60000,
            'serial_number_required' => false,
        ]);
        $comp = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => 'Component A',
            'product_code' => 'CMP-A',
            'product_price' => 50000,
            'product_unit' => 1,
            'product_cost' => 30000,
            'serial_number_required' => false,
        ]);

        $sale = Sale::create([
            'date' => now(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 200000,
            'paid_amount' => 200000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $setting->id,
        ]);

        // Two identical parent lines
        $detail1 = $this->createSaleDetail([
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'product_name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 100000,
        ]);
        $detail2 = $this->createSaleDetail([
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'product_name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 100000,
        ]);

        $posTxn = $this->createPosTransaction([
            'transaction_number' => 'TXN-AMBIGUOUS',
            'code' => 'TXN-AMBIGUOUS',
            'total_amount' => 200000,
            'setting_id' => $setting->id,
        ]);
        // Two PTL bundle lines with identical product and qty
        $this->createPosTransactionLine([
            'pos_transaction_id' => $posTxn->id,
            'product_id' => $parent->id,
            'product_name' => $parent->product_name,
            'qty' => 1,
            'unit_price' => 100000,
            'subtotal' => 100000,
            'line_meta' => ['is_bundle' => true, 'bundle_id' => 101],
        ]);
        $this->createPosTransactionLine([
            'pos_transaction_id' => $posTxn->id,
            'product_id' => $parent->id,
            'product_name' => $parent->product_name,
            'qty' => 1,
            'unit_price' => 100000,
            'subtotal' => 100000,
            'line_meta' => ['is_bundle' => true, 'bundle_id' => 102],
        ]);

        $posCheckout = $this->createPosCheckout([
            'checkout_number' => 'CHK-AMBIGUOUS',
            'pos_transaction_id' => $posTxn->id,
            'total_amount' => 200000,
            'payment_status' => 'Paid',
            'status' => 'Completed',
        ]);
        $this->createPosCheckoutSale([
            'pos_checkout_id' => $posCheckout->id,
            'sale_id' => $sale->id,
            'setting_id' => $setting->id,
            'subtotal' => 200000,
        ]);

        $checkoutSales = PosCheckoutSale::with(['sale.saleDetails.bundleItems.product'])
            ->where('pos_checkout_id', $posCheckout->id)
            ->get();

        $result = $this->resolver->resolveOriginalBundleContext($detail1, $checkoutSales, $posTxn);

        $this->assertNotNull($result);
        $this->assertTrue($result['is_ambiguous']);
        $this->assertStringContainsString('ambigu', $result['error']);
    }

    /** @test */
    public function it_uses_captured_original_composition_when_catalog_bundle_is_edited_or_deleted(): void
    {
        $setting = $this->createSetting('Store Historical');
        $customer = Customer::create(['customer_name' => 'Customer Historical']);

        $category = Category::create(['category_code' => 'CAT4', 'category_name' => 'Electronics', 'created_by' => 1, 'setting_id' => $setting->id]);
        $parent = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => 'Captured Bundle Parent',
            'product_code' => 'CAP-P',
            'product_price' => 500000,
            'product_unit' => 1,
            'product_cost' => 300000,
            'serial_number_required' => false,
        ]);
        $compOriginal = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => 'Original Component',
            'product_code' => 'ORIG-C',
            'product_price' => 150000,
            'product_unit' => 1,
            'product_cost' => 80000,
            'serial_number_required' => false,
        ]);
        $compNew = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => 'New Changed Component',
            'product_code' => 'NEW-C',
            'product_price' => 200000,
            'product_unit' => 1,
            'product_cost' => 100000,
            'serial_number_required' => false,
        ]);

        // Catalog bundle was changed after checkout to use $compNew!
        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'name' => 'Catalog Bundle Modified',
            'setting_id' => $setting->id,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $compNew->id, // Catalog now has NEW component
            'quantity' => 1,
        ]);

        $sale = Sale::create([
            'date' => now(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 500000,
            'paid_amount' => 500000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $setting->id,
        ]);

        $detail = $this->createSaleDetail([
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'product_name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'quantity' => 1,
            'price' => 500000,
            'unit_price' => 500000,
            'sub_total' => 500000,
        ]);

        $sbi = SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => 999,
            'product_id' => $compOriginal->id,
            'name' => $compOriginal->product_name,
            'quantity' => 2,
            'price' => 150000,
            'sub_total' => 150000,
        ]);

        $posTxn = $this->createPosTransaction([
            'transaction_number' => 'TXN-CAPTURED',
            'code' => 'TXN-CAPTURED',
            'total_amount' => 500000,
            'setting_id' => $setting->id,
        ]);
        // Captured composition at checkout in PTL line_meta
        $this->createPosTransactionLine([
            'pos_transaction_id' => $posTxn->id,
            'product_id' => $parent->id,
            'product_name' => $parent->product_name,
            'qty' => 1,
            'unit_price' => 500000,
            'subtotal' => 500000,
            'line_meta' => [
                'is_bundle' => true,
                'bundle_id' => $bundle->id,
                'bundle_name' => 'Original Sale Bundle',
                'bundle_items' => [
                    [
                        'product_id' => $compOriginal->id,
                        'name' => $compOriginal->product_name,
                        'quantity' => 2,
                    ],
                ],
            ],
        ]);

        $posCheckout = $this->createPosCheckout([
            'checkout_number' => 'CHK-CAPTURED',
            'pos_transaction_id' => $posTxn->id,
            'total_amount' => 500000,
            'payment_status' => 'Paid',
            'status' => 'Completed',
        ]);
        $this->createPosCheckoutSale([
            'pos_checkout_id' => $posCheckout->id,
            'sale_id' => $sale->id,
            'setting_id' => $setting->id,
            'subtotal' => 500000,
        ]);

        $checkoutSales = PosCheckoutSale::with(['sale.saleDetails.bundleItems.product'])
            ->where('pos_checkout_id', $posCheckout->id)
            ->get();

        $result = $this->resolver->resolveOriginalBundleContext($detail, $checkoutSales, $posTxn);

        $this->assertNotNull($result);
        $this->assertTrue($result['is_bundle']);
        $this->assertCount(1, $result['components']);

        // Must match captured component ($compOriginal, qty 2), NOT the altered catalog ($compNew)
        $this->assertEquals($compOriginal->id, $result['components'][0]['product_id']);
        $this->assertEquals(2.0, (float) $result['components'][0]['quantity_per_bundle']);
    }

    /** @test */
    public function it_resolves_null_for_ordinary_line_when_ordinary_and_bundle_coexist_for_same_product(): void
    {
        $setting = $this->createSetting('Ordinary Coexist Test');
        $customer = Customer::create(['customer_name' => 'Coexist Customer', 'customer_phone' => '08123456789']);
        $category = Category::create(['category_code' => 'COEX', 'category_name' => 'Electronics', 'created_by' => 1, 'setting_id' => $setting->id]);

        $cameraProduct = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => 'Camera Alpha',
            'product_code' => 'CAM-ALPHA',
            'product_price' => 630000,
            'product_unit' => 1,
            'product_cost' => 400000,
            'serial_number_required' => false,
        ]);

        $sdProduct = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => 'SD Card 64GB',
            'product_code' => 'SD-64',
            'product_price' => 260000,
            'product_unit' => 1,
            'product_cost' => 150000,
            'serial_number_required' => false,
        ]);

        $bundle = ProductBundle::create([
            'parent_product_id' => $cameraProduct->id,
            'name' => 'Camera Alpha Bundle with SD',
            'setting_id' => $setting->id,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $sdProduct->id,
            'quantity' => 1,
        ]);

        $posTxn = $this->createPosTransaction([
            'code' => 'TXN-COEXIST',
            'setting_id' => $setting->id,
        ]);

        // PTL 1: Ordinary camera line (not a bundle)
        $ptlOrdinary = $this->createPosTransactionLine([
            'pos_transaction_id' => $posTxn->id,
            'product_id' => $cameraProduct->id,
            'product_name' => $cameraProduct->product_name,
            'qty' => 1,
            'unit_price' => 630000,
            'subtotal' => 630000,
            'line_meta' => [
                'is_bundle' => false,
            ],
        ]);

        // PTL 2: Bundle camera line (with SD component)
        $ptlBundle = $this->createPosTransactionLine([
            'pos_transaction_id' => $posTxn->id,
            'product_id' => $cameraProduct->id,
            'product_name' => $cameraProduct->product_name,
            'qty' => 1,
            'unit_price' => 890000,
            'subtotal' => 890000,
            'line_meta' => [
                'is_bundle' => true,
                'bundle_id' => $bundle->id,
                'bundle_items' => [
                    [
                        'product_id' => $sdProduct->id,
                        'name' => $sdProduct->product_name,
                        'quantity' => 1,
                    ],
                ],
            ],
        ]);

        // Sale 1: contains ordinary camera detail AND bundle parent camera detail
        $sale1 = Sale::create([
            'date' => now(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1260000,
            'paid_amount' => 1260000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $setting->id,
        ]);

        $ordinaryDetail = $this->createSaleDetail([
            'sale_id' => $sale1->id,
            'product_id' => $cameraProduct->id,
            'product_name' => $cameraProduct->product_name,
            'product_code' => $cameraProduct->product_code,
            'quantity' => 1,
            'price' => 630000,
            'unit_price' => 630000,
            'sub_total' => 630000,
            'pos_transaction_line_id' => $ptlOrdinary->id,
        ]);

        $bundleParentDetail = $this->createSaleDetail([
            'sale_id' => $sale1->id,
            'product_id' => $cameraProduct->id,
            'product_name' => $cameraProduct->product_name,
            'product_code' => $cameraProduct->product_code,
            'quantity' => 1,
            'price' => 630000,
            'unit_price' => 630000,
            'sub_total' => 630000,
            'pos_transaction_line_id' => $ptlBundle->id,
        ]);

        // Sale 2: carrier row for bundle SD component
        $sale2 = Sale::create([
            'date' => now(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 260000,
            'paid_amount' => 260000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $setting->id,
        ]);

        $carrierDetail = $this->createSaleDetail([
            'sale_id' => $sale2->id,
            'product_id' => $cameraProduct->id,
            'product_name' => $cameraProduct->product_name,
            'product_code' => $cameraProduct->product_code,
            'quantity' => 0,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale2->id,
            'sale_detail_id' => $carrierDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => 1,
            'product_id' => $sdProduct->id,
            'name' => $sdProduct->product_name,
            'quantity' => 1,
            'price' => 260000,
            'sub_total' => 260000,
        ]);

        $posCheckout = $this->createPosCheckout([
            'pos_transaction_id' => $posTxn->id,
            'setting_id' => $setting->id,
            'status' => 'Completed',
        ]);

        $this->createPosCheckoutSale([
            'pos_checkout_id' => $posCheckout->id,
            'sale_id' => $sale1->id,
            'setting_id' => $setting->id,
        ]);
        $this->createPosCheckoutSale([
            'pos_checkout_id' => $posCheckout->id,
            'sale_id' => $sale2->id,
            'setting_id' => $setting->id,
        ]);

        // 1. Ordinary line MUST resolve to null (it must NOT inherit the bundle from the other line!)
        $ordinaryResolved = $this->resolver->resolveOriginalBundleContext($ordinaryDetail, null, $posTxn);
        $this->assertNull($ordinaryResolved, 'Ordinary product line must never inherit another line bundle context.');

        // 2. Bundle parent line MUST resolve to the bundle with its component
        $bundleResolved = $this->resolver->resolveOriginalBundleContext($bundleParentDetail, null, $posTxn);
        $this->assertNotNull($bundleResolved, 'Bundle parent line must correctly resolve bundle context.');
        $this->assertTrue($bundleResolved['is_bundle']);
        $this->assertCount(1, $bundleResolved['components']);
        $this->assertEquals($sdProduct->id, $bundleResolved['components'][0]['product_id']);
    }

    /** @test */
    public function it_resolves_bundle_parent_when_same_checkout_has_ordinary_and_bundle_lines_with_null_pos_transaction_line_id(): void
    {
        $setting = $this->createSetting('Null Line ID Test Store');
        $customer = Customer::create(['customer_name' => 'Customer Test', 'customer_phone' => '08123456789']);
        $category = Category::create(['category_code' => 'CAT-NULL', 'category_name' => 'Electronics', 'created_by' => 1, 'setting_id' => $setting->id]);

        $cameraProduct = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => 'Sony Alpha A7 IV Camera',
            'product_code' => 'SKU-CAM-' . uniqid(),
            'product_price' => 630000,
            'product_unit' => 1,
            'product_cost' => 400000,
            'serial_number_required' => false,
        ]);
        $sdProduct = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => 'SanDisk 64GB MicroSD',
            'product_code' => 'SKU-SD-' . uniqid(),
            'product_price' => 260000,
            'product_unit' => 1,
            'product_cost' => 100000,
            'serial_number_required' => false,
        ]);

        $bundle = ProductBundle::create([
            'parent_product_id' => $cameraProduct->id,
            'name' => 'Camera Kit Bundle',
            'setting_id' => $setting->id,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $sdProduct->id,
            'quantity' => 1,
        ]);

        $posTxn = $this->createPosTransaction(['setting_id' => $setting->id]);

        // PTL 1: Ordinary camera line (line_no = 1)
        $ptlOrdinary = $this->createPosTransactionLine([
            'pos_transaction_id' => $posTxn->id,
            'product_id' => $cameraProduct->id,
            'product_name' => $cameraProduct->product_name,
            'qty' => 1,
            'unit_price' => 630000,
            'subtotal' => 630000,
            'line_no' => 1,
        ]);

        // PTL 2: Bundle camera line (line_no = 2)
        $ptlBundle = $this->createPosTransactionLine([
            'pos_transaction_id' => $posTxn->id,
            'product_id' => $cameraProduct->id,
            'product_name' => $cameraProduct->product_name,
            'qty' => 1,
            'unit_price' => 890000,
            'subtotal' => 890000,
            'line_no' => 2,
            'line_meta' => [
                'is_bundle' => true,
                'bundle_id' => $bundle->id,
                'bundle_items' => [
                    [
                        'product_id' => $sdProduct->id,
                        'name' => $sdProduct->product_name,
                        'quantity' => 1,
                    ],
                ],
            ],
        ]);

        // Sale 1: contains ordinary camera detail (id lower) AND bundle parent camera detail (id higher)
        // CRITICAL: pos_transaction_line_id is NULL on both details (real checkout posting behavior)
        $sale1 = Sale::create([
            'date' => now(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 1260000,
            'paid_amount' => 1260000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $setting->id,
        ]);

        $ordinaryDetail = $this->createSaleDetail([
            'sale_id' => $sale1->id,
            'product_id' => $cameraProduct->id,
            'product_name' => $cameraProduct->product_name,
            'product_code' => $cameraProduct->product_code,
            'quantity' => 1,
            'price' => 630000,
            'unit_price' => 630000,
            'sub_total' => 630000,
            'pos_transaction_line_id' => null, // Empty link as in real checkout posting!
        ]);

        $bundleParentDetail = $this->createSaleDetail([
            'sale_id' => $sale1->id,
            'product_id' => $cameraProduct->id,
            'product_name' => $cameraProduct->product_name,
            'product_code' => $cameraProduct->product_code,
            'quantity' => 1,
            'price' => 630000,
            'unit_price' => 630000,
            'sub_total' => 630000,
            'pos_transaction_line_id' => null, // Empty link as in real checkout posting!
        ]);

        // Sale 2: carrier row for bundle SD component
        $sale2 = Sale::create([
            'date' => now(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 260000,
            'paid_amount' => 260000,
            'due_amount' => 0,
            'status' => 'Completed',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $setting->id,
        ]);

        $carrierDetail = $this->createSaleDetail([
            'sale_id' => $sale2->id,
            'product_id' => $cameraProduct->id,
            'product_name' => $cameraProduct->product_name,
            'product_code' => $cameraProduct->product_code,
            'quantity' => 0,
            'price' => 0,
            'unit_price' => 0,
            'sub_total' => 0,
            'pos_transaction_line_id' => null,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale2->id,
            'sale_detail_id' => $carrierDetail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => 1,
            'product_id' => $sdProduct->id,
            'name' => $sdProduct->product_name,
            'quantity' => 1,
            'price' => 260000,
            'sub_total' => 260000,
        ]);

        $posCheckout = $this->createPosCheckout([
            'pos_transaction_id' => $posTxn->id,
            'setting_id' => $setting->id,
            'status' => 'Completed',
        ]);

        $this->createPosCheckoutSale([
            'pos_checkout_id' => $posCheckout->id,
            'sale_id' => $sale1->id,
            'setting_id' => $setting->id,
        ]);
        $this->createPosCheckoutSale([
            'pos_checkout_id' => $posCheckout->id,
            'sale_id' => $sale2->id,
            'setting_id' => $setting->id,
        ]);

        // 1. Ordinary line must resolve to null (not bundle)
        $ordinaryResolved = $this->resolver->resolveOriginalBundleContext($ordinaryDetail, null, $posTxn);
        $this->assertNull($ordinaryResolved, 'Ordinary product line must resolve to null.');

        // 2. Bundle parent line must resolve to bundle with its component despite null pos_transaction_line_id
        $bundleResolved = $this->resolver->resolveOriginalBundleContext($bundleParentDetail, null, $posTxn);
        $this->assertNotNull($bundleResolved, 'Bundle parent line must correctly resolve bundle context with null pos_transaction_line_id.');
        $this->assertTrue($bundleResolved['is_bundle']);
        $this->assertCount(1, $bundleResolved['components']);
        $this->assertEquals($sdProduct->id, $bundleResolved['components'][0]['product_id']);
        $this->assertEquals($bundle->id, $bundleResolved['bundle_id']);
    }

    private function createSaleDetail(array $attributes): SaleDetails
    {
        return SaleDetails::create(array_merge([
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ], $attributes));
    }

    private function createPosTransaction(array $attributes): PosTransaction
    {
        static $txSeq = 1;
        $num = $txSeq++;
        return PosTransaction::create(array_merge([
            'setting_id' => $this->setting->id,
            'transaction_number' => "TXN-{$num}",
            'code' => "TXN-{$num}",
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
        ], $attributes));
    }

    private function createPosTransactionLine(array $attributes): PosTransactionLine
    {
        static $ln = 1;
        $name = $attributes['product_name'] ?? 'Product Line';
        unset($attributes['product_name']);
        return PosTransactionLine::create(array_merge([
            'line_no' => $ln++,
            'product_name_snapshot' => $name,
            'product_code_snapshot' => 'SKU',
        ], $attributes));
    }

    private function createPosCheckout(array $attributes): PosCheckout
    {
        static $chkSeq = 1;
        $num = $chkSeq++;
        return PosCheckout::create(array_merge([
            'setting_id' => $this->setting->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'idempotency_key' => "IDEM-{$num}",
            'payload_hash' => hash('sha256', "payload-{$num}"),
            'payment_method_code' => 'CASH',
            'status' => 'Completed',
        ], $attributes));
    }

    private function createPosCheckoutSale(array $attributes): PosCheckoutSale
    {
        static $csSeq = 1;
        $num = $csSeq++;
        $settingId = $attributes['source_setting_id'] ?? $attributes['setting_id'] ?? $this->setting->id;
        unset($attributes['setting_id']);
        return PosCheckoutSale::create(array_merge([
            'split_key' => "SPLIT-KEY-{$num}",
            'source_setting_id' => $settingId,
            'source_location_id' => $this->location->id,
            'tax_bucket' => 'NONE',
            'subtotal' => 0,
        ], $attributes));
    }

    /**
     * Builds a checkout selling the same camera product both standalone and
     * as a bundle parent with no persisted pos_transaction_line_id links.
     */
    private function createSameProductOrdinaryAndBundleCheckout(bool $bundleFirst, bool $separateSales, bool $tagBundleDispatch = false, ?bool $bundleDetailCreatedFirst = null): array
    {
        $setting = $this->createSetting('Same Product Order ' . uniqid());
        $customer = Customer::create(['customer_name' => 'Same Product Customer', 'customer_phone' => '0811']);
        $category = Category::create(['category_code' => 'CAT-SP' . uniqid(), 'category_name' => 'Electronics', 'created_by' => 1, 'setting_id' => $setting->id]);
        $cam = Product::create(['setting_id' => $setting->id, 'category_id' => $category->id, 'product_name' => 'Camera', 'product_code' => 'CAM-' . uniqid(), 'product_price' => 630000, 'product_unit' => 1, 'product_cost' => 1, 'serial_number_required' => false]);
        $sd = Product::create(['setting_id' => $setting->id, 'category_id' => $category->id, 'product_name' => 'MicroSD', 'product_code' => 'SD-' . uniqid(), 'product_price' => 260000, 'product_unit' => 1, 'product_cost' => 1, 'serial_number_required' => false]);
        $bundle = ProductBundle::create(['parent_product_id' => $cam->id, 'name' => 'Camera Kit', 'setting_id' => $setting->id]);
        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $sd->id, 'quantity' => 1]);

        $txn = $this->createPosTransaction(['setting_id' => $setting->id]);
        $bundleMeta = ['is_bundle' => true, 'bundle_id' => $bundle->id, 'bundle_items' => [['product_id' => $sd->id, 'name' => 'MicroSD', 'quantity' => 1]]];
        $this->createPosTransactionLine(['pos_transaction_id' => $txn->id, 'product_id' => $cam->id, 'product_name' => 'Camera', 'qty' => 1, 'unit_price' => $bundleFirst ? 890000 : 630000, 'subtotal' => $bundleFirst ? 890000 : 630000, 'line_no' => 1, 'line_meta' => $bundleFirst ? $bundleMeta : null]);
        $this->createPosTransactionLine(['pos_transaction_id' => $txn->id, 'product_id' => $cam->id, 'product_name' => 'Camera', 'qty' => 1, 'unit_price' => $bundleFirst ? 630000 : 890000, 'subtotal' => $bundleFirst ? 630000 : 890000, 'line_no' => 2, 'line_meta' => $bundleFirst ? null : $bundleMeta]);

        $makeSale = fn () => Sale::create(['date' => now(), 'customer_id' => $customer->id, 'customer_name' => $customer->customer_name, 'total_amount' => 0, 'paid_amount' => 0, 'due_amount' => 0, 'status' => 'Completed', 'payment_status' => 'Paid', 'payment_method' => 'Cash', 'setting_id' => $setting->id]);
        $saleA = $makeSale();
        $saleB = $separateSales ? $makeSale() : $saleA;
        $makeDetail = fn ($sale) => $this->createSaleDetail(['sale_id' => $sale->id, 'product_id' => $cam->id, 'product_name' => 'Camera', 'product_code' => $cam->product_code, 'quantity' => 1, 'price' => 630000, 'unit_price' => 630000, 'sub_total' => 630000, 'pos_transaction_line_id' => null]);
        // Sale-row creation order can differ from POS line order across owner Sales.
        $bundleDetailCreatedFirst ??= $bundleFirst;
        if ($bundleDetailCreatedFirst === $bundleFirst) {
            $firstDetail = $makeDetail($saleA);
            $secondDetail = $makeDetail($saleB);
        } else {
            $secondDetail = $makeDetail($saleB);
            $firstDetail = $makeDetail($saleA);
        }

        $saleC = $makeSale();
        $carrier = $this->createSaleDetail(['sale_id' => $saleC->id, 'product_id' => $cam->id, 'product_name' => 'Camera', 'product_code' => $cam->product_code, 'quantity' => 0, 'price' => 0, 'unit_price' => 0, 'sub_total' => 0, 'pos_transaction_line_id' => null]);
        SaleBundleItem::create(['sale_id' => $saleC->id, 'sale_detail_id' => $carrier->id, 'bundle_id' => $bundle->id, 'bundle_item_id' => 1, 'product_id' => $sd->id, 'name' => 'MicroSD', 'quantity' => 1, 'price' => 260000, 'sub_total' => 260000]);

        $bundleDetail = $bundleFirst ? $firstDetail : $secondDetail;
        if ($tagBundleDispatch) {
            $dispatch = Dispatch::create(['sale_id' => $bundleDetail->sale_id, 'status' => 'APPROVED']);
            DispatchDetail::create([
                'dispatch_id' => $dispatch->id,
                'sale_id' => $bundleDetail->sale_id,
                'sale_detail_id' => $bundleDetail->id,
                'product_id' => $cam->id,
                'bundle_id' => $bundle->id,
                'dispatched_quantity' => 1,
                'serial_numbers' => json_encode([]),
            ]);
        }

        $checkout = $this->createPosCheckout(['pos_transaction_id' => $txn->id, 'setting_id' => $setting->id, 'status' => 'Completed']);
        foreach (array_unique([$saleA->id, $saleB->id, $saleC->id]) as $saleId) {
            $this->createPosCheckoutSale(['pos_checkout_id' => $checkout->id, 'sale_id' => $saleId, 'setting_id' => $setting->id]);
        }

        return [
            'bundle_detail' => $bundleFirst ? $firstDetail : $secondDetail,
            'ordinary_detail' => $bundleFirst ? $secondDetail : $firstDetail,
            'transaction' => $txn,
            'component_product' => $sd,
        ];
    }

    /** @test */
    public function it_resolves_bundle_parent_when_bundle_line_precedes_ordinary_line_of_same_product(): void
    {
        $fixture = $this->createSameProductOrdinaryAndBundleCheckout(true, false);

        $this->assertNull($this->resolver->resolveOriginalBundleContext($fixture['ordinary_detail'], null, $fixture['transaction']));

        $ctx = $this->resolver->resolveOriginalBundleContext($fixture['bundle_detail'], null, $fixture['transaction']);
        $this->assertNotNull($ctx);
        $this->assertFalse($ctx['is_ambiguous'] ?? false, json_encode($ctx));
        $this->assertSame($fixture['component_product']->id, (int) $ctx['components'][0]['product_id']);
    }

    /** @test */
    public function it_resolves_bundle_parent_from_bundle_dispatch_when_sale_row_order_differs_from_pos_line_order(): void
    {
        // Bundle is POS line 1, but its sale row is created after the ordinary row in another owner Sale.
        $fixture = $this->createSameProductOrdinaryAndBundleCheckout(true, true, true, false);
        $this->assertGreaterThan($fixture['ordinary_detail']->id, $fixture['bundle_detail']->id);

        $this->assertNull($this->resolver->resolveOriginalBundleContext($fixture['ordinary_detail'], null, $fixture['transaction']));

        $ctx = $this->resolver->resolveOriginalBundleContext($fixture['bundle_detail'], null, $fixture['transaction']);
        $this->assertNotNull($ctx);
        $this->assertFalse($ctx['is_ambiguous'] ?? false, json_encode($ctx));
        $this->assertSame($fixture['component_product']->id, (int) $ctx['components'][0]['product_id']);
    }

    /** @test */
    public function it_flags_ambiguity_when_same_product_ordinary_and_bundle_rows_span_sales_without_row_evidence(): void
    {
        $fixture = $this->createSameProductOrdinaryAndBundleCheckout(false, true);

        foreach (['ordinary_detail', 'bundle_detail'] as $key) {
            $ctx = $this->resolver->resolveOriginalBundleContext($fixture[$key], null, $fixture['transaction']);
            $this->assertNotNull($ctx, $key);
            $this->assertTrue($ctx['is_ambiguous'] ?? false, $key . ': ' . json_encode($ctx));
        }
    }

    /** @test */
    public function it_resolves_bundle_parent_when_ordinary_and_bundle_lines_of_same_product_are_in_separate_sales(): void
    {
        $fixture = $this->createSameProductOrdinaryAndBundleCheckout(false, true, true);

        $this->assertNull($this->resolver->resolveOriginalBundleContext($fixture['ordinary_detail'], null, $fixture['transaction']));

        $ctx = $this->resolver->resolveOriginalBundleContext($fixture['bundle_detail'], null, $fixture['transaction']);
        $this->assertNotNull($ctx);
        $this->assertFalse($ctx['is_ambiguous'] ?? false, json_encode($ctx));
        $this->assertSame($fixture['component_product']->id, (int) $ctx['components'][0]['product_id']);
    }
}
