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
use Modules\Pos\Entities\PosCheckoutSale;
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
use Modules\Sale\Entities\SaleDetails;
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

/**
 * @group pos-split-bundle
 */
class SplitBundleTransactionTest extends TestCase
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
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_split_bundle_transaction_correctly_calculates_ownership_and_prices(): void
    {
        // 1. Setup Context (Terminal Setting and Source Setting)
        $terminalSetting = $this->createSetting('TERMINAL BIZ', 'T-DOC', 'T-SO');
        $sourceSetting = $this->createSetting('SOURCE BIZ', 'S-DOC', 'S-SO');

        $cashier = $this->createUserForSetting($terminalSetting, 'cashier', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
        ]);

        $locTerminal = Location::create(['name' => 'TERMINAL LOC', 'setting_id' => $terminalSetting->id]);
        $locSource = Location::create(['name' => 'SOURCE LOC', 'setting_id' => $sourceSetting->id]);

        $this->createTerminalAndSaleLocations($terminalSetting, [$locTerminal, $locSource]);
        $methods = $this->seedPaymentMethods($terminalSetting, true);
        $this->openSession($terminalSetting, PosTerminal::where('setting_id', $terminalSetting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);
        $this->assignDefaultWalkInCustomer($sourceSetting);

        $tax = Tax::query()->create(['name' => 'VAT 11', 'value' => 11, 'is_default' => true]);

        // 2. Create Bundle and Components
        // Parent: 100k
        // Comp A: 25k (Owned by Terminal)
        // Comp B: 50k (Owned by Source)
        // Bundle Price: 175k (Residual: 100k)
        $parent = $this->createStockedProduct($terminalSetting, $locTerminal, 'PARENT', 100000, 1, $tax);
        $compA = $this->createStockedProduct($terminalSetting, $locTerminal, 'COMP-A', 0, 1, $tax);
        $compB = $this->createStockedProduct($sourceSetting, $locSource, 'COMP-B', 0, 1, $tax);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $terminalSetting->id,
            'name' => 'Test Bundle',
            'bundle_sale_price' => 175000, // Total price (Parent 100k + Components 75k)
            'price' => 75000,
        ]);

        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compA->id, 'quantity' => 1, 'informational_item_price' => 25000]);
        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compB->id, 'quantity' => 1, 'informational_item_price' => 50000]);

        // Total price = 100,000 (Parent) + 75,000 (Bundle) = 175,000

        // 3. Perform Checkout
        $this->addCartLine($cashier, $terminalSetting, $parent->id, 1, $bundle->id);
        $this->selectCustomerInCart($cashier, $terminalSetting, $customer);

        $response = $this->finalize($cashier, $terminalSetting, [
            'idempotency_key' => 'K-BUNDLE-SPLIT-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 175000,
            ],
        ]);

        $response->assertStatus(201);
        $payload = $response->json();
        $this->assertCount(2, $payload['split_groups']);

        // 4. Verify Sale Records
        // Group 1: Terminal Setting (owns Parent + Comp A) -> Subtotal = 100k (residual) + 25k (Comp A) = 125k
        // Group 2: Source Setting (owns Comp B) -> Subtotal = 50k (Comp B)

        $saleIds = array_column($payload['split_groups'], 'sale_id');
        $sales = Sale::with(['saleDetails.bundleItems'])->whereIn('id', $saleIds)->get()->keyBy('setting_id');

        $saleTerminal = $sales->get($terminalSetting->id);
        $saleSource = $sales->get($sourceSetting->id);

        $this->assertNotNull($saleTerminal);
        $this->assertNotNull($saleSource);

        // Terminal Sale Verification
        $this->assertCount(1, $saleTerminal->saleDetails);
        $detailT = $saleTerminal->saleDetails->first();
        $this->assertEquals(1, $detailT->quantity);
        // unit_price must reflect only the parent residual share (100k),
        // NOT the full group subtotal (125k = 100k parent + 25k Comp A).
        $this->assertEquals(100000.0, (float)$detailT->unit_price, 'Parent unit_price should be parent residual, not full group subtotal');
        $this->assertEquals(125000.0, (float)$detailT->sub_total); // 100k residual + 25k Comp A
        $this->assertCount(1, $detailT->bundleItems);
        $biA = $detailT->bundleItems->first();
        $this->assertEquals($compA->id, $biA->product_id);
        $this->assertEquals(1, $biA->quantity);
        $this->assertEquals(25000.0, (float)$biA->sub_total);

        // Source Sale Verification
        $this->assertCount(1, $saleSource->saleDetails);
        $detailS = $saleSource->saleDetails->first();
        $this->assertEquals(0, $detailS->quantity); // New requirement: Qty 0 for parent when not owned
        $this->assertEquals(0, (float)$detailS->unit_price); // New requirement: Price 0 for parent when not owned
        $this->assertEquals(50000.0, (float)$detailS->sub_total); // Still has subtotal from components
        $this->assertCount(1, $detailS->bundleItems);
        $biB = $detailS->bundleItems->first();
        $this->assertEquals($compB->id, $biB->product_id);
        $this->assertEquals(1, $biB->quantity);
        $this->assertEquals(50000.0, (float)$biB->sub_total);
        $this->assertEquals(50000.0, (float)$biB->price); // Actual price persisted

        // Verify Tax
        // Bundled revenue is taxable ONLY for the PKP POS transaction owner's own group
        // allocation (Terminal: 125,000 = 100k residual + 25k Comp A), not the full
        // customer-facing bundle price. VAT 11% included: 125,000 * 11 / 111 = 12387.39 -> 12387.
        // The Source setting's group (Comp B, 50,000) is a non-tax source-owner allocation.
        $this->assertEquals(12387.0, round((float) $saleTerminal->tax_amount, 2));
        $this->assertEquals(0.0, round((float) $saleSource->tax_amount, 2));

        // 5. Verify Receipt Presentation: persisted internal component allocations must not
        // leak into the customer-facing receipt. Parent shows the full captured bundle price
        // once; components remain zero/free (composition entries carry no price fields).
        $checkout = PosCheckout::findOrFail((int) $payload['pos_checkout_id']);
        $receiptData = app(PosReceiptService::class)->getReceiptData($checkout);
        $this->assertCount(1, $receiptData['lines']);
        $this->assertEquals(175000.0, (float) $receiptData['lines'][0]['sub_total']);
        $compositionKeys = array_keys($receiptData['lines'][0]['bundle_composition'][0] ?? []);
        $this->assertNotContains('price', $compositionKeys);
        $this->assertNotContains('sub_total', $compositionKeys);
    }

    public function test_split_bundle_multi_quantity_allocation_persists_rounding_sensitive_component_subtotals(): void
    {
        $terminalSetting = $this->createSetting('TERMINAL QTY BIZ', 'T-DOC', 'T-SO');
        $sourceSetting = $this->createSetting('SOURCE QTY BIZ', 'S-DOC', 'S-SO');

        $cashier = $this->createUserForSetting($terminalSetting, 'cashier-qty', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
        ]);

        $locTerminal = Location::create(['name' => 'TERMINAL QTY LOC', 'setting_id' => $terminalSetting->id]);
        $locSource = Location::create(['name' => 'SOURCE QTY LOC', 'setting_id' => $sourceSetting->id]);

        $this->createTerminalAndSaleLocations($terminalSetting, [$locTerminal, $locSource]);
        $methods = $this->seedPaymentMethods($terminalSetting, true);
        $this->openSession($terminalSetting, PosTerminal::where('setting_id', $terminalSetting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);
        $this->assignDefaultWalkInCustomer($sourceSetting);

        $tax = Tax::query()->create(['name' => 'VAT 11', 'value' => 11, 'is_default' => true]);

        // Component B is priced so its total (across 3 bundles) does not divide evenly by 3,
        // exercising the minor-unit rounding path: 10,000 / 3 = 3333.33...
        $parent = $this->createStockedProduct($terminalSetting, $locTerminal, 'PARENT-QTY', 60000, 3, $tax);
        $compB = $this->createStockedProduct($sourceSetting, $locSource, 'COMP-B-QTY', 0, 3, $tax);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $terminalSetting->id,
            'name' => 'Qty Bundle',
            'bundle_sale_price' => 70000,
            'price' => 10000,
        ]);

        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compB->id, 'quantity' => 1, 'informational_item_price' => 10000]);

        $this->addCartLine($cashier, $terminalSetting, $parent->id, 3, $bundle->id);
        $this->selectCustomerInCart($cashier, $terminalSetting, $customer);

        $response = $this->finalize($cashier, $terminalSetting, [
            'idempotency_key' => 'K-BUNDLE-QTY-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 210000,
            ],
        ]);

        $response->assertStatus(201);
        $payload = $response->json();

        $saleIds = array_column($payload['split_groups'], 'sale_id');
        $sales = Sale::with(['saleDetails.bundleItems'])->whereIn('id', $saleIds)->get()->keyBy('setting_id');

        $saleSource = $sales->get($sourceSetting->id);
        $this->assertNotNull($saleSource);

        $sourceDetail = $saleSource->saleDetails->sole();
        $sourceBundleItem = $sourceDetail->bundleItems->sole();

        // Exact captured allocation for Comp B across 3 fulfilled bundles = 3 * 10,000 = 30,000.
        // sub_total must be the exact minor-unit sum; price is derived without losing that exactness.
        $this->assertEquals(3, $sourceBundleItem->quantity);
        $this->assertEquals(30000.0, (float) $sourceBundleItem->sub_total);
        $this->assertEqualsWithDelta(10000.0, (float) $sourceBundleItem->price, 0.01);
        $this->assertEqualsWithDelta(30000.0, (float) $sourceBundleItem->price * $sourceBundleItem->quantity, 1.0);
    }

    public function test_split_bundle_same_owner_nested_allocation_persists_component_subtotal_without_split(): void
    {
        $setting = $this->createSetting('SAME OWNER BIZ', 'T-DOC', 'T-SO');

        $cashier = $this->createUserForSetting($setting, 'cashier-same', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
        ]);

        $loc = Location::create(['name' => 'SAME OWNER LOC', 'setting_id' => $setting->id]);
        $this->createTerminalAndSaleLocations($setting, [$loc]);
        $methods = $this->seedPaymentMethods($setting, true);
        $this->openSession($setting, PosTerminal::where('setting_id', $setting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);

        $tax = Tax::query()->create(['name' => 'VAT 11', 'value' => 11, 'is_default' => true]);

        $parent = $this->createStockedProduct($setting, $loc, 'PARENT-SAME', 80000, 1, $tax);
        $compA = $this->createStockedProduct($setting, $loc, 'COMP-A-SAME', 0, 1, $tax);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $setting->id,
            'name' => 'Same Owner Bundle',
            'bundle_sale_price' => 100000,
            'price' => 20000,
        ]);

        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compA->id, 'quantity' => 1, 'informational_item_price' => 20000]);

        $this->addCartLine($cashier, $setting, $parent->id, 1, $bundle->id);
        $this->selectCustomerInCart($cashier, $setting, $customer);

        $response = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-BUNDLE-SAME-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 100000,
            ],
        ]);

        $response->assertStatus(201);
        $payload = $response->json();
        $this->assertCount(1, $payload['split_groups']);

        $sale = Sale::with(['saleDetails.bundleItems'])->findOrFail((int) $payload['split_groups'][0]['sale_id']);
        $detail = $sale->saleDetails->sole();
        $bundleItem = $detail->bundleItems->sole();

        $this->assertEquals(1, $detail->quantity);
        $this->assertEquals(80000.0, (float) $detail->unit_price);
        $this->assertEquals(100000.0, (float) $detail->sub_total);
        $this->assertEquals(20000.0, (float) $bundleItem->sub_total);
        $this->assertEquals(20000.0, (float) $bundleItem->price);
    }

    public function test_split_bundle_idempotent_replay_does_not_duplicate_component_allocation_rows(): void
    {
        $terminalSetting = $this->createSetting('TERMINAL REPLAY BIZ', 'T-DOC', 'T-SO');
        $sourceSetting = $this->createSetting('SOURCE REPLAY BIZ', 'S-DOC', 'S-SO');

        $cashier = $this->createUserForSetting($terminalSetting, 'cashier-replay', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
        ]);

        $locTerminal = Location::create(['name' => 'TERMINAL REPLAY LOC', 'setting_id' => $terminalSetting->id]);
        $locSource = Location::create(['name' => 'SOURCE REPLAY LOC', 'setting_id' => $sourceSetting->id]);

        $this->createTerminalAndSaleLocations($terminalSetting, [$locTerminal, $locSource]);
        $methods = $this->seedPaymentMethods($terminalSetting, true);
        $this->openSession($terminalSetting, PosTerminal::where('setting_id', $terminalSetting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);
        $this->assignDefaultWalkInCustomer($sourceSetting);

        $tax = Tax::query()->create(['name' => 'VAT 11', 'value' => 11, 'is_default' => true]);

        $parent = $this->createStockedProduct($terminalSetting, $locTerminal, 'PARENT-REPLAY', 100000, 1, $tax);
        $compB = $this->createStockedProduct($sourceSetting, $locSource, 'COMP-B-REPLAY', 0, 1, $tax);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $terminalSetting->id,
            'name' => 'Replay Bundle',
            'bundle_sale_price' => 150000,
            'price' => 50000,
        ]);

        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compB->id, 'quantity' => 1, 'informational_item_price' => 50000]);

        $this->addCartLine($cashier, $terminalSetting, $parent->id, 1, $bundle->id);
        $this->selectCustomerInCart($cashier, $terminalSetting, $customer);

        $payload = [
            'idempotency_key' => 'K-BUNDLE-REPLAY-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 150000,
            ],
        ];

        $first = $this->finalize($cashier, $terminalSetting, $payload);
        $first->assertStatus(201)->assertJsonPath('idempotent_replay', false);

        $second = $this->finalize($cashier, $terminalSetting, $payload);
        $second->assertStatus(200)->assertJsonPath('idempotent_replay', true);

        $this->assertDatabaseCount('sale_bundle_items', 1);

        $saleIds = array_column($first->json()['split_groups'], 'sale_id');
        $sourceSale = Sale::with('saleDetails.bundleItems')->whereIn('id', $saleIds)->get()->keyBy('setting_id')->get($sourceSetting->id);
        $bundleItem = $sourceSale->saleDetails->sole()->bundleItems->sole();

        $this->assertEquals(50000.0, (float) $bundleItem->sub_total);
        $this->assertEquals(50000.0, (float) $bundleItem->price);
    }

    public function test_split_bundle_failure_in_later_owner_group_leaves_no_partial_posting(): void
    {
        $terminalSetting = $this->createSetting('TERMINAL ATOMIC BIZ', 'T-DOC', 'T-SO');
        $sourceSetting = $this->createSetting('SOURCE ATOMIC BIZ', 'S-DOC', 'S-SO');

        $cashier = $this->createUserForSetting($terminalSetting, 'cashier-atomic', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
        ]);

        $locTerminal = Location::create(['name' => 'TERMINAL ATOMIC LOC', 'setting_id' => $terminalSetting->id]);
        $locSource = Location::create(['name' => 'SOURCE ATOMIC LOC', 'setting_id' => $sourceSetting->id]);

        $this->createTerminalAndSaleLocations($terminalSetting, [$locTerminal, $locSource]);
        $methods = $this->seedPaymentMethods($terminalSetting, true);
        $this->openSession($terminalSetting, PosTerminal::where('setting_id', $terminalSetting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);
        $this->assignDefaultWalkInCustomer($sourceSetting);

        $tax = Tax::query()->create(['name' => 'VAT 11', 'value' => 11, 'is_default' => true]);

        $parent = $this->createStockedProduct($terminalSetting, $locTerminal, 'PARENT-ATOMIC', 100000, 1, $tax);
        // Component B has zero stock at its owning source location, forcing a
        // STOCK_UNAVAILABLE failure while resolving the second owner group,
        // after the first (terminal) owner group has already been posted in-memory.
        $compB = $this->createStockedProduct($sourceSetting, $locSource, 'COMP-B-ATOMIC', 0, 0, $tax);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $terminalSetting->id,
            'name' => 'Atomic Bundle',
            'bundle_sale_price' => 150000,
            'price' => 50000,
        ]);

        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compB->id, 'quantity' => 1, 'informational_item_price' => 50000]);

        $this->addCartLine($cashier, $terminalSetting, $parent->id, 1, $bundle->id);
        $this->selectCustomerInCart($cashier, $terminalSetting, $customer);

        $response = $this->finalize($cashier, $terminalSetting, [
            'idempotency_key' => 'K-BUNDLE-ATOMIC-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 150000,
            ],
        ]);

        $response->assertStatus(422);

        // No partial Sales, bundle items, payments, dispatches, or inventory movements
        // must survive a mid-posting failure — the whole finalize is one DB transaction.
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_bundle_items', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('dispatches', 0);
    }

    public function test_split_bundle_groups_pkp_fallback_components_with_non_pkp_non_tax_components_separately(): void
    {
        $terminalSetting = $this->createSetting('TERMINAL PKP BIZ', 'T-DOC', 'T-SO');
        $sourceSetting = $this->createSetting('SOURCE NON PKP BIZ', 'S-DOC', 'S-SO');
        $sourceSetting->update(['is_pkp' => false]);

        $cashier = $this->createUserForSetting($terminalSetting, 'cashier-fallback', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
        ]);

        $locTerminal = Location::create(['name' => 'TERMINAL FALLBACK LOC', 'setting_id' => $terminalSetting->id]);
        $locSource = Location::create(['name' => 'SOURCE FALLBACK LOC', 'setting_id' => $sourceSetting->id]);

        $this->createTerminalAndSaleLocations($terminalSetting, [$locTerminal, $locSource]);
        $methods = $this->seedPaymentMethods($terminalSetting, true);
        $this->openSession($terminalSetting, PosTerminal::where('setting_id', $terminalSetting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);
        $this->assignDefaultWalkInCustomer($sourceSetting);

        $tax = Tax::query()->create(['name' => 'VAT 11', 'value' => 11, 'is_default' => true]);

        $parent = $this->createStockedProduct($terminalSetting, $locTerminal, 'PARENT-FALLBACK', 100000, 1, $tax);
        $compA = $this->createStockedProduct($terminalSetting, $locTerminal, 'COMP-A-FALLBACK', 0, 1, $tax);
        $compB = $this->createStockedProduct($sourceSetting, $locSource, 'COMP-B-FALLBACK', 0, 1, $tax);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $terminalSetting->id,
            'name' => 'Fallback Bundle',
            'bundle_sale_price' => 175000,
            'price' => 75000,
        ]);

        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compA->id, 'quantity' => 1, 'informational_item_price' => 25000]);
        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compB->id, 'quantity' => 1, 'informational_item_price' => 50000]);

        // parent/compA are owned by the PKP terminal setting, so they may only allocate from
        // quantity_tax; clearing the explicit sale_tax_id here exercises the fallback-tax
        // resolution path once the PKP owner is established.
        foreach ([$parent->id, $compA->id] as $productId) {
            ProductPrice::query()->where('product_id', $productId)->update(['sale_tax_id' => null]);
            ProductStock::query()->where('product_id', $productId)->update([
                'quantity_tax' => 1,
                'quantity_non_tax' => 0,
                'tax_id' => null,
            ]);
        }

        // compB is owned by the non-PKP source setting, so it may only allocate from
        // quantity_non_tax.
        ProductPrice::query()->where('product_id', $compB->id)->update(['sale_tax_id' => null]);
        ProductStock::query()->where('product_id', $compB->id)->update([
            'quantity_tax' => 0,
            'quantity_non_tax' => 1,
            'tax_id' => null,
        ]);

        $this->addCartLine($cashier, $terminalSetting, $parent->id, 1, $bundle->id);
        $this->selectCustomerInCart($cashier, $terminalSetting, $customer);

        $response = $this->finalize($cashier, $terminalSetting, [
            'idempotency_key' => 'K-BUNDLE-FALLBACK-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 175000,
            ],
        ]);

        $response->assertStatus(201);
        $payload = $response->json();
        $this->assertCount(2, $payload['split_groups']);

        $checkout = PosCheckout::with(['checkoutSales.sale.saleDetails.bundleItems.product'])->findOrFail((int) $payload['pos_checkout_id']);
        $storedPayload = $checkout->response_payload;
        $storedSplitGroups = $checkout->split_summary['groups'] ?? [];

        $this->assertSame($payload['receipt_number'], $checkout->receipt_number);
        $this->assertSame($payload['receipt_number'], $storedPayload['receipt_number'] ?? null);
        $this->assertCount(2, $checkout->checkoutSales);
        $this->assertCount(2, $storedSplitGroups);
        $this->assertSame(175000.0, round(array_sum(array_column($payload['split_groups'], 'grand_total')), 2));
        $this->assertSame(175000.0, round((float) $checkout->checkoutSales->sum('grand_total'), 2));
        $this->assertSame(175000.0, round((float) $checkout->checkoutSales->sum('paid_total'), 2));

        $checkoutSalesBySplitKey = $checkout->checkoutSales->keyBy('split_key');
        foreach ($payload['split_groups'] as $group) {
            /** @var PosCheckoutSale|null $checkoutSale */
            $checkoutSale = $checkoutSalesBySplitKey->get($group['split_key']);
            $this->assertNotNull($checkoutSale);
            $this->assertSame($group['tax_bucket'], $checkoutSale->tax_bucket);
            $this->assertSame((int) $group['sale_id'], (int) $checkoutSale->sale_id);
            $this->assertSame((int) $group['sale_payment_id'], (int) $checkoutSale->sale_payment_id);
            $this->assertSame(round((float) $group['grand_total'], 2), round((float) $checkoutSale->grand_total, 2));
            $this->assertSame(round((float) $group['paid_total'], 2), round((float) $checkoutSale->paid_total, 2));
        }

        $receiptData = app(PosReceiptService::class)->getReceiptData($checkout);
        $this->assertSame($payload['receipt_number'], $receiptData['receipt_number']);
        $this->assertCount(1, $receiptData['lines']);
        $compositionNames = array_column($receiptData['lines'][0]['bundle_composition'], 'name');
        $this->assertContains('COMP-A-FALLBACK NAME', $compositionNames);
        $this->assertContains('COMP-B-FALLBACK NAME', $compositionNames);

        $saleIds = array_column($payload['split_groups'], 'sale_id');
        $sales = Sale::with(['saleDetails.bundleItems'])->whereIn('id', $saleIds)->get()->keyBy('setting_id');

        $saleTerminal = $sales->get($terminalSetting->id);
        $saleSource = $sales->get($sourceSetting->id);

        $this->assertNotNull($saleTerminal);
        $this->assertNotNull($saleSource);

        $terminalDetail = $saleTerminal->saleDetails->sole();
        $terminalBundleItem = $terminalDetail->bundleItems->sole();
        $this->assertSame($tax->id, (int) $terminalDetail->tax_id);
        $this->assertGreaterThan(0, (float) $terminalDetail->product_tax_amount);
        $this->assertSame($tax->id, (int) $terminalBundleItem->tax_id);
        $this->assertGreaterThan(0, (float) $terminalBundleItem->tax_amount);

        $sourceDetail = $saleSource->saleDetails->sole();
        $sourceBundleItem = $sourceDetail->bundleItems->sole();
        $this->assertNull($sourceDetail->tax_id);
        $this->assertSame(0.0, (float) $sourceDetail->product_tax_amount);
        $this->assertNull($sourceBundleItem->tax_id);
        $this->assertSame(0.0, (float) $sourceBundleItem->tax_amount);
    }

    // ==== HELPER METHODS ====

    private function createSetting(string $name, string $docPrefix, string $salePrefix): Setting
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
            'is_pkp' => true,
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

    private function createStockedProduct(Setting $setting, Location $location, string $code, float $salePrice, int $qty, Tax $tax): Product
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
            'barcode' => $code . '-BAR',
            'product_quantity' => $qty,
            'product_cost' => 1000,
            'product_price' => $salePrice,
            'product_unit' => 'U',
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $qty,
            'quantity_tax' => $qty,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'tax_id' => $tax->id,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => $salePrice,
            'sale_tax_id' => $tax->id,
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
