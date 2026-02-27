<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\Contracts\PosCheckoutPostingAdapter;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Throwable;

class POSCheckoutFinalizeIdempotencyTest extends TestCase
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
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_successful_finalize_posts_once_and_replays_same_idempotency_key(): void
    {
        $context = $this->createCheckoutContext('POS CHECKOUT REPLAY');
        $this->seedPaymentMethods($context['setting']);
        $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-REPLAY-001', 50000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $payload = [
            'idempotency_key' => 'K-REPLAY-001',
            'payment' => [
                'method_code' => 'cash',
                'amount_paid' => 50000,
            ],
        ];

        $first = $this->finalize($context['cashier'], $context['setting'], $payload);
        $first->assertStatus(201)
            ->assertJsonPath('status', 'POSTED')
            ->assertJsonPath('idempotent_replay', false)
            ->assertJsonPath('paid_total', 50000.0)
            ->assertJsonPath('change_total', 0.0);

        $second = $this->finalize($context['cashier'], $context['setting'], $payload);
        $second->assertStatus(200)
            ->assertJsonPath('status', 'POSTED')
            ->assertJsonPath('idempotent_replay', true);

        $firstPayload = $first->json();
        $secondPayload = $second->json();
        $secondPayload['idempotent_replay'] = false;

        $this->assertSame($firstPayload, $secondPayload);
        $this->assertDatabaseCount('pos_checkouts', 1);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_payments', 1);
        $this->assertDatabaseCount('dispatches', 1);
        $this->assertDatabaseCount('dispatch_details', 1);
    }

    public function test_duplicate_with_finalizing_status_returns_conflict(): void
    {
        $context = $this->createCheckoutContext('POS CHECKOUT FINALIZING');
        $this->seedPaymentMethods($context['setting']);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-FINALIZING-001', 30000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $payload = [
            'idempotency_key' => 'K-FINALIZING-001',
            'payment' => [
                'method_code' => 'cash',
                'amount_paid' => 30000,
            ],
        ];

        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $hash = $this->payloadHash(
            $context['setting']->id,
            $context['session']->id,
            $context['terminal']->id,
            $context['cashier']->id,
            (int) $customer->id,
            $snapshot,
            $payload['payment']
        );

        PosCheckout::query()->create([
            'setting_id' => $context['setting']->id,
            'pos_session_id' => $context['session']->id,
            'terminal_id' => $context['terminal']->id,
            'cashier_user_id' => $context['cashier']->id,
            'customer_id' => $customer->id,
            'status' => PosCheckout::STATUS_FINALIZING,
            'idempotency_key' => 'k-finalizing-001',
            'payload_hash' => $hash,
            'subtotal' => $snapshot['totals']['subtotal'],
            'discount_total' => $snapshot['totals']['discount_total'],
            'tax_total' => $snapshot['totals']['tax_total'],
            'grand_total' => $snapshot['totals']['grand_total'],
            'paid_total' => 30000,
            'change_total' => 0,
            'payment_method_code' => 'cash',
            'payment_reference' => null,
            'metadata' => null,
        ]);

        $response = $this->finalize($context['cashier'], $context['setting'], $payload);
        $response->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_IN_PROGRESS');
    }

    public function test_duplicate_after_failed_attempt_returns_conflict(): void
    {
        $context = $this->createCheckoutContext('POS CHECKOUT FAILED');
        $this->seedPaymentMethods($context['setting']);
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-FAILED-001', 40000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $payload = [
            'idempotency_key' => 'K-FAILED-001',
            'payment' => [
                'method_code' => 'cash',
                'amount_paid' => 40000,
            ],
        ];

        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $hash = $this->payloadHash(
            $context['setting']->id,
            $context['session']->id,
            $context['terminal']->id,
            $context['cashier']->id,
            (int) $customer->id,
            $snapshot,
            $payload['payment']
        );

        PosCheckout::query()->create([
            'setting_id' => $context['setting']->id,
            'pos_session_id' => $context['session']->id,
            'terminal_id' => $context['terminal']->id,
            'cashier_user_id' => $context['cashier']->id,
            'customer_id' => $customer->id,
            'status' => PosCheckout::STATUS_FAILED,
            'idempotency_key' => 'k-failed-001',
            'payload_hash' => $hash,
            'subtotal' => $snapshot['totals']['subtotal'],
            'discount_total' => $snapshot['totals']['discount_total'],
            'tax_total' => $snapshot['totals']['tax_total'],
            'grand_total' => $snapshot['totals']['grand_total'],
            'paid_total' => 40000,
            'change_total' => 0,
            'payment_method_code' => 'cash',
            'payment_reference' => null,
            'failure_code' => 'POSTING_FAILURE',
            'failure_message' => 'Injected failure',
            'metadata' => null,
        ]);

        $response = $this->finalize($context['cashier'], $context['setting'], $payload);
        $response->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_PREVIOUS_FAILED');
    }

    public function test_posting_failure_rolls_back_partial_records_and_marks_checkout_failed(): void
    {
        $context = $this->createCheckoutContext('POS CHECKOUT ROLLBACK');
        $this->seedPaymentMethods($context['setting']);
        $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-ROLLBACK-001', 25000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        app()->bind(PosCheckoutPostingAdapter::class, function (): PosCheckoutPostingAdapter {
            return new class implements PosCheckoutPostingAdapter
            {
                public function post(array $context): array
                {
                    Sale::query()->create([
                        'date' => Carbon::now()->toDateString(),
                        'due_date' => Carbon::now()->toDateString(),
                        'customer_id' => $context['customer_id'],
                        'customer_name' => 'FORCED FAILURE',
                        'tax_percentage' => 0,
                        'tax_amount' => 0,
                        'discount_percentage' => 0,
                        'discount_amount' => 0,
                        'shipping_amount' => 0,
                        'total_amount' => 25000,
                        'paid_amount' => 25000,
                        'due_amount' => 0,
                        'status' => 'DISPATCHED',
                        'payment_status' => 'PAID',
                        'payment_method' => 'CASH',
                        'setting_id' => $context['setting_id'],
                        'is_tax_included' => false,
                    ]);

                    throw new \RuntimeException('Injected mid-posting failure');
                }
            };
        });

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-ROLLBACK-001',
            'payment' => [
                'method_code' => 'cash',
                'amount_paid' => 25000,
            ],
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('code', 'POSTING_FAILURE');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('dispatches', 0);
        $this->assertDatabaseCount('dispatch_details', 0);
        $this->assertDatabaseHas('pos_checkouts', [
            'setting_id' => $context['setting']->id,
            'idempotency_key' => 'k-rollback-001',
            'status' => PosCheckout::STATUS_FAILED,
            'failure_code' => 'POSTING_FAILURE',
        ]);
    }

    public function test_missing_idempotency_key_returns_validation_error(): void
    {
        $context = $this->createCheckoutContext('POS CHECKOUT MISSING KEY');

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'payment' => [
                'method_code' => 'cash',
                'amount_paid' => 10000,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_unresolved_customer_returns_domain_validation_error(): void
    {
        $context = $this->createCheckoutContext('POS CHECKOUT UNRESOLVED');
        $this->seedPaymentMethods($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-UNRESOLVED-001', 15000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-UNRESOLVED-001',
            'payment' => [
                'method_code' => 'cash',
                'amount_paid' => 15000,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOMER_UNRESOLVED');
    }

    public function test_non_cash_requires_reference(): void
    {
        $context = $this->createCheckoutContext('POS CHECKOUT NON CASH');
        $this->seedPaymentMethods($context['setting']);
        $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-NON-CASH-001', 20000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-NON-CASH-001',
            'payment' => [
                'method_code' => 'transfer',
                'amount_paid' => 20000,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment.reference']);
    }

    public function test_serial_tracked_line_is_rejected_for_phase_one(): void
    {
        $context = $this->createCheckoutContext('POS CHECKOUT SERIAL');
        $this->seedPaymentMethods($context['setting']);
        $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-SERIAL-001', 23000, true);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-SERIAL-001',
            'payment' => [
                'method_code' => 'cash',
                'amount_paid' => 23000,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'SERIAL_NOT_SUPPORTED');
    }

    public function test_cash_overpay_computes_change_and_updates_expected_cash_by_grand_total(): void
    {
        $context = $this->createCheckoutContext('POS CHECKOUT OVERPAY');
        $this->seedPaymentMethods($context['setting']);
        $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-OVERPAY-001', 10000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-OVERPAY-001',
            'payment' => [
                'method_code' => 'cash',
                'amount_paid' => 12000,
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('paid_total', 12000.0)
            ->assertJsonPath('change_total', 2000.0);

        $checkoutId = (int) $response->json('pos_checkout_id');
        $this->assertDatabaseHas('pos_session_cash_events', [
            'setting_id' => $context['setting']->id,
            'pos_session_id' => $context['session']->id,
            'event_type' => PosSessionCashEvent::EVENT_CASH_SALE_IN,
            'direction' => PosSessionCashEvent::DIRECTION_IN,
            'amount' => 10000,
            'reference_id' => $checkoutId,
        ]);

        $expectedCash = (float) DB::table('pos_sessions')->where('id', $context['session']->id)->value('expected_cash_total');
        $this->assertSame(110000.0, $expectedCash);
    }

    private function createCheckoutContext(string $name): array
    {
        $setting = $this->createSetting($name);
        $cashier = $this->createUserForSetting($setting, $name . '-cashier', ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $terminal = $this->createTerminalForSetting($setting);
        $location = Location::query()->findOrFail($terminal->location_id);

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
        ];
    }

    private function createSetting(string $name): Setting
    {
        $suffix = $this->sequence++;

        return Setting::create([
            'company_name' => $name . ' ' . $suffix,
            'company_email' => 'pos.checkout.' . $suffix . '@example.com',
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

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $index = $this->sequence++;

        $location = Location::create([
            'name' => 'POS CHECKOUT LOC ' . $index,
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-CHECKOUT-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Checkout Terminal ' . $index,
            'location_id' => $location->id,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => true,
        ]);

        return $terminal;
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

    private function createStockedProduct(
        Setting $setting,
        Location $location,
        string $code,
        float $salePrice,
        bool $serialRequired
    ): Product {
        $category = Category::firstOrCreate(
            ['category_code' => $code . '-CAT'],
            [
                'category_name' => $code . ' CATEGORY',
                'created_by' => 1,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'POS UNIT',
            'short_name' => 'PUNIT',
        ]);

        $product = Product::query()->create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $code . ' NAME',
            'product_code' => $code,
            'barcode' => $code . '-BAR',
            'product_quantity' => 20,
            'product_cost' => 5000,
            'product_price' => $salePrice,
            'product_unit' => 'PUNIT',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => $serialRequired,
        ]);

        ProductStock::query()->create([
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

        ProductPrice::query()->updateOrCreate([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
        ], [
            'sale_price' => $salePrice,
            'tier_1_price' => null,
            'tier_2_price' => null,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
            'purchase_tax_id' => null,
            'sale_tax_id' => null,
        ]);

        return $product;
    }

    private function seedPaymentMethods(Setting $setting): void
    {
        $index = $this->sequence++;

        $cashCoaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'POS COA CASH ' . $index,
            'account_number' => 'POS-CASH-' . $index,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transferCoaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'POS COA TRANSFER ' . $index,
            'account_number' => 'POS-TRF-' . $index,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $qrisCoaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'POS COA QRIS ' . $index,
            'account_number' => 'POS-QRIS-' . $index,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        PaymentMethod::query()->create([
            'name' => 'CASH POS ' . $index,
            'coa_id' => $cashCoaId,
            'is_cash' => true,
        ]);

        PaymentMethod::query()->create([
            'name' => 'TRANSFER POS ' . $index,
            'coa_id' => $transferCoaId,
            'is_cash' => false,
        ]);

        PaymentMethod::query()->create([
            'name' => 'QRIS POS ' . $index,
            'coa_id' => $qrisCoaId,
            'is_cash' => false,
        ]);
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

    /**
     * @return array<string, mixed>
     */
    private function cartSnapshot(User $cashier, Setting $setting): array
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.cart.show'))
            ->assertOk()
            ->json('cart_snapshot');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finalize(User $cashier, Setting $setting, array $payload)
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), $payload);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $payment
     */
    private function payloadHash(
        int $settingId,
        int $sessionId,
        int $terminalId,
        int $cashierUserId,
        int $customerId,
        array $snapshot,
        array $payment
    ): string {
        $normalized = [
            'setting_id' => $settingId,
            'pos_session_id' => $sessionId,
            'terminal_id' => $terminalId,
            'cashier_user_id' => $cashierUserId,
            'customer_id' => $customerId,
            'cart' => [
                'lines' => $snapshot['lines'] ?? [],
                'totals' => $snapshot['totals'] ?? [],
                'bill_discount' => $snapshot['bill_discount'] ?? [],
            ],
            'payment' => [
                'method_code' => strtolower((string) ($payment['method_code'] ?? '')),
                'amount_paid' => round((float) ($payment['amount_paid'] ?? 0), 2),
                'reference' => $payment['reference'] ?? null,
            ],
        ];

        return hash(
            'sha256',
            json_encode($this->canonicalize($normalized), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
        );
    }

    /**
     * @return mixed
     */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        try {
            app()->bind(PosCheckoutPostingAdapter::class, \Modules\Pos\Services\Adapters\InlinePosCheckoutPostingAdapter::class);
        } catch (Throwable) {
            // No-op.
        }
    }
}
