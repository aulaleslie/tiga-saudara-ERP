<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * WS-C: Regression Test Coverage for POS-001, POS-002, POS-003
 * 
 * @group pos-regression
 * @group pos-critical-path
 */
class POSWsCRegressionTest extends TestCase
{
    use RefreshDatabase;

    private int $terminalSequence = 1;

    protected function setUp(): void
    {
        parent::setUp();

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
     * POS-001 Regression: GET /pos/sell/cart without selected customer should return 200
     * with resolution_source = 'none' instead of 500 error.
     * 
     * This test verifies the fix: unresolved customer is no longer fatal in snapshot generation.
     */
    public function test_cart_show_returns_200_with_resolution_source_none_when_no_customer_selected(): void
    {
        $setting = $this->createSetting('WS-C CART SHOW NO CUST');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'WS-C CART SHOW NO CUST');

        // DO NOT select a customer - cart should have null customer_id
        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.cart.show'));

        $response->assertOk()
            ->assertJsonPath('cart_snapshot.customer.resolution_source', 'none')
            ->assertJsonPath('cart_snapshot.customer.selected_customer_id', null)
            ->assertJsonPath('cart_snapshot.customer.resolved_customer_id', null);
    }

    /**
     * POS-001 Regression: PATCH /pos/sell/cart/customer with customer_id=null should return 200
     * with unresolved snapshot instead of 422 error.
     * 
     * This test verifies: clearing customer selection returns non-fatal "none" resolution.
     */
    public function test_patch_cart_customer_with_null_returns_200_with_unresolved_snapshot(): void
    {
        $setting = $this->createSetting('WS-C CLEAR CUSTOMER');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'WS-C CLEAR CUSTOMER');

        $selectedCustomer = Customer::factory()->create(['setting_id' => $setting->id]);

        // First select a customer
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $selectedCustomer->id])
            ->assertOk();

        // Then clear it by passing null
        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => null]);

        $response->assertOk()
            ->assertJsonPath('cart_snapshot.customer.selected_customer_id', null)
            ->assertJsonPath('cart_snapshot.customer.resolved_customer_id', null)
            ->assertJsonPath('cart_snapshot.customer.resolution_source', 'none');
    }

    /**
     * POS-002 Regression: POST /pos/sell/cart/lines without selected customer should return 200
     * and add the line to cart instead of 422 error.
     * 
     * This test verifies: adding products does not require customer selection until checkout finalize.
     */
    public function test_post_cart_lines_without_customer_returns_200_and_adds_line(): void
    {
        $setting = $this->createSetting('WS-C ADD LINE NO CUST');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'WS-C ADD LINE NO CUST');

        // Create a stocked product but DO NOT select customer
        $product = $this->createStockedProduct($setting, $location, 'SKU-WS-C-001', 25000);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 1,
            ]);

        $response->assertOk()
            ->assertJsonPath('cart_snapshot.lines.0.product_id', $product->id)
            ->assertJsonPath('cart_snapshot.lines.0.qty', 1)
            ->assertJsonPath('cart_snapshot.meta.line_count', 1);
    }

    /**
     * Regression boundary check: POST /pos/sell/checkout/finalize without customer should still
     * return 422 with CUSTOMER_UNRESOLVED error. This confirms the customer requirement is
     * enforced only at finalization, not during cart operations.
     */
    public function test_checkout_finalize_without_customer_returns_422_customer_unresolved(): void
    {
        $context = $this->createCheckoutContext('WS-C CHECKOUT NO CUST');
        $this->seedPaymentMethods($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'SKU-WS-C-FINAL', 50000);
        
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        // Attempt checkout WITHOUT selecting customer
        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-WS-C-FINAL',
            'payment' => [
                'payment_method_id' => 1,
                'amount_paid' => 50000,
            ],
        ]);

        // Should reject at finalization boundary
        $response->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOMER_UNRESOLVED')
            ->assertJsonPath('message', 'Customer is not resolved for checkout.');
    }

    // --- Helper Methods (copied from POSWalkInCustomerSelectionTest and POSCheckoutSelectedCustomerRequiredTest) ---

    private function createSetting(string $name): Setting
    {
        return Setting::create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
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
        ]);
    }

    private function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    /**
     * @return array{0: User, 1: Location, 2: PosSession}
     */
    private function createCashierAndOpenSession(Setting $setting, string $roleSuffix): array
    {
        $cashier = $this->createUserForSetting(
            $setting,
            $roleSuffix . ' CASHIER',
            ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment']
        );

        $terminal = $this->createTerminalForSetting($setting);
        $location = SalesLocationResolver::resolve((int) $terminal->setting_id);

        $session = PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $cashier->id,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'active_marker' => 1,
        ]);

        return [$cashier, $location, $session];
    }

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $sequence = $this->terminalSequence++;

        $location = Location::create([
            'name' => 'WS-C LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-WS-C-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'WS-C Terminal ' . $sequence,
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

        return $terminal;
    }

    private function createStockedProduct(
        Setting $setting,
        Location $location,
        string $code,
        float $salePrice
    ): Product {
        $category = Category::firstOrCreate(
            ['category_code' => 'WS-C-CAT-' . $setting->id],
            [
                'category_name' => 'WS-C Category ' . $setting->id,
                'created_by' => 1,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'Piece',
            'short_name' => 'PCS',
        ]);

        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $code . ' NAME',
            'product_code' => $code,
            'barcode' => $code . '-BC',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => $salePrice,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 100,
            'quantity_non_tax' => 100,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => $salePrice,
            'tier_1_price' => null,
            'tier_2_price' => null,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        return $product->fresh();
    }

    /**
     * @return array{cash: PaymentMethod, transfer: PaymentMethod, qris: PaymentMethod}
     */
    private function seedPaymentMethods(Setting $setting): array
    {
        $methods = [];
        $index = $this->terminalSequence;

        foreach (['CASH' => true, 'TRANSFER' => false, 'QRIS' => false] as $name => $isCash) {
            $coaId = \DB::table('chart_of_accounts')->insertGetId([
                'name' => "COA $name " . $index,
                'account_number' => "ACC-WS-C-$name-" . $index,
                'category' => 'Kas & Bank',
                'setting_id' => $setting->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $methods[strtolower($name)] = PaymentMethod::create([
                'name' => "$name WS-C " . $index,
                'coa_id' => $coaId,
                'is_cash' => $isCash,
                'requires_reference' => !$isCash,
            ]);
        }

        return $methods;
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

    /**
     * @return array{setting: Setting, cashier: User, location: Location, session: PosSession, terminal: PosTerminal}
     */
    private function createCheckoutContext(string $name): array
    {
        $setting = $this->createSetting($name);
        [$cashier, $location, $session] = $this->createCashierAndOpenSession($setting, $name . '-cashier');

        return [
            'setting' => $setting,
            'cashier' => $cashier,
            'location' => $location,
            'session' => $session,
            'terminal' => null,
        ];
    }
}
