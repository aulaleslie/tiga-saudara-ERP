<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Modules\Pos\Entities\PosTerminalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Services\PosCartService;
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

/**
 * End-to-end reconciliation for both row overrides through the REAL production
 * flow: PosCartService applies the override, the real split planner and posting
 * adapter create Sales and dispatches, and PosReceiptService renders receipts.
 *
 * Nothing here reimplements planner arithmetic. Earlier coverage mirrored
 * `allocateMinorByQuantity` inside the test, which only proved the copy; these
 * assertions read persisted Sale rows and real receipt output instead.
 *
 * Extends the split-posting fixture, which already provides a product stocked
 * across two owner settings.
 */
class PosRowOverrideCheckoutIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        // Split posting is what makes the two-owner allocation path run.
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
            'pos.overrides.price',
            'pos.transactions.save',
            'pos.transactions.load',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function grantOverridePermission(User $cashier): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        \Spatie\Permission\Models\Permission::findOrCreate('pos.overrides.price', 'web');
        $cashier->givePermissionTo([
            'pos.overrides.price',
            'pos.transactions.save',
            'pos.transactions.load',
        ]);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * @return array{context: array<string, mixed>, line_id: int}
     */
    private function seedCartWithLine(int $qty): array
    {
        $context = $this->createSplitCheckoutContext();
        $this->grantOverridePermission($context['cashier']);
        $this->assignDefaultWalkInCustomer($context['setting']);

        $snapshot = app(PosCartService::class)->addLine(
            (int) $context['setting']->id,
            (int) $context['session']->id,
            (int) $context['product']->id,
            $qty
        );

        // Checkout requires a resolved customer.
        app(PosCartService::class)->updateCustomerSelection(
            (int) $context['setting']->id,
            (int) $context['session']->id,
            (int) $context['customer']->id
        );

        return [
            'context' => $context,
            'line_id' => (int) $snapshot['lines'][0]['line_id'],
        ];
    }

    private function applyRowTotalOverride(array $context, int $lineId, float $rowTotal): array
    {
        return app(PosCartService::class)->overrideLineTotal(
            (int) $context['setting']->id,
            (int) $context['session']->id,
            (int) $context['cashier']->id,
            $lineId,
            $rowTotal,
            'integration test',
            null,
            $context['cashier']
        );
    }

    private function applyUnitPriceOverride(array $context, int $lineId, float $unitPrice): array
    {
        return app(PosCartService::class)->overrideLineUnitPrice(
            (int) $context['setting']->id,
            (int) $context['session']->id,
            (int) $context['cashier']->id,
            $lineId,
            $unitPrice,
            'integration test',
            null,
            $context['cashier']
        );
    }

    private function applyBillDiscount(array $context, float $value): array
    {
        return app(PosCartService::class)->updateBillDiscount(
            (int) $context['setting']->id,
            (int) $context['session']->id,
            'fixed',
            $value
        );
    }

    /**
     * Sum every posted Sale detail across all owner Sales for this checkout.
     */
    private function postedDetailSubtotalSum(): float
    {
        $sum = 0.0;

        foreach (Sale::with('saleDetails')->get() as $sale) {
            foreach ($sale->saleDetails as $detail) {
                $sum += (float) $detail->sub_total;
            }
        }

        return round($sum, 2);
    }

    private function finalizeCart(array $context, string $key, float $amount)
    {
        return $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => $key,
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => $amount,
            ],
        ]);
    }

    // ------------------------------------ non-divisible row total, real planner

    public function test_non_divisible_row_total_allocates_exactly_across_two_owners(): void
    {
        // Rp10.000 over qty 3 does not divide evenly, and the product is stocked
        // across two owner settings, so the real planner must split it.
        ['context' => $context, 'line_id' => $lineId] = $this->seedCartWithLine(3);

        $snapshot = $this->applyRowTotalOverride($context, $lineId, 10000.0);

        $this->assertSame(
            10000.0,
            (float) $snapshot['lines'][0]['line_net_before_bill'],
            'The authoritative row net must survive the override.'
        );

        $response = $this->finalizeCart($context, 'K-SPLIT-NONDIV', 10000.0);
        $response->assertStatus(201);

        $sales = Sale::with('saleDetails')->get();
        $this->assertGreaterThanOrEqual(2, $sales->count(), 'Expected a split across owners.');

        $this->assertSame(
            10000.0,
            $this->postedDetailSubtotalSum(),
            'Real planner allocations did not sum exactly to the authoritative row net.'
        );
    }

    public function test_unit_price_override_reconciles_through_real_posting(): void
    {
        ['context' => $context, 'line_id' => $lineId] = $this->seedCartWithLine(3);

        $snapshot = $this->applyUnitPriceOverride($context, $lineId, 3000.0);
        $expectedNet = (float) $snapshot['lines'][0]['line_net_before_bill'];

        $this->assertSame(9000.0, $expectedNet);

        $this->finalizeCart($context, 'K-SPLIT-UNITPRICE', $expectedNet)->assertStatus(201);

        $this->assertSame(
            $expectedNet,
            $this->postedDetailSubtotalSum(),
            'Posted allocations did not reconcile to the overridden unit price total.'
        );

        // Dispatches were created for the fulfilled quantity.
        $this->assertDatabaseCount('dispatches', Sale::query()->count());
    }

    // --------------------------------------------- bill discount separation

    public function test_bill_discount_stays_distinct_from_the_row_override(): void
    {
        ['context' => $context, 'line_id' => $lineId] = $this->seedCartWithLine(3);

        $this->applyRowTotalOverride($context, $lineId, 10000.0);
        $snapshot = $this->applyBillDiscount($context, 1000.0);

        $line = $snapshot['lines'][0];

        // Four figures, each independently reported.
        $this->assertSame(10000.0, (float) $line['line_net_before_bill'], 'Row net is pre-bill-discount.');
        $this->assertSame(1000.0, (float) $line['bill_discount_amount'], 'Bill discount reported separately.');
        $this->assertSame(9000.0, (float) $line['line_total'], 'Charged amount is net minus bill discount.');
        $this->assertSame(9000.0, (float) $snapshot['totals']['grand_total']);

        $this->finalizeCart($context, 'K-SPLIT-BILLDISC', 9000.0)->assertStatus(201);

        $this->assertSame(
            9000.0,
            $this->postedDetailSubtotalSum(),
            'Posted allocations must reconcile to the charged amount after the bill discount.'
        );
    }

    // ------------------------------------------------- draft round-trip

    public function test_overridden_cart_survives_a_draft_round_trip_and_posts_identically(): void
    {
        ['context' => $context, 'line_id' => $lineId] = $this->seedCartWithLine(3);

        $beforeDraft = $this->applyRowTotalOverride($context, $lineId, 10000.0);
        $expectedNet = (float) $beforeDraft['lines'][0]['line_net_before_bill'];

        $transactionService = app(\Modules\Pos\Services\PosTransactionService::class);

        $transaction = $transactionService->saveAndNew(
            (int) $context['setting']->id,
            $context['session'],
            $context['cashier']
        );

        $transactionService->loadToCart(
            (int) $context['setting']->id,
            (int) $context['session']->id,
            $transaction,
            $context['cashier']
        );

        $reloaded = app(PosCartService::class)->getSnapshot(
            (int) $context['setting']->id,
            (int) $context['session']->id
        );

        $this->assertSame(
            $expectedNet,
            (float) $reloaded['lines'][0]['line_net_before_bill'],
            'The overridden total changed across the draft round trip.'
        );
        $this->assertSame(
            'LINE_TOTAL_OVERRIDE',
            $reloaded['lines'][0]['price_source'],
            'The pricing source was lost across the draft round trip.'
        );

        $this->finalizeCart($context, 'K-SPLIT-DRAFT', $expectedNet)->assertStatus(201);

        $this->assertSame($expectedNet, $this->postedDetailSubtotalSum());
    }

    // ------------------------------------------------------ receipts

    public function test_receipt_reports_gross_row_discount_bill_discount_and_charged_total(): void
    {
        ['context' => $context, 'line_id' => $lineId] = $this->seedCartWithLine(3);

        $this->applyUnitPriceOverride($context, $lineId, 4000.0);
        $this->applyBillDiscount($context, 1000.0);

        $response = $this->finalizeCart($context, 'K-SPLIT-RECEIPT', 11000.0);
        $response->assertStatus(201);

        $checkout = \Modules\Pos\Entities\PosCheckout::query()->latest('id')->firstOrFail();
        $receipt = app(PosReceiptService::class)->getReceiptData($checkout);
        $line = $receipt['lines'][0];

        // Each amount is reported independently; none is inferred.
        $this->assertArrayHasKey('line_gross', $line);
        $this->assertArrayHasKey('discount', $line);
        $this->assertArrayHasKey('bill_discount', $line);
        $this->assertArrayHasKey('charged_total', $line);

        $this->assertSame(12000.0, (float) $line['line_gross'], 'Gross must be unit price x qty.');
        $this->assertSame(0.0, (float) $line['discount'], 'No row discount was applied.');
        $this->assertSame(12000.0, (float) $line['sub_total'], 'Row net before bill discount.');
        $this->assertSame(1000.0, (float) $line['bill_discount'], 'Bill discount allocated to row.');
        $this->assertSame(11000.0, (float) $line['charged_total'], 'Charged total is net minus bill discount.');
        $this->assertSame((float) $line['line_gross'] - (float) $line['discount'], (float) $line['sub_total']);
        $this->assertSame((float) $line['sub_total'] - (float) $line['bill_discount'], (float) $line['charged_total']);
    }

    public function test_receipt_reports_row_total_override_with_row_discount_and_bill_discount(): void
    {
        ['context' => $context, 'line_id' => $lineId] = $this->seedCartWithLine(3);

        app(PosCartService::class)->updateLine(
            (int) $context['setting']->id,
            (int) $context['session']->id,
            $lineId,
            [
                'qty' => 3,
                'line_discount_type' => 'fixed',
                'line_discount_value' => 1000.0,
            ]
        );

        $this->applyRowTotalOverride($context, $lineId, 10000.0);
        $this->applyBillDiscount($context, 1000.0);

        $response = $this->finalizeCart($context, 'K-SPLIT-ROWTOTAL-RECEIPT', 9000.0);
        $response->assertStatus(201);

        $checkout = \Modules\Pos\Entities\PosCheckout::query()->latest('id')->firstOrFail();
        $receipt = app(PosReceiptService::class)->getReceiptData($checkout);
        $line = $receipt['lines'][0];

        $this->assertSame(11000.0, (float) $line['line_gross']);
        $this->assertSame(1000.0, (float) $line['discount']);
        $this->assertSame(10000.0, (float) $line['sub_total']);
        $this->assertSame(1000.0, (float) $line['bill_discount']);
        $this->assertSame(9000.0, (float) $line['charged_total']);

        $this->assertSame((float) $line['line_gross'] - (float) $line['discount'], (float) $line['sub_total']);
        $this->assertSame((float) $line['sub_total'] - (float) $line['bill_discount'], (float) $line['charged_total']);
    }

    public function test_receipt_does_not_recompute_a_non_divisible_overridden_total(): void
    {
        ['context' => $context, 'line_id' => $lineId] = $this->seedCartWithLine(3);

        $this->applyRowTotalOverride($context, $lineId, 10000.0);
        $this->finalizeCart($context, 'K-SPLIT-RECEIPT-NONDIV', 10000.0)->assertStatus(201);

        $checkout = \Modules\Pos\Entities\PosCheckout::query()->latest('id')->firstOrFail();
        $receipt = app(PosReceiptService::class)->getReceiptData($checkout);
        $line = $receipt['lines'][0];

        // qty x rounded unit price would drift away from Rp10.000.
        $this->assertSame(
            10000.0,
            (float) $line['sub_total'],
            'The receipt recomputed the overridden total instead of rendering it as charged.'
        );
    }

    // ------------------------------------------------- idempotent replay

    public function test_idempotent_replay_does_not_post_the_override_twice(): void
    {
        ['context' => $context, 'line_id' => $lineId] = $this->seedCartWithLine(3);

        $this->applyRowTotalOverride($context, $lineId, 10000.0);

        $first = $this->finalizeCart($context, 'K-SPLIT-REPLAY', 10000.0);
        $first->assertStatus(201);

        $salesAfterFirst = Sale::query()->count();
        $detailSumAfterFirst = $this->postedDetailSubtotalSum();

        $second = $this->finalizeCart($context, 'K-SPLIT-REPLAY', 10000.0);
        $second->assertStatus(200)->assertJsonPath('idempotent_replay', true);

        $this->assertSame($salesAfterFirst, Sale::query()->count(), 'Replay posted duplicate Sales.');
        $this->assertSame(
            $detailSumAfterFirst,
            $this->postedDetailSubtotalSum(),
            'Replay allocated the override a second time.'
        );
    }

    protected function createSplitCheckoutContext(bool $configureSourceWalkIn = true): array
    {
        $setting = $this->createSetting('POS SPLIT TERMINAL BIZ', 'TNC', 'JL');
        $sourceSetting = $this->createSetting('POS SPLIT SOURCE BIZ', 'TOP', 'JL');
        $cashier = $this->createUserForSetting($setting, 'pos split cashier', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
        ]);
        $sourceLocation = Location::create([
            'name' => 'SPLIT SOURCE LOC ' . $this->sequence++,
            'setting_id' => $sourceSetting->id,
        ]);
        [$terminal, $locations] = $this->createTerminalAndSaleLocations($setting, $sourceLocation);
        $methods = $this->seedPaymentMethods($setting, true);
        $session = $this->openSession($setting, $terminal, $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);
        if ($configureSourceWalkIn) {
            $this->assignDefaultWalkInCustomer($sourceSetting);
        }
        $tax = Tax::query()->create([
            'name' => 'VAT 11',
            'value' => 11,
            'is_default' => true,
        ]);
        $product = $this->createSplitStockProduct($setting, $locations[0], $locations[1], $tax);

        return [
            'setting' => $setting,
            'cashier' => $cashier,
            'terminal' => $terminal,
            'session' => $session,
            'methods' => $methods,
            'customer' => $customer,
            'source_setting' => $sourceSetting,
            'terminal_location' => $locations[0],
            'source_location' => $locations[1],
            'product' => $product,
            'tax' => $tax,
        ];
    }

    protected function createSetting(
        string $name,
        string $documentPrefix = 'DOC',
        string $salePrefix = 'SO'
    ): Setting
    {
        $suffix = $this->sequence++;

        return Setting::create([
            'company_name' => $name . ' ' . $suffix,
            'company_email' => "pos.split.{$suffix}@example.com",
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => $documentPrefix,
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => $salePrefix,
            'pos_enabled' => true,
            'is_pkp' => true,
        ]);
    }

    protected function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => strtoupper($roleName) . '-' . $setting->id]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    protected function createTerminalAndSaleLocations(Setting $setting, Location $sourceLocation): array
    {
        $index = $this->sequence++;

        $locationA = Location::create([
            'name' => 'SPLIT LOC A ' . $index,
            'setting_id' => $setting->id,
        ]);

        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $setting->id, 'location_id' => $locationA->id],
            ['is_enabled' => true, 'position' => 1]
        );
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $setting->id, 'location_id' => $sourceLocation->id],
            ['is_enabled' => true, 'position' => 2]
        );
        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-SPLIT-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Split Terminal ' . $index,
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

        return [$terminal, [$locationA, $sourceLocation]];
    }

    protected function openSession(Setting $setting, PosTerminal $terminal, User $cashier)
    {
        /** @var PosSessionLifecycleService $sessionLifecycle */
        $sessionLifecycle = app(PosSessionLifecycleService::class);

        return $sessionLifecycle->openSession(
            $setting->id,
            $terminal->id,
            $cashier->id,
            100000,
            ['100000' => 1],
            $cashier->id
        );
    }

    protected function assignDefaultWalkInCustomer(Setting $setting): Customer
    {
        $customer = Customer::factory()->create([
            'setting_id' => $setting->id,
        ]);

        $setting->update([
            'pos_walk_in_customer_id' => $customer->id,
        ]);

        return $customer;
    }

    protected function createSplitStockProduct(Setting $setting, Location $locationA, Location $locationB, Tax $tax): Product
    {
        $createdBy = User::query()->value('id') ?? User::factory()->create()->id;
        $index = $this->sequence++;

        $category = Category::firstOrCreate(
            ['category_code' => 'POS-SPLIT-CAT-' . $index],
            [
                'category_name' => 'POS SPLIT CATEGORY ' . $index,
                'created_by' => $createdBy,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'POS UNIT SPLIT',
            'short_name' => 'PUS',
        ]);

        $product = Product::query()->create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => 'POS SPLIT PRODUCT ' . $index,
            'product_code' => 'POS-SPLIT-' . $index,
            'barcode' => 'POS-SPLIT-BAR-' . $index,
            'product_quantity' => 11,
            'product_cost' => 50000,
            'product_price' => 100000,
            'product_unit' => 'PUS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        ProductStock::query()->create([
            'product_id' => $product->id,
            'location_id' => $locationA->id,
            'quantity' => 1,
            'quantity_non_tax' => 0,
            'quantity_tax' => 1,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => $tax->id,
        ]);

        ProductStock::query()->create([
            'product_id' => $product->id,
            'location_id' => $locationB->id,
            'quantity' => 10,
            'quantity_non_tax' => 0,
            'quantity_tax' => 10,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => $tax->id,
        ]);

        ProductPrice::query()->updateOrCreate([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
        ], [
            'sale_price' => 100000,
            'tier_1_price' => null,
            'tier_2_price' => null,
            'last_purchase_price' => 50000,
            'average_purchase_price' => 50000,
            'purchase_tax_id' => null,
            'sale_tax_id' => $tax->id,
        ]);

        return $product;
    }

    protected function seedPaymentMethods(Setting $setting, bool $enableForSetting = false): array
    {
        $index = $this->sequence++;
        $methods = [];

        $cashCoaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'POS SPLIT COA CASH ' . $index,
            'account_number' => 'POS-SPLIT-CASH-' . $index,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $methods['cash'] = PaymentMethod::query()->create([
            'name' => 'CASH SPLIT ' . $index,
            'coa_id' => $cashCoaId,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        $transferCoaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'POS SPLIT COA TRANSFER ' . $index,
            'account_number' => 'POS-SPLIT-TRANSFER-' . $index,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $methods['transfer'] = PaymentMethod::query()->create([
            'name' => 'TRANSFER SPLIT ' . $index,
            'coa_id' => $transferCoaId,
            'is_cash' => false,
            'requires_reference' => true,
        ]);

        if ($enableForSetting) {
            foreach ($methods as $method) {
                DB::table('setting_pos_payment_methods')->updateOrInsert(
                    [
                        'setting_id' => $setting->id,
                        'payment_method_id' => $method->id,
                    ],
                    [
                        'is_enabled' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        return $methods;
    }

    protected function finalize(User $cashier, Setting $setting, array $payload)
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), $payload);
    }

    public function test_bundle_parent_row_total_override_splits_across_owners_preserving_authority_and_components(): void
    {
        $context = $this->createSplitCheckoutContext();
        $this->grantOverridePermission($context['cashier']);
        $this->assignDefaultWalkInCustomer($context['setting']);
        $this->assignDefaultWalkInCustomer($context['source_setting']);

        $parent = $this->createProductForSetting($context['setting'], $context['terminal_location'], 'BUNDLE-PARENT', 175000.0, 5, $context['tax']);
        $compA = $this->createProductForSetting($context['setting'], $context['terminal_location'], 'COMP-A', 0.0, 10, $context['tax']);
        $compB = $this->createProductForSetting($context['source_setting'], $context['source_location'], 'COMP-B', 0.0, 10, $context['tax']);

        $bundle = ProductBundle::query()->create([
            'parent_product_id' => $parent->id,
            'setting_id' => $context['setting']->id,
            'name' => 'SPLIT TEST BUNDLE',
            'bundle_sale_price' => 175000.0,
            'price' => 75000.0,
        ]);

        ProductBundleItem::query()->create([
            'bundle_id' => $bundle->id,
            'product_id' => $compA->id,
            'quantity' => 1,
            'informational_item_price' => 25000.0,
        ]);

        ProductBundleItem::query()->create([
            'bundle_id' => $bundle->id,
            'product_id' => $compB->id,
            'quantity' => 1,
            'informational_item_price' => 50000.0,
        ]);

        // Add bundle line to cart
        $cartSnapshot = app(PosCartService::class)->addLine(
            (int) $context['setting']->id,
            (int) $context['session']->id,
            (int) $parent->id,
            1,
            null,
            (int) $bundle->id
        );

        app(PosCartService::class)->updateCustomerSelection(
            (int) $context['setting']->id,
            (int) $context['session']->id,
            (int) $context['customer']->id
        );

        $lineId = (int) $cartSnapshot['lines'][0]['line_id'];

        // Apply LINE_TOTAL_OVERRIDE on the billable bundle parent: Rp150,000 (>= informational 75,000)
        $overriddenSnapshot = $this->applyRowTotalOverride($context, $lineId, 150000.0);
        $this->assertSame(150000.0, (float) $overriddenSnapshot['lines'][0]['line_net_before_bill']);
        $this->assertSame('LINE_TOTAL_OVERRIDE', $overriddenSnapshot['lines'][0]['price_source']);

        $response = $this->finalizeCart($context, 'K-BUNDLE-PARENT-OVERRIDE', 150000.0);
        $response->assertStatus(201);

        $checkoutId = (int) $response->json('pos_checkout_id');
        $checkout = \Modules\Pos\Entities\PosCheckout::with([
            'transactions.lines',
            'checkoutSales.sale.saleDetails.bundleItems.product',
        ])->findOrFail($checkoutId);

        $sales = $checkout->checkoutSales->map->sale;
        $this->assertGreaterThanOrEqual(2, $sales->count(), 'At least two owner Sales/split groups must be created.');

        $terminalSale = $sales->firstWhere('setting_id', $context['setting']->id);
        $sourceSale = $sales->firstWhere('setting_id', $context['source_setting']->id);
        $this->assertNotNull($terminalSale, 'Terminal owner sale must exist.');
        $this->assertNotNull($sourceSale, 'Source owner sale must exist.');

        // Authoritative totals:
        // Informational components: compA = 25,000 (terminal), compB = 50,000 (source). Total comp = 75,000.
        // Parent residual: 150,000 - 75,000 = 75,000 (non-negative, on terminal).
        // Terminal sale total = 75,000 (parent residual) + 25,000 (compA) = 100,000.
        // Source sale total = 50,000 (compB).
        $this->assertSame(100000.0, (float) $terminalSale->total_amount, 'Terminal sale gets parent residual + local component.');
        $this->assertSame(50000.0, (float) $sourceSale->total_amount, 'Source sale gets source component allocation.');

        // Sum of all posted customer-facing SaleDetail charged/subtotal amounts equals overridden charged amount exactly (150,000).
        $this->assertSame(150000.0, $this->postedDetailSubtotalSum(), 'SaleDetail subtotal sum must reconcile to overridden total.');
        $this->assertSame(150000.0, (float) $sales->sum('total_amount'), 'Sales header sum must equal overridden total.');

        // Check SaleBundleItems and component informational pricing
        $allBundleItems = SaleBundleItem::query()->whereIn('sale_id', $sales->pluck('id'))->get();
        $this->assertCount(2, $allBundleItems, 'Two SaleBundleItem records must exist.');

        $bundleItemA = $allBundleItems->firstWhere('product_id', $compA->id);
        $bundleItemB = $allBundleItems->firstWhere('product_id', $compB->id);
        $this->assertNotNull($bundleItemA);
        $this->assertNotNull($bundleItemB);

        // Commercial price and sub_total remain zero for non-billable linked components
        $this->assertSame(0.0, (float) $bundleItemA->price, 'Component A commercial price must be 0.');
        $this->assertSame(0.0, (float) $bundleItemA->sub_total, 'Component A commercial subtotal must be 0.');
        $this->assertSame(0.0, (float) $bundleItemB->price, 'Component B commercial price must be 0.');
        $this->assertSame(0.0, (float) $bundleItemB->sub_total, 'Component B commercial subtotal must be 0.');

        // Component informational_item_price remains equal to captured snapshot value
        $this->assertSame(25000.0, (float) $bundleItemA->informational_item_price, 'Component A snapshot info price.');
        $this->assertSame(50000.0, (float) $bundleItemB->informational_item_price, 'Component B snapshot info price.');

        // Override applies only to the billable bundle parent transaction line
        $transaction = $checkout->transactions->firstOrFail();
        $this->assertCount(1, $transaction->lines, 'Only the billable bundle parent is a customer transaction line.');
        $parentTxLine = $transaction->lines->first();
        $this->assertSame($parent->id, (int) $parentTxLine->product_id);
        $this->assertSame('LINE_TOTAL_OVERRIDE', $parentTxLine->line_meta['price_source']);
        $this->assertSame(15000000, (int) $parentTxLine->line_meta['line_net_minor']);

        // Stock decrements:
        $this->assertSame(4, (int) ProductStock::where('product_id', $parent->id)->where('location_id', $context['terminal_location']->id)->value('quantity'));
        $this->assertSame(9, (int) ProductStock::where('product_id', $compA->id)->where('location_id', $context['terminal_location']->id)->value('quantity'));
        $this->assertSame(9, (int) ProductStock::where('product_id', $compB->id)->where('location_id', $context['source_location']->id)->value('quantity'));

        // Dispatches created for both sales with correct quantities
        $this->assertDatabaseCount('dispatches', $sales->count());

        // Allocation taxability follows source-owner is_pkp contract:
        $this->assertGreaterThan(0.0, (float) $terminalSale->tax_amount, 'Terminal PKP sale must have tax calculated.');
        $this->assertGreaterThan(0.0, (float) $sourceSale->tax_amount, 'Source PKP sale must have tax calculated.');

        // Receipt output verification:
        $receiptService = app(PosReceiptService::class);
        $receiptData = $receiptService->getReceiptData($checkout);

        $this->assertCount(1, $receiptData['lines'], 'Receipt must contain exactly 1 customer-facing bundle line.');
        $receiptLine = $receiptData['lines'][0];

        $this->assertSame($parent->product_name, $receiptLine['product_name']);
        $this->assertSame(150000.0, (float) $receiptLine['sub_total'], 'Authoritative overridden total rendered directly.');
        $this->assertSame(150000.0, (float) $receiptLine['charged_total']);
        $this->assertSame(150000.0, (float) $receiptData['grand_total']);

        // Bundle composition in receipt contains the components, not separate customer rows
        $this->assertCount(2, $receiptLine['bundle_composition']);
        $compNames = array_column($receiptLine['bundle_composition'], 'name');
        $this->assertContains($compA->product_name, $compNames);
        $this->assertContains($compB->product_name, $compNames);
    }

    protected function createProductForSetting(Setting $setting, Location $location, string $code, float $salePrice, int $qty, ?Tax $tax = null): Product
    {
        $createdBy = User::query()->value('id') ?? User::factory()->create()->id;
        $index = $this->sequence++;

        $category = Category::firstOrCreate(
            ['category_code' => 'POS-SPLIT-CAT-' . $index],
            [
                'category_name' => 'POS SPLIT CATEGORY ' . $index,
                'created_by' => $createdBy,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'POS UNIT SPLIT',
            'short_name' => 'PUS',
        ]);

        $product = Product::query()->create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $code . ' NAME',
            'product_code' => $code . '-' . $index,
            'barcode' => 'BAR-' . $code . '-' . $index,
            'product_quantity' => $qty,
            'product_cost' => 1000,
            'product_price' => $salePrice,
            'product_unit' => 'PUS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        ProductStock::query()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $qty,
            'quantity_non_tax' => $tax ? 0 : $qty,
            'quantity_tax' => $tax ? $qty : 0,
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
            'last_purchase_price' => 1000,
            'average_purchase_price' => 1000,
            'purchase_tax_id' => null,
            'sale_tax_id' => $tax?->id,
        ]);

        return $product;
    }
}
