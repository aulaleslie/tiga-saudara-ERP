<?php

namespace Modules\Pos\Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosReceiptService;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosBundleCapturedPricingAndAllocationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);

        config(['pos.checkout.split_posting.enabled' => true]);

        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ([
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
            'pos.transactions.view',
            'pos.overrides.price',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    /**
     * Canonical Scenario:
     * - POS owner Setting 1 is PKP.
     * - Laptop A parent stock belongs to Setting 1.
     * - Mouse stock belongs to Setting 2 (PKP or non-PKP).
     * - Mousepad stock belongs to Setting 3 (non-PKP).
     * - Bundle customer price: 5,550,000.
     * - Captured Mouse component snapshot: 50,000.
     * - Captured Mousepad component snapshot: 25,000.
     * - Expected parent residual: 5,475,000.
     * - Expected owner Sales totals: Setting 1: 5,475,000; Setting 2: 50,000; Setting 3: 25,000.
     * - Tax extracted ONLY from Setting 1's 5,475,000. Setting 2 & 3 have zero tax.
     * - Customer receipt retains 5,550,000 parent price, 0/free components, and tax equal to Setting 1 tax.
     * - Inventory decrements against 3 source locations.
     */
    public function test_canonical_three_owner_pos_bundle_split_allocation_and_tax(): void
    {
        // 1. Setup 3 settings
        $setting1 = $this->createSetting('Setting 1 Terminal PKP', 'DOC1', 'SO1', true);
        $setting2 = $this->createSetting('Setting 2 Source PKP', 'DOC2', 'SO2', true); // Even if PKP, foreign bundle alloc is non-tax
        $setting3 = $this->createSetting('Setting 3 Source Non-PKP', 'DOC3', 'SO3', false);

        $cashier = $this->createUserForSetting($setting1, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.transactions.view'
        ]);

        $loc1 = Location::create(['name' => 'LOC-1', 'setting_id' => $setting1->id]);
        $loc2 = Location::create(['name' => 'LOC-2', 'setting_id' => $setting2->id]);
        $loc3 = Location::create(['name' => 'LOC-3', 'setting_id' => $setting3->id]);

        $this->createTerminalAndSaleLocations($setting1, [$loc1, $loc2, $loc3]);
        $methods = $this->seedPaymentMethods($setting1, true);
        $this->openSession($setting1, PosTerminal::where('setting_id', $setting1->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting1);

        $tax11 = Tax::query()->create(['name' => 'PPN 11%', 'value' => 11, 'is_default' => true]);

        // Products
        $laptop = $this->createStockedProduct($setting1, $loc1, 'LAPTOP-A', 5550000, 5, $tax11);
        $mouse = $this->createStockedProduct($setting2, $loc2, 'MOUSE', 60000, 10, $tax11);
        $mousepad = $this->createStockedProduct($setting3, $loc3, 'MOUSEPAD', 30000, 10, null);

        // Bundle configured under Setting 1
        $bundle = ProductBundle::create([
            'parent_product_id' => $laptop->id,
            'setting_id' => $setting1->id,
            'name' => 'LAPTOP BUNDLE SET',
            'bundle_sale_price' => 5550000,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $mouse->id,
            'quantity' => 1,
            'informational_item_price' => 50000,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $mousepad->id,
            'quantity' => 1,
            'informational_item_price' => 25000,
        ]);

        // Add 1 bundle to cart
        $this->addCartLine($cashier, $setting1, $laptop->id, 1, $bundle->id);
        $this->selectCustomerInCart($cashier, $setting1, $customer);

        $response = $this->finalize($cashier, $setting1, [
            'idempotency_key' => 'K-CANONICAL-3OWNER-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 5550000,
            ],
        ]);

        $response->assertStatus(201);
        $checkoutId = (int) $response->json('pos_checkout_id');
        $checkout = PosCheckout::with([
            'transactions.lines',
            'checkoutSales.sale.saleDetails.bundleItems.product'
        ])->findOrFail($checkoutId);

        // Check Sales by setting
        $sales = $checkout->checkoutSales->map->sale;
        $this->assertCount(3, $sales);

        $sale1 = $sales->firstWhere('setting_id', $setting1->id);
        $sale2 = $sales->firstWhere('setting_id', $setting2->id);
        $sale3 = $sales->firstWhere('setting_id', $setting3->id);

        $this->assertNotNull($sale1);
        $this->assertNotNull($sale2);
        $this->assertNotNull($sale3);

        // Owner Sales totals
        $this->assertEquals(5475000.0, (float) $sale1->total_amount);
        $this->assertEquals(50000.0, (float) $sale2->total_amount);
        $this->assertEquals(25000.0, (float) $sale3->total_amount);

        // Total sum reconciles exactly to 5,550,000
        $totalSalesSum = (float) $sale1->total_amount + (float) $sale2->total_amount + (float) $sale3->total_amount;
        $this->assertEquals(5550000.0, $totalSalesSum);

        // Tax assertions:
        // Setting 1: 5,475,000 included tax at 11% => extractIncludedTaxMinor(547,500,000, 11) = 54,256,800 minor = 542,568.0
        $expectedTaxSetting1 = 542568.0;
        $this->assertEquals($expectedTaxSetting1, (float) $sale1->tax_amount);

        // Setting 2 & Setting 3 have zero tax
        $this->assertEquals(0.0, (float) $sale2->tax_amount);
        $this->assertEquals(0.0, (float) $sale3->tax_amount);

        // Inventory decrements against 3 locations
        $this->assertEquals(4, (int) ProductStock::where('product_id', $laptop->id)->where('location_id', $loc1->id)->value('quantity'));
        $this->assertEquals(9, (int) ProductStock::where('product_id', $mouse->id)->where('location_id', $loc2->id)->value('quantity'));
        $this->assertEquals(9, (int) ProductStock::where('product_id', $mousepad->id)->where('location_id', $loc3->id)->value('quantity'));

        // Receipt verification
        $receiptService = app(PosReceiptService::class);
        $receiptData = $receiptService->getReceiptData($checkout);

        $this->assertEquals(5550000.0, (float) $receiptData['grand_total']);
        $this->assertEquals($expectedTaxSetting1, (float) $receiptData['tax']);

        $receiptLine = $receiptData['lines'][0];
        $this->assertEquals(5550000.0, (float) $receiptLine['sub_total']);
        $this->assertCount(2, $receiptLine['bundle_composition']);
        $compNames = array_column($receiptLine['bundle_composition'], 'name');
        $this->assertContains('MOUSE NAME', $compNames);
        $this->assertContains('MOUSEPAD NAME', $compNames);
    }

    /**
     * Non-PKP POS owner: all bundle allocations are non-tax.
     */
    public function test_non_pkp_pos_owner_produces_all_non_tax_bundle_allocations(): void
    {
        $setting1 = $this->createSetting('Setting 1 Non-PKP', 'DOC1', 'SO1', false);
        $setting2 = $this->createSetting('Setting 2 Source PKP', 'DOC2', 'SO2', true);

        $cashier = $this->createUserForSetting($setting1, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment'
        ]);

        $loc1 = Location::create(['name' => 'LOC-1', 'setting_id' => $setting1->id]);
        $loc2 = Location::create(['name' => 'LOC-2', 'setting_id' => $setting2->id]);

        $this->createTerminalAndSaleLocations($setting1, [$loc1, $loc2]);
        $methods = $this->seedPaymentMethods($setting1, true);
        $this->openSession($setting1, PosTerminal::where('setting_id', $setting1->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting1);

        $tax11 = Tax::query()->create(['name' => 'PPN 11%', 'value' => 11, 'is_default' => true]);

        $laptop = $this->createStockedProduct($setting1, $loc1, 'LAPTOP-B', 5550000, 5, null);
        $mouse = $this->createStockedProduct($setting2, $loc2, 'MOUSE-B', 60000, 10, $tax11);

        $bundle = ProductBundle::create([
            'parent_product_id' => $laptop->id,
            'setting_id' => $setting1->id,
            'name' => 'NON-PKP BUNDLE',
            'bundle_sale_price' => 5550000,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $mouse->id,
            'quantity' => 1,
            'informational_item_price' => 50000,
        ]);

        $this->addCartLine($cashier, $setting1, $laptop->id, 1, $bundle->id);
        $this->selectCustomerInCart($cashier, $setting1, $customer);

        $response = $this->finalize($cashier, $setting1, [
            'idempotency_key' => 'K-NONPKP-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 5550000,
            ],
        ]);

        $response->assertStatus(201);
        $checkoutId = (int) $response->json('pos_checkout_id');
        $checkout = PosCheckout::with(['checkoutSales.sale'])->findOrFail($checkoutId);

        foreach ($checkout->checkoutSales as $cs) {
            $this->assertEquals(0.0, (float) $cs->sale->tax_amount);
        }
    }

    /**
     * Manual parent price override:
     * Three-owner fixture with Setting 1 (PKP Laptop parent), Setting 2 (Mouse 50,000), Setting 3 (Mousepad 25,000).
     * Override bundle price from 5,550,000 to 5,500,000.
     * Components remain 50,000 and 25,000. Parent residual becomes 5,425,000.
     */
    public function test_manual_parent_price_override_adjusts_only_parent_residual(): void
    {
        $setting1 = $this->createSetting('Setting 1 PKP', 'DOC1', 'SO1', true);
        $setting2 = $this->createSetting('Setting 2 Source', 'DOC2', 'SO2', false);
        $setting3 = $this->createSetting('Setting 3 Source', 'DOC3', 'SO3', true);

        $cashier = $this->createUserForSetting($setting1, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.overrides.price'
        ]);

        $loc1 = Location::create(['name' => 'LOC-1', 'setting_id' => $setting1->id]);
        $loc2 = Location::create(['name' => 'LOC-2', 'setting_id' => $setting2->id]);
        $loc3 = Location::create(['name' => 'LOC-3', 'setting_id' => $setting3->id]);

        $this->createTerminalAndSaleLocations($setting1, [$loc1, $loc2, $loc3]);
        $methods = $this->seedPaymentMethods($setting1, true);
        $this->openSession($setting1, PosTerminal::where('setting_id', $setting1->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting1);

        $tax11 = Tax::query()->create(['name' => 'PPN 11%', 'value' => 11, 'is_default' => true]);

        $laptop = $this->createStockedProduct($setting1, $loc1, 'LAPTOP-OVR', 5550000, 5, $tax11);
        $mouse = $this->createStockedProduct($setting2, $loc2, 'MOUSE-OVR', 60000, 10, null);
        $mousepad = $this->createStockedProduct($setting3, $loc3, 'MOUSEPAD-OVR', 30000, 10, $tax11);

        $bundle = ProductBundle::create([
            'parent_product_id' => $laptop->id,
            'setting_id' => $setting1->id,
            'name' => 'OVERRIDE BUNDLE',
            'bundle_sale_price' => 5550000,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $mouse->id,
            'quantity' => 1,
            'informational_item_price' => 50000,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $mousepad->id,
            'quantity' => 1,
            'informational_item_price' => 25000,
        ]);

        // Add to cart
        $resStore = $this->actingAs($cashier)->withSession(['setting_id' => $setting1->id])->postJson(route('pos.sell.cart.lines.store'), [
            'product_id' => $laptop->id,
            'qty' => 1,
            'bundle_id' => $bundle->id,
        ])->assertOk();

        $lineId = $resStore->json('cart_snapshot.lines.0.line_id');

        // Apply price override to 5,500,000 using active unit-price-override endpoint
        $this->actingAs($cashier)->withSession(['setting_id' => $setting1->id])->postJson(
            route('pos.sell.cart.lines.unit-price-override', ['lineId' => $lineId]),
            [
                'unit_price' => 5500000,
                'reason' => 'Integration test override',
            ]
        )->assertOk();

        $this->selectCustomerInCart($cashier, $setting1, $customer);

        $response = $this->finalize($cashier, $setting1, [
            'idempotency_key' => 'K-OVERRIDE-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 5500000,
            ],
        ]);

        $response->assertStatus(201);
        $checkoutId = (int) $response->json('pos_checkout_id');
        $checkout = PosCheckout::with([
            'checkoutSales.sale.saleDetails.bundleItems.product'
        ])->findOrFail($checkoutId);

        $sales = $checkout->checkoutSales->map->sale;
        $this->assertCount(3, $sales);

        $sale1 = $sales->firstWhere('setting_id', $setting1->id);
        $sale2 = $sales->firstWhere('setting_id', $setting2->id);
        $sale3 = $sales->firstWhere('setting_id', $setting3->id);

        $this->assertNotNull($sale1);
        $this->assertNotNull($sale2);
        $this->assertNotNull($sale3);

        // Parent residual adjusted to 5,500,000 - 50,000 - 25,000 = 5,425,000
        $this->assertEquals(5425000.0, (float) $sale1->total_amount);
        $this->assertEquals(50000.0, (float) $sale2->total_amount);
        $this->assertEquals(25000.0, (float) $sale3->total_amount);

        // Reconciles exactly to 5,500,000
        $totalSalesSum = (float) $sale1->total_amount + (float) $sale2->total_amount + (float) $sale3->total_amount;
        $this->assertEquals(5500000.0, $totalSalesSum);

        // Tax assertions: Setting 1 tax is extracted from 5,425,000; others are 0.00
        // (5425000 * 1100) / 11100 = 537612.61... => round = 537613.0
        $expectedTaxSetting1 = 537613.0;
        $this->assertEquals($expectedTaxSetting1, (float) $sale1->tax_amount);
        $this->assertEquals(0.0, (float) $sale2->tax_amount);
        $this->assertEquals(0.0, (float) $sale3->tax_amount);

        // Inventory decrements against 3 locations
        $this->assertEquals(4, (int) ProductStock::where('product_id', $laptop->id)->where('location_id', $loc1->id)->value('quantity'));
        $this->assertEquals(9, (int) ProductStock::where('product_id', $mouse->id)->where('location_id', $loc2->id)->value('quantity'));
        $this->assertEquals(9, (int) ProductStock::where('product_id', $mousepad->id)->where('location_id', $loc3->id)->value('quantity'));

        // Receipt verification: Shows full overridden parent price and zero components
        $receiptService = app(PosReceiptService::class);
        $receiptData = $receiptService->getReceiptData($checkout);

        // Authoritative persisted payment assertions
        $this->assertEquals(5500000.0, (float) $checkout->paid_total);
        $this->assertEquals(0.0, (float) $checkout->change_total);
        $this->assertEquals($methods['cash']->id, $checkout->payment_method_id);

        // Assert payment record exists and reconciles with customer payment, Sales aggregate, and receipt
        $this->assertEquals(5500000.0, $totalSalesSum);
        $this->assertEquals(5500000.0, (float) $receiptData['grand_total']);
        $this->assertEquals((float) $checkout->paid_total, $totalSalesSum);
        $this->assertEquals($totalSalesSum, (float) $receiptData['grand_total']);
        $this->assertEquals($expectedTaxSetting1, (float) $receiptData['tax']);

        $receiptLine = $receiptData['lines'][0];
        $this->assertEquals(5500000.0, (float) $receiptLine['sub_total']);
        $this->assertCount(2, $receiptLine['bundle_composition']);
        $compNames = array_column($receiptLine['bundle_composition'], 'name');
        $this->assertContains('MOUSE-OVR NAME', $compNames);
        $this->assertContains('MOUSEPAD-OVR NAME', $compNames);
    }

    /**
     * Multi-quantity allocation with zero price component:
     * Component quantities = parent outgoing base-unit qty * quantity per bundle.
     * Minor-unit exact reconciliation.
     */
    public function test_multi_quantity_bundle_allocation_and_zero_price_snapshot(): void
    {
        $setting1 = $this->createSetting('Setting 1 Multi', 'DOC1', 'SO1', true);
        $setting2 = $this->createSetting('Setting 2 Multi', 'DOC2', 'SO2', false);

        $cashier = $this->createUserForSetting($setting1, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment'
        ]);

        $loc1 = Location::create(['name' => 'LOC-1', 'setting_id' => $setting1->id]);
        $loc2 = Location::create(['name' => 'LOC-2', 'setting_id' => $setting2->id]);

        $this->createTerminalAndSaleLocations($setting1, [$loc1, $loc2]);
        $methods = $this->seedPaymentMethods($setting1, true);
        $this->openSession($setting1, PosTerminal::where('setting_id', $setting1->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting1);

        $tax11 = Tax::query()->create(['name' => 'PPN 11%', 'value' => 11, 'is_default' => true]);

        $parent = $this->createStockedProduct($setting1, $loc1, 'PARENT-MULTI', 500000, 10, $tax11);
        $compA = $this->createStockedProduct($setting1, $loc1, 'COMP-A-MULTI', 120000, 20, $tax11);
        $compZero = $this->createStockedProduct($setting2, $loc2, 'COMP-ZERO-MULTI', 50000, 20, null);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $setting1->id,
            'name' => 'MULTI BUNDLE',
            'bundle_sale_price' => 450000,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $compA->id,
            'quantity' => 1,
            'informational_item_price' => 100000,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $compZero->id,
            'quantity' => 2,
            'informational_item_price' => 0, // Zero price snapshot must be preserved
        ]);

        // Add 3 bundles
        // Total price = 3 * 450,000 = 1,350,000
        // Comp A alloc = 3 * 1 * 100,000 = 300,000
        // Comp Zero alloc = 3 * 2 * 0 = 0
        // Parent residual = 1,350,000 - 300,000 - 0 = 1,050,000
        $this->addCartLine($cashier, $setting1, $parent->id, 3, $bundle->id);
        $this->selectCustomerInCart($cashier, $setting1, $customer);

        $response = $this->finalize($cashier, $setting1, [
            'idempotency_key' => 'K-MULTI-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 1350000,
            ],
        ]);

        $response->assertStatus(201);
        $checkoutId = (int) $response->json('pos_checkout_id');
        $checkout = PosCheckout::with(['checkoutSales.sale.saleDetails.bundleItems'])->findOrFail($checkoutId);

        $totalSalesAmount = (float) $checkout->checkoutSales->sum(fn ($cs) => (float) $cs->sale->total_amount);
        $this->assertEquals(1350000.0, $totalSalesAmount);

        $allBundleItems = SaleBundleItem::whereIn('sale_id', $checkout->checkoutSales->pluck('sale_id'))->get();
        $itemZero = $allBundleItems->firstWhere('product_id', $compZero->id);

        $this->assertNotNull($itemZero);
        $this->assertEquals(6, (int) $itemZero->quantity); // 3 * 2
        $this->assertEquals(0.0, (float) $itemZero->sub_total); // 0 price preserved without catalog fallback

        // Inventory decrements: Parent 10 - 3 = 7; CompA 20 - 3 = 17; CompZero 20 - 6 = 14
        $this->assertEquals(7, (int) ProductStock::where('product_id', $parent->id)->value('quantity'));
        $this->assertEquals(17, (int) ProductStock::where('product_id', $compA->id)->value('quantity'));
        $this->assertEquals(14, (int) ProductStock::where('product_id', $compZero->id)->value('quantity'));
    }

    /**
     * Multi-source bundle checkout posting:
     * Proves that planned owner amounts survive finalize/posting and reconcile
     * through tax, payment, receipt, and inventory.
     * 3 parent units priced at 1,000,001 (total 3,000,003).
     * Comp allocated at 3 * 1 * 100,000 = 300,000.
     * Parent residual = 2,700,003.
     * Parent stock sourced from Loc 2 (2 units) and Loc 1 (1 unit).
     * Planned owner totals:
     * - Loc 2 (Setting 2 Non-PKP, 2 units): 1,800,002.00
     * - Loc 1 (Setting 1 PKP, 1 unit): 900,001.00
     * - Loc 3 Comp (Setting 3 Non-PKP, 3 units): 300,000.00
     * Aggregate generated Sales total = 1,800,002 + 900,001 + 300,000 = 3,000,003.00 exact.
     * Tax: Setting 1 PKP tax is extracted solely from 900,001.00.
     */
    public function test_multi_source_bundle_posting_preserves_planned_amounts_and_reconciles_tax_and_payment(): void
    {
        $setting1 = $this->createSetting('Setting 1 Round PKP', 'DOC1', 'SO1', true);
        $setting2 = $this->createSetting('Setting 2 Round NonPKP', 'DOC2', 'SO2', false);
        $setting3 = $this->createSetting('Setting 3 Round Comp', 'DOC3', 'SO3', false);

        $cashier = $this->createUserForSetting($setting1, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment'
        ]);

        $loc1 = Location::create(['name' => 'LOC-1', 'setting_id' => $setting1->id]);
        $loc2 = Location::create(['name' => 'LOC-2', 'setting_id' => $setting2->id]);
        $loc3 = Location::create(['name' => 'LOC-3', 'setting_id' => $setting3->id]);

        $this->createTerminalAndSaleLocations($setting1, [$loc2, $loc1, $loc3]);
        $methods = $this->seedPaymentMethods($setting1, true);
        $this->openSession($setting1, PosTerminal::where('setting_id', $setting1->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting1);

        $tax11 = Tax::query()->create(['name' => 'PPN 11%', 'value' => 11, 'is_default' => true]);

        // Stock parent: 2 at Loc 2 (Setting 2 Non-PKP), 5 at Loc 1 (Setting 1 PKP)
        $parent = $this->createStockedProduct($setting1, $loc1, 'PARENT-ROUND', 1000001, 5, $tax11);
        $parent->update(['product_quantity' => 10]);

        ProductStock::create([
            'product_id' => $parent->id,
            'location_id' => $loc2->id,
            'quantity' => 2,
            'quantity_tax' => 0,
            'quantity_non_tax' => 2,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'tax_id' => null,
        ]);

        $comp = $this->createStockedProduct($setting3, $loc3, 'COMP-ROUND', 100000, 10, null);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $setting1->id,
            'name' => 'ROUNDING BUNDLE',
            'bundle_sale_price' => 1000001,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $comp->id,
            'quantity' => 1,
            'informational_item_price' => 100000,
        ]);

        // Add 3 bundles -> allocates 2 from Loc 2 (Non-PKP non-tax bucket first) + 1 from Loc 1 (PKP tax bucket)
        $this->addCartLine($cashier, $setting1, $parent->id, 3, $bundle->id);
        $this->selectCustomerInCart($cashier, $setting1, $customer);

        $response = $this->finalize($cashier, $setting1, [
            'idempotency_key' => 'K-ROUND-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 3000003,
            ],
        ]);

        $response->assertStatus(201);
        $checkoutId = (int) $response->json('pos_checkout_id');
        $checkout = PosCheckout::with(['checkoutSales.sale'])->findOrFail($checkoutId);
        $sales = $checkout->checkoutSales->map->sale;
        $this->assertCount(3, $sales);

        $sale1 = $sales->firstWhere('setting_id', $setting1->id);
        $sale2 = $sales->firstWhere('setting_id', $setting2->id);
        $sale3 = $sales->firstWhere('setting_id', $setting3->id);

        $this->assertNotNull($sale1);
        $this->assertNotNull($sale2);
        $this->assertNotNull($sale3);

        // Assert exact planned shares (Loc 2 Non-PKP gets 2 units = 1,800,002; Loc 1 PKP gets 1 unit = 900,001; Loc 3 Comp = 300,000)
        $this->assertEquals(900001.0, (float) $sale1->total_amount);
        $this->assertEquals(1800002.0, (float) $sale2->total_amount);
        $this->assertEquals(300000.0, (float) $sale3->total_amount);

        // Aggregate reconciles exactly
        $totalSalesSum = (float) $sale1->total_amount + (float) $sale2->total_amount + (float) $sale3->total_amount;
        $this->assertEquals(3000003.0, $totalSalesSum);

        // Tax extracted only on Setting 1 (900,001.00 included tax 11%)
        // (90000100 * 1100) / 11100 = 8918928 minor = 89,189.0
        $expectedTax = 89189.0;
        $this->assertEquals($expectedTax, (float) $sale1->tax_amount);
        $this->assertEquals(0.0, (float) $sale2->tax_amount);
        $this->assertEquals(0.0, (float) $sale3->tax_amount);

        // Receipt grand total and tax match checkout and payments
        $receiptService = app(PosReceiptService::class);
        $receiptData = $receiptService->getReceiptData($checkout);

        $this->assertEquals(3000003.0, (float) $receiptData['grand_total']);
        $this->assertEquals($expectedTax, (float) $receiptData['tax']);

        // Authoritative persisted payment assertions
        $this->assertEquals(3000003.0, (float) $checkout->paid_total);
        $this->assertEquals(0.0, (float) $checkout->change_total);
        $this->assertEquals($methods['cash']->id, $checkout->payment_method_id);

        // Assert customer payment total, aggregate Sales total, and receipt grand total are equal
        $this->assertEquals((float) $checkout->paid_total, $totalSalesSum);
        $this->assertEquals($totalSalesSum, (float) $receiptData['grand_total']);

        // Assert aggregate owner taxes match checkout/receipt tax
        $totalOwnerTax = (float) $sale1->tax_amount + (float) $sale2->tax_amount + (float) $sale3->tax_amount;
        $this->assertEquals((float) $receiptData['tax'], $totalOwnerTax);
        $this->assertEquals((float) $checkout->tax_total, $totalOwnerTax);

        // Stock decrements: Parent 2 - 2 = 0 at loc2; 5 - 1 = 4 at loc1; Comp 10 - 3 = 7 at loc3
        $this->assertEquals(4, (int) ProductStock::where('product_id', $parent->id)->where('location_id', $loc1->id)->value('quantity'));
        $this->assertEquals(0, (int) ProductStock::where('product_id', $parent->id)->where('location_id', $loc2->id)->value('quantity'));
        $this->assertEquals(7, (int) ProductStock::where('product_id', $comp->id)->where('location_id', $loc3->id)->value('quantity'));
    }

    /**
     * Parent amount below fixed component allocations fails atomically with BUNDLE_RESIDUAL_NEGATIVE.
     */
    public function test_parent_amount_below_component_allocations_fails_with_bundle_residual_negative(): void
    {
        $setting1 = $this->createSetting('Setting 1 Neg', 'DOC1', 'SO1', true);
        $cashier = $this->createUserForSetting($setting1, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment'
        ]);

        $loc1 = Location::create(['name' => 'LOC-1', 'setting_id' => $setting1->id]);
        $this->createTerminalAndSaleLocations($setting1, [$loc1]);
        $methods = $this->seedPaymentMethods($setting1, true);
        $this->openSession($setting1, PosTerminal::where('setting_id', $setting1->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting1);

        $tax11 = Tax::query()->create(['name' => 'PPN 11%', 'value' => 11, 'is_default' => true]);

        $parent = $this->createStockedProduct($setting1, $loc1, 'PARENT-NEG', 100000, 5, $tax11);
        $comp = $this->createStockedProduct($setting1, $loc1, 'COMP-NEG', 100000, 5, $tax11);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $setting1->id,
            'name' => 'NEG BUNDLE',
            'bundle_sale_price' => 100000,
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $comp->id,
            'quantity' => 1,
            'informational_item_price' => 120000, // 120,000 > 100,000
        ]);

        $this->addCartLine($cashier, $setting1, $parent->id, 1, $bundle->id);
        $this->selectCustomerInCart($cashier, $setting1, $customer);

        $response = $this->finalize($cashier, $setting1, [
            'idempotency_key' => 'K-NEG-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 100000,
            ],
        ]);

        $response->assertStatus(422);
        $this->assertEquals('BUNDLE_RESIDUAL_NEGATIVE', $response->json('code'));
    }

    /**
     * Requirement: A single product (Product X) appearing simultaneously as:
     * 1. Bundle parent (Line 1)
     * 2. Component of that same bundle (Line 1 component)
     * 3. Standalone cart line (Line 2)
     *
     * Proves per-role quantities, stock deduction, Sale details, bundle items, and aggregate revenue
     * remain isolated without cross-role leakage or double-allocation.
     */
    public function test_same_sku_across_bundle_parent_component_and_standalone_lines(): void
    {
        $setting1 = $this->createSetting('Setting 1 SameSKU', 'DOC1', 'SO1', true);

        $cashier = $this->createUserForSetting($setting1, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment'
        ]);

        $loc1 = Location::create(['name' => 'LOC-1', 'setting_id' => $setting1->id]);

        $this->createTerminalAndSaleLocations($setting1, [$loc1]);
        $methods = $this->seedPaymentMethods($setting1, true);
        $this->openSession($setting1, PosTerminal::where('setting_id', $setting1->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting1);

        $tax11 = Tax::query()->create(['name' => 'PPN 11%', 'value' => 11, 'is_default' => true]);

        // Product X: Sale price 1,000,000, 10 units in stock
        $prodX = $this->createStockedProduct($setting1, $loc1, 'PROD-X', 1000000, 10, $tax11);

        // Bundle: Parent is Prod X, and it contains 1 unit of Prod X as a self-component
        // Bundle sale price = 1,000,000; Informational price for component = 300,000
        $bundle = ProductBundle::create([
            'parent_product_id' => $prodX->id,
            'setting_id' => $setting1->id,
            'name' => 'SELF BUNDLE X',
            'bundle_sale_price' => 1000000,
        ]);
        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $prodX->id,
            'quantity' => 1,
            'informational_item_price' => 300000,
        ]);

        // Cart contains:
        // Line 1: Bundle with Prod X (1 parent + 1 component = 2 units of Prod X consumed) -> 1,000,000
        // Line 2: Standalone Prod X qty 3 (3 units of Prod X consumed) -> 3,000,000
        // Total Prod X consumed = 2 + 3 = 5 units
        $this->addCartLine($cashier, $setting1, $prodX->id, 1, $bundle->id);
        $this->addCartLine($cashier, $setting1, $prodX->id, 3, null);
        $this->selectCustomerInCart($cashier, $setting1, $customer);

        $response = $this->finalize($cashier, $setting1, [
            'idempotency_key' => 'K-SAME-SKU-SINGLE-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 4000000,
            ],
        ]);

        $response->assertStatus(201);
        $checkoutId = (int) $response->json('pos_checkout_id');
        $checkout = PosCheckout::with([
            'checkoutSales.sale.saleDetails.bundleItems',
        ])->findOrFail($checkoutId);

        $sales = $checkout->checkoutSales->map->sale;
        $this->assertCount(1, $sales);

        $sale = $sales->first();
        $this->assertEquals(4000000.0, (float) $sale->total_amount);

        // Verify stock deduction: 10 initial - 5 sold = 5 remaining
        $stock = ProductStock::where('product_id', $prodX->id)->where('location_id', $loc1->id)->first();
        $this->assertEquals(5, $stock->quantity);

        // Verify sale details:
        // 1. Bundle parent detail (qty 1) with subtotal 1,000,000 (captured bundle price)
        // 2. Standalone detail (qty 3) with subtotal 3,000,000
        $details = $sale->saleDetails;
        $this->assertCount(2, $details);

        $parentDetail = $details->firstWhere('quantity', 1);
        $standaloneDetail = $details->firstWhere('quantity', 3);

        $this->assertNotNull($parentDetail);
        $this->assertNotNull($standaloneDetail);

        $this->assertEquals(1, (int) $parentDetail->quantity);
        $this->assertEquals(1000000.0, (float) $parentDetail->sub_total);

        $this->assertEquals(3, (int) $standaloneDetail->quantity);
        $this->assertEquals(3000000.0, (float) $standaloneDetail->sub_total);

        // Verify sale bundle items: 1 child component item for Prod X with informational price
        // 300,000. Its captured internal allocation (price/sub_total) now persists as non-zero
        // nested allocation identity within the parent detail's 1,000,000 captured bundle price
        // (single-owner posting: no split, so this is inline persistence, not split posting).
        $bundleItems = $parentDetail->bundleItems;
        $this->assertCount(1, $bundleItems);
        $bi = $bundleItems->first();
        $this->assertEquals($prodX->id, $bi->product_id);
        $this->assertEquals(1, $bi->quantity);
        $this->assertEquals(300000.0, (float) $bi->informational_item_price);
        $this->assertEquals(300000.0, (float) $bi->price);
        $this->assertEquals(300000.0, (float) $bi->sub_total);
    }

    /**
     * Requirement: Multi-owner plan with split_posting feature flag disabled MUST still route to split posting.
     */
    public function test_disabled_feature_flag_forces_split_posting_for_cross_owner_plan(): void
    {
        config(['pos.checkout.split_posting.enabled' => false]);

        $setting1 = $this->createSetting('Setting 1 FlagTest', 'DOC1', 'SO1', true);
        $setting2 = $this->createSetting('Setting 2 FlagTest', 'DOC2', 'SO2', false);

        $cashier = $this->createUserForSetting($setting1, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment'
        ]);

        $loc1 = Location::create(['name' => 'LOC-1', 'setting_id' => $setting1->id]);
        $loc2 = Location::create(['name' => 'LOC-2', 'setting_id' => $setting2->id]);

        $this->createTerminalAndSaleLocations($setting1, [$loc1, $loc2]);
        $methods = $this->seedPaymentMethods($setting1, true);
        $this->openSession($setting1, PosTerminal::where('setting_id', $setting1->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting1);

        $tax11 = Tax::query()->create(['name' => 'PPN 11%', 'value' => 11, 'is_default' => true]);

        $p1 = $this->createStockedProduct($setting1, $loc1, 'PROD-S1', 100000, 5, $tax11);
        $p2 = $this->createStockedProduct($setting2, $loc2, 'PROD-S2', 200000, 5, null);
        ProductPrice::create([
            'product_id' => $p2->id,
            'setting_id' => $setting1->id,
            'sale_price' => 200000,
            'sale_tax_id' => null,
        ]);

        $this->addCartLine($cashier, $setting1, $p1->id, 1);
        $this->addCartLine($cashier, $setting1, $p2->id, 1);
        $this->selectCustomerInCart($cashier, $setting1, $customer);

        $response = $this->finalize($cashier, $setting1, [
            'idempotency_key' => 'K-FLAG-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 300000,
            ],
        ]);

        $response->assertStatus(201);
        $checkoutId = (int) $response->json('pos_checkout_id');
        $checkout = PosCheckout::with(['checkoutSales.sale'])->findOrFail($checkoutId);

        // Assert that even with config disabled, 2 distinct Sales are created (one per owner setting)
        $sales = $checkout->checkoutSales->map->sale;
        $this->assertCount(2, $sales);
        $this->assertNotNull($sales->firstWhere('setting_id', $setting1->id));
        $this->assertNotNull($sales->firstWhere('setting_id', $setting2->id));
    }

    // ==== HELPER METHODS ====

    private function createSetting(string $name, string $docPrefix, string $salePrefix, bool $isPkp): Setting
    {
        $suffix = $this->sequence++;
        return Setting::create([
            'company_name' => $name . ' ' . $suffix,
            'company_email' => 'test.' . $suffix . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => $docPrefix,
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => $salePrefix,
            'pos_enabled' => true,
            'pos_transactions_enabled' => true,
            'is_pkp' => $isPkp,
        ]);
    }

    private function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => strtoupper($roleName) . '-' . $setting->id]);
        $role->syncPermissions($permissions);
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);
        return $user;
    }

    private function createTerminalAndSaleLocations(Setting $setting, array $locations): void
    {
        SettingSaleLocation::query()->where('setting_id', $setting->id)->delete();
        foreach ($locations as $index => $loc) {
            SettingSaleLocation::create([
                'setting_id' => $setting->id,
                'location_id' => $loc->id,
                'is_enabled' => true,
                'position' => $index + 1
            ]);
        }
        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'T-' . $this->sequence++,
            'name' => 'Terminal',
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => true,
            'cash_threshold' => 50000,
        ]);
    }

    private function openSession(Setting $setting, PosTerminal $terminal, User $cashier)
    {
        return app(PosSessionLifecycleService::class)->openSession(
            $setting->id,
            $terminal->id,
            $cashier->id,
            100000,
            ['100000' => 1],
            $cashier->id
        );
    }

    private function assignDefaultWalkInCustomer(Setting $setting): Customer
    {
        $customer = Customer::factory()->create(['setting_id' => $setting->id]);
        $setting->update(['pos_walk_in_customer_id' => $customer->id]);
        return $customer;
    }

    private function createStockedProduct(Setting $setting, Location $location, string $code, float $salePrice, int $qty, ?Tax $tax): Product
    {
        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => Category::create([
                'category_name' => 'CAT-' . $code,
                'category_code' => 'CODE-' . $code,
                'setting_id' => $setting->id,
                'created_by' => 1,
            ])->id,
            'unit_id' => Unit::firstOrCreate(['name' => 'UNIT', 'short_name' => 'U'])->id,
            'base_unit_id' => Unit::firstOrCreate(['name' => 'UNIT', 'short_name' => 'U'])->id,
            'product_name' => $code . ' NAME',
            'product_code' => $code,
            'barcode' => 'BAR-' . $code,
            'product_quantity' => $qty,
            'product_cost' => 10000,
            'product_price' => $salePrice,
            'product_unit' => 'U',
            'product_stock_alert' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $qty,
            'quantity_tax' => $tax ? $qty : 0,
            'quantity_non_tax' => $tax ? 0 : $qty,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'tax_id' => $tax?->id,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => $salePrice,
            'sale_tax_id' => $tax?->id,
        ]);

        return $product;
    }

    private function seedPaymentMethods(Setting $setting, bool $enableForSetting = false): array
    {
        $coaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'COA ' . $this->sequence++,
            'account_number' => 'ACC-' . $this->sequence++,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
        ]);

        $method = PaymentMethod::create(['name' => 'CASH', 'coa_id' => $coaId, 'is_cash' => true]);

        if ($enableForSetting) {
            DB::table('setting_pos_payment_methods')->insert(['setting_id' => $setting->id, 'payment_method_id' => $method->id, 'is_enabled' => true]);
        }

        return ['cash' => $method];
    }

    private function addCartLine(User $cashier, Setting $setting, int $productId, int $qty, ?int $bundleId = null): void
    {
        $payload = ['product_id' => $productId, 'qty' => $qty];
        if ($bundleId) $payload['bundle_id'] = $bundleId;

        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])->postJson(route('pos.sell.cart.lines.store'), $payload)->assertOk();
    }

    private function selectCustomerInCart(User $cashier, Setting $setting, Customer $customer): void
    {
        $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $customer->id])->assertOk();
    }

    private function finalize(User $cashier, Setting $setting, array $payload)
    {
        return $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])->postJson(route('pos.sell.checkout.finalize'), $payload);
    }
}

