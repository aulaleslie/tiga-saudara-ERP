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
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosReturnApprovalPlanPersistenceService;
use Modules\Pos\Services\PosReturnApprovalPreviewPlannerService;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
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

/**
 * Sequence 10 correction (align-bundle-return-replacement-rules): a
 * NON-serial, split-owner, quantity > 1 bundle carrier-row scenario —
 * complementary to POSReturnSplitOwnerCarrierRowRegressionTest, which only
 * covers serial-tracked (quantity always 1) carrier rows. Proves a PARTIAL
 * whole-bundle cash return (returning fewer than all purchased parent
 * units) correctly synthesizes a PROPORTIONAL component quantity
 * (quantity_per_bundle × returned parent quantity) against a real
 * checkout-produced carrier row, and that the customer refund amount is
 * carried only by the parent line (component internal-allocation lines
 * always price at zero — see PosReturnLifecycleService/
 * PosReturnApprovalPreviewPlannerService "one customer refund" invariant).
 */
class POSReturnBundleCarrierRowCashReturnCompletenessTest extends TestCase
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
            'pos.returns.create',
            'pos.returns.approve',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    /** @test */
    public function it_synthesizes_proportional_component_quantity_for_a_partial_whole_bundle_cash_return(): void
    {
        $terminalSetting = $this->createSetting('PARTIAL TERMINAL BIZ', 'PT-DOC', 'PT-SO');
        $sourceSetting = $this->createSetting('PARTIAL SOURCE BIZ', 'PS-DOC', 'PS-SO');

        $cashier = $this->createUserForSetting($terminalSetting, 'partial cashier', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
            'pos.transactions.view',
            'pos.returns.create',
            'pos.returns.approve',
        ]);

        $locTerminal = Location::create(['name' => 'PARTIAL TERMINAL LOC', 'setting_id' => $terminalSetting->id]);
        $locSource = Location::create(['name' => 'PARTIAL SOURCE LOC', 'setting_id' => $sourceSetting->id]);

        $this->createTerminalAndSaleLocations($terminalSetting, [$locTerminal, $locSource]);
        $methods = $this->seedPaymentMethods($terminalSetting, true);
        $this->openSession($terminalSetting, PosTerminal::where('setting_id', $terminalSetting->id)->first(), $cashier);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);
        $this->assignDefaultWalkInCustomer($sourceSetting);

        $tax = Tax::query()->create(['name' => 'VAT 11 Partial', 'value' => 11, 'is_default' => true]);

        // Parent qty 4 purchased; component quantity_per_bundle = 2 (whole
        // bundle component quantity = 8). We will return only 1 of the 4
        // parent units, so the synthesized component quantity must be
        // exactly 1 * 2 = 2, never the full 8 nor the naive per-checkout
        // persisted SaleBundleItem.quantity (which is already 4*2=8 after
        // checkout — see InlinePosCheckoutPostingAdapter's proportional
        // scaling).
        $parent = $this->createStockedProduct($terminalSetting, $locTerminal, 'PARENT-PARTIAL', 100000, 4, $tax);
        $component = $this->createStockedProduct($sourceSetting, $locSource, 'COMP-PARTIAL', 0, 8, $tax);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parent->id,
            'setting_id' => $terminalSetting->id,
            'name' => 'Partial Bundle',
            'bundle_sale_price' => 100000,
            'price' => 0,
        ]);

        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $component->id, 'quantity' => 2]);

        $this->addCartLine($cashier, $terminalSetting, $parent->id, 4, $bundle->id);
        $this->selectCustomerInCart($cashier, $terminalSetting, $customer);

        $response = $this->finalize($cashier, $terminalSetting, [
            'idempotency_key' => 'K-PARTIAL-BUNDLE-' . uniqid(),
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 400000,
            ],
        ]);

        $response->assertStatus(201);
        $payload = $response->json();

        $posCheckoutId = $payload['pos_checkout_id'];
        $transactionId = (int) PosCheckout::findOrFail($posCheckoutId)->pos_transaction_id;

        $saleIds = array_column($payload['split_groups'], 'sale_id');
        $sales = Sale::with(['saleDetails.bundleItems'])->whereIn('id', $saleIds)->get()->keyBy('setting_id');

        $carrierDetail = $sales->get($sourceSetting->id)->saleDetails->sole();
        $this->assertEquals(0, (int) $carrierDetail->quantity, 'Source owner carrier row must be quantity 0 (fulfills none of the parent).');

        $carrierBundleItem = $carrierDetail->bundleItems->sole();
        $this->assertEquals(8.0, (float) $carrierBundleItem->quantity, 'Sanity: persisted SaleBundleItem.quantity is already scaled to the FULL parent quantity (4 * 2 = 8).');

        $parentDetail = $sales->get($terminalSetting->id)->saleDetails->sole();
        $this->assertEquals(4, (int) $parentDetail->quantity);

        // Partial whole-bundle cash return: only 1 of 4 parent units.
        $snapshot = app(PosReturnSnapshotService::class)->build($transactionId);
        $parentLine = collect($snapshot['lines'])->first(fn ($l) => (int) $l['product_id'] === (int) $parent->id);
        $this->assertNotNull($parentLine);

        $submissionService = app(PosReturnSubmissionService::class);
        $posReturn = $submissionService->store([
            'pos_transaction_id' => $transactionId,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [
                [
                    'sale_detail_id' => $parentLine['sale_detail_id'],
                    'sale_id' => $parentLine['sale_id'],
                    'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN,
                    'quantity' => 1,
                ],
            ],
        ]);

        $this->assertSame(PosReturn::STATUS_DRAFT, $posReturn->status);

        $lines = $posReturn->lines()->get();
        $parentReturnLine = $lines->firstWhere('sale_detail_id', $parentDetail->id);
        $componentReturnLine = $lines->firstWhere(function ($line) use ($carrierDetail, $component) {
            return (int) $line->sale_detail_id === (int) $carrierDetail->id
                && (int) $line->product_id === (int) $component->id;
        });

        $this->assertNotNull($parentReturnLine, 'Parent cash_return line must be present.');
        $this->assertNotNull($componentReturnLine, 'Synthesized component cash_return line must be present, keyed by the carrier row.');

        // Proportional synthesis: returned parent qty (1) * quantity_per_bundle (2) = 2.
        $this->assertEquals(2.0, (float) $componentReturnLine->quantity, 'Synthesized component quantity must equal returned parent quantity * quantity_per_bundle, not the full checkout total.');
        $this->assertEquals(1.0, (float) $parentReturnLine->quantity);

        // The customer refund is carried only by the parent: component
        // internal-allocation lines must never carry a nonzero refund
        // amount of their own (unit_price = 0 allocation convention).
        $this->assertEquals(0.0, (float) ($componentReturnLine->line_total ?? 0), 'Component synthesized cash_return line must carry zero customer refund amount — the parent alone carries the refund.');

        // 2. Approve/execute and confirm the SaleReturnDetail component
        // quantity persisted for physical/inventory effects is exactly the
        // proportional 2, not 8.
        $posReturn = $submissionService->submitDraftForApproval($posReturn->fresh());
        $plan = app(PosReturnApprovalPreviewPlannerService::class)->plan($posReturn->fresh());
        $this->assertFalse($plan['is_blocked'] ?? true, json_encode($plan['blockers'] ?? []));
        app(PosReturnApprovalPlanPersistenceService::class)->synchronize($posReturn->fresh(), $plan);
        app(PosReturnLifecycleService::class)->executeApprovalFromPreview($posReturn->id);

        $posReturn->refresh();
        $this->assertSame(PosReturn::STATUS_COMPLETED, $posReturn->status);

        $componentSaleReturnDetail = \Modules\SalesReturn\Entities\SaleReturnDetail::query()
            ->where('sale_detail_id', $carrierDetail->id)
            ->where('product_id', $component->id)
            ->first();
        $this->assertNotNull($componentSaleReturnDetail);
        $this->assertEquals(2.0, (float) $componentSaleReturnDetail->quantity, 'Executed component return quantity must remain proportional (2), not the full bundle total (8).');
    }

    private function createSetting(string $name, string $docPrefix, string $salePrefix): Setting
    {
        $suffix = $this->sequence++;
        return Setting::create([
            'company_name' => $name . ' ' . $suffix,
            'company_email' => 'test.partial.' . $suffix . '@example.com',
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
            'code' => 'T-PARTIAL-' . $this->sequence++,
            'name' => 'Partial Terminal',
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
            'name' => 'COA PARTIAL ' . $this->sequence++,
            'account_number' => 'ACC-PARTIAL-' . $this->sequence++,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
        ]);

        $method = PaymentMethod::create(['name' => 'CASH PARTIAL', 'coa_id' => $coaId, 'is_cash' => true]);

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
