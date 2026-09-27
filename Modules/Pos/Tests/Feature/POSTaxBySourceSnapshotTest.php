<?php

namespace Modules\Pos\Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\SaleDetail;
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
 * @group pos-critical-path
 */
class POSTaxBySourceSnapshotTest extends TestCase
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
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    /**
     * POS-TM-050: Tax follows source owner (single source, PKP owner)
     */
    public function test_tax_follows_source_owner_pkp_source(): void
    {
        // 1. Setup Source Owner (PKP)
        $ownerSetting = $this->createSetting('SOURCE-PKP-BIZ', true);
        $sourceLocation = $this->createLocation($ownerSetting, 'PKP-LOC');

        // 2. Setup Terminal Business (Non-PKP Borrower)
        $borrowerSetting = $this->createSetting('TERMINAL-NON-PKP-BIZ', false);
        $terminal = $this->createTerminalForSetting($borrowerSetting, $sourceLocation->id);
        $cashier = $this->createUserForSetting($borrowerSetting, 'cashier', ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment']);
        $customer = $this->assignDefaultWalkInCustomer($borrowerSetting);
        $methods = $this->seedPaymentMethods($borrowerSetting);
        $this->assignSaleLocation($borrowerSetting, $sourceLocation);

        // 3. Create Product with 10% Tax
        $tax = Tax::create(['name' => 'VAT 10%', 'value' => 10]);
        $product = $this->createStockedProduct($borrowerSetting, $sourceLocation, 'PROD-T-001', 100000);
        $this->applySaleTax($product, $borrowerSetting, $tax);
        $this->seedStock($product, $sourceLocation, 10, true); // Taxed bucket

        // 4. Open Session and Finalize Checkout
        $session = $this->openSession($borrowerSetting, $terminal, $cashier);
        $this->selectCustomerInCart($cashier, $borrowerSetting, $customer);
        $this->addCartLine($cashier, $borrowerSetting, $product->id, 1);

        $response = $this->finalize($cashier, $borrowerSetting, [
            'idempotency_key' => 'K-TAX-PKP-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 100000, // Gross/final price
            ],
        ]);

        $response->assertStatus(201);
        $checkoutId = $response->json('pos_checkout_id');

        // 5. Verification
        // Check SaleDetail has tax
        $this->assertDatabaseHas('sale_details', [
            'sale_id' => $response->json('sale_id'),
            'product_id' => $product->id,
            'product_tax_amount' => 9091,
        ]);

        // Check DispatchDetail has tax_id
        $this->assertDatabaseHas('dispatch_details', [
            'dispatch_id' => $response->json('dispatch_ids.0'),
            'tax_id' => $tax->id,
        ]);

        // Check PosCheckout totals
        $this->assertDatabaseHas('pos_checkouts', [
            'id' => $checkoutId,
            'tax_total' => 9091,
            'grand_total' => 100000,
        ]);
    }

    /**
     * POS-TM-050 Variant: Non-PKP source zeroes tax regardless of product config
     */
    public function test_tax_follows_source_owner_non_pkp_source_zeroes_tax(): void
    {
        // 1. Setup Source Owner (Non-PKP)
        $ownerSetting = $this->createSetting('SOURCE-NON-PKP-BIZ', false);
        $sourceLocation = $this->createLocation($ownerSetting, 'NON-PKP-LOC');

        // 2. Setup Terminal Business (PKP Borrower)
        $borrowerSetting = $this->createSetting('TERMINAL-PKP-BIZ', true);
        $terminal = $this->createTerminalForSetting($borrowerSetting, $sourceLocation->id);
        $cashier = $this->createUserForSetting($borrowerSetting, 'cashier', ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment']);
        $customer = $this->assignDefaultWalkInCustomer($borrowerSetting);
        $methods = $this->seedPaymentMethods($borrowerSetting);
        $this->assignSaleLocation($borrowerSetting, $sourceLocation);

        // 3. Create Product with 10% Tax
        $tax = Tax::create(['name' => 'VAT 10%', 'value' => 10]);
        $product = $this->createStockedProduct($borrowerSetting, $sourceLocation, 'PROD-T-002', 100000);
        $this->applySaleTax($product, $borrowerSetting, $tax);
        $this->seedStock($product, $sourceLocation, 10, false); // Non-taxed bucket

        // 4. Open Session and Finalize Checkout
        $session = $this->openSession($borrowerSetting, $terminal, $cashier);
        $this->selectCustomerInCart($cashier, $borrowerSetting, $customer);
        $this->addCartLine($cashier, $borrowerSetting, $product->id, 1);

        $response = $this->finalize($cashier, $borrowerSetting, [
            'idempotency_key' => 'K-TAX-NON-PKP-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 100000, // Gross/final price
            ],
        ]);

        $response->assertStatus(201);
        
        // 5. Verification
        // Check SaleDetail has ZERO tax because source owner is non-PKP
        $this->assertDatabaseHas('sale_details', [
            'sale_id' => $response->json('sale_id'),
            'product_id' => $product->id,
            'product_tax_amount' => 0,
        ]);

        // Check DispatchDetail has NULL tax_id
        $this->assertDatabaseHas('dispatch_details', [
            'dispatch_id' => $response->json('dispatch_ids.0'),
            'tax_id' => null,
        ]);

        // Check PosCheckout totals
        $this->assertDatabaseHas('pos_checkouts', [
            'id' => $response->json('pos_checkout_id'),
            'tax_total' => 0,
            'grand_total' => 100000,
        ]);
    }

    /**
     * POS-TM-051: Mixed tax outcomes in split allocation with owner-priority
     * When allocating taxable non-serial lines, non-PKP sources are prioritized first,
     * then PKP sources. This ensures non-tax inventory is consumed before tax inventory.
     */
    public function test_mixed_tax_outcomes_in_split_allocation(): void
    {
        // 1. Setup Businesses
        $bizPKP = $this->createSetting('BIZ-PKP', true);
        $bizNonPKP = $this->createSetting('BIZ-NON-PKP', false);

        $locPKP = $this->createLocation($bizPKP, 'A-LOC-PKP');
        $locNonPKP = $this->createLocation($bizNonPKP, 'B-LOC-NON-PKP');

        // 2. Setup Terminal Business (PKP)
        $terminalSetting = $this->createSetting('TERMINAL-BIZ', true);
        $terminal = $this->createTerminalForSetting($terminalSetting, $locPKP->id);
        $cashier = $this->createUserForSetting($terminalSetting, 'cashier', ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment']);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);
        $methods = $this->seedPaymentMethods($terminalSetting);

        // Sale locations assigned in order: PKP then non-PKP
        // With owner-priority: non-PKP will be checked first, then PKP
        $this->assignSaleLocation($terminalSetting, $locPKP);
        $this->assignSaleLocation($terminalSetting, $locNonPKP);

        // 3. Create Product with 10% Tax
        $tax = Tax::create(['name' => 'VAT 10%', 'value' => 10]);
        $product = $this->createStockedProduct($terminalSetting, $locPKP, 'PROD-MIX-001', 100000);
        $this->applySaleTax($product, $terminalSetting, $tax);

        // Stock: 3 units taxed in PKP loc, 5 units non-taxed in non-PKP loc
        $this->seedStock($product, $locPKP, 3, true);
        $this->seedStock($product, $locNonPKP, 5, false);

        // 4. Finalize Checkout for 5 units (taxable line)
        $this->openSession($terminalSetting, $terminal, $cashier);
        $this->selectCustomerInCart($cashier, $terminalSetting, $customer);
        $this->addCartLine($cashier, $terminalSetting, $product->id, 5);

        // Expected with owner-priority allocation:
        // Non-PKP location is checked first (lower priority), has 5 non-taxed units
        // -> 5 units from locNonPKP (non-PKP source) -> non-taxed (0 tax)
        // -> PKP location is skipped (no more units needed)
        // Total: All 5 units are non-taxed because they come from non-PKP source owner

        $response = $this->finalize($cashier, $terminalSetting, [
            'idempotency_key' => 'K-TAX-MIXED-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 500000, // Gross/final price
            ],
        ]);

        $response->assertStatus(201);

        // 5. Verification
        $this->assertDatabaseHas('sale_details', [
            'sale_id' => $response->json('sale_id'),
            'product_id' => $product->id,
            'quantity' => 5,
            'product_tax_amount' => 0, // All units from non-PKP source, so no tax
        ]);

        // All 5 units should be dispatched from non-PKP location without tax
        $this->assertDatabaseHas('dispatch_details', [
            'dispatch_id' => $response->json('dispatch_ids.0'),
            'dispatched_quantity' => 5,
            'tax_id' => null,
        ]);

        $this->assertDatabaseHas('pos_checkouts', [
            'id' => $response->json('pos_checkout_id'),
            'tax_total' => 0,
            'grand_total' => 500000,
        ]);

        // Check stock buckets: non-PKP location should be depleted
        $this->assertEquals(3, ProductStock::where('product_id', $product->id)->where('location_id', $locPKP->id)->first()->quantity_tax);  // Unchanged
        $this->assertEquals(0, ProductStock::where('product_id', $product->id)->where('location_id', $locNonPKP->id)->first()->quantity_non_tax); // 5 - 5
    }

    /**
     * POS-TM-052: Historical stability after tax config change
     */
    public function test_historical_stability_after_tax_config_change(): void
    {
        // 1. Setup Success Checkout with Tax
        $setting = $this->createSetting('STABILITY-BIZ', true);
        $location = $this->createLocation($setting, 'LOC-1');
        $terminal = $this->createTerminalForSetting($setting, $location->id);
        $cashier = $this->createUserForSetting($setting, 'cashier', ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment']);
        $customer = $this->assignDefaultWalkInCustomer($setting);
        $methods = $this->seedPaymentMethods($setting);
        $this->assignSaleLocation($setting, $location);

        $tax = Tax::create(['name' => 'VAT 10%', 'value' => 10]);
        $product = $this->createStockedProduct($setting, $location, 'PROD-S-001', 100000);
        $this->applySaleTax($product, $setting, $tax);
        $this->seedStock($product, $location, 10, true);

        $this->openSession($setting, $terminal, $cashier);
        $this->selectCustomerInCart($cashier, $setting, $customer);
        $this->addCartLine($cashier, $setting, $product->id, 1);

        $response = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-STABILITY-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 100000,
            ],
        ]);

        $response->assertStatus(201);
        $checkoutId = $response->json('pos_checkout_id');
        $saleId = $response->json('sale_id');

        // 2. Change Config: Business becomes Non-PKP
        $setting->update(['is_pkp' => false]);

        // 3. Verification: Historical values must remain unchanged
        $this->assertDatabaseHas('pos_checkouts', [
            'id' => $checkoutId,
            'tax_total' => 9091,
            'grand_total' => 100000,
        ]);

        $this->assertDatabaseHas('sale_details', [
            'sale_id' => $saleId,
            'product_tax_amount' => 9091,
        ]);
    }

    // --- Helpers ---

    private function createSetting(string $name, bool $isPkp): Setting
    {
        return Setting::create([
            'company_name' => $name . ' ' . $this->sequence++,
            'company_email' => 'biz' . $this->sequence . '@example.com',
            'company_phone' => '0800',
            'company_address' => 'Addr',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'biz' . $this->sequence . '@example.com',
            'footer_text' => 'F',
            'document_prefix' => 'D',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
            'is_pkp' => $isPkp,
        ]);
    }

    private function createLocation(Setting $setting, string $name): Location
    {
        return Location::create([
            'name' => $name,
            'setting_id' => $setting->id,
        ]);
    }

    private function createTerminalForSetting(Setting $setting, ?int $locationId = null): PosTerminal
    {
        if (!$locationId) {
            $locationId = $this->createLocation($setting, 'DEFAULT-LOC')->id;
        }

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'TERM-' . $this->sequence++,
            'name' => 'Terminal ' . $this->sequence,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'cash_threshold' => 0,
            'require_pickup_supervisor_approval' => true,
            'cash_threshold' => 50000,
        ]);

        return $terminal;
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

    private function assignDefaultWalkInCustomer(Setting $setting): Customer
    {
        $customer = Customer::factory()->create(['setting_id' => $setting->id]);
        $setting->update(['pos_walk_in_customer_id' => $customer->id]);
        
        return $customer;
    }

    private function assignSaleLocation(Setting $setting, Location $location): void
    {
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $setting->id, 'location_id' => $location->id],
            ['is_enabled' => true]
        );
    }

    private function createStockedProduct(Setting $setting, Location $location, string $code, float $price): Product
    {
        $category = Category::create([
            'category_code' => $code . '-C',
            'category_name' => 'Cat',
            'setting_id' => $setting->id,
            'created_by' => 1,
        ]);

        $unit = Unit::firstOrCreate(['name' => 'PC', 'short_name' => 'PC']);

        return Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'product_name' => $code,
            'product_code' => $code,
            'product_quantity' => 0,
            'product_price' => $price,
            'product_cost' => $price * 0.5,
            'product_unit' => 'PC',
            'stock_managed' => true,
        ]);
    }

    private function seedStock(Product $product, Location $location, int $qty, bool $taxed): void
    {
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $qty,
            'quantity_non_tax' => $taxed ? 0 : $qty,
            'quantity_tax' => $taxed ? $qty : 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);
        $product->increment('product_quantity', $qty);
    }

    private function applySaleTax(Product $product, Setting $setting, Tax $tax): void
    {
        ProductPrice::updateOrCreate([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
        ], [
            'sale_price' => $product->product_price,
            'sale_tax_id' => $tax->id,
        ]);
    }

    private function openSession(Setting $setting, PosTerminal $terminal, User $cashier): PosSession
    {
        /** @var PosSessionLifecycleService $service */
        $service = app(PosSessionLifecycleService::class);
        return $service->openSession(
            $setting->id,
            $terminal->id,
            $cashier->id,
            100000,
            ['100000' => 1],
            $cashier->id
        );
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

    private function addCartLine(User $cashier, Setting $setting, int $productId, int $qty): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $productId,
                'qty' => $qty,
            ])
            ->assertOk();
    }

    private function finalize(User $cashier, Setting $setting, array $payload)
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), $payload);
    }

    private function seedPaymentMethods(Setting $setting): array
    {
        $methods = [];
        
        foreach (['CASH' => true, 'TRANSFER' => false, 'QRIS' => false] as $name => $isCash) {
            $methodSuffix = $this->sequence++;
            $coaId = DB::table('chart_of_accounts')->insertGetId([
                'name' => "COA $name " . $methodSuffix,
                'account_number' => "ACC-$name-" . $methodSuffix,
                'category' => 'Kas & Bank',
                'setting_id' => $setting->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $methods[strtolower($name)] = PaymentMethod::create([
                'name' => "$name POS $methodSuffix",
                'coa_id' => $coaId,
                'is_cash' => $isCash,
                'requires_reference' => !$isCash,
            ]);

            DB::table('setting_pos_payment_methods')->updateOrInsert(
                [
                    'setting_id' => $setting->id,
                    'payment_method_id' => $methods[strtolower($name)]->id,
                ],
                [
                    'is_enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        return $methods;
    }
}
