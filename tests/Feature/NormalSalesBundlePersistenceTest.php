<?php

namespace Tests\Feature;

use App\Livewire\Sale\CreateForm;
use App\Livewire\Sale\EditForm;
use App\Livewire\Sale\ProductCart;
use App\Models\User;
use Exception;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Services\SaleService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class NormalSalesBundlePersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected User $user;
    protected Customer $customer;
    protected PaymentTerm $paymentTerm;
    protected Category $category;
    protected Unit $unit;
    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Persist Co',
            'company_email' => 'persist@example.com',
            'company_phone' => '081234567890',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'company_address' => 'Persist Lane 1',
            'footer_text' => 'Footer',
            'is_pkp' => false,
        ]);

        Session::put('setting_id', $this->setting->id);
        Session::put('user_settings', collect([$this->setting]));

        $this->category = Category::create([
            'category_code' => 'CAT01',
            'category_name' => 'General',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $this->unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $this->setting->id,
        ]);

        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Main Warehouse',
        ]);

        $this->paymentTerm = PaymentTerm::create(['name' => 'Cash', 'longevity' => 0]);

        $this->customer = Customer::create([
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'customer_phone' => '08123456789',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Addr',
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        Cart::instance('sale')->destroy();
    }

    protected function createTestProduct(string $name, string $code, float $price = 100000, float $cost = 50000, int $qty = 10, bool $stockManaged = true): Product
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'product_name' => $name,
            'product_code' => $code,
            'product_quantity' => $qty,
            'product_cost' => $cost,
            'product_price' => $price,
            'sale_price' => $price,
            'product_unit' => 'PCS',
            'stock_managed' => $stockManaged,
            'is_sold' => true,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => $price,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => $qty,
            'quantity_non_tax' => $qty,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        return $product;
    }

    /**
     * 1.1 Add a normal Sales persistence test proving the same parent product with two different bundles
     * creates distinct parent details and correctly linked component rows.
     */
    public function test_1_1_same_parent_product_with_two_different_bundles_creates_distinct_details_and_links(): void
    {
        $parent = $this->createTestProduct('Laptop Model X', 'LAP-X', 10000000);
        $compA = $this->createTestProduct('Mouse Wireless', 'MOU-01', 200000);
        $compB = $this->createTestProduct('Backpack Pro', 'BAG-01', 500000);

        $bundle1 = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parent->id,
            'name' => 'Work Pack',
            'bundle_sale_price' => 10100000,
            'is_active' => true,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle1->id,
            'product_id' => $compA->id,
            'quantity' => 1,
            'informational_item_price' => 100000,
        ]);

        $bundle2 = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parent->id,
            'name' => 'Travel Pack',
            'bundle_sale_price' => 10400000,
            'is_active' => true,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle2->id,
            'product_id' => $compB->id,
            'quantity' => 2,
            'informational_item_price' => 250000,
        ]);

        $cart = Cart::instance('sale');
        $cart->destroy();

        // Row 1: Laptop with Bundle 1 (qty = 1)
        $cart->add([
            'id' => Str::uuid()->toString(),
            'name' => $parent->product_name,
            'qty' => 1,
            'price' => 10100000,
            'weight' => 1,
            'options' => [
                'product_id' => $parent->id,
                'code' => $parent->product_code,
                'unit_price' => 10100000,
                'sub_total' => 10100000,
                'sub_total_before_tax' => 10100000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'product_tax' => null,
                'is_bundled_row' => true,
                'bundle_id' => $bundle1->id,
                'bundle_name' => $bundle1->name,
                'bundle_price' => 0,
                'bundle_items' => [
                    [
                        'bundle_id' => $bundle1->id,
                        'bundle_item_id' => $bundle1->items->first()->id,
                        'product_id' => $compA->id,
                        'name' => $compA->product_name,
                        'price' => 0.0,
                        'quantity' => 1,
                        'quantity_per_bundle' => 1,
                        'sub_total' => 0.0,
                        'informational_item_price' => 100000,
                    ]
                ],
            ]
        ]);

        // Row 2: Same Laptop with Bundle 2 (qty = 2)
        $cart->add([
            'id' => Str::uuid()->toString(),
            'name' => $parent->product_name,
            'qty' => 2,
            'price' => 10400000,
            'weight' => 1,
            'options' => [
                'product_id' => $parent->id,
                'code' => $parent->product_code,
                'unit_price' => 10400000,
                'sub_total' => 20800000,
                'sub_total_before_tax' => 20800000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'product_tax' => null,
                'is_bundled_row' => true,
                'bundle_id' => $bundle2->id,
                'bundle_name' => $bundle2->name,
                'bundle_price' => 0,
                'bundle_items' => [
                    [
                        'bundle_id' => $bundle2->id,
                        'bundle_item_id' => $bundle2->items->first()->id,
                        'product_id' => $compB->id,
                        'name' => $compB->product_name,
                        'price' => 0.0,
                        'quantity' => 4, // 2 per bundle * 2 parents
                        'quantity_per_bundle' => 2,
                        'sub_total' => 0.0,
                        'informational_item_price' => 250000,
                    ]
                ],
            ]
        ]);

        $saleService = app(SaleService::class);
        $sale = $saleService->createSale([
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'payment_term_id' => $this->paymentTerm->id,
            'setting_id' => $this->setting->id,
        ], $cart->content());

        $sale->load(['saleDetails.bundleItems']);
        $this->assertCount(2, $sale->saleDetails, 'Must persist 2 distinct parent details for different bundles');

        $detail1 = $sale->saleDetails->firstWhere('price', 10100000);
        $detail2 = $sale->saleDetails->firstWhere('price', 10400000);

        $this->assertNotNull($detail1);
        $this->assertNotNull($detail2);
        $this->assertNotEquals($detail1->id, $detail2->id);

        $this->assertCount(1, $detail1->bundleItems);
        $this->assertEquals($compA->id, $detail1->bundleItems->first()->product_id);
        $this->assertEquals(1, $detail1->bundleItems->first()->quantity);
        $this->assertEquals($detail1->id, $detail1->bundleItems->first()->sale_detail_id);
        $this->assertEquals($sale->id, $detail1->bundleItems->first()->sale_id);

        $this->assertCount(1, $detail2->bundleItems);
        $this->assertEquals($compB->id, $detail2->bundleItems->first()->product_id);
        $this->assertEquals(4, $detail2->bundleItems->first()->quantity);
        $this->assertEquals($detail2->id, $detail2->bundleItems->first()->sale_detail_id);
        $this->assertEquals($sale->id, $detail2->bundleItems->first()->sale_id);
    }

    /**
     * 1.2 Add a persistence test proving bundled and ordinary instances of the same product
     * remain distinct and only the bundled parent owns component rows.
     */
    public function test_1_2_bundled_and_ordinary_instances_of_same_product_remain_distinct(): void
    {
        $product = $this->createTestProduct('Smartphone Pro', 'PH-01', 5000000);
        $case = $this->createTestProduct('Armor Case', 'CAS-01', 150000);

        $bundle = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $product->id,
            'name' => 'Protective Bundle',
            'bundle_sale_price' => 5100000,
            'is_active' => true,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $case->id,
            'quantity' => 1,
        ]);

        $cart = Cart::instance('sale');
        $cart->destroy();

        // Row 1: Ordinary product (no bundle)
        $cart->add([
            'id' => Str::uuid()->toString(),
            'name' => $product->product_name,
            'qty' => 1,
            'price' => 5000000,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'code' => $product->product_code,
                'unit_price' => 5000000,
                'sub_total' => 5000000,
                'sub_total_before_tax' => 5000000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'product_tax' => null,
                'bundle_items' => [],
            ]
        ]);

        // Row 2: Bundled product
        $cart->add([
            'id' => Str::uuid()->toString(),
            'name' => $product->product_name,
            'qty' => 1,
            'price' => 5100000,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'code' => $product->product_code,
                'unit_price' => 5100000,
                'sub_total' => 5100000,
                'sub_total_before_tax' => 5100000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'product_tax' => null,
                'is_bundled_row' => true,
                'bundle_id' => $bundle->id,
                'bundle_name' => $bundle->name,
                'bundle_price' => 0,
                'bundle_items' => [
                    [
                        'bundle_id' => $bundle->id,
                        'bundle_item_id' => $bundle->items->first()->id,
                        'product_id' => $case->id,
                        'name' => $case->product_name,
                        'price' => 0.0,
                        'quantity' => 1,
                        'quantity_per_bundle' => 1,
                        'sub_total' => 0.0,
                    ]
                ],
            ]
        ]);

        $saleService = app(SaleService::class);
        $sale = $saleService->createSale([
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'payment_term_id' => $this->paymentTerm->id,
            'setting_id' => $this->setting->id,
        ], $cart->content());

        $sale->load(['saleDetails.bundleItems']);
        $this->assertCount(2, $sale->saleDetails, 'Must persist separate parent details for ordinary and bundled rows');

        $ordinaryDetail = $sale->saleDetails->firstWhere('price', 5000000);
        $bundledDetail = $sale->saleDetails->firstWhere('price', 5100000);

        $this->assertNotNull($ordinaryDetail);
        $this->assertNotNull($bundledDetail);

        $this->assertCount(0, $ordinaryDetail->bundleItems, 'Ordinary detail must not own bundle items');
        $this->assertCount(1, $bundledDetail->bundleItems, 'Bundled detail must own the component row');
        $this->assertEquals($case->id, $bundledDetail->bundleItems->first()->product_id);
    }

    /**
     * 1.3 Add a persistence test proving a shared component product under multiple bundle rows
     * produces separate parent-linked component records.
     */
    public function test_1_3_shared_component_product_under_multiple_bundles_creates_separate_records(): void
    {
        $parentA = $this->createTestProduct('Product Alpha', 'ALP-01', 100000);
        $parentB = $this->createTestProduct('Product Beta', 'BET-01', 200000);
        $sharedComp = $this->createTestProduct('Shared USB Cable', 'USB-01', 30000);

        $bundleA = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parentA->id,
            'name' => 'Alpha Bundle',
            'bundle_sale_price' => 110000,
            'is_active' => true,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundleA->id,
            'product_id' => $sharedComp->id,
            'quantity' => 1,
        ]);

        $bundleB = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parentB->id,
            'name' => 'Beta Bundle',
            'bundle_sale_price' => 220000,
            'is_active' => true,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundleB->id,
            'product_id' => $sharedComp->id,
            'quantity' => 3,
        ]);

        $cart = Cart::instance('sale');
        $cart->destroy();

        $cart->add([
            'id' => Str::uuid()->toString(),
            'name' => $parentA->product_name,
            'qty' => 1,
            'price' => 110000,
            'weight' => 1,
            'options' => [
                'product_id' => $parentA->id,
                'code' => $parentA->product_code,
                'unit_price' => 110000,
                'sub_total' => 110000,
                'sub_total_before_tax' => 110000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'product_tax' => null,
                'is_bundled_row' => true,
                'bundle_id' => $bundleA->id,
                'bundle_items' => [
                    [
                        'bundle_id' => $bundleA->id,
                        'bundle_item_id' => $bundleA->items->first()->id,
                        'product_id' => $sharedComp->id,
                        'name' => $sharedComp->product_name,
                        'price' => 0.0,
                        'quantity' => 1,
                        'quantity_per_bundle' => 1,
                        'sub_total' => 0.0,
                    ]
                ],
            ]
        ]);

        $cart->add([
            'id' => Str::uuid()->toString(),
            'name' => $parentB->product_name,
            'qty' => 2,
            'price' => 220000,
            'weight' => 1,
            'options' => [
                'product_id' => $parentB->id,
                'code' => $parentB->product_code,
                'unit_price' => 220000,
                'sub_total' => 440000,
                'sub_total_before_tax' => 440000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'product_tax' => null,
                'is_bundled_row' => true,
                'bundle_id' => $bundleB->id,
                'bundle_items' => [
                    [
                        'bundle_id' => $bundleB->id,
                        'bundle_item_id' => $bundleB->items->first()->id,
                        'product_id' => $sharedComp->id,
                        'name' => $sharedComp->product_name,
                        'price' => 0.0,
                        'quantity' => 6, // 3 * 2
                        'quantity_per_bundle' => 3,
                        'sub_total' => 0.0,
                    ]
                ],
            ]
        ]);

        $saleService = app(SaleService::class);
        $sale = $saleService->createSale([
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'payment_term_id' => $this->paymentTerm->id,
            'setting_id' => $this->setting->id,
        ], $cart->content());

        $sale->load(['saleDetails.bundleItems']);
        $this->assertCount(2, $sale->saleDetails);

        $detailA = $sale->saleDetails->firstWhere('product_id', $parentA->id);
        $detailB = $sale->saleDetails->firstWhere('product_id', $parentB->id);

        $this->assertCount(1, $detailA->bundleItems);
        $this->assertEquals($sharedComp->id, $detailA->bundleItems->first()->product_id);
        $this->assertEquals(1, $detailA->bundleItems->first()->quantity);
        $this->assertEquals($detailA->id, $detailA->bundleItems->first()->sale_detail_id);

        $this->assertCount(1, $detailB->bundleItems);
        $this->assertEquals($sharedComp->id, $detailB->bundleItems->first()->product_id);
        $this->assertEquals(6, $detailB->bundleItems->first()->quantity);
        $this->assertEquals($detailB->id, $detailB->bundleItems->first()->sale_detail_id);

        // Verify they are separate rows in DB
        $allBundleItems = SaleBundleItem::where('sale_id', $sale->id)->get();
        $this->assertCount(2, $allBundleItems);
        $this->assertNotEquals($allBundleItems[0]->id, $allBundleItems[1]->id);
    }

    /**
     * 1.4 Add a cart/update test proving removal of one bundled row leaves the other row
     * and its captured components unchanged.
     */
    public function test_1_4_removal_of_one_bundled_row_leaves_other_row_and_components_unchanged(): void
    {
        $parent1 = $this->createTestProduct('Device 1', 'DEV-1', 100000);
        $parent2 = $this->createTestProduct('Device 2', 'DEV-2', 200000);
        $comp1 = $this->createTestProduct('Comp 1', 'CMP-1', 10000);
        $comp2 = $this->createTestProduct('Comp 2', 'CMP-2', 20000);

        $bundle1 = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parent1->id,
            'name' => 'Bundle 1',
            'bundle_sale_price' => 105000,
            'is_active' => true,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle1->id,
            'product_id' => $comp1->id,
            'quantity' => 2,
        ]);

        $bundle2 = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parent2->id,
            'name' => 'Bundle 2',
            'bundle_sale_price' => 210000,
            'is_active' => true,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle2->id,
            'product_id' => $comp2->id,
            'quantity' => 3,
        ]);

        // 1. Create a persisted 2-bundle draft sale
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 315000,
            'paid_amount' => 0,
            'due_amount' => 315000,
            'reference' => 'SO-TWO-BUNDLES',
            'is_tax_included' => false,
        ]);

        $detail1 = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $parent1->id,
            'product_name' => $parent1->product_name,
            'product_code' => $parent1->product_code,
            'quantity' => 1,
            'price' => 105000,
            'unit_price' => 105000,
            'sub_total' => 105000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $bundleItem1 = SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail1->id,
            'bundle_id' => $bundle1->id,
            'bundle_item_id' => $bundle1->items->first()->id,
            'product_id' => $comp1->id,
            'name' => $comp1->product_name,
            'quantity' => 2,
            'price' => 0,
            'sub_total' => 0,
        ]);

        $detail2 = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $parent2->id,
            'product_name' => $parent2->product_name,
            'product_code' => $parent2->product_code,
            'quantity' => 1,
            'price' => 210000,
            'unit_price' => 210000,
            'sub_total' => 210000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $bundleItem2 = SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail2->id,
            'bundle_id' => $bundle2->id,
            'bundle_item_id' => $bundle2->items->first()->id,
            'product_id' => $comp2->id,
            'name' => $comp2->product_name,
            'quantity' => 3,
            'price' => 0,
            'sub_total' => 0,
        ]);

        // 2. Hydrate into Livewire EditForm / ProductCart
        Cart::instance('sale')->destroy();
        Livewire::test(EditForm::class, ['sale' => $sale]);

        $cartContent = Cart::instance('sale')->content();
        $this->assertCount(2, $cartContent);

        $row1 = $cartContent->firstWhere('id', $detail1->id);
        $row2 = $cartContent->firstWhere('id', $detail2->id);
        $this->assertNotNull($row1);
        $this->assertNotNull($row2);

        // 3. Remove row 1 in ProductCart
        $lwCart = Livewire::test(ProductCart::class, ['cartInstance' => 'sale']);
        $lwCart->call('removeItem', $row1->rowId, $row1->id);

        $remainingContent = Cart::instance('sale')->content();
        $this->assertCount(1, $remainingContent);

        $remainingRow = $remainingContent->first();
        $this->assertEquals($detail2->id, $remainingRow->id);
        $this->assertEquals(210000, (float) $remainingRow->price);
        $this->assertEquals(1, $remainingRow->qty);
        $this->assertCount(1, $remainingRow->options->bundle_items);
        $this->assertEquals($comp2->id, $remainingRow->options->bundle_items[0]['product_id']);
        $this->assertEquals(3, $remainingRow->options->bundle_items[0]['quantity']);

        // 4. Update the persisted Sale via SaleService
        $saleService = app(SaleService::class);
        $updatedSale = $saleService->updateSale($sale, [
            'customer_id' => $this->customer->id,
            'date' => $sale->date,
        ], Cart::instance('sale')->content());

        // 5. Assert the persisted database state
        $updatedSale->load(['saleDetails.bundleItems']);
        $this->assertCount(1, $updatedSale->saleDetails, 'Only remaining parent detail should persist');

        $persistedDetail2 = $updatedSale->saleDetails->first();
        $this->assertEquals($parent2->id, $persistedDetail2->product_id);
        $this->assertEquals(210000, (float) $persistedDetail2->price);
        $this->assertEquals(1, $persistedDetail2->quantity);

        $this->assertCount(1, $persistedDetail2->bundleItems, 'Remaining parent must retain its component row');
        $persistedComp2 = $persistedDetail2->bundleItems->first();
        $this->assertEquals($comp2->id, $persistedComp2->product_id);
        $this->assertEquals(3, $persistedComp2->quantity);
        $this->assertEquals($persistedDetail2->id, $persistedComp2->sale_detail_id);
        $this->assertEquals($updatedSale->id, $persistedComp2->sale_id);

        // Assert removed row and its components are completely absent and no stale components exist
        $this->assertDatabaseMissing('sale_details', ['id' => $detail1->id]);
        $this->assertDatabaseMissing('sale_details', ['product_id' => $parent1->id, 'sale_id' => $sale->id]);
        $this->assertDatabaseMissing('sale_bundle_items', ['id' => $bundleItem1->id]);
        $this->assertDatabaseMissing('sale_bundle_items', ['product_id' => $comp1->id, 'sale_id' => $sale->id]);
        $this->assertEquals(1, SaleBundleItem::where('sale_id', $sale->id)->count(), 'No stale component rows should remain');
    }

    /**
     * 2.1 Add a create-flow test that changes parent quantity through increase, decrease,
     * and increase steps and verifies final component quantity equals parent quantity multiplied by quantity per bundle exactly once.
     */
    public function test_2_1_create_flow_repeated_quantity_changes_scale_component_exactly_once(): void
    {
        $parent = $this->createTestProduct('Gaming Rig', 'RIG-01', 15000000);
        $comp = $this->createTestProduct('RGB Fan', 'FAN-01', 100000);

        $bundle = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parent->id,
            'name' => 'Rig Fan Bundle',
            'bundle_sale_price' => 15200000,
            'is_active' => true,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $comp->id,
            'quantity' => 3, // 3 fans per rig
        ]);

        $lw = Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->set('pendingProduct', [
                'id' => $parent->id,
                'product_name' => $parent->product_name,
                'product_code' => $parent->product_code,
                'product_quantity' => 10,
                'product_unit' => 'PCS',
            ])
            ->call('confirmBundleSelection', $bundle->id);

        $cartItem = Cart::instance('sale')->content()->first();
        $id = $cartItem->id;
        $rowId = $cartItem->rowId;

        // Step 1: Increase quantity from 1 to 4
        $lw->set('quantity.' . $id, 4)
            ->call('updateQuantity', $rowId, $id);

        $itemAfter4 = Cart::instance('sale')->content()->first();
        $this->assertEquals(4, $itemAfter4->qty);
        $this->assertEquals(12, $itemAfter4->options->bundle_items[0]['quantity']); // 4 * 3

        // Step 2: Decrease quantity from 4 to 2
        $lw->set('quantity.' . $id, 2)
            ->call('updateQuantity', $rowId, $id);

        $itemAfter2 = Cart::instance('sale')->content()->first();
        $this->assertEquals(2, $itemAfter2->qty);
        $this->assertEquals(6, $itemAfter2->options->bundle_items[0]['quantity']); // 2 * 3

        // Step 3: Increase quantity from 2 to 5
        $lw->set('quantity.' . $id, 5)
            ->call('updateQuantity', $rowId, $id);

        $itemAfter5 = Cart::instance('sale')->content()->first();
        $this->assertEquals(5, $itemAfter5->qty);
        $this->assertEquals(15, $itemAfter5->options->bundle_items[0]['quantity']); // 5 * 3

        // Persist via SaleService
        $saleService = app(SaleService::class);
        $sale = $saleService->createSale([
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'payment_term_id' => $this->paymentTerm->id,
            'setting_id' => $this->setting->id,
        ], Cart::instance('sale')->content());

        $sale->load('saleDetails.bundleItems');
        $this->assertCount(1, $sale->saleDetails);
        $detail = $sale->saleDetails->first();
        $this->assertEquals(5, $detail->quantity);

        $bundleItems = $detail->bundleItems;
        $this->assertCount(1, $bundleItems);
        $this->assertEquals(15, $bundleItems->first()->quantity, 'Persisted component quantity must equal 5 * 3 = 15 exactly once');
    }

    /**
     * 2.2 Add an edit-flow test that hydrates a persisted draft, repeats the quantity-change sequence,
     * and verifies reconstructed quantity per bundle and final persisted quantity.
     */
    public function test_2_2_edit_flow_hydrates_and_recalculates_reconstructed_quantity_per_bundle(): void
    {
        $parent = $this->createTestProduct('Workstation', 'WS-01', 8000000);
        $comp = $this->createTestProduct('RAM 16GB', 'RAM-16', 800000);

        $bundle = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parent->id,
            'name' => 'WS Quad RAM Bundle',
            'bundle_sale_price' => 10000000,
            'is_active' => true,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $comp->id,
            'quantity' => 4, // 4 sticks per workstation
        ]);

        // Create initial sale with qty = 2 (so components = 8)
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 20000000,
            'paid_amount' => 0,
            'due_amount' => 20000000,
            'reference' => 'SO-DRAFT-RECALC',
            'is_tax_included' => false,
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'product_name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'quantity' => 2,
            'price' => 10000000,
            'unit_price' => 10000000,
            'sub_total' => 20000000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bundle->items->first()->id,
            'product_id' => $comp->id,
            'name' => $comp->product_name,
            'quantity' => 8, // 4 * 2
            'price' => 0,
            'sub_total' => 0,
        ]);

        // Hydrate in EditForm
        Cart::instance('sale')->destroy();
        Livewire::test(EditForm::class, ['sale' => $sale]);

        $cartItem = Cart::instance('sale')->content()->first();
        $this->assertNotNull($cartItem);
        $this->assertEquals(2, $cartItem->qty);
        // Verify reconstructed quantity_per_bundle is 8 / 2 = 4.0
        $this->assertEquals(4.0, (float) $cartItem->options->bundle_items[0]['quantity_per_bundle']);

        // Now test quantity updates through ProductCart component in edit flow
        $lwCart = Livewire::test(ProductCart::class, ['cartInstance' => 'sale']);
        $id = $cartItem->id;
        $rowId = $cartItem->rowId;

        // Increase to 6 -> component qty = 24
        $lwCart->set('quantity.' . $id, 6)
            ->call('updateQuantity', $rowId, $id);
        $item6 = Cart::instance('sale')->content()->first();
        $this->assertEquals(24, $item6->options->bundle_items[0]['quantity']);

        // Decrease to 3 -> component qty = 12
        $lwCart->set('quantity.' . $id, 3)
            ->call('updateQuantity', $rowId, $id);
        $item3 = Cart::instance('sale')->content()->first();
        $this->assertEquals(12, $item3->options->bundle_items[0]['quantity']);

        // Increase to 5 -> component qty = 20
        $lwCart->set('quantity.' . $id, 5)
            ->call('updateQuantity', $rowId, $id);
        $item5 = Cart::instance('sale')->content()->first();
        $this->assertEquals(20, $item5->options->bundle_items[0]['quantity']);

        // Update sale via SaleService
        $saleService = app(SaleService::class);
        $updatedSale = $saleService->updateSale($sale, [
            'customer_id' => $this->customer->id,
            'date' => $sale->date,
        ], Cart::instance('sale')->content());

        $updatedSale->load('saleDetails.bundleItems');
        $this->assertCount(1, $updatedSale->saleDetails);
        $updatedDetail = $updatedSale->saleDetails->first();
        $this->assertEquals(5, $updatedDetail->quantity);

        $this->assertCount(1, $updatedDetail->bundleItems);
        $this->assertEquals(20, $updatedDetail->bundleItems->first()->quantity, 'Final component quantity must be 5 * 4 = 20');
    }

    /**
     * 2.3 Extend draft-drift coverage to verify changed live component identity, quantity,
     * and informational allocation do not replace the captured composition after acknowledgement.
     */
    public function test_2_3_draft_drift_preserves_captured_composition_after_acknowledgement(): void
    {
        $parent = $this->createTestProduct('Server Node', 'SRV-01', 20000000);
        $compOriginal = $this->createTestProduct('Original SSD 1TB', 'SSD-1T', 1500000);
        $compNew = $this->createTestProduct('New SSD 2TB', 'SSD-2T', 2500000);

        $bundle = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parent->id,
            'name' => 'Server Storage Bundle',
            'bundle_sale_price' => 22000000,
            'is_active' => true,
        ]);
        $bundleItem = ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $compOriginal->id,
            'quantity' => 2,
            'informational_item_price' => 1000000,
        ]);

        // Create draft sale with captured composition (Original SSD, qty = 2, info price = 1000000)
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 22000000,
            'paid_amount' => 0,
            'due_amount' => 22000000,
            'reference' => 'SO-DRIFT-PRESERVE',
            'is_tax_included' => false,
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'product_name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'quantity' => 1,
            'price' => 22000000,
            'unit_price' => 22000000,
            'sub_total' => 22000000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bundleItem->id,
            'product_id' => $compOriginal->id,
            'name' => $compOriginal->product_name,
            'quantity' => 2,
            'price' => 0,
            'sub_total' => 0,
            'informational_item_price' => 1000000,
        ]);

        // Now mutate live definition drastically: change component product, quantity, and informational price
        $bundleItem->update([
            'product_id' => $compNew->id,
            'quantity' => 4,
            'informational_item_price' => 2500000,
        ]);

        // Hydrate cart for edit
        Cart::instance('sale')->destroy();
        Livewire::test(EditForm::class, ['sale' => $sale]);

        $hydratedRow = Cart::instance('sale')->content()->first();
        $this->assertEquals($compOriginal->id, $hydratedRow->options->bundle_items[0]['product_id']);
        $this->assertEquals(2, $hydratedRow->options->bundle_items[0]['quantity']);
        $this->assertEquals(1000000, (float) $hydratedRow->options->bundle_items[0]['informational_item_price']);

        // Update draft with acknowledgement
        $putData = [
            'reference' => $sale->reference,
            'status' => Sale::STATUS_DRAFTED,
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 22000000,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'payment_method' => 'Cash',
            'paid_amount' => 0,
            'note' => 'Drift acknowledged',
            'acknowledge_lifecycle_warning' => 1,
        ];

        $response = $this->put(route('sales.update', $sale), $putData);
        $response->assertSessionHasNoErrors();

        // Assert persisted sale detail and bundle item retained captured composition
        $sale->refresh();
        $sale->load('saleDetails.bundleItems');
        $persistedBundleItems = $sale->saleDetails->first()->bundleItems;

        $this->assertCount(1, $persistedBundleItems);
        $this->assertEquals($compOriginal->id, $persistedBundleItems->first()->product_id, 'Must keep original component ID');
        $this->assertEquals(2, $persistedBundleItems->first()->quantity, 'Must keep original captured quantity');
        $this->assertEquals(1000000, (float) $persistedBundleItems->first()->informational_item_price, 'Must keep original captured informational price');
    }

    /**
     * 3.1 Assert in create-flow coverage that every linked component's `sale_detail_id` points to its owning parent
     * and its `sale_id` equals the parent detail's `sale_id`.
     */
    public function test_3_1_create_flow_every_component_sale_detail_id_and_sale_id_match_parent(): void
    {
        $parentA = $this->createTestProduct('Parent Product 1', 'PAR-01', 500000);
        $parentB = $this->createTestProduct('Parent Product 2', 'PAR-02', 750000);
        $comp = $this->createTestProduct('Sub Part', 'SUB-01', 50000);

        $bundleA = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parentA->id,
            'name' => 'Bundle A',
            'bundle_sale_price' => 550000,
            'is_active' => true,
        ]);
        ProductBundleItem::create(['bundle_id' => $bundleA->id, 'product_id' => $comp->id, 'quantity' => 1]);

        $bundleB = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parentB->id,
            'name' => 'Bundle B',
            'bundle_sale_price' => 800000,
            'is_active' => true,
        ]);
        ProductBundleItem::create(['bundle_id' => $bundleB->id, 'product_id' => $comp->id, 'quantity' => 2]);

        $cart = Cart::instance('sale');
        $cart->destroy();

        $cart->add([
            'id' => Str::uuid()->toString(),
            'name' => $parentA->product_name,
            'qty' => 1,
            'price' => 550000,
            'weight' => 1,
            'options' => [
                'product_id' => $parentA->id,
                'code' => $parentA->product_code,
                'unit_price' => 550000,
                'sub_total' => 550000,
                'sub_total_before_tax' => 550000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'product_tax' => null,
                'is_bundled_row' => true,
                'bundle_id' => $bundleA->id,
                'bundle_items' => [
                    [
                        'bundle_id' => $bundleA->id,
                        'bundle_item_id' => $bundleA->items->first()->id,
                        'product_id' => $comp->id,
                        'name' => $comp->product_name,
                        'price' => 0.0,
                        'quantity' => 1,
                        'quantity_per_bundle' => 1,
                        'sub_total' => 0.0,
                    ]
                ],
            ]
        ]);

        $cart->add([
            'id' => Str::uuid()->toString(),
            'name' => $parentB->product_name,
            'qty' => 3,
            'price' => 800000,
            'weight' => 1,
            'options' => [
                'product_id' => $parentB->id,
                'code' => $parentB->product_code,
                'unit_price' => 800000,
                'sub_total' => 2400000,
                'sub_total_before_tax' => 2400000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'product_tax' => null,
                'is_bundled_row' => true,
                'bundle_id' => $bundleB->id,
                'bundle_items' => [
                    [
                        'bundle_id' => $bundleB->id,
                        'bundle_item_id' => $bundleB->items->first()->id,
                        'product_id' => $comp->id,
                        'name' => $comp->product_name,
                        'price' => 0.0,
                        'quantity' => 6, // 2 * 3
                        'quantity_per_bundle' => 2,
                        'sub_total' => 0.0,
                    ]
                ],
            ]
        ]);

        $saleService = app(SaleService::class);
        $sale = $saleService->createSale([
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'payment_term_id' => $this->paymentTerm->id,
            'setting_id' => $this->setting->id,
        ], $cart->content());

        $sale->load('saleDetails.bundleItems');
        $this->assertCount(2, $sale->saleDetails);

        foreach ($sale->saleDetails as $detail) {
            $this->assertNotEmpty($detail->bundleItems);
            foreach ($detail->bundleItems as $bundleItem) {
                $this->assertEquals($detail->id, $bundleItem->sale_detail_id, 'bundle item sale_detail_id must point to parent detail id');
                $this->assertEquals($detail->sale_id, $bundleItem->sale_id, 'bundle item sale_id must match parent sale_id');
                $this->assertEquals($sale->id, $bundleItem->sale_id, 'bundle item sale_id must match sale header id');
            }
        }
    }

    /**
     * 3.2 Assert in editable-update coverage that replacement components point to replacement parent details
     * and no stale component rows remain.
     */
    public function test_3_2_editable_update_replacement_components_point_to_new_details_no_stale_rows(): void
    {
        $parentOld = $this->createTestProduct('Old Parent', 'OLD-01', 300000);
        $parentNew = $this->createTestProduct('New Parent', 'NEW-01', 400000);
        $comp = $this->createTestProduct('Generic Comp', 'GEN-01', 50000);

        $bundleOld = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parentOld->id,
            'name' => 'Old Bundle',
            'bundle_sale_price' => 320000,
            'is_active' => true,
        ]);
        ProductBundleItem::create(['bundle_id' => $bundleOld->id, 'product_id' => $comp->id, 'quantity' => 1]);

        $bundleNew = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parentNew->id,
            'name' => 'New Bundle',
            'bundle_sale_price' => 450000,
            'is_active' => true,
        ]);
        ProductBundleItem::create(['bundle_id' => $bundleNew->id, 'product_id' => $comp->id, 'quantity' => 2]);

        $saleService = app(SaleService::class);

        $cart = Cart::instance('sale');
        $cart->destroy();
        $cart->add([
            'id' => Str::uuid()->toString(),
            'name' => $parentOld->product_name,
            'qty' => 1,
            'price' => 320000,
            'weight' => 1,
            'options' => [
                'product_id' => $parentOld->id,
                'code' => $parentOld->product_code,
                'unit_price' => 320000,
                'sub_total' => 320000,
                'sub_total_before_tax' => 320000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'product_tax' => null,
                'is_bundled_row' => true,
                'bundle_id' => $bundleOld->id,
                'bundle_items' => [
                    [
                        'bundle_id' => $bundleOld->id,
                        'bundle_item_id' => $bundleOld->items->first()->id,
                        'product_id' => $comp->id,
                        'name' => $comp->product_name,
                        'price' => 0.0,
                        'quantity' => 1,
                        'quantity_per_bundle' => 1,
                        'sub_total' => 0.0,
                    ]
                ],
            ]
        ]);

        $sale = $saleService->createSale([
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'payment_term_id' => $this->paymentTerm->id,
            'setting_id' => $this->setting->id,
        ], $cart->content());

        $oldDetailId = $sale->saleDetails()->first()->id;
        $oldBundleItemId = SaleBundleItem::where('sale_id', $sale->id)->first()->id;

        // Replace entire composition in cart with parentNew and bundleNew
        $cart->destroy();
        $cart->add([
            'id' => Str::uuid()->toString(),
            'name' => $parentNew->product_name,
            'qty' => 2,
            'price' => 450000,
            'weight' => 1,
            'options' => [
                'product_id' => $parentNew->id,
                'code' => $parentNew->product_code,
                'unit_price' => 450000,
                'sub_total' => 900000,
                'sub_total_before_tax' => 900000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'product_tax' => null,
                'is_bundled_row' => true,
                'bundle_id' => $bundleNew->id,
                'bundle_items' => [
                    [
                        'bundle_id' => $bundleNew->id,
                        'bundle_item_id' => $bundleNew->items->first()->id,
                        'product_id' => $comp->id,
                        'name' => $comp->product_name,
                        'price' => 0.0,
                        'quantity' => 4, // 2 * 2
                        'quantity_per_bundle' => 2,
                        'sub_total' => 0.0,
                    ]
                ],
            ]
        ]);

        $updatedSale = $saleService->updateSale($sale, [
            'customer_id' => $this->customer->id,
            'date' => $sale->date,
        ], $cart->content());

        $updatedSale->load('saleDetails.bundleItems');
        $this->assertCount(1, $updatedSale->saleDetails);

        $newDetail = $updatedSale->saleDetails->first();
        $this->assertNotEquals($oldDetailId, $newDetail->id);
        $this->assertEquals($parentNew->id, $newDetail->product_id);

        $this->assertCount(1, $newDetail->bundleItems);
        $newBundleItem = $newDetail->bundleItems->first();
        $this->assertNotEquals($oldBundleItemId, $newBundleItem->id);
        $this->assertEquals($newDetail->id, $newBundleItem->sale_detail_id);
        $this->assertEquals($sale->id, $newBundleItem->sale_id);
        $this->assertEquals(4, $newBundleItem->quantity);

        // Verify old rows are deleted completely
        $this->assertDatabaseMissing('sale_details', ['id' => $oldDetailId]);
        $this->assertDatabaseMissing('sale_bundle_items', ['id' => $oldBundleItemId]);
        $this->assertEquals(1, SaleBundleItem::where('sale_id', $sale->id)->count());
    }

    /**
     * 3.3 Add a controlled component-write failure test for Sale creation
     * and verify the header, parent details, and components all roll back.
     */
    public function test_3_3_creation_rolls_back_atomically_when_component_write_fails(): void
    {
        $parent = $this->createTestProduct('Atomic Parent', 'ATM-01', 500000);
        $comp = $this->createTestProduct('Atomic Comp', 'ATC-01', 50000);

        $bundle = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parent->id,
            'name' => 'Atomic Bundle',
            'bundle_sale_price' => 520000,
            'is_active' => true,
        ]);
        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $comp->id, 'quantity' => 1]);

        $cart = Cart::instance('sale');
        $cart->destroy();
        $cart->add([
            'id' => Str::uuid()->toString(),
            'name' => $parent->product_name,
            'qty' => 1,
            'price' => 520000,
            'weight' => 1,
            'options' => [
                'product_id' => $parent->id,
                'code' => $parent->product_code,
                'unit_price' => 520000,
                'sub_total' => 520000,
                'sub_total_before_tax' => 520000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'product_tax' => null,
                'is_bundled_row' => true,
                'bundle_id' => $bundle->id,
                'bundle_items' => [
                    [
                        'bundle_id' => $bundle->id,
                        'bundle_item_id' => $bundle->items->first()->id,
                        'product_id' => $comp->id,
                        'name' => $comp->product_name,
                        'price' => 0.0,
                        'quantity' => 1,
                        'quantity_per_bundle' => 1,
                        'sub_total' => 0.0,
                    ]
                ],
            ]
        ]);

        $initialSaleCount = Sale::count();
        $initialDetailCount = SaleDetails::count();
        $initialBundleCount = SaleBundleItem::count();

        // Snapshot original listeners for eloquent.saving on SaleBundleItem
        $dispatcher = SaleBundleItem::getEventDispatcher();
        $eventName = 'eloquent.saving: ' . SaleBundleItem::class;
        $originalListeners = $dispatcher->getListeners($eventName);

        // Attach a failure listener
        $failListener = function () {
            throw new Exception('Simulated component write failure during Sale creation.');
        };
        $dispatcher->listen($eventName, $failListener);

        $saleService = app(SaleService::class);
        $exceptionCaught = false;

        try {
            $saleService->createSale([
                'customer_id' => $this->customer->id,
                'date' => now()->toDateString(),
                'due_date' => now()->toDateString(),
                'payment_term_id' => $this->paymentTerm->id,
                'setting_id' => $this->setting->id,
            ], $cart->content());
        } catch (Exception $e) {
            $exceptionCaught = true;
            $this->assertStringContainsString('Simulated component write failure', $e->getMessage());
        } finally {
            // Restore original listeners cleanly without duplicating model logic
            $dispatcher->forget($eventName);
            foreach ($originalListeners as $listener) {
                $dispatcher->listen($eventName, $listener);
            }
        }

        $this->assertTrue($exceptionCaught, 'Exception should have been thrown and caught');
        $this->assertEquals($initialSaleCount, Sale::count(), 'Sale header must be rolled back');
        $this->assertEquals($initialDetailCount, SaleDetails::count(), 'Sale details must be rolled back');
        $this->assertEquals($initialBundleCount, SaleBundleItem::count(), 'Sale bundle items must be rolled back');
    }

    /**
     * 3.4 Add a controlled component-write failure test for editable draft update
     * and verify the previously committed header, details, and components remain unchanged.
     */
    public function test_3_4_editable_draft_update_rolls_back_atomically_when_component_write_fails(): void
    {
        $parent = $this->createTestProduct('Draft Parent', 'DFP-01', 500000);
        $comp = $this->createTestProduct('Draft Comp', 'DFC-01', 50000);

        $bundle = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parent->id,
            'name' => 'Draft Bundle',
            'bundle_sale_price' => 520000,
            'is_active' => true,
        ]);
        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $comp->id, 'quantity' => 1]);

        $cart = Cart::instance('sale');
        $cart->destroy();
        $cart->add([
            'id' => Str::uuid()->toString(),
            'name' => $parent->product_name,
            'qty' => 1,
            'price' => 520000,
            'weight' => 1,
            'options' => [
                'product_id' => $parent->id,
                'code' => $parent->product_code,
                'unit_price' => 520000,
                'sub_total' => 520000,
                'sub_total_before_tax' => 520000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'product_tax' => null,
                'is_bundled_row' => true,
                'bundle_id' => $bundle->id,
                'bundle_items' => [
                    [
                        'bundle_id' => $bundle->id,
                        'bundle_item_id' => $bundle->items->first()->id,
                        'product_id' => $comp->id,
                        'name' => $comp->product_name,
                        'price' => 0.0,
                        'quantity' => 1,
                        'quantity_per_bundle' => 1,
                        'sub_total' => 0.0,
                    ]
                ],
            ]
        ]);

        $saleService = app(SaleService::class);
        $committedSale = $saleService->createSale([
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'payment_term_id' => $this->paymentTerm->id,
            'setting_id' => $this->setting->id,
        ], $cart->content());

        $originalDetailId = $committedSale->saleDetails->first()->id;
        $originalBundleItemId = SaleBundleItem::where('sale_id', $committedSale->id)->first()->id;

        // Change quantity to 3 in cart
        $cart->destroy();
        $cart->add([
            'id' => Str::uuid()->toString(),
            'name' => $parent->product_name,
            'qty' => 3,
            'price' => 520000,
            'weight' => 1,
            'options' => [
                'product_id' => $parent->id,
                'code' => $parent->product_code,
                'unit_price' => 520000,
                'sub_total' => 1560000,
                'sub_total_before_tax' => 1560000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'product_tax' => null,
                'is_bundled_row' => true,
                'bundle_id' => $bundle->id,
                'bundle_items' => [
                    [
                        'bundle_id' => $bundle->id,
                        'bundle_item_id' => $bundle->items->first()->id,
                        'product_id' => $comp->id,
                        'name' => $comp->product_name,
                        'price' => 0.0,
                        'quantity' => 3,
                        'quantity_per_bundle' => 1,
                        'sub_total' => 0.0,
                    ]
                ],
            ]
        ]);

        // Snapshot original listeners for eloquent.saving on SaleBundleItem
        $dispatcher = SaleBundleItem::getEventDispatcher();
        $eventName = 'eloquent.saving: ' . SaleBundleItem::class;
        $originalListeners = $dispatcher->getListeners($eventName);

        // Attach a failure listener
        $failListener = function () {
            throw new Exception('Simulated component write failure during draft update.');
        };
        $dispatcher->listen($eventName, $failListener);

        $exceptionCaught = false;
        try {
            $saleService->updateSale($committedSale, [
                'customer_id' => $this->customer->id,
                'date' => $committedSale->date,
            ], $cart->content());
        } catch (Exception $e) {
            $exceptionCaught = true;
            $this->assertStringContainsString('Simulated component write failure during draft update', $e->getMessage());
        } finally {
            // Restore original listeners cleanly without duplicating model logic
            $dispatcher->forget($eventName);
            foreach ($originalListeners as $listener) {
                $dispatcher->listen($eventName, $listener);
            }
        }

        $this->assertTrue($exceptionCaught);

        // Verify previously committed header, details, and bundle items remain intact
        $committedSale->refresh();
        $committedSale->load('saleDetails.bundleItems');

        $this->assertEquals(520000, (float) $committedSale->total_amount);
        $this->assertCount(1, $committedSale->saleDetails);
        $detail = $committedSale->saleDetails->first();
        $this->assertEquals($originalDetailId, $detail->id);
        $this->assertEquals(1, $detail->quantity);

        $this->assertCount(1, $detail->bundleItems);
        $bundleItem = $detail->bundleItems->first();
        $this->assertEquals($originalBundleItemId, $bundleItem->id);
        $this->assertEquals(1, $bundleItem->quantity);
    }

    /**
     * 4.1 Consolidate or extend coverage proving insufficient component stock
     * does not block normal Sale creation and causes no inventory mutation.
     */
    public function test_4_1_insufficient_component_stock_does_not_block_creation_no_inventory_mutation(): void
    {
        $parent = $this->createTestProduct('Parent P1', 'P1', 100000, 50000, 10);
        // Component only has 2 in stock
        $comp = $this->createTestProduct('Scarce Comp', 'SC-01', 20000, 10000, 2);

        $bundle = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parent->id,
            'name' => 'Scarce Bundle',
            'bundle_sale_price' => 110000,
            'is_active' => true,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $comp->id,
            'quantity' => 5, // Requires 5 per parent
        ]);

        $cart = Cart::instance('sale');
        $cart->destroy();
        // 2 parents = 10 components demand (stock is only 2)
        $cart->add([
            'id' => Str::uuid()->toString(),
            'name' => $parent->product_name,
            'qty' => 2,
            'price' => 110000,
            'weight' => 1,
            'options' => [
                'product_id' => $parent->id,
                'code' => $parent->product_code,
                'unit_price' => 110000,
                'sub_total' => 220000,
                'sub_total_before_tax' => 220000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'product_tax' => null,
                'is_bundled_row' => true,
                'bundle_id' => $bundle->id,
                'bundle_items' => [
                    [
                        'bundle_id' => $bundle->id,
                        'bundle_item_id' => $bundle->items->first()->id,
                        'product_id' => $comp->id,
                        'name' => $comp->product_name,
                        'price' => 0.0,
                        'quantity' => 10,
                        'quantity_per_bundle' => 5,
                        'sub_total' => 0.0,
                    ]
                ],
            ]
        ]);

        $saleService = app(SaleService::class);
        $sale = $saleService->createSale([
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'payment_term_id' => $this->paymentTerm->id,
            'setting_id' => $this->setting->id,
        ], $cart->content());

        $this->assertNotNull($sale);
        $this->assertEquals(Sale::STATUS_DRAFTED, $sale->status);
        $this->assertEquals(1, $sale->saleDetails()->count());
        $this->assertEquals(10, $sale->saleDetails()->first()->bundleItems()->first()->quantity);

        // Verify stock is untouched
        $comp->refresh();
        $this->assertEquals(2, $comp->product_quantity);
        $parent->refresh();
        $this->assertEquals(10, $parent->product_quantity);

        // Verify no inventory transaction
        $this->assertEquals(0, Transaction::where('product_id', $comp->id)->count());
        $this->assertEquals(0, Transaction::where('product_id', $parent->id)->count());
    }

    /**
     * 4.2 Extend coverage proving an editable draft may increase component demand beyond stock without inventory mutation.
     */
    public function test_4_2_editable_draft_may_increase_component_demand_beyond_stock(): void
    {
        $parent = $this->createTestProduct('Parent P2', 'P2', 100000, 50000, 10);
        $comp = $this->createTestProduct('Comp C2', 'C2', 20000, 10000, 5);

        $bundle = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parent->id,
            'name' => 'Bundle C2',
            'bundle_sale_price' => 110000,
            'is_active' => true,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $comp->id,
            'quantity' => 2,
        ]);

        $saleService = app(SaleService::class);

        // Initial draft: 2 parents = 4 components (stock is 5)
        $cart = Cart::instance('sale');
        $cart->destroy();
        $cart->add([
            'id' => Str::uuid()->toString(),
            'name' => $parent->product_name,
            'qty' => 2,
            'price' => 110000,
            'weight' => 1,
            'options' => [
                'product_id' => $parent->id,
                'code' => $parent->product_code,
                'unit_price' => 110000,
                'sub_total' => 220000,
                'sub_total_before_tax' => 220000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'product_tax' => null,
                'is_bundled_row' => true,
                'bundle_id' => $bundle->id,
                'bundle_items' => [
                    [
                        'bundle_id' => $bundle->id,
                        'bundle_item_id' => $bundle->items->first()->id,
                        'product_id' => $comp->id,
                        'name' => $comp->product_name,
                        'price' => 0.0,
                        'quantity' => 4,
                        'quantity_per_bundle' => 2,
                        'sub_total' => 0.0,
                    ]
                ],
            ]
        ]);

        $sale = $saleService->createSale([
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'payment_term_id' => $this->paymentTerm->id,
            'setting_id' => $this->setting->id,
        ], $cart->content());

        // Update draft: Increase parent qty to 10 -> component demand becomes 20 (stock is only 5)
        $cart->destroy();
        $cart->add([
            'id' => Str::uuid()->toString(),
            'name' => $parent->product_name,
            'qty' => 10,
            'price' => 110000,
            'weight' => 1,
            'options' => [
                'product_id' => $parent->id,
                'code' => $parent->product_code,
                'unit_price' => 110000,
                'sub_total' => 1100000,
                'sub_total_before_tax' => 1100000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'product_tax' => null,
                'is_bundled_row' => true,
                'bundle_id' => $bundle->id,
                'bundle_items' => [
                    [
                        'bundle_id' => $bundle->id,
                        'bundle_item_id' => $bundle->items->first()->id,
                        'product_id' => $comp->id,
                        'name' => $comp->product_name,
                        'price' => 0.0,
                        'quantity' => 20,
                        'quantity_per_bundle' => 2,
                        'sub_total' => 0.0,
                    ]
                ],
            ]
        ]);

        $updatedSale = $saleService->updateSale($sale, [
            'customer_id' => $this->customer->id,
            'date' => $sale->date,
        ], $cart->content());

        $updatedSale->load('saleDetails.bundleItems');
        $this->assertEquals(10, $updatedSale->saleDetails->first()->quantity);
        $this->assertEquals(20, $updatedSale->saleDetails->first()->bundleItems->first()->quantity);

        // Verify stock is still 5 and no transaction recorded
        $comp->refresh();
        $this->assertEquals(5, $comp->product_quantity);
        $this->assertEquals(0, Transaction::where('product_id', $comp->id)->count());
    }

    /**
     * 4.3 Verify dispatch rejects unavailable stock-managed component quantity at the selected location before inventory movement.
     */
    public function test_4_3_dispatch_rejects_unavailable_stock_managed_component_at_selected_location(): void
    {
        $parent = $this->createTestProduct('Bundle Parent Dispatch', 'BPD-01', 1000000, 500000, 10);
        // Component only has 3 units at location
        $comp = $this->createTestProduct('Component Stock Check', 'CSC-01', 100000, 50000, 3, true);

        $bundle = ProductBundle::create([
            'setting_id' => $this->setting->id,
            'parent_product_id' => $parent->id,
            'name' => 'Dispatch Check Bundle',
            'bundle_sale_price' => 1100000,
            'is_active' => true,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $comp->id,
            'quantity' => 2,
        ]);

        // Create APPROVED sale requiring 5 bundle parents -> 10 components demand
        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 5500000,
            'paid_amount' => 0,
            'due_amount' => 5500000,
            'reference' => 'SO-DISPATCH-STOCK-REJECT',
            'is_tax_included' => false,
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'product_name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'quantity' => 5,
            'price' => 1100000,
            'unit_price' => 1100000,
            'sub_total' => 5500000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bundle->items->first()->id,
            'product_id' => $comp->id,
            'name' => $comp->product_name,
            'quantity' => 10,
            'price' => 0,
            'sub_total' => 0,
        ]);

        // Attempt to dispatch 5 component units when only 3 are available at location
        // Composite key format in storeDispatch: product_id - tax_id - bundle_id => $comp->id . '-null-' . $bundle->id (or $comp->id . '--' . $bundle->id when tax is null)
        $compositeKey = $comp->id . '--' . $bundle->id;
        $payload = [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [
                $compositeKey => 5,
            ],
            'selectedLocations' => [
                $compositeKey => $this->location->id,
            ],
            'acknowledge_lifecycle_warning' => 1,
        ];

        $response = $this->post(route('sales.storeDispatch', $sale), $payload);
        $response->assertRedirect();
        $response->assertSessionHasErrors("dispatchedQuantities.$compositeKey");

        // Assert no dispatch created
        $this->assertNull(Dispatch::where('sale_id', $sale->id)->first());

        // Assert stock remains untouched
        $stock = ProductStock::where('product_id', $comp->id)
            ->where('location_id', $this->location->id)
            ->first();
        $this->assertEquals(3, $stock->quantity);
        $this->assertEquals(0, Transaction::where('product_id', $comp->id)->count());
    }
}
