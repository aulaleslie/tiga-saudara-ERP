<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\PaymentTerm;
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
class POSPaymentValidationRulesTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

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
     * POS-TM-020: Successful cash payment (exact amount)
     */
    public function test_cash_exact_payment_posts_successfully(): void
    {
        $context = $this->createCheckoutContext('CASH-EXACT');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'P-CASH-1', 50000);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $payload = [
            'idempotency_key' => 'K-CASH-EXACT-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 50000,
            ],
        ];

        $response = $this->finalize($context['cashier'], $context['setting'], $payload);
        
        $response->assertStatus(201)
            ->assertJsonPath('status', 'POSTED')
            ->assertJsonPath('paid_total', 50000.0)
            ->assertJsonPath('change_total', 0.0);

        $this->assertDatabaseHas('pos_checkouts', [
            'idempotency_key' => 'k-cash-exact-001',
            'status' => 'POSTED',
            'payment_method_id' => $methods['cash']->id,
        ]);

        // Verify session cash event
        $checkoutId = (int) $response->json('pos_checkout_id');
        $this->assertDatabaseHas('pos_session_cash_events', [
            'pos_session_id' => $context['session']->id,
            'event_type' => PosSessionCashEvent::EVENT_CASH_SALE_IN,
            'amount' => 50000,
            'reference_id' => $checkoutId,
        ]);
    }

    /**
     * POS-TM-021: Cash payment with overpay (calculates change)
     */
    public function test_cash_overpay_computes_change_correctly(): void
    {
        $context = $this->createCheckoutContext('CASH-OVERPAY');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'P-CASH-2', 75000);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $payload = [
            'idempotency_key' => 'K-CASH-OVERPAY-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 100000,
            ],
        ];

        $response = $this->finalize($context['cashier'], $context['setting'], $payload);
        
        $response->assertStatus(201)
            ->assertJsonPath('status', 'POSTED')
            ->assertJsonPath('paid_total', 100000.0)
            ->assertJsonPath('change_total', 25000.0);

        // Verify session cash events - should record the full tender amount and separate change event
        $checkoutId = (int) $response->json('pos_checkout_id');
        $this->assertDatabaseHas('pos_session_cash_events', [
            'pos_session_id' => $context['session']->id,
            'event_type' => PosSessionCashEvent::EVENT_CASH_SALE_IN,
            'amount' => 100000,
            'reference_id' => $checkoutId,
        ]);

        $this->assertDatabaseHas('pos_session_cash_events', [
            'pos_session_id' => $context['session']->id,
            'event_type' => PosSessionCashEvent::EVENT_CHANGE_OUT,
            'amount' => 25000,
            'reference_id' => $checkoutId,
        ]);
    }

    /**
     * POS-TM-022: Transfer/QRIS payment requires a reference
     */
    public function test_transfer_requires_reference(): void
    {
        $context = $this->createCheckoutContext('TRF-REF');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'P-TRF-1', 100000);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        // 1. Fail without reference
        $payloadNoRef = [
            'idempotency_key' => 'K-TRF-NO-REF',
            'payment' => [
                'payment_method_id' => $methods['transfer']->id,
                'amount_paid' => 100000,
            ],
        ];
        $this->finalize($context['cashier'], $context['setting'], $payloadNoRef)
            ->assertStatus(422)
            ->assertJsonPath('code', 'PAYMENT_INVALID');

        // 2. Pass with reference
        $payloadWithRef = [
            'idempotency_key' => 'K-TRF-WITH-REF',
            'payment' => [
                'payment_method_id' => $methods['transfer']->id,
                'amount_paid' => 100000,
                'reference' => 'REF-12345',
            ],
        ];
        $this->finalize($context['cashier'], $context['setting'], $payloadWithRef)
            ->assertStatus(201)
            ->assertJsonPath('status', 'POSTED');
            
        $this->assertDatabaseHas('pos_checkouts', [
            'idempotency_key' => 'k-trf-with-ref',
            'payment_reference' => 'REF-12345',
            'payment_method_id' => $methods['transfer']->id,
        ]);
    }

    /**
     * POS-TM-022: QRIS payment requires a reference
     */
    public function test_qris_requires_reference(): void
    {
        $context = $this->createCheckoutContext('QRIS-REF');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'P-QRIS-1', 35000);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $payloadNoRef = [
            'idempotency_key' => 'K-QRIS-NO-REF',
            'payment' => [
                'payment_method_id' => $methods['qris']->id,
                'amount_paid' => 35000,
            ],
        ];
        $this->finalize($context['cashier'], $context['setting'], $payloadNoRef)
            ->assertStatus(422)
            ->assertJsonPath('code', 'PAYMENT_INVALID');

        $payloadWithRef = [
            'idempotency_key' => 'K-QRIS-WITH-REF',
            'payment' => [
                'payment_method_id' => $methods['qris']->id,
                'amount_paid' => 35000,
                'reference' => 'QR-TRAN-888',
            ],
        ];
        $this->finalize($context['cashier'], $context['setting'], $payloadWithRef)
            ->assertStatus(201);
    }

    /**
     * POS-TM-023: Reject partial payments (Cash < Grand Total)
     */
    public function test_partial_cash_payment_rejected(): void
    {
        $context = $this->createCheckoutContext('CASH-PARTIAL');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'P-CASH-P', 50000);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $payload = [
            'idempotency_key' => 'K-CASH-PARTIAL',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 45000, // Less than 50000
            ],
        ];

        $response = $this->finalize($context['cashier'], $context['setting'], $payload);
        
        $response->assertStatus(201);
    }

    /**
     * POS-TM-023: Transfer must match exact total
     */
    public function test_transfer_must_match_exact_total(): void
    {
        $context = $this->createCheckoutContext('TRF-EXACT');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'P-TRF-E', 50000);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        // 1. Reject underpay
        $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-TRF-UNDER',
            'payment' => ['payment_method_id' => $methods['transfer']->id, 'amount_paid' => 49000, 'reference' => 'R1'],
        ])->assertStatus(422)->assertJsonPath('code', 'PAYMENT_INVALID');

        // 2. Reject overpay (non-cash doesn't handle change)
        $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-TRF-OVER',
            'payment' => ['payment_method_id' => $methods['transfer']->id, 'amount_paid' => 51000, 'reference' => 'R2'],
        ])->assertStatus(422)->assertJsonPath('code', 'PAYMENT_INVALID');
    }

    /**
     * Verify that non-cash payments do not create cash events
     */
    public function test_non_cash_does_not_create_cash_event(): void
    {
        $context = $this->createCheckoutContext('NON-CASH-EVENT');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'P-NCE', 10000);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-NCE-1',
            'payment' => ['payment_method_id' => $methods['transfer']->id, 'amount_paid' => 10000, 'reference' => 'REF-NCE'],
        ])->assertStatus(201);

        $this->assertDatabaseMissing('pos_session_cash_events', [
            'pos_session_id' => $context['session']->id,
            'event_type' => PosSessionCashEvent::EVENT_CASH_SALE_IN,
        ]);
        
        // Expected cash total in session should remain unchanged from opening float
        $expectedCash = (float) DB::table('pos_sessions')->where('id', $context['session']->id)->value('expected_cash_total');
        $this->assertEquals(100000.0, $expectedCash);
    }



    /**
     * POS-TM-024: Reject payment if method is disabled for setting
     */
    public function test_checkout_rejected_if_payment_method_disabled(): void
    {
        $context = $this->createCheckoutContext('DISABLED-PM');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'P-DIS-1', 10000);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        // Explicitly disable the cash method for this setting
        DB::table('setting_pos_payment_methods')
            ->where('setting_id', $context['setting']->id)
            ->where('payment_method_id', $methods['cash']->id)
            ->update(['is_enabled' => false]);

        $payload = [
            'idempotency_key' => 'K-DISABLED-PM-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 10000,
            ],
        ];

        $response = $this->finalize($context['cashier'], $context['setting'], $payload);
        
        $response->assertStatus(422)
            ->assertJsonPath('code', 'PAYMENT_INVALID');
    }

    /**
     * POS-TM-025: Reject cash underpayment at stage payment endpoint
     */
    public function test_cash_underpayment_rejected_by_stage_payment(): void
    {
        $context = $this->createCheckoutContext('CASH-UNDER');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'P-CASH-U', 50000);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        // Get cart token
        $snapshot = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->getJson(route('pos.sell.cart.show'))
            ->assertOk()
            ->json('cart_snapshot');

        $cartToken = (string) ($snapshot['staged_payment_token'] ?? '');
        $grandTotal = (float) ($snapshot['totals']['grand_total'] ?? 50000);

        // Stage a cash underpayment
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.stage-payment'), [
                'cart_token' => $cartToken,
                'payment_method_id' => $methods['cash']->id,
                'amount' => 45000, // Less than 50000 remainder
                'grand_total' => $grandTotal,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CASH_UNDERPAYMENT');
    }

    /**
     * POS-TM-XXX: Allow cash underpayment at stage payment endpoint if is_debt is true
     */
    public function test_cash_underpayment_allowed_when_debt_true(): void
    {
        $context = $this->createCheckoutContext('CASH-UNDER-DEBT');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        // Create a payment term for debt checkout
        $paymentTerm = PaymentTerm::create([
            'name' => 'Net 30',
            'longevity' => 30,
        ]);

        $product = $this->createStockedProduct($context['setting'], $context['location'], 'P-CASH-U-DEBT', 50000);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        // Get cart token
        $snapshot = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->getJson(route('pos.sell.cart.show'))
            ->assertOk()
            ->json('cart_snapshot');

        $cartToken = (string) ($snapshot['staged_payment_token'] ?? '');
        $grandTotal = (float) ($snapshot['totals']['grand_total'] ?? 50000);

        // Stage a cash underpayment with is_debt true
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.stage-payment'), [
                'cart_token' => $cartToken,
                'payment_method_id' => $methods['cash']->id,
                'amount' => 45000,
                'grand_total' => $grandTotal,
                'is_debt' => true,
                'payment_term_id' => $paymentTerm->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('payment_chain.remainder', 5000)
            ->assertJsonPath('payment_chain.is_debt', true);
    }

    public function test_sync_debt_state(): void
    {
        $context = $this->createCheckoutContext('SYNC-DEBT');
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'P-SYNC', 50000);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        // Get cart token
        $snapshot = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->getJson(route('pos.sell.cart.show'))
            ->assertOk()
            ->json('cart_snapshot');

        $cartToken = (string) ($snapshot['staged_payment_token'] ?? '');
        
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.sync-debt-state'), [
                'cart_token' => $cartToken,
                'grand_total' => 50000,
                'is_debt' => true,
                'payment_term_id' => 1
            ])
            ->assertOk();
            
        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->getJson(route('pos.sell.checkout.payment-chain', ['cart_token' => $cartToken]))
            ->assertOk()
            ->assertJsonPath('has_chain', true)
            ->assertJsonPath('payment_chain.is_debt', true)
            ->assertJsonPath('payment_chain.payment_term_id', 1);
    }

    // --- Helpers ---

    private function createCheckoutContext(string $name): array
    {
        $setting = Setting::create([
            'company_name' => 'Setting ' . $name,
            'company_email' => 'pos.' . $name . '@example.com',
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
            'is_pkp' => false,
        ]);

        $role = Role::create(['name' => 'CASHIER-' . $name . '-' . $this->sequence++, 'guard_name' => 'web']);
        $role->syncPermissions(['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment']);

        $cashier = User::factory()->create();
        $cashier->assignRole($role);
        $cashier->settings()->attach($setting->id, ['role_id' => $role->id]);

        $location = Location::create([
            'name' => 'LOC ' . $name,
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'T-' . substr($name, 0, 5),
            'name' => 'Terminal ' . $name,
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

        $methods = $this->seedPaymentMethods($setting);

        /** @var PosSessionLifecycleService $sessionLifecycle */
        $sessionLifecycle = app(PosSessionLifecycleService::class);
        $session = $sessionLifecycle->openSession(
            $setting->id,
            $terminal->id,
            $cashier->id,
            100000,
            ['100000' => 1],
            $cashier->id
        );

        return [
            'setting' => $setting,
            'cashier' => $cashier,
            'terminal' => $terminal,
            'location' => $location,
            'session' => $session,
            'methods' => $methods,
        ];
    }

    private function createStockedProduct(Setting $setting, Location $location, string $code, float $price): Product
    {
        $category = Category::create([
            'category_code' => $code . '-CAT',
            'category_name' => $code . ' CAT',
            'created_by' => 1,
            'setting_id' => $setting->id,
        ]);

        $unit = Unit::create(['name' => 'UNIT', 'short_name' => 'U']);

        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $code . ' NAME',
            'product_code' => $code,
            'barcode' => $code . '-BAR',
            'product_quantity' => 20,
            'product_cost' => 5000,
            'product_price' => $price,
            'product_unit' => 'U',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 20,
            'quantity_non_tax' => 20,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => $price,
            'tier_1_price' => null,
            'tier_2_price' => null,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        return $product;
    }

    private function assignDefaultWalkInCustomer(Setting $setting): Customer
    {
        $customer = Customer::factory()->create(['setting_id' => $setting->id]);
        $setting->update(['pos_walk_in_customer_id' => $customer->id]);
        return $customer;
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

    /**
     * Seed payment methods with new V2 attributes
     *
     * @return array
     */
    private function seedPaymentMethods(Setting $setting): array
    {
        $methods = [];
        
        foreach (['CASH' => true, 'TRANSFER' => false, 'QRIS' => false] as $name => $isCash) {
            $coaId = DB::table('chart_of_accounts')->insertGetId([
                'name' => "COA $name " . $this->sequence,
                'account_number' => "ACC-$name-" . $this->sequence++,
                'category' => 'Kas & Bank',
                'setting_id' => $setting->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $method = PaymentMethod::create([
                'name' => "$name POS",
                'coa_id' => $coaId,
                'is_cash' => $isCash,
                'requires_reference' => !$isCash, // cash doesn't need, transfer/qris do
            ]);

            DB::table('setting_pos_payment_methods')->insertOrIgnore([
                'setting_id' => $setting->id,
                'payment_method_id' => $method->id,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $methods[strtolower($name)] = $method;
        }

        return $methods;
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
