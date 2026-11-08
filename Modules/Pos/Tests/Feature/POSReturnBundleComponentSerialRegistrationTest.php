<?php

namespace Modules\Pos\Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Support\ProductBundleResolver;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Entities\PosTransaction;
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
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
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
 * Unknown-serial registration for split-owner bundle components (whole-bundle cash return).
 */
class POSReturnBundleComponentSerialRegistrationTest extends TestCase
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


    private function createUntrackedCarrierContext(): array
    {
        $setting = $this->createSetting('Carrier Only Terminal Setting');
        $ownerB = $this->createSetting('Carrier Only Owner B Setting');

        $cashier = $this->createUserForSetting($setting, 'carrier only cashier', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
            'pos.transactions.view',
        ]);

        $locA = Location::create(['name' => 'CARRIER ONLY LOC A ' . $this->sequence++, 'setting_id' => $setting->id]);
        $locB = Location::create(['name' => 'CARRIER ONLY LOC B ' . $this->sequence++, 'setting_id' => $ownerB->id]);

        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $setting->id, 'location_id' => $locA->id],
            ['is_enabled' => true, 'position' => 1]
        );
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $setting->id, 'location_id' => $locB->id],
            ['is_enabled' => true, 'position' => 2]
        );
        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'T-CARRIER-' . $this->sequence++,
            'name' => 'Carrier Only Terminal',
            'is_active' => true,
        ]);
        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => false,
        ]);

        $methods = $this->seedPaymentMethods($setting, true);
        $session = app(PosSessionLifecycleService::class)->openSession($setting->id, $terminal->id, $cashier->id, 100000, ['100000' => 1], $cashier->id);
        $customer = Customer::factory()->create(['setting_id' => $setting->id]);
        $setting->update(['pos_walk_in_customer_id' => $customer->id]);
        $ownerBCustomer = Customer::factory()->create(['setting_id' => $ownerB->id]);
        $ownerB->update(['pos_walk_in_customer_id' => $ownerBCustomer->id]);

        $tax = Tax::query()->create(['name' => 'VAT 11 Carrier', 'value' => 11, 'is_default' => true]);

        $parent = $this->createProduct($setting, 'CARRIER PARENT', 100000, false, $tax->id);
        ProductStock::create([
            'product_id' => $parent->id,
            'location_id' => $locA->id,
            'quantity' => 5, 'quantity_tax' => 5, 'quantity_non_tax' => 0,
            'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'broken_quantity' => 0,
            'tax_id' => $tax->id,
        ]);
        $component = $this->createProduct($setting, 'CARRIER COMPONENT', 0, false, $tax->id);
        ProductStock::create([
            'product_id' => $component->id,
            'location_id' => $locB->id,
            'quantity' => 5, 'quantity_tax' => 5, 'quantity_non_tax' => 0,
            'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'broken_quantity' => 0,
            'tax_id' => $tax->id,
        ]);
        $bundle = ProductBundle::create([
            'setting_id' => $setting->id,
            'parent_product_id' => $parent->id,
            'name' => 'CARRIER BUNDLE',
            'bundle_sale_price' => 100000,
            'price' => 100000,
        ]);
        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $component->id, 'quantity' => 1]);
        ProductBundleResolver::clearCache();

        return [
            'setting' => $setting,
            'ownerB' => $ownerB,
            'cashier' => $cashier,
            'methods' => $methods,
            'customer' => $customer,
            'locA' => $locA,
            'locB' => $locB,
            'parent' => $parent,
            'component' => $component,
            'bundle' => $bundle,
            'tax' => $tax,
        ];
    }

    private function createProduct(Setting $setting, string $name, float $price, bool $serialRequired, ?int $taxId): Product
    {
        $unit = Unit::firstOrCreate(['name' => 'PCS', 'short_name' => 'PCS']);
        $category = Category::firstOrCreate([
            'category_code' => 'CAT-CARRIER-' . $this->sequence++,
            'category_name' => 'CAT CARRIER NAME ' . $this->sequence++,
            'setting_id' => $setting->id,
            'created_by' => User::first()->id ?? User::factory()->create()->id,
        ]);

        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $name,
            'product_code' => 'PC-CARRIER-' . $this->sequence++,
            'product_price' => $price,
            'product_cost' => $price * 0.5,
            'product_unit' => 'PCS',
            'stock_managed' => true,
            'serial_number_required' => $serialRequired,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => $price,
            'sale_tax_id' => $taxId,
        ]);

        return $product;
    }

    private function createSetting(string $name): Setting
    {
        return Setting::create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . $this->sequence++ . '@example.com',
            'company_phone' => '0812345678',
            'company_address' => 'Address',
            'default_currency_id' => Currency::first()->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'CAR' . $this->sequence,
            'sale_prefix_document' => 'JL',
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

    private function seedPaymentMethods(Setting $setting, bool $enableForSetting = false): array
    {
        $coaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'COA CARRIER ' . $this->sequence++,
            'account_number' => 'ACC-CARRIER-' . $this->sequence++,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
        ]);

        $method = PaymentMethod::create([
            'name' => 'CASH CARRIER ' . $this->sequence++,
            'coa_id' => $coaId,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        if ($enableForSetting) {
            DB::table('setting_pos_payment_methods')->insert([
                'setting_id' => $setting->id,
                'payment_method_id' => $method->id,
                'is_enabled' => true,
            ]);
        }

        return ['cash' => $method];
    }

    private function addCartLine(User $cashier, Setting $setting, int $productId, int $qty, ?int $bundleId = null): int
    {
        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $productId,
                'qty' => $qty,
                'bundle_id' => $bundleId,
            ]);

        $response->assertOk();

        $lines = $response->json('cart_snapshot.lines');
        return (int) end($lines)['line_id'];
    }

    private function selectCustomerInCart(User $cashier, Setting $setting, Customer $customer): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $customer->id,
            ])
            ->assertOk();
    }

    private function finalize(User $cashier, Setting $setting, array $payload)
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), $payload);
    }

    private function checkoutUntrackedBundle(array $context, int $quantity = 1): int
    {
        $this->addCartLine($context['cashier'], $context['setting'], $context['parent']->id, $quantity, $context['bundle']->id);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'IDEM-REG-' . uniqid(),
            'payment' => ['payment_method_id' => $context['methods']['cash']->id, 'amount_paid' => 100000 * $quantity],
        ]);
        $response->assertStatus(201);

        return (int) \Modules\Pos\Entities\PosCheckout::findOrFail($response->json('pos_checkout_id'))->pos_transaction_id;
    }

    private function parentLine(array $context, int $transactionId): array
    {
        $snapshot = app(PosReturnSnapshotService::class)->build($transactionId);

        return [$snapshot, collect($snapshot['lines'])->first(fn ($l) => (int) $l['product_id'] === (int) $context['parent']->id)];
    }

    private function wholeBundleCashReturn(array $context, int $transactionId, array $componentSerials, int $quantity = 1): PosReturn
    {
        [$snapshot, $parentLine] = $this->parentLine($context, $transactionId);

        return app(PosReturnSubmissionService::class)->store([
            'pos_transaction_id' => $transactionId,
            'source_snapshot_hash' => $snapshot['hash'],
            'component_pending_serials' => $componentSerials,
            'lines' => [[
                'sale_detail_id' => $parentLine['sale_detail_id'], 'sale_id' => $parentLine['sale_id'],
                'returned_serial_id' => null, 'resolution' => PosReturnLine::RESOLUTION_CASH_RETURN, 'quantity' => $quantity,
                'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
            ]],
        ]);
    }

    /** @test */
    public function newly_tracked_component_is_not_stranded_and_is_marked_eligible(): void
    {
        $context = $this->createUntrackedCarrierContext();
        $transactionId = $this->checkoutUntrackedBundle($context);
        $context['component']->update(['serial_number_required' => true]);

        [, $parentLine] = $this->parentLine($context, $transactionId);
        $component = collect($parentLine['bundle_items'])->first(fn ($c) => (int) $c['product_id'] === (int) $context['component']->id);

        $this->assertNotNull($component);
        $this->assertEquals(1, $component['returnable_quantity'], 'tracked component without recorded serials keeps its own allocation quantity');
        $this->assertTrue($component['registration_eligible']);
        $this->assertSame('quantity_only', $component['registration_classification']);
        $this->assertNotSame((int) $parentLine['sale_detail_id'], (int) $component['sale_detail_id'], 'component keeps its own carrier row identity');
    }

    /** @test */
    public function whole_bundle_cash_return_requires_one_serial_per_component_unit(): void
    {
        $context = $this->createUntrackedCarrierContext();
        $transactionId = $this->checkoutUntrackedBundle($context);
        $context['component']->update(['serial_number_required' => true]);

        $this->expectExceptionMessage('Nomor seri wajib diisi untuk setiap unit komponen');
        $this->wholeBundleCashReturn($context, $transactionId, []);
    }

    /** @test */
    public function whole_bundle_cash_return_registers_component_serial_with_component_owner_and_location(): void
    {
        $context = $this->createUntrackedCarrierContext();
        $transactionId = $this->checkoutUntrackedBundle($context);
        $context['component']->update(['serial_number_required' => true]);
        [, $parentLine] = $this->parentLine($context, $transactionId);
        $component = collect($parentLine['bundle_items'])->first(fn ($c) => (int) $c['product_id'] === (int) $context['component']->id);

        $this->actingAs($context['cashier'])->withSession(['setting_id' => $context['setting']->id]);
        $return = $this->wholeBundleCashReturn($context, $transactionId, [$component['sale_detail_id'] . ':' . $context['component']->id => ['cmp-new-1']]);

        $claim = \Modules\SalesReturn\Entities\ReturnSerialRegistration::sole();
        $this->assertSame((int) $context['component']->id, (int) $claim->product_id, 'claim belongs to the component product, not the parent');
        $this->assertSame(0, ProductSerialNumber::where('serial_number', 'CMP-NEW-1')->count());
        $line = $return->lines()->where('product_id', $context['component']->id)->firstOrFail();
        $this->assertSame((int) $context['ownerB']->id, (int) $line->source_setting_id, 'component source owner');
        $this->assertSame($claim->pos_return_line_id, $line->id);

        $this->assertSame(0, ProductSerialNumber::where('product_id', $context['parent']->id)->where('serial_number', 'CMP-NEW-1')->count());
    }

    /** @test */
    public function approval_registers_the_component_serial_in_the_component_owners_location_and_stock(): void
    {
        $context = $this->createUntrackedCarrierContext();
        $transactionId = $this->checkoutUntrackedBundle($context);
        $context['component']->update(['serial_number_required' => true]);
        [, $parentLine] = $this->parentLine($context, $transactionId);
        $component = collect($parentLine['bundle_items'])->first(fn ($c) => (int) $c['product_id'] === (int) $context['component']->id);

        foreach (['pos.returns.edit', 'pos.returns.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $approver = $this->createUserForSetting($context['setting'], 'registration approver', ['pos.access', 'pos.returns.create', 'pos.returns.approve', 'pos.returns.view', 'pos.returns.edit']);
        $this->actingAs($approver)->withSession(['setting_id' => $context['setting']->id]);

        $return = $this->wholeBundleCashReturn($context, $transactionId, [$component['sale_detail_id'] . ':' . $context['component']->id => ['cmp-new-2']]);
        $return = app(PosReturnSubmissionService::class)->submitDraftForApproval($return);
        $stockBefore = (float) ProductStock::where('product_id', $context['component']->id)->where('location_id', $context['locB']->id)->sum('quantity');

        $this->get(route('pos.returns.approval-preview', $return))->assertOk();
        $this->post(route('pos.returns.approve', $return));

        $this->assertSame(PosReturn::STATUS_COMPLETED, $return->fresh()->status);
        $serial = ProductSerialNumber::where('serial_number', 'CMP-NEW-2')->firstOrFail();
        $this->assertSame((int) $context['component']->id, (int) $serial->product_id);
        $this->assertSame((int) $context['locB']->id, (int) $serial->location_id, 'component owner location, not the parent owner');
        $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, $serial->status);
        $this->assertSame((int) $context['tax']->id, (int) $serial->tax_id);
        $this->assertSame($stockBefore + 1, (float) ProductStock::where('product_id', $context['component']->id)->where('location_id', $context['locB']->id)->sum('quantity'));
        $this->assertNotNull(\Modules\SalesReturn\Entities\ReturnSerialRegistration::sole()->registered_serial_id);
    }

    private function componentReplacementReturn(array $context, int $transactionId, array $originalTexts, array $replacementIds): PosReturn
    {
        [$snapshot, $parentLine] = $this->parentLine($context, $transactionId);
        $key = collect($parentLine['bundle_items'])->first(fn ($c) => (int) $c['product_id'] === (int) $context['component']->id)['sale_detail_id'] . ':' . $context['component']->id;

        return app(PosReturnSubmissionService::class)->store([
            'pos_transaction_id' => $transactionId,
            'source_snapshot_hash' => $snapshot['hash'],
            'component_pending_serials' => [$key => $originalTexts],
            'component_replacement_serials' => [$key => $replacementIds],
            'lines' => [[
                'sale_detail_id' => $parentLine['sale_detail_id'], 'sale_id' => $parentLine['sale_id'],
                'returned_serial_id' => null, 'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT, 'quantity' => 1,
                'replacement_reason' => 'Komponen rusak', 'bundle_return_scope' => PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE,
            ]],
        ]);
    }

    private function componentSerial(array $context, string $text): ProductSerialNumber
    {
        return ProductSerialNumber::create([
            'product_id' => $context['component']->id, 'location_id' => $context['locB']->id, 'serial_number' => $text,
            'tax_id' => $context['tax']->id, 'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);
    }

    /** @test */
    public function split_owner_component_replacement_rejects_wrong_sku_missing_and_duplicate_replacements_without_effects(): void
    {
        $context = $this->createUntrackedCarrierContext();
        $transactionId = $this->checkoutUntrackedBundle($context);
        $context['component']->update(['serial_number_required' => true]);
        $this->actingAs($context['cashier'])->withSession(['setting_id' => $context['setting']->id]);

        $wrongSku = ProductSerialNumber::create([
            'product_id' => $context['parent']->id, 'location_id' => $context['locA']->id, 'serial_number' => 'WRONG-SKU',
            'tax_id' => $context['tax']->id, 'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);
        $extra = $this->componentSerial($context, 'CMP-X-1');
        $extra2 = $this->componentSerial($context, 'CMP-X-2');
        foreach ([[[$wrongSku->id], 'product_id'], [[$extra->id, $extra2->id], 'tepat satu serial pengganti']] as [$ids, $needle]) {
            try {
                $this->componentReplacementReturn($context, $transactionId, ['cmp-orig-1'], $ids);
                $this->fail('must be rejected');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertStringContainsString($needle, implode(' ', \Illuminate\Support\Arr::flatten($e->errors())));
            } catch (\Exception $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        }

        $this->assertSame(0, PosReturnLine::count());
        $this->assertSame(0, \Modules\SalesReturn\Entities\ReturnSerialRegistration::count());
        $this->assertSame(0, ProductSerialNumber::where('serial_number', 'CMP-ORIG-1')->count());
    }

    /** @test */
    public function split_owner_component_registered_original_is_replaced_from_the_component_owners_stock(): void
    {
        $context = $this->createUntrackedCarrierContext();
        $transactionId = $this->checkoutUntrackedBundle($context);
        $context['component']->update(['serial_number_required' => true]);
        foreach (['pos.returns.edit', 'pos.returns.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $approver = $this->createUserForSetting($context['setting'], 'component replace approver', ['pos.access', 'pos.returns.create', 'pos.returns.approve', 'pos.returns.view', 'pos.returns.edit']);
        $this->actingAs($approver)->withSession(['setting_id' => $context['setting']->id]);
        $replacement = $this->componentSerial($context, 'CMP-REPL-1');

        $return = $this->componentReplacementReturn($context, $transactionId, ['cmp-orig-1'], [$replacement->id]);
        $componentLine = $return->lines()->where('product_id', $context['component']->id)->firstOrFail();
        $this->assertSame(PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT, $componentLine->resolution);
        $this->assertSame($replacement->id, (int) $componentLine->replacement_serial_id);
        $this->assertSame((int) $context['ownerB']->id, (int) $componentLine->source_setting_id, 'component owner, not the parent owner');
        $this->assertSame(0, ProductSerialNumber::where('serial_number', 'CMP-ORIG-1')->count(), 'draft registers nothing');

        $return = app(PosReturnSubmissionService::class)->submitDraftForApproval($return);
        $this->get(route('pos.returns.approval-preview', $return))->assertOk();
        $this->post(route('pos.returns.approve', $return));

        $this->assertSame(PosReturn::STATUS_COMPLETED, $return->fresh()->status);
        $original = ProductSerialNumber::where('serial_number', 'CMP-ORIG-1')->firstOrFail();
        $this->assertSame((int) $context['component']->id, (int) $original->product_id);
        $this->assertSame((int) $context['locB']->id, (int) $original->location_id, 'registered in the component owner location');
        $this->assertSame(ProductSerialNumber::STATUS_SOLD, $replacement->fresh()->status, 'replacement stock left the owner');
        $this->assertNotNull($replacement->fresh()->dispatch_detail_id);
        $this->assertNotNull(\Modules\Sale\Entities\DispatchDetail::where('replacement_returned_serial_id', $original->id)->first(), 'dispatch links the replacement to the registered original');
        $this->assertNotNull(\Modules\SalesReturn\Entities\ReturnSerialRegistration::sole()->registered_serial_id);
        // a replacement does not consume the component entitlement
        [, $parentLine] = $this->parentLine($context, $transactionId);
        $component = collect($parentLine['bundle_items'])->first(fn ($c) => (int) $c['product_id'] === (int) $context['component']->id);
        $this->assertEquals(1, $component['returnable_quantity']);
    }

    /** @test */
    public function two_bundle_units_returned_one_after_another_register_independently_and_exhaust_the_component_once(): void
    {
        $context = $this->createUntrackedCarrierContext();
        $transactionId = $this->checkoutUntrackedBundle($context, 2);
        $context['component']->update(['serial_number_required' => true]);

        foreach (['pos.returns.edit', 'pos.returns.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $approver = $this->createUserForSetting($context['setting'], 'mixed approver', ['pos.access', 'pos.returns.create', 'pos.returns.approve', 'pos.returns.view', 'pos.returns.edit']);
        $this->actingAs($approver)->withSession(['setting_id' => $context['setting']->id]);

        $componentFor = function () use ($context, $transactionId) {
            [, $parentLine] = $this->parentLine($context, $transactionId);

            return [$parentLine, collect($parentLine['bundle_items'] ?? [])->first(fn ($c) => (int) $c['product_id'] === (int) $context['component']->id)];
        };

        [$parentLine, $component] = $componentFor();
        $this->assertEquals(2, $component['returnable_quantity'], 'two untouched component units');
        $key = $component['sale_detail_id'] . ':' . $context['component']->id;

        // Unit 1 is registered through the first return.
        $first = app(PosReturnSubmissionService::class)->submitDraftForApproval($this->wholeBundleCashReturn($context, $transactionId, [$key => ['mix-1']], 1));
        $this->get(route('pos.returns.approval-preview', $first))->assertOk();
        $this->post(route('pos.returns.approve', $first));
        $this->assertSame(PosReturn::STATUS_COMPLETED, $first->fresh()->status);

        [$parentLine, $component] = $componentFor();
        $this->assertEquals(1, $component['returnable_quantity'], 'the registered unit is consumed once; one untouched unit remains');
        $this->assertEquals(1, $parentLine['returnable_quantity'] ?? 1);

        // Asking for two units now must fail without touching the registered one.
        try {
            $this->wholeBundleCashReturn($context, $transactionId, [$key => ['mix-2', 'mix-3']], 2);
            $this->fail('over-claim must be rejected');
        } catch (\Exception $e) {
            $this->assertStringContainsString('melebihi', $e->getMessage());
        }

        // Unit 2 is registered by its own return and exhausts the component.
        $second = app(PosReturnSubmissionService::class)->submitDraftForApproval($this->wholeBundleCashReturn($context, $transactionId, [$key => ['mix-2']], 1));
        $this->get(route('pos.returns.approval-preview', $second))->assertOk();
        $this->post(route('pos.returns.approve', $second));
        $this->assertSame(PosReturn::STATUS_COMPLETED, $second->fresh()->status);

        [, $exhausted] = $this->parentLine($context, $transactionId);
        $this->assertTrue($exhausted === null || (float) ($exhausted['returnable_quantity'] ?? 0) === 0.0, 'nothing of the bundle remains returnable');
        $this->assertSame(['MIX-1', 'MIX-2'], ProductSerialNumber::where('product_id', $context['component']->id)->orderBy('serial_number')->pluck('serial_number')->all());
        $this->assertSame(2, \Modules\SalesReturn\Entities\ReturnSerialRegistration::whereNotNull('registered_serial_id')->count());
    }

    /** @test */
    public function receipt_projection_and_return_history_include_a_registration_origin_return(): void
    {
        $context = $this->createUntrackedCarrierContext();
        $transactionId = $this->checkoutUntrackedBundle($context, 2);
        $context['component']->update(['serial_number_required' => true]);
        foreach (['pos.returns.edit', 'pos.returns.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $approver = $this->createUserForSetting($context['setting'], 'receipt approver', ['pos.access', 'pos.returns.create', 'pos.returns.approve', 'pos.returns.view', 'pos.returns.edit']);
        $this->actingAs($approver)->withSession(['setting_id' => $context['setting']->id]);

        [, $parentLine] = $this->parentLine($context, $transactionId);
        $component = collect($parentLine['bundle_items'])->first(fn ($c) => (int) $c['product_id'] === (int) $context['component']->id);
        $return = app(PosReturnSubmissionService::class)->submitDraftForApproval(
            $this->wholeBundleCashReturn($context, $transactionId, [$component['sale_detail_id'] . ':' . $context['component']->id => ['rcpt-1']], 1)
        );
        $this->get(route('pos.returns.approval-preview', $return))->assertOk();
        $this->post(route('pos.returns.approve', $return));
        $this->assertSame(PosReturn::STATUS_COMPLETED, $return->fresh()->status);

        $checkout = \Modules\Pos\Entities\PosCheckout::query()->where('pos_transaction_id', $transactionId)->firstOrFail();
        $data = app(\Modules\Pos\Services\PosReceiptReprintProjectionService::class)->getCompletedReprintReceiptData($checkout);

        $history = collect($data['return_history']);
        $this->assertCount(1, $history);
        $items = collect($history->first()['items']);
        $registered = $items->firstWhere('product_name', 'CARRIER COMPONENT');
        $this->assertSame('RCPT-1', $registered['returned_serial'], 'the registered serial is shown on the return history');
        $this->assertSame('cash_return', $registered['resolution_code']);
        $this->assertNotNull($registered['pos_transaction_line_id'], 'registration-origin line resolves to its checkout line');
        $this->assertSame($items->firstWhere('product_name', 'CARRIER PARENT')['pos_transaction_line_id'], $registered['pos_transaction_line_id']);
        $this->assertSame(100000.0, (float) $history->first()['total_amount']);
        $this->assertSame(1, $history->count(), 'no duplicate occurrence');
    }

    /** @test */
    public function receipt_projection_shows_a_registered_original_replaced_on_a_real_checkout_line(): void
    {
        $context = $this->createUntrackedCarrierContext();
        $plain = $this->createProduct($context['setting'], 'PLAIN ITEM', 50000, false, $context['tax']->id);
        ProductStock::create([
            'product_id' => $plain->id, 'location_id' => $context['locA']->id, 'quantity' => 5, 'quantity_tax' => 5, 'quantity_non_tax' => 0,
            'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'broken_quantity' => 0, 'tax_id' => $context['tax']->id,
        ]);
        $this->addCartLine($context['cashier'], $context['setting'], $plain->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);
        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'IDEM-REG-' . uniqid(),
            'payment' => ['payment_method_id' => $context['methods']['cash']->id, 'amount_paid' => 50000],
        ]);
        $response->assertStatus(201);
        $transactionId = (int) \Modules\Pos\Entities\PosCheckout::findOrFail($response->json('pos_checkout_id'))->pos_transaction_id;
        $context['parent'] = $plain;
        $plain->update(['serial_number_required' => true]);
        foreach (['pos.returns.edit', 'pos.returns.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $approver = $this->createUserForSetting($context['setting'], 'replace approver', ['pos.access', 'pos.returns.create', 'pos.returns.approve', 'pos.returns.view', 'pos.returns.edit']);
        $this->actingAs($approver)->withSession(['setting_id' => $context['setting']->id]);

        [$snapshot, $parentLine] = $this->parentLine($context, $transactionId);
        $replacement = ProductSerialNumber::create([
            'product_id' => $context['parent']->id, 'location_id' => $context['locA']->id, 'serial_number' => 'PAR-REPL-1',
            'tax_id' => $context['tax']->id, 'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);
        $return = app(PosReturnSubmissionService::class)->store([
            'pos_transaction_id' => $transactionId,
            'source_snapshot_hash' => $snapshot['hash'],
            'lines' => [[
                'sale_detail_id' => $parentLine['sale_detail_id'], 'sale_id' => $parentLine['sale_id'], 'returned_serial_id' => null,
                'pending_serial' => 'par-orig-1', 'resolution' => PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT, 'quantity' => 1,
                'replacement_serial_id' => $replacement->id, 'replacement_reason' => 'Rusak',
                ]],
        ]);
        $return = app(PosReturnSubmissionService::class)->submitDraftForApproval($return);
        $this->get(route('pos.returns.approval-preview', $return))->assertOk();
        $this->post(route('pos.returns.approve', $return));

        $checkout = \Modules\Pos\Entities\PosCheckout::query()->where('pos_transaction_id', $transactionId)->firstOrFail();
        $data = app(\Modules\Pos\Services\PosReceiptReprintProjectionService::class)->getCompletedReprintReceiptData($checkout);

        $this->assertSame(PosReturn::STATUS_COMPLETED, $return->fresh()->status);
        $item = collect($data['return_history'])->flatMap(fn ($h) => $h['items'])->first();
        $this->assertSame('PAR-ORIG-1', $item['returned_serial'], 'the registered original is shown');
        $this->assertSame('PAR-REPL-1', $item['replacement_serial']);
        $line = collect($data['lines'])->first(fn ($l) => str_contains(json_encode($l), 'PAR-REPL-1'));
        $this->assertNotNull($line, 'the receipt line now shows the replacement serial the customer holds');
        $this->assertStringNotContainsString('PAR-ORIG-1', json_encode($data['lines']), 'the unregistered original was never on the receipt');
    }

    /** Logged-in approver + checkout of a 1-unit untracked bundle whose component now requires tracking. */
    private function componentFormContext(): array
    {
        $context = $this->createUntrackedCarrierContext();
        $transactionId = $this->checkoutUntrackedBundle($context);
        $context['component']->update(['serial_number_required' => true]);
        foreach (['pos.returns.edit', 'pos.returns.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $approver = $this->createUserForSetting($context['setting'], 'form approver ' . uniqid(), ['pos.access', 'pos.returns.create', 'pos.returns.approve', 'pos.returns.view', 'pos.returns.edit']);
        $this->actingAs($approver)->withSession(['setting_id' => $context['setting']->id]);

        [, $parentLine] = $this->parentLine($context, $transactionId);
        $context['transactionId'] = $transactionId;
        $context['parentKey'] = \Modules\Pos\Livewire\PosReturn\PosReturnCreateForm::buildLineKey($parentLine);
        $context['componentKey'] = collect($parentLine['bundle_items'])->first(fn ($c) => (int) $c['product_id'] === (int) $context['component']->id)['sale_detail_id'] . ':' . $context['component']->id;
        $context['receipt'] = \Modules\Pos\Entities\PosCheckout::query()->where('pos_transaction_id', $transactionId)->value('receipt_number');

        return $context;
    }

    private function openCreateForm(array $c)
    {
        return \Livewire\Livewire::test(\Modules\Pos\Livewire\PosReturn\PosReturnCreateForm::class)
            ->set('identifier', $c['receipt'])->call('lookup')->assertSet('error', null)
            ->call('updateResolution', $c['parentKey'], 'product_replacement')
            ->set("lineSelections.{$c['parentKey']}.quantity", 1)
            ->set("lineSelections.{$c['parentKey']}.replacement_reason", 'Komponen rusak');
    }

    /** @test */
    public function create_form_submits_a_component_replacement_with_per_unit_original_and_replacement_serials(): void
    {
        $c = $this->componentFormContext();
        $replacement = $this->componentSerial($c, 'CMP-FORM-R1');

        $form = $this->openCreateForm($c)
            ->set("componentPendingInput.{$c['componentKey']}", 'cmp-form-o1')->call('addComponentPendingSerial', $c['componentKey'])
            ->set("componentReplacementInput.{$c['componentKey']}.0", 'cmp-form-r1')->call('scanComponentReplacementSerial', $c['componentKey'], 0)
            ->assertHasNoErrors()
            ->assertSet("componentReplacementSerials.{$c['componentKey']}.0.id", $replacement->id);

        $form->call('submit');

        $return = PosReturn::latest('id')->firstOrFail();
        $this->assertSame(PosReturn::STATUS_DRAFT, $return->status);
        $line = $return->lines()->where('product_id', $c['component']->id)->firstOrFail();
        $this->assertSame(PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT, $line->resolution);
        $this->assertSame($replacement->id, (int) $line->replacement_serial_id);
        $this->assertSame('CMP-FORM-O1', \Modules\SalesReturn\Entities\ReturnSerialRegistration::sole()->proposed_serial);
        $this->assertSame(0, ProductSerialNumber::where('serial_number', 'CMP-FORM-O1')->count(), 'draft registers nothing');
    }

    /** @test */
    public function create_form_reports_component_replacement_validation_errors_without_saving(): void
    {
        $c = $this->componentFormContext();
        $other = $this->componentSerial($c, 'CMP-FORM-R2');
        $wrongSku = ProductSerialNumber::create([
            'product_id' => $c['parent']->id, 'location_id' => $c['locA']->id, 'serial_number' => 'PARENT-SERIAL',
            'tax_id' => $c['tax']->id, 'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);
        $field = "componentReplacementInput.{$c['componentKey']}.0";

        $form = $this->openCreateForm($c)
            // a replacement before its unit exists
            ->set($field, 'cmp-form-r2')->call('scanComponentReplacementSerial', $c['componentKey'], 0)->assertHasErrors([$field])
            ->set("componentPendingInput.{$c['componentKey']}", 'cmp-form-o2')->call('addComponentPendingSerial', $c['componentKey'])
            // unknown, and a serial of another product (SKU)
            ->set($field, 'does-not-exist')->call('scanComponentReplacementSerial', $c['componentKey'], 0)->assertHasErrors([$field])
            ->set($field, 'parent-serial')->call('scanComponentReplacementSerial', $c['componentKey'], 0)->assertHasErrors([$field]);
        $this->assertNull($form->get("componentReplacementSerials.{$c['componentKey']}.0"));

        // a registered/duplicate original is refused by the existing validator
        $form->set("componentPendingInput.{$c['componentKey']}", 'CMP-FORM-R2')->call('addComponentPendingSerial', $c['componentKey'])
            ->assertHasErrors(["componentPendingInput.{$c['componentKey']}"]);

        // submitting without the unit's replacement serial is refused and saves nothing
        $form->call('submit')->assertHasErrors([$field]);
        $this->assertSame(0, PosReturn::count());
        $this->assertSame(0, \Modules\SalesReturn\Entities\ReturnSerialRegistration::count());
        $this->assertSame(ProductSerialNumber::STATUS_ACTIVE, $other->fresh()->status);
    }

    /** @test */
    public function edit_form_reloads_the_component_pairing_and_saves_corrections(): void
    {
        $c = $this->componentFormContext();
        $first = $this->componentSerial($c, 'CMP-EDIT-R1');
        $second = $this->componentSerial($c, 'CMP-EDIT-R2');

        $this->openCreateForm($c)
            ->set("componentPendingInput.{$c['componentKey']}", 'cmp-edit-o1')->call('addComponentPendingSerial', $c['componentKey'])
            ->set("componentReplacementInput.{$c['componentKey']}.0", 'cmp-edit-r1')->call('scanComponentReplacementSerial', $c['componentKey'], 0)
            ->call('submit');
        $return = PosReturn::latest('id')->firstOrFail();

        $edit = \Livewire\Livewire::test(\Modules\Pos\Livewire\PosReturn\PosReturnEditForm::class, ['return' => $return])
            ->assertSet("lineSelections.{$c['parentKey']}.resolution", 'product_replacement')
            ->assertSet("componentPendingSerials.{$c['componentKey']}.0", 'CMP-EDIT-O1')
            ->assertSet("componentReplacementSerials.{$c['componentKey']}.0.id", $first->id)
            ->assertSet("componentReplacementSerials.{$c['componentKey']}.0.label", 'CMP-EDIT-R1');

        // correct the replacement, then the original
        $edit->call('clearComponentReplacementSerial', $c['componentKey'], 0)
            ->set("componentReplacementInput.{$c['componentKey']}.0", 'cmp-edit-r2')->call('scanComponentReplacementSerial', $c['componentKey'], 0)
            ->assertHasNoErrors()
            ->call('submit');

        $line = $return->fresh()->lines()->where('product_id', $c['component']->id)->sole();
        $this->assertSame($second->id, (int) $line->replacement_serial_id);
        $this->assertSame('CMP-EDIT-O1', \Modules\SalesReturn\Entities\ReturnSerialRegistration::sole()->proposed_serial);

        $again = \Livewire\Livewire::test(\Modules\Pos\Livewire\PosReturn\PosReturnEditForm::class, ['return' => $return->fresh()])
            ->call('removeComponentPendingSerial', $c['componentKey'], 0)
            ->set("componentPendingInput.{$c['componentKey']}", 'cmp-edit-o3')->call('addComponentPendingSerial', $c['componentKey'])
            ->assertSet("componentReplacementSerials.{$c['componentKey']}", [])
            ->call('submit')->assertHasErrors(["componentReplacementInput.{$c['componentKey']}.0"]);
        $this->assertSame('CMP-EDIT-O1', \Modules\SalesReturn\Entities\ReturnSerialRegistration::sole()->proposed_serial, 'a refused save keeps the stored draft');
    }

    /** @test */
    public function approval_preview_shows_the_component_original_and_its_replacement(): void
    {
        $c = $this->componentFormContext();
        $this->componentSerial($c, 'CMP-PREV-R1');

        $this->openCreateForm($c)
            ->set("componentPendingInput.{$c['componentKey']}", 'cmp-prev-o1')->call('addComponentPendingSerial', $c['componentKey'])
            ->set("componentReplacementInput.{$c['componentKey']}.0", 'cmp-prev-r1')->call('scanComponentReplacementSerial', $c['componentKey'], 0)
            ->call('submit');
        $return = app(PosReturnSubmissionService::class)->submitDraftForApproval(PosReturn::latest('id')->firstOrFail());

        $before = [ProductSerialNumber::count(), \Modules\SalesReturn\Entities\ReturnSerialRegistration::whereNotNull('registered_serial_id')->count()];
        $this->get(route('pos.returns.approval-preview', $return))->assertOk()
            ->assertSee('CMP-PREV-O1')->assertSee('CMP-PREV-R1')->assertSee('serial_baru_didaftarkan_saat_receiving', false);
        $this->assertSame($before, [ProductSerialNumber::count(), \Modules\SalesReturn\Entities\ReturnSerialRegistration::whereNotNull('registered_serial_id')->count()], 'preview mutates nothing');
    }
}
