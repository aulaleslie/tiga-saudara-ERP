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
 * @group pos-critical-path
 */
class POSCheckoutSelectedCustomerRequiredTest extends TestCase
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

    public function test_checkout_without_selected_customer_returns_validation_error(): void
    {
        $context = $this->createCheckoutContext('CUSTOMER REQUIRED NONE');
        $this->seedPaymentMethods($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'P-CUST-REQ-001', 50000);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-CUSTOMER-REQ-NONE',
            'payment' => [
                'payment_method_id' => 1, // Placeholder, not important for this test
                'amount_paid' => 50000,
            ],
        ]);

        // Should reject when no customer selected
        $response->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOMER_UNRESOLVED')
            ->assertJsonPath('message', 'Customer is not resolved for checkout.');
    }

    public function test_checkout_with_selected_customer_succeeds(): void
    {
        $context = $this->createCheckoutContext('CUSTOMER REQUIRED OK');
        $methods = $this->seedPaymentMethods($context['setting']);
        $customer = Customer::factory()->create(['setting_id' => $context['setting']->id]);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'P-CUST-REQ-002', 60000);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        // Select customer first
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $customer->id])
            ->assertOk();

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-CUSTOMER-REQ-OK',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 60000,
            ],
        ]);

        // Should succeed with selected customer
        $response->assertStatus(201)
            ->assertJsonPath('status', 'POSTED');
    }

    public function test_base_price_applied_when_no_customer_selected_in_cart(): void
    {
        $context = $this->createCheckoutContext('BASE PRICE NO CUST');
        $cashier = $context['cashier'];
        $location = $context['location'];
        $methods = $this->seedPaymentMethods($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $location, 'P-BASE-PRICE', 40000);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 1,
            ])
            ->assertOk();

        // Without customer, should use base sale_price (40000)
        $response->assertJsonPath('cart_snapshot.lines.0.unit_price', 40000);
    }

    public function test_base_price_applied_for_non_tier_customer(): void
    {
        $context = $this->createCheckoutContext('BASE PRICE NON TIER');
        $cashier = $context['cashier'];
        $location = $context['location'];
        $this->seedPaymentMethods($context['setting']);
        $customer = Customer::factory()->create(['setting_id' => $context['setting']->id]);
        $product = $this->createStockedProduct($context['setting'], $location, 'P-BASE-PRICE-NT', 35000);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $context['setting']->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $customer->id])
            ->assertOk();

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 1,
            ])
            ->assertOk();

        // Non-tier customer: should still use base sale_price (35000)
        $response->assertJsonPath('cart_snapshot.lines.0.unit_price', 35000);
    }

    public function test_no_default_walk_in_fallback_at_checkout(): void
    {
        $setting = $this->createSetting('NO WALKIN FALLBACK');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'NO WALKIN FALLBACK');

        $methods = $this->seedPaymentMethods($setting);
        
        // Even with walk-in configured, checkout must have explicit selection
        $walkIn = Customer::factory()->create(['setting_id' => $setting->id]);
        $setting->update(['pos_walk_in_customer_id' => $walkIn->id]);

        $product = $this->createStockedProduct($setting, $location, 'P-NO-WALKIN', 25000);
        $this->addCartLine($cashier, $setting, $product->id, 1);

        // Try to finalize WITHOUT selecting customer (even though walk-in is configured)
        $response = $this->finalize($cashier, $setting, [
            'idempotency_key' => 'K-NO-WALKIN',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 25000,
            ],
        ]);

        // Should still reject (no fallback anymore)
        $response->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOMER_UNRESOLVED');
    }

    // --- Helpers (complete set) ---

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
            'name' => 'CUST REQ LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-CR-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Customer Required Terminal ' . $sequence,
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
            ['category_code' => 'CUST-REQ-CAT-' . $setting->id],
            [
                'category_name' => 'Customer Required Category ' . $setting->id,
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
     * @return array
     */
    private function seedPaymentMethods(Setting $setting): array
    {
        $methods = [];
        $index = $this->terminalSequence;

        foreach (['CASH' => true, 'TRANSFER' => false, 'QRIS' => false] as $name => $isCash) {
            $coaId = \DB::table('chart_of_accounts')->insertGetId([
                'name' => "COA $name " . $index,
                'account_number' => "ACC-CUS-$name-" . $index,
                'category' => 'Kas & Bank',
                'setting_id' => $setting->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $methods[strtolower($name)] = PaymentMethod::create([
                'name' => "$name CustomerRequired " . $index,
                'coa_id' => $coaId,
                'is_cash' => $isCash,
                'requires_reference' => !$isCash,
            ]);

            \Modules\Setting\Entities\SettingPosPaymentMethod::updateOrCreate(
                [
                    'setting_id' => $setting->id,
                    'payment_method_id' => $methods[strtolower($name)]->id,
                ],
                [
                    'is_enabled' => true,
                ]
            );
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
}
