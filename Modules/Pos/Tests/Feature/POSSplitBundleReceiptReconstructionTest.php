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
        $this->assertEqualsCanonicalizing(['name', 'qty'], array_keys($composition[0]));
        $this->assertSame(1.0, (float) $componentByName['COMP-A NAME']['qty']);
        $this->assertSame(1.0, (float) $componentByName['COMP-B NAME']['qty']);
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
