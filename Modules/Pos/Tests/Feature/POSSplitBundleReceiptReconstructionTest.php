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
class POSSplitBundleReceiptReconstructionTest extends TestCase
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
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_receipt_shows_complete_composition_for_2_owner_split_bundle(): void
    {
        // 1. Setup Context
        $terminalSetting = $this->createSetting('TERMINAL BIZ', 'T-DOC', 'T-SO');
        $sourceSetting = $this->createSetting('SOURCE BIZ', 'S-DOC', 'S-SO');

        $cashier = $this->createUserForSetting($terminalSetting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment',
        ]);

        $locTerminal = Location::create(['name' => 'TERMINAL LOC', 'setting_id' => $terminalSetting->id]);
        $locSource = Location::create(['name' => 'SOURCE LOC', 'setting_id' => $sourceSetting->id]);

        $this->createTerminalAndSaleLocations($terminalSetting, [$locTerminal, $locSource]);
        $methods = $this->seedPaymentMethods($terminalSetting, true);
        $this->openSession($terminalSetting, PosTerminal::where('setting_id', $terminalSetting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);

        $tax = Tax::query()->create(['name' => 'VAT 11', 'value' => 11, 'is_default' => true]);

        // 2. Create Bundle
        $parent = $this->createStockedProduct($terminalSetting, $locTerminal, 'PARENT', 100000, 1, $tax);
        $compA = $this->createStockedProduct($terminalSetting, $locTerminal, 'COMP-A', 0, 1, $tax);
        $compB = $this->createStockedProduct($sourceSetting, $locSource, 'COMP-B', 0, 1, $tax);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $terminalSetting->id,
            'name' => 'Test Bundle',
            'bundle_sale_price' => 175000,
            'price' => 75000,
        ]);

        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compA->id, 'quantity' => 1, 'informational_item_price' => 25000]);
        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compB->id, 'quantity' => 1, 'informational_item_price' => 50000]);

        // 3. Checkout
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
        $checkoutId = $response->json('pos_checkout_id');
        $checkout = PosCheckout::with(['transactions.lines', 'checkoutSales.sale.saleDetails.bundleItems.product'])->findOrFail($checkoutId);

        // 4. Verify Receipt Data
        $receiptService = app(PosReceiptService::class);
        $receiptData = $receiptService->getReceiptData($checkout);

        $lines = $receiptData['lines'];
        $this->assertCount(1, $lines);
        $bundleLine = $lines[0];
        
        $composition = $bundleLine['bundle_composition'];
        
        $compNames = array_column($composition, 'name');
        $this->assertContains('COMP-A NAME', $compNames, 'Composition should contain COMP-A');
        $this->assertContains('COMP-B NAME', $compNames, 'Composition should contain COMP-B');
        $this->assertCount(2, $composition, 'Composition should have exactly 2 items');
    }

    public function test_receipt_shows_complete_composition_for_3_owner_split_bundle(): void
    {
         // 1. Setup Context
        $terminalSetting = $this->createSetting('TERMINAL BIZ', 'T-DOC', 'T-SO');
        $source1Setting = $this->createSetting('SOURCE1 BIZ', 'S1-DOC', 'S1-SO');
        $source2Setting = $this->createSetting('SOURCE2 BIZ', 'S2-DOC', 'S2-SO');

        $cashier = $this->createUserForSetting($terminalSetting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment',
        ]);

        $locTerminal = Location::create(['name' => 'TERMINAL LOC', 'setting_id' => $terminalSetting->id]);
        $locSource1 = Location::create(['name' => 'SOURCE1 LOC', 'setting_id' => $source1Setting->id]);
        $locSource2 = Location::create(['name' => 'SOURCE2 LOC', 'setting_id' => $source2Setting->id]);

        $this->createTerminalAndSaleLocations($terminalSetting, [$locTerminal, $locSource1, $locSource2]);
        $methods = $this->seedPaymentMethods($terminalSetting, true);
        $this->openSession($terminalSetting, PosTerminal::where('setting_id', $terminalSetting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);

        $tax = Tax::query()->create(['name' => 'VAT 11', 'value' => 11, 'is_default' => true]);

        // 2. Create Bundle
        // Parent (Terminal), Comp A (Source 1), Comp B (Source 2)
        $parent = $this->createStockedProduct($terminalSetting, $locTerminal, 'PARENT', 100000, 1, $tax);
        $compA = $this->createStockedProduct($source1Setting, $locSource1, 'COMP-A', 0, 1, $tax);
        $compB = $this->createStockedProduct($source2Setting, $locSource2, 'COMP-B', 0, 1, $tax);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $terminalSetting->id,
            'name' => 'Test Bundle 3-Owner',
            'bundle_sale_price' => 175000,
            'price' => 75000,
        ]);

        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compA->id, 'quantity' => 1, 'informational_item_price' => 25000]);
        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compB->id, 'quantity' => 1, 'informational_item_price' => 50000]);

        // 3. Checkout
        $this->addCartLine($cashier, $terminalSetting, $parent->id, 1, $bundle->id);
        $this->selectCustomerInCart($cashier, $terminalSetting, $customer);

        $response = $this->finalize($cashier, $terminalSetting, [
            'idempotency_key' => 'K-BUNDLE-SPLIT-3-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 175000,
            ],
        ]);

        $response->assertStatus(201);
        $checkoutId = $response->json('pos_checkout_id');
        $checkout = PosCheckout::with(['transactions.lines', 'checkoutSales.sale.saleDetails.bundleItems.product'])->findOrFail($checkoutId);

        // 4. Verify Receipt Data
        $receiptService = app(PosReceiptService::class);
        $receiptData = $receiptService->getReceiptData($checkout);

        $lines = $receiptData['lines'];
        $bundleLine = $lines[0];
        $composition = $bundleLine['bundle_composition'];
        
        $compNames = array_column($composition, 'name');
        $this->assertContains('COMP-A NAME', $compNames);
        $this->assertContains('COMP-B NAME', $compNames);
        $this->assertCount(2, $composition);
    }

    public function test_receipt_isolation_for_mixed_bundled_and_non_bundled_lines(): void
    {
        // 1. Setup Context
        $terminalSetting = $this->createSetting('TERMINAL BIZ', 'T-DOC', 'T-SO');
        $sourceSetting = $this->createSetting('SOURCE BIZ', 'S-DOC', 'S-SO');

        $cashier = $this->createUserForSetting($terminalSetting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment',
        ]);

        $locTerminal = Location::create(['name' => 'TERMINAL LOC', 'setting_id' => $terminalSetting->id]);
        $locSource = Location::create(['name' => 'SOURCE LOC', 'setting_id' => $sourceSetting->id]);

        $this->createTerminalAndSaleLocations($terminalSetting, [$locTerminal, $locSource]);
        $methods = $this->seedPaymentMethods($terminalSetting, true);
        $this->openSession($terminalSetting, PosTerminal::where('setting_id', $terminalSetting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);

        $tax = Tax::query()->create(['name' => 'VAT 11', 'value' => 11, 'is_default' => true]);

        // 2. Create Bundle
        $parent = $this->createStockedProduct($terminalSetting, $locTerminal, 'PARENT', 100000, 10, $tax);
        $compA = $this->createStockedProduct($terminalSetting, $locTerminal, 'COMP-A', 0, 10, $tax);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $terminalSetting->id,
            'name' => 'Test Bundle',
            'bundle_sale_price' => 125000,
            'price' => 25000,
        ]);

        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compA->id, 'quantity' => 1, 'informational_item_price' => 25000]);

        // 3. Add two lines: one bundled, one plain for the same parent product
        $this->addCartLine($cashier, $terminalSetting, $parent->id, 1, $bundle->id);
        $this->addCartLine($cashier, $terminalSetting, $parent->id, 1, null); // Plain
        
        $this->selectCustomerInCart($cashier, $terminalSetting, $customer);

        $response = $this->finalize($cashier, $terminalSetting, [
            'idempotency_key' => 'K-MIXED-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 225000, // 125k (bundle) + 100k (plain)
            ],
        ]);

        $response->assertStatus(201);
        $checkoutId = $response->json('pos_checkout_id');
        $checkout = PosCheckout::with(['transactions.lines', 'checkoutSales.sale.saleDetails.bundleItems.product'])->findOrFail($checkoutId);

        // 4. Verify Receipt Data
        $receiptService = app(PosReceiptService::class);
        $receiptData = $receiptService->getReceiptData($checkout);

        $lines = $receiptData['lines'];
        $this->assertCount(2, $lines);

        // Find bundled line
        $bundledLine = null;
        $plainLine = null;
        foreach ($lines as $line) {
            if (!empty($line['bundle_composition'])) {
                $bundledLine = $line;
            } else {
                $plainLine = $line;
            }
        }

        $this->assertNotNull($bundledLine, 'One line should have bundle composition');
        $this->assertNotNull($plainLine, 'One line should NOT have bundle composition');
        
        $this->assertCount(1, $bundledLine['bundle_composition']);
        $this->assertEmpty($plainLine['bundle_composition'], 'Plain line should have empty composition');
    }

    public function test_transaction_detail_shows_complete_composition_for_3_owner_split_bundle(): void
    {
        $terminalSetting = $this->createSetting('TERMINAL BIZ', 'T-DOC', 'T-SO');
        $source1Setting = $this->createSetting('SOURCE1 BIZ', 'S1-DOC', 'S1-SO');
        $source2Setting = $this->createSetting('SOURCE2 BIZ', 'S2-DOC', 'S2-SO');

        $cashier = $this->createUserForSetting($terminalSetting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.transactions.view',
        ]);

        $locTerminal = Location::create(['name' => 'TERMINAL LOC', 'setting_id' => $terminalSetting->id]);
        $locSource1 = Location::create(['name' => 'SOURCE1 LOC', 'setting_id' => $source1Setting->id]);
        $locSource2 = Location::create(['name' => 'SOURCE2 LOC', 'setting_id' => $source2Setting->id]);

        $this->createTerminalAndSaleLocations($terminalSetting, [$locTerminal, $locSource1, $locSource2]);
        $methods = $this->seedPaymentMethods($terminalSetting, true);
        $this->openSession($terminalSetting, PosTerminal::where('setting_id', $terminalSetting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);

        $tax = Tax::query()->create(['name' => 'VAT 11', 'value' => 11, 'is_default' => true]);

        $parent = $this->createStockedProduct($terminalSetting, $locTerminal, 'PARENT', 100000, 1, $tax);
        $compA = $this->createStockedProduct($source1Setting, $locSource1, 'COMP-A', 0, 1, $tax);
        $compB = $this->createStockedProduct($source2Setting, $locSource2, 'COMP-B', 0, 1, $tax);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $terminalSetting->id,
            'name' => 'Test Bundle 3-Owner',
            'bundle_sale_price' => 175000,
            'price' => 75000,
        ]);

        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compA->id, 'quantity' => 1, 'informational_item_price' => 25000]);
        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compB->id, 'quantity' => 1, 'informational_item_price' => 50000]);

        $this->addCartLine($cashier, $terminalSetting, $parent->id, 1, $bundle->id);
        $this->selectCustomerInCart($cashier, $terminalSetting, $customer);

        $response = $this->finalize($cashier, $terminalSetting, [
            'idempotency_key' => 'K-BUNDLE-TX-3-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 175000,
            ],
        ]);
        $response->assertStatus(201);

        $checkout = PosCheckout::with(['transactions.lines'])->findOrFail((int) $response->json('pos_checkout_id'));
        $transaction = $checkout->transactions->firstOrFail();
        $lineId = (int) $transaction->lines->firstOrFail()->id;

        $detailResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $terminalSetting->id])
            ->get(route('pos.transactions.show', ['transaction' => $transaction->id]));

        $detailResponse->assertOk()->assertViewIs('pos::transactions.show')->assertViewHas('bundleCompositionByLine');

        /** @var array<int, array<int, array{name: string, qty: float}>> $compositionByLine */
        $compositionByLine = $detailResponse->viewData('bundleCompositionByLine');
        $composition = $compositionByLine[$lineId] ?? [];

        $this->assertCount(2, $composition);
        $componentByName = collect($composition)->keyBy('name');
        $this->assertArrayHasKey('COMP-A NAME', $componentByName->all());
        $this->assertArrayHasKey('COMP-B NAME', $componentByName->all());
        $this->assertEqualsCanonicalizing(['name', 'qty', 'serials'], array_keys($composition[0]));
        $this->assertSame(1.0, (float) $componentByName['COMP-A NAME']['qty']);
        $this->assertSame(1.0, (float) $componentByName['COMP-B NAME']['qty']);
    }

    public function test_receipt_shows_component_serials_beneath_their_component_and_separate_from_parent_serials(): void
    {
        $terminalSetting = $this->createSetting('SERIAL RECEIPT BIZ', 'SR-DOC', 'SR-SO');
        $cashier = $this->createUserForSetting($terminalSetting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment',
        ]);

        $locTerminal = Location::create(['name' => 'SR TERMINAL LOC', 'setting_id' => $terminalSetting->id]);
        $this->createTerminalAndSaleLocations($terminalSetting, [$locTerminal]);
        $methods = $this->seedPaymentMethods($terminalSetting, true);
        $this->openSession($terminalSetting, PosTerminal::where('setting_id', $terminalSetting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);

        $tax = Tax::query()->create(['name' => 'VAT 11', 'value' => 11, 'is_default' => true]);

        $parent = $this->createStockedProduct($terminalSetting, $locTerminal, 'SR-PARENT', 100000, 1, $tax, true);
        $comp = $this->createStockedProduct($terminalSetting, $locTerminal, 'SR-COMP', 0, 2, $tax, true);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $terminalSetting->id,
            'name' => 'Serial Receipt Bundle',
            'bundle_sale_price' => 150000,
            'price' => 50000,
        ]);
        $bundleItem = ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $comp->id, 'quantity' => 1, 'informational_item_price' => 20000]);

        $parentSerial = \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $parent->id,
            'location_id' => $locTerminal->id,
            'serial_number' => 'SN-PARENT-RECEIPT-1',
            'status' => 'ACTIVE',
            'tax_id' => $tax->id,
        ]);
        $compSerial = \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $comp->id,
            'location_id' => $locTerminal->id,
            'serial_number' => 'SN-COMP-RECEIPT-1',
            'status' => 'ACTIVE',
            'tax_id' => $tax->id,
        ]);

        $lineId = $this->addCartLine($cashier, $terminalSetting, $parent->id, 1, $bundle->id);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $terminalSetting->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-PARENT-RECEIPT-1'],
            ])
            ->assertOk();

        $this->appendComponentSerial($cashier, $terminalSetting, $lineId, $bundleItem->id, 'SN-COMP-RECEIPT-1');

        $this->selectCustomerInCart($cashier, $terminalSetting, $customer);

        $response = $this->finalize($cashier, $terminalSetting, [
            'idempotency_key' => 'K-SERIAL-RECEIPT-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 150000,
            ],
        ]);
        $response->assertStatus(201);

        $checkout = PosCheckout::with(['transactions.lines'])->findOrFail((int) $response->json('pos_checkout_id'));
        $receiptService = app(PosReceiptService::class);
        $receiptData = $receiptService->getReceiptData($checkout);

        $bundleLine = $receiptData['lines'][0];

        // Parent serial appears at the parent level, not inside the component.
        $this->assertContains('SN-PARENT-RECEIPT-1', $bundleLine['assigned_serials']);

        $composition = $bundleLine['bundle_composition'];
        $componentEntry = collect($composition)->firstWhere('name', 'SR-COMP NAME');
        $this->assertNotNull($componentEntry);
        $this->assertEquals(['SN-COMP-RECEIPT-1'], $componentEntry['serials']);
        $this->assertNotContains('SN-COMP-RECEIPT-1', $bundleLine['assigned_serials']);
    }

    public function test_receipt_disambiguates_component_serials_across_two_identical_bundle_occurrences_in_one_sale(): void
    {
        // Two separate SaleDetails rows in the SAME Sale purchase the SAME
        // bundle definition (the scenario dispatch_details.sale_detail_id
        // exists to disambiguate — without it, both occurrences' component
        // DispatchDetail rows collide on the same (product_id, bundle_id) key
        // and their serials get unioned onto both receipt lines).
        $terminalSetting = $this->createSetting('REPEATED BUNDLE RECEIPT BIZ', 'RB-DOC', 'RB-SO');
        $cashier = $this->createUserForSetting($terminalSetting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment',
        ]);
        $locTerminal = Location::create(['name' => 'RB TERMINAL LOC', 'setting_id' => $terminalSetting->id]);
        $this->createTerminalAndSaleLocations($terminalSetting, [$locTerminal]);
        $this->seedPaymentMethods($terminalSetting, true);
        $terminal = PosTerminal::where('setting_id', $terminalSetting->id)->first();
        $session = $this->openSession($terminalSetting, $terminal, $cashier);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);

        $tax = Tax::query()->create(['name' => 'VAT 11', 'value' => 11, 'is_default' => true]);

        $parent = $this->createStockedProduct($terminalSetting, $locTerminal, 'RB-PARENT', 100000, 2, $tax);
        $comp = $this->createStockedProduct($terminalSetting, $locTerminal, 'RB-COMP', 0, 2, $tax, true);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $terminalSetting->id,
            'name' => 'Repeated Bundle',
            'bundle_sale_price' => 150000,
            'price' => 50000,
        ]);
        $bundleItem = ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $comp->id, 'quantity' => 1, 'informational_item_price' => 20000]);

        $compSerial1 = \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $comp->id,
            'location_id' => $locTerminal->id,
            'serial_number' => 'SN-COMP-REPEAT-1',
            'status' => 'ACTIVE',
            'tax_id' => $tax->id,
        ]);
        $compSerial2 = \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $comp->id,
            'location_id' => $locTerminal->id,
            'serial_number' => 'SN-COMP-REPEAT-2',
            'status' => 'ACTIVE',
            'tax_id' => $tax->id,
        ]);

        $sale = \Modules\Sale\Entities\Sale::create([
            'setting_id' => $terminalSetting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->display_name ?? 'Walk-in Customer',
            'total_amount' => 300000,
            'paid_amount' => 300000,
            'due_amount' => 0,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'reference' => 'SO-REPEAT-' . uniqid(),
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $terminalSetting->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 300000,
            'receipt_number' => 'RCP-REPEAT-' . uniqid(),
            'idempotency_key' => 'IDEM-REPEAT-' . uniqid(),
            'payload_hash' => 'HASH-REPEAT-' . uniqid(),
        ]);
        \Modules\Pos\Entities\PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $terminalSetting->id,
            'source_location_id' => $locTerminal->id,
            'grand_total' => 300000,
            'split_key' => 'SPLIT-REPEAT-' . uniqid(),
            'tax_bucket' => 'NON_TAX',
        ]);

        // First bundle occurrence: its own SaleDetails, SaleBundleItem, and
        // component DispatchDetail (with dispatch_detail's sale_detail_id
        // pointing at THIS occurrence's SaleDetails row).
        $saleDetail1 = \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 150000,
            'product_name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        \Modules\Sale\Entities\SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail1->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bundleItem->id,
            'product_id' => $comp->id,
            'name' => $comp->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
        ]);
        $dispatch1 = \Modules\Sale\Entities\Dispatch::create(['sale_id' => $sale->id, 'status' => \Modules\Sale\Entities\Dispatch::STATUS_APPROVED]);
        $compDispatchDetail1 = \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch1->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail1->id,
            'product_id' => $comp->id,
            'bundle_id' => $bundle->id,
            'dispatched_quantity' => 1,
            'location_id' => $locTerminal->id,
            'serial_numbers' => json_encode([$compSerial1->serial_number]),
        ]);
        $compSerial1->update(['dispatch_detail_id' => $compDispatchDetail1->id, 'status' => 'SOLD']);

        // Second occurrence of the SAME bundle in the SAME Sale — its own
        // SaleDetails and its own component DispatchDetail, distinguished
        // only by sale_detail_id (product_id + bundle_id are identical to
        // the first occurrence).
        $saleDetail2 = \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $parent->id,
            'quantity' => 1,
            'price' => 100000,
            'unit_price' => 100000,
            'sub_total' => 150000,
            'product_name' => $parent->product_name,
            'product_code' => $parent->product_code,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);
        \Modules\Sale\Entities\SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail2->id,
            'bundle_id' => $bundle->id,
            'bundle_item_id' => $bundleItem->id,
            'product_id' => $comp->id,
            'name' => $comp->product_name,
            'quantity' => 1,
            'price' => 0,
            'sub_total' => 0,
        ]);
        $dispatch2 = \Modules\Sale\Entities\Dispatch::create(['sale_id' => $sale->id, 'status' => \Modules\Sale\Entities\Dispatch::STATUS_APPROVED]);
        $compDispatchDetail2 = \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dispatch2->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail2->id,
            'product_id' => $comp->id,
            'bundle_id' => $bundle->id,
            'dispatched_quantity' => 1,
            'location_id' => $locTerminal->id,
            'serial_numbers' => json_encode([$compSerial2->serial_number]),
        ]);
        $compSerial2->update(['dispatch_detail_id' => $compDispatchDetail2->id, 'status' => 'SOLD']);

        $checkout = $checkout->fresh(['checkoutSales.sale.saleDetails.bundleItems.product', 'checkoutSales.sale.dispatchDetails']);

        $receiptService = app(PosReceiptService::class);
        $groups = (new \ReflectionMethod($receiptService, 'bundleCompositionGroupsByProduct'))->invoke($receiptService, $checkout);

        $productGroups = $groups[$parent->id] ?? [];
        $this->assertCount(2, $productGroups, 'Both bundle occurrences must produce their own composition group.');

        $components1 = collect($productGroups[0]['items'])->firstWhere('name', $comp->product_name);
        $components2 = collect($productGroups[1]['items'])->firstWhere('name', $comp->product_name);

        // Each occurrence's component serial must be attributed only to its
        // own SaleDetails-linked dispatch, never unioned across both
        // identical-bundle occurrences.
        $this->assertEquals(['SN-COMP-REPEAT-1'], $components1['serials']);
        $this->assertEquals(['SN-COMP-REPEAT-2'], $components2['serials']);
    }

    public function test_receipt_and_transaction_detail_disambiguate_same_serialized_sku_standalone_and_as_component(): void
    {
        $terminalSetting = $this->createSetting('SAME SKU RECEIPT BIZ', 'SS-DOC', 'SS-SO');
        $cashier = $this->createUserForSetting($terminalSetting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.transactions.view',
        ]);

        $locTerminal = Location::create(['name' => 'SS TERMINAL LOC', 'setting_id' => $terminalSetting->id]);
        $this->createTerminalAndSaleLocations($terminalSetting, [$locTerminal]);
        $methods = $this->seedPaymentMethods($terminalSetting, true);
        $this->openSession($terminalSetting, PosTerminal::where('setting_id', $terminalSetting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);

        $tax = Tax::query()->create(['name' => 'VAT 11', 'value' => 11, 'is_default' => true]);

        $parent = $this->createStockedProduct($terminalSetting, $locTerminal, 'SS-PARENT', 100000, 1, $tax, false);
        $shared = $this->createStockedProduct($terminalSetting, $locTerminal, 'SS-SHARED', 10000, 2, $tax, true);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $terminalSetting->id,
            'name' => 'Same SKU Bundle',
            'bundle_sale_price' => 130000,
            'price' => 30000,
        ]);
        $bundleItem = ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $shared->id, 'quantity' => 1, 'informational_item_price' => 10000]);

        $standaloneSerial = \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $shared->id,
            'location_id' => $locTerminal->id,
            'serial_number' => 'SN-SHARED-STANDALONE-1',
            'status' => 'ACTIVE',
            'tax_id' => $tax->id,
        ]);
        $componentSerial = \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $shared->id,
            'location_id' => $locTerminal->id,
            'serial_number' => 'SN-SHARED-COMPONENT-1',
            'status' => 'ACTIVE',
            'tax_id' => $tax->id,
        ]);

        // Standalone line for the shared serialized SKU
        $standaloneLineId = $this->addCartLine($cashier, $terminalSetting, $shared->id, 1);
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $terminalSetting->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $standaloneLineId]), [
                'serial_numbers' => ['SN-SHARED-STANDALONE-1'],
            ])
            ->assertOk();

        // Bundle line whose component is the same serialized SKU
        $bundleLineId = $this->addCartLine($cashier, $terminalSetting, $parent->id, 1, $bundle->id);
        $this->appendComponentSerial($cashier, $terminalSetting, $bundleLineId, $bundleItem->id, 'SN-SHARED-COMPONENT-1');

        $this->selectCustomerInCart($cashier, $terminalSetting, $customer);

        $response = $this->finalize($cashier, $terminalSetting, [
            'idempotency_key' => 'K-SAME-SKU-RECEIPT-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 140000,
            ],
        ]);
        $response->assertStatus(201);

        $checkout = PosCheckout::with(['transactions.lines'])->findOrFail((int) $response->json('pos_checkout_id'));
        $receiptService = app(PosReceiptService::class);
        $receiptData = $receiptService->getReceiptData($checkout);

        $standaloneLine = collect($receiptData['lines'])->firstWhere('product_name', 'SS-SHARED NAME');
        $bundleReceiptLine = collect($receiptData['lines'])->first(fn ($l) => ! empty($l['bundle_composition']));

        $this->assertNotNull($standaloneLine);
        $this->assertEquals(['SN-SHARED-STANDALONE-1'], $standaloneLine['assigned_serials']);

        $componentEntry = collect($bundleReceiptLine['bundle_composition'])->firstWhere('name', 'SS-SHARED NAME');
        $this->assertNotNull($componentEntry);
        $this->assertEquals(['SN-SHARED-COMPONENT-1'], $componentEntry['serials']);

        // The standalone serial must never leak into the bundle component's list, and vice versa.
        $this->assertNotContains('SN-SHARED-COMPONENT-1', $standaloneLine['assigned_serials']);
        $this->assertNotContains('SN-SHARED-STANDALONE-1', $componentEntry['serials']);
    }

    public function test_receipt_component_serials_survive_after_live_bundle_definition_changes(): void
    {
        $terminalSetting = $this->createSetting('LIVE DEF CHANGE BIZ', 'LD-DOC', 'LD-SO');
        $cashier = $this->createUserForSetting($terminalSetting, 'cashier', [
            'pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment',
        ]);

        $locTerminal = Location::create(['name' => 'LD TERMINAL LOC', 'setting_id' => $terminalSetting->id]);
        $this->createTerminalAndSaleLocations($terminalSetting, [$locTerminal]);
        $methods = $this->seedPaymentMethods($terminalSetting, true);
        $this->openSession($terminalSetting, PosTerminal::where('setting_id', $terminalSetting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);

        $tax = Tax::query()->create(['name' => 'VAT 11', 'value' => 11, 'is_default' => true]);

        $parent = $this->createStockedProduct($terminalSetting, $locTerminal, 'LD-PARENT', 100000, 1, $tax, false);
        $comp = $this->createStockedProduct($terminalSetting, $locTerminal, 'LD-COMP', 0, 1, $tax, true);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $terminalSetting->id,
            'name' => 'Live Def Change Bundle',
            'bundle_sale_price' => 120000,
            'price' => 20000,
        ]);
        $bundleItem = ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $comp->id, 'quantity' => 1, 'informational_item_price' => 10000]);

        $compSerial = \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $comp->id,
            'location_id' => $locTerminal->id,
            'serial_number' => 'SN-COMP-LIVEDEF-1',
            'status' => 'ACTIVE',
            'tax_id' => $tax->id,
        ]);

        $lineId = $this->addCartLine($cashier, $terminalSetting, $parent->id, 1, $bundle->id);
        $this->appendComponentSerial($cashier, $terminalSetting, $lineId, $bundleItem->id, 'SN-COMP-LIVEDEF-1');
        $this->selectCustomerInCart($cashier, $terminalSetting, $customer);

        $response = $this->finalize($cashier, $terminalSetting, [
            'idempotency_key' => 'K-LIVEDEF-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 120000,
            ],
        ]);
        $response->assertStatus(201);
        $checkoutId = (int) $response->json('pos_checkout_id');

        // Live bundle definition changes after the sale: the component is removed.
        $bundleItem->delete();
        \App\Support\ProductBundleResolver::clearCache();

        $checkout = PosCheckout::with(['transactions.lines'])->findOrFail($checkoutId);
        $receiptService = app(PosReceiptService::class);
        $receiptData = $receiptService->getReceiptData($checkout);

        $bundleLine = $receiptData['lines'][0];
        $componentEntry = collect($bundleLine['bundle_composition'])->firstWhere('name', 'LD-COMP NAME');

        // Historical receipt display is unaffected by the live bundle definition change.
        $this->assertNotNull($componentEntry);
        $this->assertEquals(['SN-COMP-LIVEDEF-1'], $componentEntry['serials']);
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
            'pos_transactions_enabled' => true,
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

    private function createStockedProduct(Setting $setting, Location $location, string $code, float $salePrice, int $qty, Tax $tax, bool $serialRequired = false): Product
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
            'serial_number_required' => $serialRequired,
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

    private function addCartLine(User $cashier, Setting $setting, int $productId, int $qty, ?int $bundleId = null): int
    {
        $payload = ['product_id' => $productId, 'qty' => $qty];
        if ($bundleId) $payload['bundle_id'] = $bundleId;

        $response = $this->actingAs($cashier)->withSession(['setting_id' => $setting->id])->postJson(route('pos.sell.cart.lines.store'), $payload)->assertOk();

        $lines = $response->json('cart_snapshot.lines') ?? [];
        return (int) (end($lines)['line_id'] ?? 0);
    }

    private function appendComponentSerial(User $cashier, Setting $setting, int $lineId, int $bundleItemId, string $serialNumber): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.serials.append', ['lineId' => $lineId]), [
                'serial_number' => $serialNumber,
                'bundle_item_id' => $bundleItemId,
            ])
            ->assertOk();
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
