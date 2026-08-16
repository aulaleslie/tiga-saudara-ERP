<?php

namespace Modules\Pos\Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
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

/**
 * @group pos-critical-path
 */
class POSMixedOwnerTaxBySourceTest extends TestCase
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
     * Test 4.1: Serial-assigned taxable line with non-PKP source owner
     * should persist non-tax outcome despite serial having tax metadata
     */
    public function test_mixed_owner_serial_chunk_with_non_pkp_source_persists_non_tax(): void
    {
        // Setup: Non-PKP source owner with PKP terminal business
        $nonPkpSource = $this->createSetting('NON-PKP-SOURCE', false);
        $this->assignDefaultWalkInCustomer($nonPkpSource);
        $sourceLocation = $this->createLocation($nonPkpSource, 'NON-PKP-LOC');

        $pkpTerminal = $this->createSetting('PKP-TERMINAL', true);
        $terminal = $this->createTerminalForSetting($pkpTerminal, $sourceLocation->id);
        $cashier = $this->createUserForSetting($pkpTerminal, 'cashier-' . $this->sequence, ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment']);
        $customer = $this->assignDefaultWalkInCustomer($pkpTerminal);
        $methods = $this->seedPaymentMethods($pkpTerminal);
        $this->assignSaleLocation($pkpTerminal, $sourceLocation);

        // Product with 10% tax
        $seq = $this->sequence++;
        $tax = Tax::create(['name' => 'VAT 10%', 'value' => 10]);
        $product = $this->createStockedProduct($pkpTerminal, $sourceLocation, 'PROD-MIX-SERIAL-' . $seq, 100000, true);
        $this->applySaleTax($product, $pkpTerminal, $tax);

        // Create serials owned by non-PKP location
        $sn1 = $this->createSerialNumber($product, $sourceLocation, 'SN-1-' . $seq, null);
        $sn2 = $this->createSerialNumber($product, $sourceLocation, 'SN-2-' . $seq, null);

        // Setup stock at non-PKP location: 2 non-tax units
        $stock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $sourceLocation->id)
            ->first();
        $stock->update([
            'quantity' => 2,
            'quantity_tax' => 0,
            'quantity_non_tax' => 2,
        ]);

        // Open session and checkout
        $session = $this->openSession($pkpTerminal, $terminal, $cashier);
        $this->selectCustomerInCart($cashier, $pkpTerminal, $customer);
        $this->addCartLine($cashier, $pkpTerminal, $product->id, 2);

        // Get line ID to assign serials
        $snapshot = $this->cartSnapshot($cashier, $pkpTerminal);
        $lineId = $snapshot['lines'][0]['line_id'];

        // Assign both serials
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $pkpTerminal->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => $lineId]), [
                'serial_numbers' => ['SN-1-' . $seq, 'SN-2-' . $seq],
            ])
            ->assertOk();

        // Finalize checkout
        $response = $this->finalize($cashier, $pkpTerminal, [
            'idempotency_key' => 'K-MIX-SERIAL-' . $seq,
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 200000,
            ],
        ]);

        if ($response->status() !== 201) {
            dump($response->json());
        }
        $response->assertStatus(201);
        $splitGroups = $response->json('split_groups', []);
        $this->assertCount(1, $splitGroups);
        $saleId = $splitGroups[0]['sale_id'];

        // Verify: Both serials should be persisted as non-tax
        // because source owner is non-PKP
        $saleDetails = SaleDetails::query()
            ->where('sale_id', $saleId)
            ->where('product_id', $product->id)
            ->first();

        $this->assertNotNull($saleDetails);
        $this->assertEquals(0, $saleDetails->product_tax_amount, 'Expected non-PKP source to result in zero tax');

        // Dispatch detail should have no tax_id
        $dispatchDetails = DispatchDetail::query()
            ->where('sale_id', $saleId)
            ->where('product_id', $product->id)
            ->get();

        $this->assertEquals(1, $dispatchDetails->count(), 'Expected 1 dispatch detail record for chunk');
        $this->assertEquals(2, $dispatchDetails->first()->dispatched_quantity);
        $this->assertNull($dispatchDetails->first()->tax_id, 'Non-PKP source serial should persist with null tax_id');

        // Stock should be decremented from non-tax bucket only
        $updatedStock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $sourceLocation->id)
            ->first();
        $this->assertEquals(0, $updatedStock->quantity_non_tax, 'Non-tax quantity should be fully decremented');
        $this->assertEquals(0, $updatedStock->quantity_tax, 'Tax quantity should remain unchanged');
    }

    /**
     * Test 4.2: Non-serial allocation follows configured (location, setting) priority order.
     * When non-PKP location is position 1 and PKP location is position 2:
     * First 5 units from non-PKP location (non-tax bucket) -> non-tax Sales allocation
     * Remaining 3 units from PKP location (tax bucket) -> taxed Sales allocation
     */
    public function test_non_serial_allocation_follows_configured_source_order_non_pkp_first(): void
    {
        // Setup: Two source locations with different owner PKP status
        $nonPkpSource = $this->createSetting('NON-PKP-SOURCE-ALLOC', false);
        $nonPkpLoc = $this->createLocation($nonPkpSource, 'NON-PKP-LOC-ALLOC');

        $pkpSource = $this->createSetting('PKP-SOURCE-ALLOC', true);
        $pkpLoc = $this->createLocation($pkpSource, 'PKP-LOC-ALLOC');

        // Terminal setting
        $terminalSetting = $this->createSetting('TERMINAL-ALLOC', true);
        $this->assignDefaultWalkInCustomer($nonPkpSource);
        $this->assignDefaultWalkInCustomer($pkpSource);
        $terminal = $this->createTerminalForSetting($terminalSetting, $nonPkpLoc->id);
        $cashier = $this->createUserForSetting($terminalSetting, 'cashier-alloc-' . $this->sequence, ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment']);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);
        $methods = $this->seedPaymentMethods($terminalSetting);

        // Assign both locations as sale locations: non-PKP first, PKP second
        SettingSaleLocation::query()->where('setting_id', $terminalSetting->id)->delete();
        SettingSaleLocation::create([
            'setting_id' => $terminalSetting->id,
            'location_id' => $nonPkpLoc->id,
            'is_enabled' => true,
            'position' => 1,
        ]);
        SettingSaleLocation::create([
            'setting_id' => $terminalSetting->id,
            'location_id' => $pkpLoc->id,
            'is_enabled' => true,
            'position' => 2,
        ]);
        SalesLocationResolver::forget($terminalSetting->id);

        // Create product with tax
        $seq = $this->sequence++;
        $tax = Tax::create(['name' => 'VAT 10%', 'value' => 10]);
        $product = $this->createStockedProduct($terminalSetting, $nonPkpLoc, 'PROD-ALLOC-' . $seq, 100000);
        $this->applySaleTax($product, $terminalSetting, $tax);

        // Setup stock: 5 units non-taxed at non-PKP loc, 5 units taxed at PKP loc
        $this->seedStockAtLocation($product, $nonPkpLoc, 5, false); // non-tax bucket
        $this->seedStockAtLocation($product, $pkpLoc, 5, true);   // tax bucket

        // Open session
        $session = $this->openSession($terminalSetting, $terminal, $cashier);
        $this->selectCustomerInCart($cashier, $terminalSetting, $customer);

        // Add line requesting 8 units (taxable)
        $this->addCartLine($cashier, $terminalSetting, $product->id, 8, taxId: $tax->id);

        // Finalize
        $response = $this->finalize($cashier, $terminalSetting, [
            'idempotency_key' => 'K-ALLOC-' . $this->sequence,
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 800000,
            ],
        ]);

        $response->assertStatus(201);
        $splitGroups = $response->json('split_groups', []);
        $this->assertCount(2, $splitGroups);

        $saleIds = array_column($splitGroups, 'sale_id');

        // Verify allocation order:
        // - First 5 units from non-PKP location (non-tax bucket) -> should be persisted as non-tax
        // - Remaining 3 units from PKP location (tax bucket) -> should be persisted as tax
        $dispatchDetails = DispatchDetail::query()
            ->whereIn('sale_id', $saleIds)
            ->where('product_id', $product->id)
            ->orderBy('location_id')
            ->get();

        $this->assertEquals(2, $dispatchDetails->count(), 'Expected 2 dispatch detail records (non-PKP and PKP)');

        // First chunk: non-PKP location, 5 units, no tax
        $nonPkpDetail = $dispatchDetails->where('location_id', $nonPkpLoc->id)->first();
        $this->assertNotNull($nonPkpDetail);
        $this->assertEquals(5, $nonPkpDetail->dispatched_quantity);
        $this->assertNull($nonPkpDetail->tax_id, 'Non-PKP source should be posted as non-tax');

        // Second chunk: PKP location, 3 units, with tax
        $pkpDetail = $dispatchDetails->where('location_id', $pkpLoc->id)->first();
        $this->assertNotNull($pkpDetail);
        $this->assertEquals(3, $pkpDetail->dispatched_quantity);
        $this->assertEquals($tax->id, $pkpDetail->tax_id, 'PKP source should be posted with tax');

        // Verify stock decrements: non-PKP non-tax should be depleted, PKP tax should have 2 left
        $nonPkpStock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $nonPkpLoc->id)
            ->first();
        $this->assertEquals(0, $nonPkpStock->quantity_non_tax, 'Non-PKP non-tax bucket should be emptied');

        $pkpStock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $pkpLoc->id)
            ->first();
        $this->assertEquals(2, $pkpStock->quantity_tax, 'PKP tax bucket should have 2 units remaining');

        $this->sequence++;
    }

    /**
     * Test 4.3: Reverse order regression.
     * When PKP location is position 1 and non-PKP location is position 2:
     * Requesting 2 units when both settings have stock must allocate strictly from position 1 (PKP),
     * producing a taxed Sales allocation and leaving position 2 (non-PKP) untouched with 0 allocated.
     */
    public function test_non_serial_allocation_follows_configured_source_order_pkp_first(): void
    {
        $nonPkpSource = $this->createSetting('NON-PKP-SOURCE-REV', false);
        $nonPkpLoc = $this->createLocation($nonPkpSource, 'NON-PKP-LOC-REV');

        $pkpSource = $this->createSetting('PKP-SOURCE-REV', true);
        $pkpLoc = $this->createLocation($pkpSource, 'PKP-LOC-REV');

        // Terminal setting
        $terminalSetting = $this->createSetting('TERMINAL-REV', true);
        $this->assignDefaultWalkInCustomer($nonPkpSource);
        $this->assignDefaultWalkInCustomer($pkpSource);
        $terminal = $this->createTerminalForSetting($terminalSetting, $pkpLoc->id);
        $cashier = $this->createUserForSetting($terminalSetting, 'cashier-rev-' . $this->sequence, ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment']);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);
        $methods = $this->seedPaymentMethods($terminalSetting);

        // Assign locations: PKP first (position 1), non-PKP second (position 2)
        SettingSaleLocation::query()->where('setting_id', $terminalSetting->id)->delete();
        SettingSaleLocation::create([
            'setting_id' => $terminalSetting->id,
            'location_id' => $pkpLoc->id,
            'is_enabled' => true,
            'position' => 1,
        ]);
        SettingSaleLocation::create([
            'setting_id' => $terminalSetting->id,
            'location_id' => $nonPkpLoc->id,
            'is_enabled' => true,
            'position' => 2,
        ]);
        SalesLocationResolver::forget($terminalSetting->id);

        // Create product with tax
        $seq = $this->sequence++;
        $tax = Tax::create(['name' => 'VAT 10%', 'value' => 10]);
        $product = $this->createStockedProduct($terminalSetting, $pkpLoc, 'PROD-REV-' . $seq, 100000);
        $this->applySaleTax($product, $terminalSetting, $tax);

        // Both locations have stock: PKP loc has 5 tax units, non-PKP loc has 5 non-tax units
        $this->seedStockAtLocation($product, $pkpLoc, 5, true);    // PKP tax bucket
        $this->seedStockAtLocation($product, $nonPkpLoc, 5, false); // non-PKP non-tax bucket

        // Open session
        $session = $this->openSession($terminalSetting, $terminal, $cashier);
        $this->selectCustomerInCart($cashier, $terminalSetting, $customer);

        // Add line requesting 2 units
        $this->addCartLine($cashier, $terminalSetting, $product->id, 2, taxId: $tax->id);

        // Finalize
        $response = $this->finalize($cashier, $terminalSetting, [
            'idempotency_key' => 'K-REV-' . $this->sequence,
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 200000,
            ],
        ]);

        $response->assertStatus(201);
        $splitGroups = $response->json('split_groups', []);
        $this->assertCount(1, $splitGroups, 'Expected single split group from PKP source');

        $saleId = $splitGroups[0]['sale_id'];

        // Verify dispatch detail: 2 units from PKP location with tax
        $dispatchDetails = DispatchDetail::query()
            ->where('sale_id', $saleId)
            ->where('product_id', $product->id)
            ->get();

        $this->assertEquals(1, $dispatchDetails->count());
        $detail = $dispatchDetails->first();
        $this->assertEquals($pkpLoc->id, $detail->location_id);
        $this->assertEquals(2, $detail->dispatched_quantity);
        $this->assertEquals($tax->id, $detail->tax_id, 'PKP source allocation must be taxed');

        // Stock verification: PKP loc decremented to 3, non-PKP loc remains at 5 untouched
        $pkpStock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $pkpLoc->id)
            ->first();
        $this->assertEquals(3, $pkpStock->quantity_tax, 'PKP stock should have 3 remaining');

        $nonPkpStock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $nonPkpLoc->id)
            ->first();
        $this->assertEquals(5, $nonPkpStock->quantity_non_tax, 'Non-PKP stock must remain untouched at 5');

        $this->sequence++;
    }

    // ==== HELPER METHODS ====

    private function createSetting(string $name, bool $isPkp = false): Setting
    {
        $suffix = $this->sequence++;
        return Setting::create([
            'company_name' => $name . ' ' . $suffix,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . '.' . $suffix . '@example.com',
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

    private function createLocation(Setting $setting, string $name): Location
    {
        return Location::create([
            'setting_id' => $setting->id,
            'name' => $name,
        ]);
    }

    private function createTerminalForSetting(Setting $setting, int $locationId): PosTerminal
    {
        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'T-' . $this->sequence . '-' . $setting->id,
            'name' => 'Terminal-' . $setting->id,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'policy_name' => 'Default Policy',
        ]);

        return $terminal;
    }

    private function assignDefaultWalkInCustomer(Setting $setting): Customer
    {
        $customer = Customer::create([
            'setting_id' => $setting->id,
            'customer_name' => 'Walk-in',
            'customer_email' => 'walkin@example.com',
            'customer_phone' => '0800000000',
        ]);

        $setting->update(['pos_walk_in_customer_id' => $customer->id]);

        return $customer;
    }

    private function seedPaymentMethods(Setting $setting): array
    {
        $index = $this->sequence++;
        $coaId = \Illuminate\Support\Facades\DB::table('chart_of_accounts')->insertGetId([
            'name' => 'COA CASH ' . $index,
            'account_number' => 'CASH-' . $index,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cash = PaymentMethod::create([
            'setting_id' => $setting->id,
            'name' => 'Cash',
            'code' => 'CASH',
            'type' => 'CASH',
            'coa_id' => $coaId,
            'is_cash' => true,
            'is_active' => true,
            'requires_reference' => false,
        ]);

        \Illuminate\Support\Facades\DB::table('setting_pos_payment_methods')->updateOrInsert(
            [
                'setting_id' => $setting->id,
                'payment_method_id' => $cash->id,
            ],
            [
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return [
            'cash' => $cash,
        ];
    }

    private function assignSaleLocation(Setting $setting, Location $location): void
    {
        SettingSaleLocation::firstOrCreate([
            'setting_id' => $setting->id,
            'location_id' => $location->id,
        ], [
            'order' => 1,
            'is_enabled' => true,
            'position' => 1,
        ]);
        SalesLocationResolver::forget($setting->id);
    }

    private function createStockedProduct(Setting $setting, Location $location, string $code, int $price, bool $isSerialized = false): Product
    {
        $createdBy = User::query()->value('id') ?? User::factory()->create()->id;
        $category = Category::firstOrCreate(
            ['category_code' => 'CAT-' . $this->sequence],
            [
                'category_name' => 'Test Category ' . $this->sequence,
                'created_by' => $createdBy,
                'setting_id' => $setting->id,
            ]
        );
        $unit = Unit::firstOrCreate(
            ['short_name' => 'PCS'],
            ['name' => 'PCS']
        );

        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_code' => $code,
            'product_name' => 'Product ' . $code,
            'barcode' => 'BAR-' . $code,
            'product_quantity' => 10,
            'product_cost' => 50000,
            'product_price' => $price,
            'product_unit' => 'PCS',
            'serial_number_required' => $isSerialized,
            'stock_managed' => true,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => $price,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 0,
            'quantity_non_tax' => 0,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        return $product;
    }

    private function applySaleTax(Product $product, Setting $setting, Tax $tax): void
    {
        $product->update(['sale_tax_id' => $tax->id]);
        ProductPrice::query()
            ->where('product_id', $product->id)
            ->where('setting_id', $setting->id)
            ->update(['sale_tax_id' => $tax->id]);
    }

    private function seedStock(Product $product, Location $location, int $qty, bool $isTaxed): void
    {
        $stock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->first();

        if ($isTaxed) {
            $stock->update([
                'quantity' => $qty,
                'quantity_tax' => $qty,
            ]);
        } else {
            $stock->update([
                'quantity' => $qty,
                'quantity_non_tax' => $qty,
            ]);
        }
    }

    private function seedStockAtLocation(Product $product, Location $location, int $qty, bool $isTaxed): void
    {
        $stock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->first();

        if (! $stock) {
            $stock = ProductStock::create([
                'product_id' => $product->id,
                'location_id' => $location->id,
                'quantity' => 0,
                'quantity_non_tax' => 0,
                'quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
                'broken_quantity_tax' => 0,
                'broken_quantity' => 0,
            ]);
        }

        if ($isTaxed) {
            $stock->update([
                'quantity' => $stock->quantity + $qty,
                'quantity_tax' => $stock->quantity_tax + $qty,
            ]);
        } else {
            $stock->update([
                'quantity' => $stock->quantity + $qty,
                'quantity_non_tax' => $stock->quantity_non_tax + $qty,
            ]);
        }
    }

    private function createSerialNumber(Product $product, Location $location, string $serialNumber, ?int $taxId): ProductSerialNumber
    {
        return ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => $serialNumber,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'tax_id' => $taxId,
        ]);
    }

    private function openSession(Setting $setting, PosTerminal $terminal, User $cashier): PosSession
    {
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

    private function selectCustomerInCart(User $user, Setting $setting, Customer $customer): void
    {
        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $customer->id,
            ])
            ->assertOk();
    }

    private function addCartLine(User $user, Setting $setting, int $productId, int $qty, ?int $taxId = null): void
    {
        $payload = [
            'product_id' => $productId,
            'qty' => $qty,
        ];
        if ($taxId !== null) {
            $payload['tax_id'] = $taxId;
        }

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), $payload)
            ->assertOk();
    }

    private function cartSnapshot(User $user, Setting $setting): array
    {
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.cart.show'));

        return $response->json('cart_snapshot') ?? [];
    }

    private function finalize(User $user, Setting $setting, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), $payload);
    }
}
