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
        $sourceLocation = $this->createLocation($nonPkpSource, 'NON-PKP-LOC');

        $pkpTerminal = $this->createSetting('PKP-TERMINAL', true);
        $terminal = $this->createTerminalForSetting($pkpTerminal, $sourceLocation->id);
        $cashier = $this->createUserForSetting($pkpTerminal, 'cashier-' . $this->sequence, ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment']);
        $customer = $this->assignDefaultWalkInCustomer($pkpTerminal);
        $methods = $this->seedPaymentMethods($pkpTerminal);
        $this->assignSaleLocation($pkpTerminal, $sourceLocation);

        // Product with 10% tax
        $tax = Tax::create(['name' => 'VAT 10%', 'value' => 10]);
        $product = $this->createStockedProduct($pkpTerminal, $sourceLocation, 'PROD-MIX-SERIAL-' . $this->sequence, 100000);
        $this->applySaleTax($product, $pkpTerminal, $tax);

        // Create serials: one with tax_id, one without
        $snWithTax = $this->createSerialNumber($product, $sourceLocation, 'SN-TAX-' . $this->sequence, $tax->id);
        $snNoTax = $this->createSerialNumber($product, $sourceLocation, 'SN-NOTAX-' . $this->sequence, null);

        // Setup stock with both taxed and non-taxed quantities
        $stock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $sourceLocation->id)
            ->first();
        $stock->update([
            'quantity_tax' => 1,
            'quantity_non_tax' => 1,
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
                'serial_numbers' => ['SN-TAX-' . $this->sequence, 'SN-NOTAX-' . $this->sequence],
            ])
            ->assertOk();

        // Finalize checkout
        $response = $this->finalize($cashier, $pkpTerminal, [
            'idempotency_key' => 'K-MIX-SERIAL-' . $this->sequence,
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 200000,
            ],
        ]);

        $response->assertStatus(201);
        $saleId = $response->json('sale_id');
        $dispatchId = $response->json('dispatch_ids.0');

        // Verify: Both serials should be persisted as non-tax
        // because source owner is non-PKP
        $saleDetails = SaleDetails::query()
            ->where('sale_id', $saleId)
            ->where('product_id', $product->id)
            ->first();

        $this->assertNotNull($saleDetails);
        $this->assertEquals(0, $saleDetails->product_tax_amount, 'Expected non-PKP source to result in zero tax');

        // Both dispatch details should have no tax_id
        $dispatchDetails = DispatchDetail::query()
            ->where('dispatch_id', $dispatchId)
            ->where('product_id', $product->id)
            ->get();

        $this->assertEquals(2, $dispatchDetails->count(), 'Expected 2 dispatch detail records for 2 serials');
        foreach ($dispatchDetails as $detail) {
            $this->assertNull($detail->tax_id, 'Non-PKP source serial should persist with null tax_id');
        }

        // Stock should be decremented from non-tax bucket only
        $updatedStock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $sourceLocation->id)
            ->first();
        $this->assertEquals(0, $updatedStock->quantity_non_tax, 'Non-tax quantity should be fully decremented');
        $this->assertEquals(1, $updatedStock->quantity_tax, 'Tax quantity should remain unchanged');

        $this->sequence++;
    }

    /**
     * Test 4.2: Non-serial taxable allocation should prioritize non-PKP sources first,
     * then PKP sources, while preserving configured location order within each priority group
     */
    public function test_non_serial_taxable_allocation_prioritizes_non_pkp_source_first(): void
    {
        // Setup: Two source locations with different owner PKP status
        $nonPkpSource = $this->createSetting('NON-PKP-SOURCE-ALLOC', false);
        $nonPkpLoc = $this->createLocation($nonPkpSource, 'NON-PKP-LOC-ALLOC');

        $pkpSource = $this->createSetting('PKP-SOURCE-ALLOC', true);
        $pkpLoc = $this->createLocation($pkpSource, 'PKP-LOC-ALLOC');

        // Terminal setting
        $terminalSetting = $this->createSetting('TERMINAL-ALLOC', true);
        $terminal = $this->createTerminalForSetting($terminalSetting, $nonPkpLoc->id);
        $cashier = $this->createUserForSetting($terminalSetting, 'cashier-alloc-' . $this->sequence, ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment']);
        $customer = $this->assignDefaultWalkInCustomer($terminalSetting);
        $methods = $this->seedPaymentMethods($terminalSetting);

        // Assign both locations as sale locations
        SettingSaleLocation::query()->where('setting_id', $terminalSetting->id)->delete();
        SettingSaleLocation::create([
            'setting_id' => $terminalSetting->id,
            'location_id' => $nonPkpLoc->id,
            'order' => 1,
        ]);
        SettingSaleLocation::create([
            'setting_id' => $terminalSetting->id,
            'location_id' => $pkpLoc->id,
            'order' => 2,
        ]);

        // Create product with tax
        $tax = Tax::create(['name' => 'VAT 10%', 'value' => 10]);
        $product = $this->createStockedProduct($terminalSetting, $nonPkpLoc, 'PROD-ALLOC-' . $this->sequence, 100000);
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
        $dispatchId = $response->json('dispatch_ids.0');

        // Verify allocation order:
        // - First 5 units from non-PKP location (non-tax bucket) -> should be persisted as non-tax
        // - Remaining 3 units from PKP location (tax bucket) -> should be persisted as tax
        $dispatchDetails = DispatchDetail::query()
            ->where('dispatch_id', $dispatchId)
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
            'customer_type' => 'INDIVIDUAL',
            'customer_name' => 'Walk-in',
            'customer_email' => 'walkin@example.com',
            'customer_phone' => '0800000000',
        ]);

        $setting->update(['walk_in_customer_id' => $customer->id]);

        return $customer;
    }

    private function seedPaymentMethods(Setting $setting): array
    {
        return [
            'cash' => PaymentMethod::create([
                'setting_id' => $setting->id,
                'name' => 'Cash',
                'code' => 'CASH',
                'type' => 'CASH',
                'is_active' => true,
            ]),
        ];
    }

    private function assignSaleLocation(Setting $setting, Location $location): void
    {
        SettingSaleLocation::firstOrCreate([
            'setting_id' => $setting->id,
            'location_id' => $location->id,
        ], [
            'order' => 1,
        ]);
    }

    private function createStockedProduct(Setting $setting, Location $location, string $code, int $price, bool $isSerialized = false): Product
    {
        $category = Category::firstOrCreate(['category_name' => 'Test Category']);
        $unit = Unit::firstOrCreate(['unit_name' => 'PCS']);

        $product = Product::create([
            'category_id' => $category->id,
            'product_code' => $code,
            'product_name' => 'Product ' . $code,
            'product_description' => 'Test product',
            'unit_id' => $unit->id,
            'product_type' => $isSerialized ? 'SERIALIZED' : 'NON_SERIALIZED',
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
            'quantity_reserved' => 0,
            'quantity_damaged' => 0,
            'quantity_non_tax' => 0,
            'quantity_tax' => 0,
        ]);

        return $product;
    }

    private function applySaleTax(Product $product, Setting $setting, Tax $tax): void
    {
        $product->taxes()->sync([$tax->id]);
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
                'quantity_reserved' => 0,
                'quantity_damaged' => 0,
                'quantity_non_tax' => 0,
                'quantity_tax' => 0,
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
            ->postJson(route('pos.sell.cart.customer.select'), [
                'customer_id' => $customer->id,
            ])
            ->assertOk();
    }

    private function addCartLine(User $user, Setting $setting, int $productId, int $qty, ?int $taxId = null): void
    {
        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.add'), [
                'product_id' => $productId,
                'qty' => $qty,
                'tax_id' => $taxId,
            ])
            ->assertOk();
    }

    private function cartSnapshot(User $user, Setting $setting): array
    {
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.cart.view'));

        return $response->json('cart_snapshot') ?? [];
    }

    private function finalize(User $user, Setting $setting, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.checkout.finalize'), $payload);
    }
}
