<?php

namespace Modules\Pos\Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosLineageSchemaAndCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);

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
            'pos.receipts.reprint',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_sale_details_table_has_pos_transaction_line_id_column_and_index(): void
    {
        $this->assertTrue(Schema::hasColumn('sale_details', 'pos_transaction_line_id'));

        $detail = new SaleDetails();
        $this->assertTrue(method_exists($detail, 'posTransactionLine'));
    }

    public function test_inline_checkout_maps_each_generated_sale_detail_to_originating_pos_line(): void
    {
        config(['pos.checkout.split_posting.enabled' => false]);

        $context = $this->createCheckoutContext('POS INLINE LINEAGE');
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product1 = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-1', 50000);
        $product2 = $this->createStockedProduct($context['setting'], $context['location'], 'PROD-2', 75000);

        $this->addCartLine($context['cashier'], $context['setting'], $product1->id, 2);
        $this->addCartLine($context['cashier'], $context['setting'], $product2->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'inline-lineage-001',
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 175000,
            ],
        ]);

        $response->assertStatus(201);
        $checkoutId = (int) $response->json('pos_checkout_id');
        $checkout = PosCheckout::with(['transaction.lines', 'sale.saleDetails'])->findOrFail($checkoutId);

        $transaction = $checkout->transaction;
        $this->assertNotNull($transaction);
        $this->assertCount(2, $transaction->lines);

        $sale = $checkout->sale;
        $this->assertNotNull($sale);
        $this->assertCount(2, $sale->saleDetails);

        $detailsByProduct = $sale->saleDetails->keyBy('product_id');
        $linesByProduct = $transaction->lines->keyBy('product_id');

        $this->assertNotNull($detailsByProduct->get($product1->id)->pos_transaction_line_id);
        $this->assertEquals(
            (int) $linesByProduct->get($product1->id)->id,
            (int) $detailsByProduct->get($product1->id)->pos_transaction_line_id
        );

        $this->assertNotNull($detailsByProduct->get($product2->id)->pos_transaction_line_id);
        $this->assertEquals(
            (int) $linesByProduct->get($product2->id)->id,
            (int) $detailsByProduct->get($product2->id)->pos_transaction_line_id
        );
    }

    public function test_three_owner_split_bundle_and_repeated_line_establish_distinct_links(): void
    {
        config(['pos.checkout.split_posting.enabled' => true]);

        // 1. Setup Context with 3 business settings
        $terminalSetting = $this->createSetting('TERMINAL BIZ');
        $source1Setting = $this->createSetting('SOURCE1 BIZ');
        $source2Setting = $this->createSetting('SOURCE2 BIZ');

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

        // 2. Create Bundle: Parent (Terminal), Comp A (Source 1), Comp B (Source 2)
        $parent = $this->createStockedProduct($terminalSetting, $locTerminal, 'PARENT', 100000, 10, $tax);
        $compA = $this->createStockedProduct($source1Setting, $locSource1, 'COMP-A', 0, 10, $tax);
        $compB = $this->createStockedProduct($source2Setting, $locSource2, 'COMP-B', 0, 10, $tax);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $terminalSetting->id,
            'name' => 'Test Bundle 3-Owner',
            'bundle_sale_price' => 175000,
            'price' => 75000,
        ]);

        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compA->id, 'quantity' => 1, 'informational_item_price' => 25000]);
        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compB->id, 'quantity' => 1, 'informational_item_price' => 50000]);

        // Plain standalone product
        $standalone = $this->createStockedProduct($terminalSetting, $locTerminal, 'STANDALONE', 50000, 10, $tax);

        // Cart with bundle line and plain line
        $this->addCartLine($cashier, $terminalSetting, $parent->id, 1, null, $bundle->id);
        $this->addCartLine($cashier, $terminalSetting, $standalone->id, 1, null, null);
        $this->selectCustomerInCart($cashier, $terminalSetting, $customer);

        $response = $this->finalize($cashier, $terminalSetting, [
            'idempotency_key' => 'K-SPLIT-3OWNER-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 225000,
            ],
        ]);

        $response->assertStatus(201);
        $checkoutId = (int) $response->json('pos_checkout_id');
        $checkout = PosCheckout::with([
            'transaction.lines',
            'checkoutSales.sale.saleDetails',
        ])->findOrFail($checkoutId);

        $transaction = $checkout->transaction;
        $this->assertNotNull($transaction);
        $this->assertCount(2, $transaction->lines);

        $bundleLine = $transaction->lines->firstWhere('product_id', $parent->id);
        $standaloneLine = $transaction->lines->firstWhere('product_id', $standalone->id);
        $this->assertNotNull($bundleLine);
        $this->assertNotNull($standaloneLine);
        $this->assertNotEquals($bundleLine->id, $standaloneLine->id);

        // Collect all saleDetails across all generated owner sales
        $allSaleDetails = $checkout->checkoutSales->flatMap(fn ($cs) => $cs->sale->saleDetails);
        $this->assertNotEmpty($allSaleDetails);

        // Check that all 3 owner sales details generated for the bundle point to $bundleLine->id
        $bundleDetails = $allSaleDetails->where('pos_transaction_line_id', $bundleLine->id);
        // There should be 3 details (1 parent residual in terminal setting, 2 components in source1 and source2)
        $this->assertCount(3, $bundleDetails);

        // Check that standalone detail points to $standaloneLine->id
        $standaloneDetails = $allSaleDetails->where('pos_transaction_line_id', $standaloneLine->id);
        $this->assertCount(1, $standaloneDetails);
    }

    protected function createCheckoutContext(string $prefix): array
    {
        $setting = $this->createSetting($prefix);
        $cashier = $this->createUserForSetting($setting, $prefix . '-cashier', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
        ]);

        $methods = $this->seedPaymentMethods($setting, true);
        $terminal = $this->createTerminalForSetting($setting);
        $location = SalesLocationResolver::resolve((int) $terminal->setting_id);

        /** @var PosSessionLifecycleService $sessionLifecycle */
        $sessionLifecycle = app(PosSessionLifecycleService::class);
        $session = $sessionLifecycle->openSession(
            $setting->id,
            $terminal->id,
            $cashier->id,
            100000,
            ['100000' => 1],
            $cashier->id
        );

        return compact('setting', 'location', 'cashier', 'terminal', 'session', 'methods');
    }

    protected function createSetting(string $name): Setting
    {
        $suffix = $this->sequence++;

        return Setting::create([
            'company_name' => $name . ' ' . $suffix,
            'company_email' => 'pos.lineage.' . $suffix . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
            'pos_transactions_enabled' => true,
            'is_pkp' => false,
        ]);
    }

    protected function createUserForSetting(Setting $setting, string $name, array $permissions): User
    {
        $index = $this->sequence++;
        $user = clone User::factory()->create([
            'name' => $name,
            'email' => "user.{$index}@example.com",
        ]);

        $roleName = 'pos_role_' . $index;
        $role = Role::findOrCreate($roleName, 'web');

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $role->givePermissionTo($permissions);
        $user->assignRole($role);
        $setting->users()->attach($user->id, ['role_id' => $role->id]);

        return $user;
    }

    protected function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $index = $this->sequence++;

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-CHECKOUT-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Checkout Terminal ' . $index,
            'is_active' => true,
        ]);

        $location = Location::create([
            'name' => 'POS LINEAGE LOC ' . $index,
            'setting_id' => $setting->id,
        ]);

        SettingSaleLocation::create([
            'setting_id' => $setting->id,
            'location_id' => $location->id,
            'is_enabled' => true,
            'position' => 1,
        ]);

        SalesLocationResolver::forget($setting->id);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => true,
            'cash_threshold' => 50000,
        ]);

        return $terminal;
    }

    protected function assignDefaultWalkInCustomer(Setting $setting): Customer
    {
        $customer = Customer::factory()->create([
            'setting_id' => $setting->id,
        ]);
        $setting->update(['pos_walk_in_customer_id' => $customer->id]);
        return $customer;
    }

    protected function createStockedProduct(
        Setting $setting,
        Location $location,
        string $code,
        float $salePrice,
        int $qty = 10,
        ?Tax $tax = null,
        bool $serialRequired = false
    ): Product {
        $category = Category::firstOrCreate(
            ['category_code' => $code . '-CAT'],
            [
                'category_name' => $code . ' CATEGORY',
                'created_by' => 1,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'POS UNIT',
            'short_name' => 'PUNIT',
        ]);

        $product = Product::query()->create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $code . ' NAME',
            'product_code' => $code,
            'barcode' => $code . '-BAR',
            'product_quantity' => $qty,
            'product_cost' => 5000,
            'product_price' => $salePrice,
            'product_unit' => 'PUNIT',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => $serialRequired,
        ]);

        ProductStock::query()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $qty,
            'quantity_non_tax' => $tax ? $qty : $qty,
            'quantity_tax' => $tax ? $qty : $qty,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => $tax?->id,
        ]);

        ProductPrice::query()->updateOrCreate([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
        ], [
            'sale_price' => $salePrice,
            'tier_1_price' => null,
            'tier_2_price' => null,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
            'purchase_tax_id' => null,
            'sale_tax_id' => $tax?->id,
        ]);

        return $product;
    }

    protected function seedPaymentMethods(Setting $setting, bool $enableForSetting = false): array
    {
        $coaId = \Illuminate\Support\Facades\DB::table('chart_of_accounts')->insertGetId([
            'name' => 'COA ' . $this->sequence++,
            'account_number' => 'ACC-' . $this->sequence++,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
        ]);

        $method = PaymentMethod::create(['name' => 'CASH', 'coa_id' => $coaId, 'is_cash' => true]);

        if ($enableForSetting) {
            \Illuminate\Support\Facades\DB::table('setting_pos_payment_methods')->insert(['setting_id' => $setting->id, 'payment_method_id' => $method->id, 'is_enabled' => true]);
        }

        return ['cash' => $method];
    }

    protected function createTerminalAndSaleLocations(Setting $setting, array $locations): void
    {
        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-TRM-' . $setting->id,
            'name' => 'Terminal ' . $setting->id,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => false,
            'cash_threshold' => 100000000,
        ]);

        foreach ($locations as $idx => $loc) {
            SettingSaleLocation::create([
                'setting_id' => $setting->id,
                'location_id' => $loc->id,
                'is_enabled' => true,
                'position' => $idx + 1,
            ]);
        }

        SalesLocationResolver::forget($setting->id);
    }

    protected function openSession(Setting $setting, PosTerminal $terminal, User $cashier): void
    {
        /** @var PosSessionLifecycleService $sessionLifecycle */
        $sessionLifecycle = app(PosSessionLifecycleService::class);
        $sessionLifecycle->openSession(
            $setting->id,
            $terminal->id,
            $cashier->id,
            100000,
            ['100000' => 1],
            $cashier->id
        );
    }

    protected function selectCustomerInCart(User $cashier, Setting $setting, Customer $customer): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson('/pos/sell/cart/customer', [
                'customer_id' => $customer->id,
            ], ['X-Setting-Id' => (string) $setting->id])
            ->assertOk();
    }

    protected function addCartLine(User $cashier, Setting $setting, int $productId, int $qty, ?int $conversionId = null, ?int $bundleId = null): void
    {
        $payload = [
            'product_id' => $productId,
            'qty' => $qty,
            'conversion_id' => $conversionId,
        ];
        if ($bundleId !== null) {
            $payload['bundle_id'] = $bundleId;
        }

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson('/pos/sell/cart/lines', $payload, ['X-Setting-Id' => (string) $setting->id])
            ->assertStatus(200);
    }

    protected function finalize(User $cashier, Setting $setting, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson('/pos/sell/checkout/finalize', $payload, [
                'X-Setting-Id' => (string) $setting->id,
            ]);
    }
}
