<?php

namespace Modules\Pos\Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
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
use Modules\Sale\Services\SalesCostSnapshotService;
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
class PosSplitBundleCostSnapshotTest extends TestCase
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

    /**
     * Sets up a bundle spanning two owner settings: Terminal owns the parent + Comp A,
     * Source owns Comp B. Terminal's group fulfills the parent; Source's group is
     * component-only (parent_not_fulfilled_by_group).
     *
     * @return array{terminal: Setting, source: Setting, parent: Product, compA: Product, compB: Product, cashier: User, customer: Customer, methods: array}
     */
    protected function setUpSplitBundleFixture(?float $compBAveragePrice = 30000): array
    {
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

        $parent = $this->createStockedProduct($terminalSetting, $locTerminal, 'PARENT', 100000, 1, $tax);
        $compA = $this->createStockedProduct($terminalSetting, $locTerminal, 'COMP-A', 0, 1, $tax);
        $compB = $this->createStockedProduct($sourceSetting, $locSource, 'COMP-B', 0, 1, $tax);

        ProductPrice::query()->where('product_id', $parent->id)->where('setting_id', $terminalSetting->id)
            ->update(['average_purchase_price' => 40000]);
        ProductPrice::query()->where('product_id', $compA->id)->where('setting_id', $terminalSetting->id)
            ->update(['average_purchase_price' => 10000]);

        if ($compBAveragePrice !== null) {
            ProductPrice::query()->where('product_id', $compB->id)->where('setting_id', $sourceSetting->id)
                ->update(['average_purchase_price' => $compBAveragePrice]);
        }

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $terminalSetting->id,
            'name' => 'Test Bundle',
            'bundle_sale_price' => 175000,
            'price' => 75000,
        ]);

        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compA->id, 'quantity' => 1, 'informational_item_price' => 25000]);
        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $compB->id, 'quantity' => 1, 'informational_item_price' => 50000]);

        return [
            'terminal' => $terminalSetting,
            'source' => $sourceSetting,
            'parent' => $parent,
            'compA' => $compA,
            'compB' => $compB,
            'cashier' => $cashier,
            'customer' => $customer,
            'methods' => $methods,
            'bundle' => $bundle,
        ];
    }

    public function test_component_only_group_persists_zero_parent_hpp_with_not_fulfilled_source(): void
    {
        $fixture = $this->setUpSplitBundleFixture();

        $this->addCartLine($fixture['cashier'], $fixture['terminal'], $fixture['parent']->id, 1, $fixture['bundle']->id);
        $this->selectCustomerInCart($fixture['cashier'], $fixture['terminal'], $fixture['customer']);

        $response = $this->finalize($fixture['cashier'], $fixture['terminal'], [
            'idempotency_key' => 'K-HPP-NOTFULFILLED-' . uniqid(),
            'payment' => [
                'payment_method_id' => $fixture['methods']['cash']->id,
                'amount_paid' => 175000,
            ],
        ]);

        $response->assertStatus(201);
        $payload = $response->json();
        $saleIds = array_column($payload['split_groups'], 'sale_id');

        $sales = Sale::with(['saleDetails.bundleItems'])->whereIn('id', $saleIds)->get()->keyBy('setting_id');
        $saleSource = $sales->get($fixture['source']->id);
        $sourceDetail = $saleSource->saleDetails->sole();

        $this->assertEquals(0, (float) $sourceDetail->cost_unit_snapshot);
        $this->assertEquals(0, (float) $sourceDetail->cost_total_snapshot);
        $this->assertSame(SalesCostSnapshotService::SOURCE_NOT_FULFILLED_BY_GROUP, $sourceDetail->cost_snapshot_source);
    }

    public function test_owner_specific_component_cost_resolves_from_the_physical_owner_setting(): void
    {
        $fixture = $this->setUpSplitBundleFixture(compBAveragePrice: 30000);

        $this->addCartLine($fixture['cashier'], $fixture['terminal'], $fixture['parent']->id, 1, $fixture['bundle']->id);
        $this->selectCustomerInCart($fixture['cashier'], $fixture['terminal'], $fixture['customer']);

        $response = $this->finalize($fixture['cashier'], $fixture['terminal'], [
            'idempotency_key' => 'K-HPP-OWNER-' . uniqid(),
            'payment' => [
                'payment_method_id' => $fixture['methods']['cash']->id,
                'amount_paid' => 175000,
            ],
        ]);

        $response->assertStatus(201);
        $payload = $response->json();
        $saleIds = array_column($payload['split_groups'], 'sale_id');

        $sales = Sale::with(['saleDetails.bundleItems'])->whereIn('id', $saleIds)->get()->keyBy('setting_id');

        // Terminal group: parent fulfilled here + Comp A fulfilled here.
        $terminalDetail = $sales->get($fixture['terminal']->id)->saleDetails->sole();
        $this->assertEquals(40000, (float) $terminalDetail->cost_unit_snapshot);
        $this->assertEquals(40000, (float) $terminalDetail->cost_total_snapshot); // qty 1
        $this->assertSame(SalesCostSnapshotService::SOURCE_CURRENT_AVERAGE_PRICE, $terminalDetail->cost_snapshot_source);

        $compABundleItem = $terminalDetail->bundleItems->sole();
        $this->assertEquals($fixture['compA']->id, $compABundleItem->product_id);
        $this->assertEquals(10000, (float) $compABundleItem->cost_unit_snapshot);
        $this->assertEquals(10000, (float) $compABundleItem->cost_total_snapshot);
        $this->assertEquals($fixture['terminal']->id, $compABundleItem->cost_snapshot_setting_id);

        // Source group: parent NOT fulfilled here; Comp B fulfilled here using the
        // source setting's own average, not the terminal's.
        $sourceDetail = $sales->get($fixture['source']->id)->saleDetails->sole();
        $compBBundleItem = $sourceDetail->bundleItems->sole();
        $this->assertEquals($fixture['compB']->id, $compBBundleItem->product_id);
        $this->assertEquals(30000, (float) $compBBundleItem->cost_unit_snapshot);
        $this->assertEquals(30000, (float) $compBBundleItem->cost_total_snapshot);
        $this->assertEquals($fixture['source']->id, $compBBundleItem->cost_snapshot_setting_id);
    }

    public function test_parent_and_component_costs_are_recognized_exactly_once_across_groups(): void
    {
        $fixture = $this->setUpSplitBundleFixture();

        $this->addCartLine($fixture['cashier'], $fixture['terminal'], $fixture['parent']->id, 1, $fixture['bundle']->id);
        $this->selectCustomerInCart($fixture['cashier'], $fixture['terminal'], $fixture['customer']);

        $response = $this->finalize($fixture['cashier'], $fixture['terminal'], [
            'idempotency_key' => 'K-HPP-EXACTLY-ONCE-' . uniqid(),
            'payment' => [
                'payment_method_id' => $fixture['methods']['cash']->id,
                'amount_paid' => 175000,
            ],
        ]);

        $response->assertStatus(201);
        $payload = $response->json();
        $saleIds = array_column($payload['split_groups'], 'sale_id');

        $sales = Sale::with(['saleDetails.bundleItems'])->whereIn('id', $saleIds)->get();

        // Exactly one non-zero parent cost across all groups (the fulfilling group).
        $nonZeroParentCosts = $sales->flatMap(fn (Sale $s) => $s->saleDetails)
            ->filter(fn ($d) => (float) $d->cost_total_snapshot > 0);
        $this->assertCount(1, $nonZeroParentCosts);
        $this->assertEquals(40000, (float) $nonZeroParentCosts->first()->cost_total_snapshot);

        // Every bundle component appears with cost exactly once across all groups.
        $bundleItems = $sales->flatMap(fn (Sale $s) => $s->saleDetails)->flatMap->bundleItems;
        $this->assertCount(2, $bundleItems);
        $this->assertEquals(
            [$fixture['compA']->id, $fixture['compB']->id],
            $bundleItems->pluck('product_id')->sort()->values()->all()
        );
    }

    public function test_missing_component_fallback_returns_structured_non_blocking_warning(): void
    {
        // Source has no positive average anywhere -> MISSING_AVERAGE_PRICE for Comp B.
        $fixture = $this->setUpSplitBundleFixture(compBAveragePrice: null);

        $this->addCartLine($fixture['cashier'], $fixture['terminal'], $fixture['parent']->id, 1, $fixture['bundle']->id);
        $this->selectCustomerInCart($fixture['cashier'], $fixture['terminal'], $fixture['customer']);

        $response = $this->finalize($fixture['cashier'], $fixture['terminal'], [
            'idempotency_key' => 'K-HPP-WARNING-' . uniqid(),
            'payment' => [
                'payment_method_id' => $fixture['methods']['cash']->id,
                'amount_paid' => 175000,
            ],
        ]);

        // Missing cost never blocks checkout completion.
        $response->assertStatus(201);
        $payload = $response->json();

        $this->assertArrayHasKey('hpp_warnings', $payload);
        $this->assertNotEmpty($payload['hpp_warnings']);

        $compBWarning = collect($payload['hpp_warnings'])->firstWhere('product_id', $fixture['compB']->id);
        $this->assertNotNull($compBWarning);
        $this->assertSame('warning', $compBWarning['level']);
        $this->assertArrayHasKey('split_key', $compBWarning);

        $saleIds = array_column($payload['split_groups'], 'sale_id');
        $sales = Sale::with(['saleDetails.bundleItems'])->whereIn('id', $saleIds)->get()->keyBy('setting_id');
        $sourceBundleItem = $sales->get($fixture['source']->id)->saleDetails->sole()->bundleItems->sole();
        $this->assertEquals(0, (float) $sourceBundleItem->cost_total_snapshot);
        $this->assertSame(SalesCostSnapshotService::SOURCE_MISSING_AVERAGE_PRICE, $sourceBundleItem->cost_snapshot_source);
    }

    public function test_idempotent_replay_does_not_duplicate_or_change_persisted_cost_snapshots(): void
    {
        $fixture = $this->setUpSplitBundleFixture();

        $this->addCartLine($fixture['cashier'], $fixture['terminal'], $fixture['parent']->id, 1, $fixture['bundle']->id);
        $this->selectCustomerInCart($fixture['cashier'], $fixture['terminal'], $fixture['customer']);

        $idempotencyKey = 'K-HPP-RETRY-' . uniqid();
        $paymentPayload = [
            'idempotency_key' => $idempotencyKey,
            'payment' => [
                'payment_method_id' => $fixture['methods']['cash']->id,
                'amount_paid' => 175000,
            ],
        ];

        $first = $this->finalize($fixture['cashier'], $fixture['terminal'], $paymentPayload);
        $first->assertStatus(201);
        $firstPayload = $first->json();

        // Re-submit the identical idempotency key: must replay, not re-post.
        $second = $this->finalize($fixture['cashier'], $fixture['terminal'], $paymentPayload);
        $second->assertStatus(200);
        $secondPayload = $second->json();

        $this->assertTrue((bool) ($secondPayload['idempotent_replay'] ?? false));
        $this->assertSame($firstPayload['sale_id'], $secondPayload['sale_id']);

        $saleIds = array_column($firstPayload['split_groups'], 'sale_id');
        $this->assertCount(2, Sale::whereIn('id', $saleIds)->get());

        $sales = Sale::with('saleDetails.bundleItems')->whereIn('id', $saleIds)->get();
        // No duplicate SaleDetails/SaleBundleItem rows were created by the replay.
        $this->assertEquals(2, $sales->flatMap(fn (Sale $s) => $s->saleDetails)->count());
        $this->assertEquals(2, $sales->flatMap(fn (Sale $s) => $s->saleDetails)->flatMap->bundleItems->count());
    }

    // ==== HELPER METHODS (mirrors SplitBundleTransactionTest fixtures) ====

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
                'position' => $index + 1,
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
        if ($bundleId) {
            $payload['bundle_id'] = $bundleId;
        }

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
