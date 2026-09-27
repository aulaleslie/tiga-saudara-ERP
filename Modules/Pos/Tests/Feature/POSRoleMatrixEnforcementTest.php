<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingPosPaymentMethod;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class POSRoleMatrixEnforcementTest extends TestCase
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
            'pos.sessions.require-terminal',
            'pos.checkout.payment',
            'pos.sessions.view',
            'pos.sessions.close-admin',
            'pos.cart.clear',
            'pos.cart.line.remove',
            'pos.cart.line.reduce',
            'pos.overrides.price',
            'pos.supervisor.approval',
            'pos.transactions.view',
            'pos.transactions.save',
            'pos.transactions.load',
            'pos.transactions.edit.any',
            'pos.void',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_helper_bundle_can_open_non_terminal_session_access_shell_and_cannot_access_payment_flow(): void
    {
        $setting = $this->createSetting('HELPER BUNDLE');
        $helper = $this->createUserForSetting($setting, 'Some Manager-ish Label', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.view',
            'pos.transactions.save',
            'pos.transactions.load',
        ]);

        [$terminal, $location] = $this->createTerminalForSetting($setting);
        $paymentMethodId = $this->createPaymentMethodForSetting($setting);
        $this->assignDefaultWalkInCustomer($setting);

        /** @var PosSessionLifecycleService $sessionLifecycle */
        $sessionLifecycle = app(PosSessionLifecycleService::class);
        $session = $sessionLifecycle->openSession($setting->id, null, $helper->id, 0, null, $helper->id);

        $this->assertSame('OPEN', $session->status);
        $this->assertNull($session->terminal_id);

        $product = $this->createStockedProduct($setting, $location, 'HELPER-BUNDLE-001');

        $this->actingAs($helper)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'))
            ->assertOk();

        $this->actingAs($helper)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $this->actingAs($helper)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201);

        $transactionId = (int) \Modules\Pos\Entities\PosTransaction::query()->value('id');

        $this->actingAs($helper)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk();

        $this->actingAs($helper)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.payment-methods.search'))
            ->assertForbidden();

        $this->actingAs($helper)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.stage-payment'), [
                'cart_token' => (string) \Illuminate\Support\Str::uuid(),
                'payment_method_id' => $paymentMethodId,
                'amount' => 1000,
                'grand_total' => 1000,
            ])
            ->assertForbidden();

        $this->actingAs($helper)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), [
                'idempotency_key' => 'helper-finalize-' . uniqid(),
                'payment' => [
                    'payment_method_id' => $paymentMethodId,
                    'amount_paid' => 1000,
                ],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'terminal_id' => null,
            'cashier_user_id' => $helper->id,
        ]);
    }

    public function test_cashier_bundle_can_open_session_with_terminal_and_stage_then_finalize_payment(): void
    {
        $setting = $this->createSetting('CASHIER BUNDLE');
        $cashier = $this->createUserForSetting($setting, 'NoCashierWordHere', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.sessions.require-terminal',
            'pos.checkout.payment',
            'pos.transactions.view',
            'pos.transactions.load',
            'pos.transactions.save',
        ]);

        [$terminal, $location] = $this->createTerminalForSetting($setting);
        $paymentMethodId = $this->createPaymentMethodForSetting($setting);
        $walkIn = $this->assignDefaultWalkInCustomer($setting);

        /** @var PosSessionLifecycleService $sessionLifecycle */
        $sessionLifecycle = app(PosSessionLifecycleService::class);

        // Terminal is now optional for all users
        $session = $sessionLifecycle->openSession(
            $setting->id,
            $terminal->id,
            $cashier->id,
            100000,
            ['100000' => 1],
            $cashier->id
        );

        $this->assertSame((int) $terminal->id, (int) $session->terminal_id);

        $product = $this->createStockedProduct($setting, $location, 'CASHIER-BUNDLE-001');

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $walkIn->id])
            ->assertOk();

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sessions.index'))
            ->assertForbidden();

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201);

        $transactionId = (int) \Modules\Pos\Entities\PosTransaction::query()->latest('id')->value('id');

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk();

        $snapshot = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.cart.show'))
            ->assertOk()
            ->json('cart_snapshot');

        $cartToken = (string) ($snapshot['staged_payment_token'] ?? '');
        $grandTotal = (float) ($snapshot['totals']['grand_total'] ?? 0);

        $this->assertNotSame('', $cartToken);
        $this->assertGreaterThan(0, $grandTotal);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.stage-payment'), [
                'cart_token' => $cartToken,
                'payment_method_id' => $paymentMethodId,
                'amount' => $grandTotal,
                'grand_total' => $grandTotal,
            ])
            ->assertStatus(201);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), [
                'idempotency_key' => 'cashier-finalize-' . uniqid(),
                'cart_token' => $cartToken,
            ])
            ->assertStatus(201)
            ->assertJsonPath('status', 'POSTED');
    }

    public function test_cashier_without_terminal_cannot_finalize_checkout_even_with_checkout_permission(): void
    {
        $setting = $this->createSetting('CASHIER NO TERMINAL CHECKOUT');
        $cashier = $this->createUserForSetting($setting, 'Cashier No Terminal', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
            'pos.transactions.view',
            'pos.transactions.load',
            'pos.transactions.save',
        ]);

        [, $location] = $this->createTerminalForSetting($setting);
        $paymentMethodId = $this->createPaymentMethodForSetting($setting);
        $walkIn = $this->assignDefaultWalkInCustomer($setting);

        /** @var PosSessionLifecycleService $sessionLifecycle */
        $sessionLifecycle = app(PosSessionLifecycleService::class);
        $sessionLifecycle->openSession($setting->id, null, $cashier->id, 0, null, $cashier->id);

        $product = $this->createStockedProduct($setting, $location, 'CASHIER-NO-TERM-001');

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $walkIn->id])
            ->assertOk();

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), [
                'idempotency_key' => 'cashier-no-terminal-finalize-' . uniqid(),
                'payment' => [
                    'payment_method_id' => $paymentMethodId,
                    'amount_paid' => 10000,
                ],
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'CHECKOUT_TERMINAL_REQUIRED');
    }

    public function test_manager_without_terminal_can_stage_and_finalize_checkout(): void
    {
        $setting = $this->createSetting('MANAGER NO TERMINAL CHECKOUT');
        $manager = $this->createUserForSetting($setting, 'Manager No Terminal', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
            'pos.sessions.view',
            'pos.sessions.close-admin',
            'pos.transactions.edit.any',
            'pos.transactions.view',
            'pos.transactions.load',
            'pos.transactions.save',
        ]);

        [, $location] = $this->createTerminalForSetting($setting);
        $paymentMethodId = $this->createPaymentMethodForSetting($setting);
        $walkIn = $this->assignDefaultWalkInCustomer($setting);

        /** @var PosSessionLifecycleService $sessionLifecycle */
        $sessionLifecycle = app(PosSessionLifecycleService::class);
        $session = $sessionLifecycle->openSession($setting->id, null, $manager->id, 0, null, $manager->id);

        $this->assertNull($session->terminal_id);

        $product = $this->createStockedProduct($setting, $location, 'MANAGER-NO-TERM-001');

        $this->actingAs($manager)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $this->actingAs($manager)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), ['customer_id' => $walkIn->id])
            ->assertOk();

        $snapshot = $this->actingAs($manager)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.cart.show'))
            ->assertOk()
            ->json('cart_snapshot');

        $cartToken = (string) ($snapshot['staged_payment_token'] ?? '');
        $grandTotal = (float) ($snapshot['totals']['grand_total'] ?? 0);

        $this->assertNotSame('', $cartToken);
        $this->assertGreaterThan(0, $grandTotal);

        $this->actingAs($manager)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.stage-payment'), [
                'cart_token' => $cartToken,
                'payment_method_id' => $paymentMethodId,
                'amount' => $grandTotal,
                'grand_total' => $grandTotal,
            ])
            ->assertStatus(201);

        $this->actingAs($manager)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), [
                'idempotency_key' => 'manager-no-terminal-finalize-' . uniqid(),
                'cart_token' => $cartToken,
            ])
            ->assertStatus(201)
            ->assertJsonPath('status', 'POSTED');
    }

    public function test_direct_price_override_permission_bypasses_approval_even_if_role_name_is_floor_staff(): void
    {
        $setting = $this->createSetting('PRICE OVERRIDE DIRECT');
        $cashier = $this->createUserForSetting($setting, 'Floor Staff', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.overrides.price',
        ]);

        [$terminal, $location] = $this->createTerminalForSetting($setting);
        $this->createPaymentMethodForSetting($setting);

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

        $product = $this->createStockedProduct($setting, $location, 'PRICE-OVERRIDE-001');

        $lineId = (int) $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk()
            ->json('cart_snapshot.lines.0.line_id');

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.price-override', ['lineId' => $lineId]), [
                'unit_price' => 8500,
            ])
            ->assertOk();

        $line = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.cart.show'))
            ->assertOk()
            ->json('cart_snapshot.lines.0');

        $this->assertSame(8500.0, (float) ($line['unit_price'] ?? 0));
        $this->assertSame('OVERRIDE', (string) ($line['price_source'] ?? ''));
    }

    public function test_floor_staff_can_load_cashier_draft_but_cannot_cancel_without_void_authority(): void
    {
        $setting = $this->createSetting('HANDOFF WITHOUT VOID');
        $cashier = $this->createUserForSetting($setting, 'Cashier Handoff', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.view',
            'pos.transactions.save',
            'pos.transactions.load',
        ]);
        $floorStaff = $this->createUserForSetting($setting, 'Floor Staff Handoff', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.transactions.view',
            'pos.transactions.load',
        ]);

        [$cashierTerminal, $location] = $this->createTerminalForSetting($setting);
        [$floorTerminal] = $this->createTerminalForSetting($setting);
        $this->createPaymentMethodForSetting($setting);

        /** @var PosSessionLifecycleService $sessionLifecycle */
        $sessionLifecycle = app(PosSessionLifecycleService::class);
        $sessionLifecycle->openSession($setting->id, $cashierTerminal->id, $cashier->id, 100000, ['100000' => 1], $cashier->id);
        $sessionLifecycle->openSession($setting->id, $floorTerminal->id, $floorStaff->id, 100000, ['100000' => 1], $floorStaff->id);

        $product = $this->createStockedProduct($setting, $location, 'HANDOFF-NO-VOID-001');

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), ['product_id' => $product->id, 'qty' => 1])
            ->assertOk();

        $transactionId = (int) $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.transactions.save-and-new'))
            ->assertStatus(201)
            ->json('transaction.id');

        $this->actingAs($floorStaff)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.transactions.load', ['transaction' => $transactionId]))
            ->assertOk();

        $this->actingAs($floorStaff)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.transactions.cancel', ['transaction' => $transactionId]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'APPROVAL_REQUIRED');
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
            'pos_transactions_enabled' => true,
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
     * @return array{0: PosTerminal, 1: Location}
     */
    private function createTerminalForSetting(Setting $setting): array
    {
        $sequence = $this->terminalSequence++;
        $location = Location::create([
            'name' => 'ROLE MATRIX LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'ROLE-MTRX-' . $sequence,
            'name' => 'Role Matrix Terminal ' . $sequence,
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

        return [$terminal, $location];
    }

    private function createStockedProduct(Setting $setting, Location $location, string $code): Product
    {
        $category = Category::firstOrCreate(
            ['category_code' => 'ROLE-MTRX-CAT-' . $setting->id],
            [
                'category_name' => 'Role Matrix Category',
                'setting_id' => $setting->id,
                'created_by' => User::query()->value('id') ?? User::factory()->create()->id,
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
            'product_name' => 'Role Matrix Product ' . $code,
            'product_code' => $code,
            'barcode' => $code . '-BC',
            'product_quantity' => 30,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 30,
            'quantity_non_tax' => 30,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        ProductPrice::updateOrCreate(
            [
                'product_id' => $product->id,
                'setting_id' => $setting->id,
            ],
            [
                'sale_price' => 10000,
                'tier_1_price' => null,
                'tier_2_price' => null,
                'last_purchase_price' => 5000,
                'average_purchase_price' => 5000,
                'purchase_tax_id' => null,
                'sale_tax_id' => null,
            ]
        );

        return $product;
    }

    private function createPaymentMethodForSetting(Setting $setting): int
    {
        $coa = ChartOfAccount::create([
            'name' => 'Role Matrix Cash',
            'account_number' => '1101-' . $setting->id . '-' . $this->terminalSequence,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
        ]);

        $method = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $coa->id,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        SettingPosPaymentMethod::updateOrCreate(
            [
                'setting_id' => $setting->id,
                'payment_method_id' => $method->id,
            ],
            [
                'is_enabled' => true,
            ]
        );

        return (int) $method->id;
    }

    private function assignDefaultWalkInCustomer(Setting $setting): Customer
    {
        $customer = Customer::factory()->create([
            'setting_id' => $setting->id,
        ]);

        $setting->update([
            'pos_walk_in_customer_id' => $customer->id,
        ]);

        return $customer;
    }
}
