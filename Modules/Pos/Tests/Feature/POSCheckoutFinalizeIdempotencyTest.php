<?php

namespace Modules\Pos\Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Support\SalesLocationResolver;
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
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Throwable;

/**
 * @group pos-critical-path
 */
class POSCheckoutFinalizeIdempotencyTest extends TestCase
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
            'pos.sessions.require-terminal',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_successful_finalize_posts_once_and_replays_same_idempotency_key(): void
    {
        $context = $this->createCheckoutContext('POS CHECKOUT REPLAY');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-REPLAY-001', 50000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $payload = [
            'idempotency_key' => 'K-REPLAY-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
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

        $this->assertEquals($firstPayload, $secondPayload);
        $this->assertDatabaseCount('pos_checkouts', 1);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_payments', 1);
        $this->assertDatabaseCount('dispatches', 1);
        $this->assertDatabaseCount('dispatch_details', 1);
    }

    public function test_duplicate_with_finalizing_status_returns_conflict(): void
    {
        $context = $this->createCheckoutContext('POS CHECKOUT FINALIZING');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-FINALIZING-001', 30000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $payload = [
            'idempotency_key' => 'K-FINALIZING-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
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
            'payment_method_id' => $methods['cash']->id,
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
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-FAILED-001', 40000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $payload = [
            'idempotency_key' => 'K-FAILED-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
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
            'payment_method_id' => $methods['cash']->id,
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
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-ROLLBACK-001', 25000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

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
                'payment_method_id' => $methods['cash']->id,
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
                'payment_method_id' => 1,
                'amount_paid' => 10000,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_unresolved_customer_returns_domain_validation_error(): void
    {
        $context = $this->createCheckoutContext('POS CHECKOUT UNRESOLVED');
        $methods = $context['methods'];
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-UNRESOLVED-001', 15000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-UNRESOLVED-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 15000,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOMER_UNRESOLVED');
    }

    public function test_non_cash_requires_reference(): void
    {
        $context = $this->createCheckoutContext('POS CHECKOUT NON CASH');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-NON-CASH-001', 20000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-NON-CASH-001',
            'payment' => [
                'payment_method_id' => $methods['transfer']->id,
                'amount_paid' => 20000,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'PAYMENT_INVALID');
    }

    public function test_serial_tracked_line_with_incomplete_assignment_is_rejected(): void
    {
        $context = $this->createCheckoutContext('POS CHECKOUT SERIAL');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-SERIAL-001', 23000, true);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        // Without serial assignment: qty=1 but no serials assigned => rejected
        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-SERIAL-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 23000,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'SERIAL_INVALID')
            ->assertJsonPath('message', 'Product POS-SERIAL-001 NAME requires 1 serial number(s) but 0 assigned.');
    }

    public function test_finalize_succeeds_for_mixed_business_cart_with_taxed_serial_and_non_tax_line(): void
    {
        $context = $this->createCheckoutContext('POS CHECKOUT MIXED SERIAL TAX');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $sourceOwner = $this->createSetting('POS SERIAL SOURCE OWNER');
        $sourceOwner->update(['is_pkp' => true]);
        $sourceLocation = Location::query()->create([
            'name' => 'POS SERIAL SOURCE LOC ' . $this->sequence++,
            'setting_id' => $sourceOwner->id,
        ]);
        SettingSaleLocation::query()->updateOrCreate(
            ['setting_id' => $context['setting']->id, 'location_id' => $sourceLocation->id],
            ['is_enabled' => true, 'position' => 2]
        );
        SalesLocationResolver::forget($context['setting']->id);

        $tax = Tax::query()->create([
            'name' => 'VAT SERIAL 11',
            'value' => 11,
            'is_default' => true,
        ]);

        $serialProduct = $this->createStockedProduct(
            $context['setting'],
            $sourceLocation,
            'POS-MIXED-SERIAL-001',
            80000,
            true
        );
        ProductStock::query()
            ->where('product_id', $serialProduct->id)
            ->where('location_id', $sourceLocation->id)
            ->update([
                'quantity' => 5,
                'quantity_non_tax' => 0,
                'quantity_tax' => 5,
                'tax_id' => $tax->id,
            ]);
        $this->createSerialNumber($serialProduct, $sourceLocation, 'POS-MIX-SN-001', $tax->id);

        $nonSerialProduct = $this->createStockedProduct(
            $context['setting'],
            $context['location'],
            'POS-MIXED-NON-TAX-001',
            20000,
            false
        );

        $this->addCartLine($context['cashier'], $context['setting'], $serialProduct->id, 1);
        $this->addCartLine($context['cashier'], $context['setting'], $nonSerialProduct->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $snapshot = $this->cartSnapshot($context['cashier'], $context['setting']);
        $serialLine = collect($snapshot['lines'])->firstWhere('product_id', $serialProduct->id);
        $this->assertNotNull($serialLine, 'Serial line was not found in cart snapshot.');

        $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.cart.lines.serials.store', ['lineId' => (int) $serialLine['line_id']]), [
                'serial_numbers' => ['POS-MIX-SN-001'],
            ])
            ->assertOk();

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-MIXED-SERIAL-TAX-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 100000,
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'POSTED')
            ->assertJsonPath('idempotent_replay', false);

        $this->assertDatabaseHas('pos_checkouts', [
            'setting_id' => $context['setting']->id,
            'idempotency_key' => 'k-mixed-serial-tax-001',
            'status' => PosCheckout::STATUS_POSTED,
        ]);
    }

    public function test_stock_unavailable_response_includes_actionable_unfulfilled_line_details(): void
    {
        $context = $this->createCheckoutContext('POS CHECKOUT STOCK DETAILS');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-STOCK-DETAIL-001', 15000, false);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $context['location']->id)
            ->update([
                'quantity' => 0,
                'quantity_non_tax' => 0,
                'quantity_tax' => 0,
            ]);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-STOCK-DETAILS-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 15000,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'STOCK_UNAVAILABLE')
            ->assertJsonPath('details.unfulfilled_lines.0.line_index', 0)
            ->assertJsonPath('details.unfulfilled_lines.0.product_id', $product->id)
            ->assertJsonPath('details.unfulfilled_lines.0.reason_code', 'INSUFFICIENT_STOCK');

        $checkout = PosCheckout::query()
            ->where('setting_id', $context['setting']->id)
            ->where('idempotency_key', 'k-stock-details-001')
            ->first();

        $this->assertNotNull($checkout);
        $this->assertSame(PosCheckout::STATUS_FAILED, $checkout->status);
        $this->assertSame('STOCK_UNAVAILABLE', $checkout->failure_code);
    }

    public function test_taxed_line_decrements_non_tax_bucket_when_allocation_uses_non_tax_stock(): void
    {
        $context = $this->createCheckoutContext('POS CHECKOUT TAX BUCKET ALIGN');
        $context['setting']->update(['is_pkp' => true]);
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);

        $tax = Tax::query()->create([
            'name' => 'VAT ALIGN 11',
            'value' => 11,
            'is_default' => true,
        ]);

        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-TAX-BUCKET-001', 10000, false);
        ProductPrice::query()
            ->where('product_id', $product->id)
            ->where('setting_id', $context['setting']->id)
            ->update(['sale_tax_id' => $tax->id]);
        ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $context['location']->id)
            ->update([
                'quantity' => 1,
                'quantity_non_tax' => 1,
                'quantity_tax' => 0,
                'tax_id' => $tax->id,
            ]);

        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-TAX-BUCKET-ALIGN-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 10000,
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'POSTED');

        $this->assertDatabaseHas('product_stocks', [
            'product_id' => $product->id,
            'location_id' => $context['location']->id,
            'quantity' => 0,
            'quantity_non_tax' => 0,
            'quantity_tax' => 0,
        ]);

        $this->assertDatabaseHas('transactions', [
            'product_id' => $product->id,
            'location_id' => $context['location']->id,
            'quantity' => -1,
            'quantity_tax' => 0,
            'quantity_non_tax' => 1,
            'type' => 'DISPATCH',
        ]);

        $this->assertDatabaseHas('dispatch_details', [
            'product_id' => $product->id,
            'location_id' => $context['location']->id,
            'tax_id' => $tax->id,
            'dispatched_quantity' => 1,
        ]);
    }

    public function test_checkout_regression_5m_float_780k_sale_800k_tender_20k_change(): void
    {
        // 1.1 Add a checkout regression test for Rp5,000,000 opening cash, Rp780,000 grand total, Rp800,000 cash tender, and Rp20,000 change
        $context = $this->createCheckoutContext('POS REGRESSION 5M FLOAT');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        
        // Update session opening float to 5,000,000
        $context['session']->update(['opening_float_total' => 5000000, 'expected_cash_total' => 5000000]);
        DB::table('pos_session_cash_events')
            ->where('pos_session_id', $context['session']->id)
            ->where('event_type', PosSessionCashEvent::EVENT_OPEN_FLOAT)
            ->update(['amount' => 5000000]);

        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-REGRESS-001', 780000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-REGRESS-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 800000,
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('paid_total', 800000.0)
            ->assertJsonPath('change_total', 20000.0);

        $checkoutId = (int) $response->json('pos_checkout_id');
        $this->assertDatabaseHas('pos_session_cash_events', [
            'setting_id' => $context['setting']->id,
            'pos_session_id' => $context['session']->id,
            'event_type' => PosSessionCashEvent::EVENT_CASH_SALE_IN,
            'direction' => PosSessionCashEvent::DIRECTION_IN,
            'amount' => 800000,
            'reference_id' => $checkoutId,
        ]);

        $this->assertDatabaseHas('pos_session_cash_events', [
            'setting_id' => $context['setting']->id,
            'pos_session_id' => $context['session']->id,
            'event_type' => PosSessionCashEvent::EVENT_CHANGE_OUT,
            'direction' => PosSessionCashEvent::DIRECTION_OUT,
            'amount' => 20000,
            'reference_id' => $checkoutId,
        ]);

        $expectedCash = (float) DB::table('pos_sessions')->where('id', $context['session']->id)->value('expected_cash_total');
        $this->assertSame(5780000.0, $expectedCash);

        $calculator = app(\Modules\Pos\Services\PosSessionExpectedCashCalculator::class);
        $this->assertSame(5780000.0, (float) $calculator->calculate($context['session']->id)['expected_cash_total']);
    }

    public function test_cash_overpay_computes_change_and_updates_expected_cash_by_tender(): void
    {
        $context = $this->createCheckoutContext('POS CHECKOUT OVERPAY');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-OVERPAY-001', 10000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-OVERPAY-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
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
            'amount' => 12000,
            'reference_id' => $checkoutId,
        ]);

        // Opening float 100000 + cash sale tender 12000 - change 2000 = 110000
        $expectedCash = (float) DB::table('pos_sessions')->where('id', $context['session']->id)->value('expected_cash_total');
        $this->assertSame(110000.0, $expectedCash);

        // Verify CHANGE_OUT event was created
        $this->assertDatabaseHas('pos_session_cash_events', [
            'setting_id' => $context['setting']->id,
            'pos_session_id' => $context['session']->id,
            'event_type' => PosSessionCashEvent::EVENT_CHANGE_OUT,
            'direction' => PosSessionCashEvent::DIRECTION_OUT,
            'amount' => 2000,
            'reference_id' => $checkoutId,
        ]);
    }

    public function test_single_payment_cash_with_change_creates_change_out_event(): void
    {
        $context = $this->createCheckoutContext('POS CHANGE OUT SINGLE');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-CHANGE-SINGLE-001', 12000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-CHANGE-SINGLE-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 13000,
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('change_total', 1000.0);

        $checkoutId = (int) $response->json('pos_checkout_id');

        // Verify CHANGE_OUT event was created
        $this->assertDatabaseHas('pos_session_cash_events', [
            'setting_id' => $context['setting']->id,
            'pos_session_id' => $context['session']->id,
            'event_type' => PosSessionCashEvent::EVENT_CHANGE_OUT,
            'direction' => PosSessionCashEvent::DIRECTION_OUT,
            'amount' => 1000,
            'reference_id' => $checkoutId,
        ]);
    }

    public function test_multi_payment_cash_with_change_creates_change_out_event(): void
    {
        $context = $this->createCheckoutContext('POS CHANGE OUT MULTI');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-CHANGE-MULTI-001', 8000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        // Grand total: 8000. Cash: 9000, Non-cash: 0. Change: 1000 (9000 - 8000)
        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-CHANGE-MULTI-001',
            'payments' => [
                [
                    'payment_method_id' => $methods['cash']->id,
                    'amount_paid' => 9000,
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('change_total', 1000.0);

        $checkoutId = (int) $response->json('pos_checkout_id');

        // Verify CHANGE_OUT event was created with correct amount
        $this->assertDatabaseHas('pos_session_cash_events', [
            'setting_id' => $context['setting']->id,
            'pos_session_id' => $context['session']->id,
            'event_type' => PosSessionCashEvent::EVENT_CHANGE_OUT,
            'direction' => PosSessionCashEvent::DIRECTION_OUT,
            'amount' => 1000,
            'reference_id' => $checkoutId,
        ]);
    }

    public function test_expected_cash_calculation_with_change_event(): void
    {
        $context = $this->createCheckoutContext('POS EXPECTED CASH CHANGE');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-EXP-CASH-001', 5000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-EXP-CASH-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 6000,
            ],
        ]);

        // Opening float: 100000, cash sale tender: 6000, change: 1000
        // Expected: 100000 + 6000 - 1000 = 105000
        $expectedCash = (float) DB::table('pos_sessions')->where('id', $context['session']->id)->value('expected_cash_total');
        $this->assertSame(105000.0, $expectedCash);
    }

    public function test_no_change_out_event_when_payment_equals_grand_total(): void
    {
        $context = $this->createCheckoutContext('POS NO CHANGE');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-NO-CHANGE-001', 10000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-NO-CHANGE-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 10000,
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('change_total', 0.0);

        $checkoutId = (int) $response->json('pos_checkout_id');

        // Verify no CHANGE_OUT event was created
        $changeOutCount = DB::table('pos_session_cash_events')
            ->where('setting_id', $context['setting']->id)
            ->where('pos_session_id', $context['session']->id)
            ->where('event_type', PosSessionCashEvent::EVENT_CHANGE_OUT)
            ->where('reference_id', $checkoutId)
            ->count();

        $this->assertSame(0, $changeOutCount);
    }

    public function test_no_change_out_event_for_non_cash_only_payments(): void
    {
        $context = $this->createCheckoutContext('POS NON-CASH ONLY');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-NONCASH-001', 10000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-NONCASH-001',
            'payment' => [
                'payment_method_id' => $methods['qris']->id,
                'amount_paid' => 10000,
                'reference' => 'REF-NONCASH-001',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('change_total', 0.0);

        $checkoutId = (int) $response->json('pos_checkout_id');

        // Verify no CHANGE_OUT event was created
        $changeOutCount = DB::table('pos_session_cash_events')
            ->where('setting_id', $context['setting']->id)
            ->where('pos_session_id', $context['session']->id)
            ->where('event_type', PosSessionCashEvent::EVENT_CHANGE_OUT)
            ->where('reference_id', $checkoutId)
            ->count();

        $this->assertSame(0, $changeOutCount);
    }

    public function test_session_summary_includes_change_out_events_in_cash_events_timeline(): void
    {
        $context = $this->createCheckoutContext('POS SUMMARY CHANGE');
        $methods = $context['methods'];
        $customer = $this->assignDefaultWalkInCustomer($context['setting']);
        $product = $this->createStockedProduct($context['setting'], $context['location'], 'POS-SUMMARY-CHANGE-001', 5000, false);
        $this->addCartLine($context['cashier'], $context['setting'], $product->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $customer);

        $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-SUMMARY-CHANGE-001',
            'payment' => [
                'payment_method_id' => $methods['cash']->id,
                'amount_paid' => 6000,
            ],
        ]);

        // Get session summary
        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->getJson(route('pos.sessions.summary', ['session' => $context['session']->id]));

        $response->assertOk();

        // Verify CHANGE_OUT event appears in cash_events timeline
        $cashEvents = $response->json('cash_events');
        $this->assertIsArray($cashEvents);

        $changeOutEvent = collect($cashEvents)->first(function ($event) {
            return $event['event_type'] === PosSessionCashEvent::EVENT_CHANGE_OUT
                && $event['direction'] === PosSessionCashEvent::DIRECTION_OUT;
        });

        $this->assertNotNull($changeOutEvent);
        $this->assertSame(1000.0, (float) $changeOutEvent['amount']);
    }

    protected function createCheckoutContext(string $name): array
    {
        $setting = $this->createSetting($name);
        $cashier = $this->createUserForSetting($setting, $name . '-cashier', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.sessions.require-terminal',
            'pos.checkout.payment',
        ]);
        $terminal = $this->createTerminalForSetting($setting);
        $location = SalesLocationResolver::resolve((int) $terminal->setting_id);
        $methods = $this->seedPaymentMethods($setting, true);

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

    protected function createSetting(string $name): Setting
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

    protected function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => strtoupper($roleName) . '-' . $setting->id]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    protected function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $index = $this->sequence++;

        $location = Location::create([
            'name' => 'POS IDEM LOC ' . $index,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-CHECKOUT-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Checkout Terminal ' . $index,
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

    protected function assignDefaultWalkInCustomer(Setting $setting): Customer
    {
        $customer = Customer::factory()->create([
            'setting_id' => $setting->id,
        ]);

        $setting->update([
            'pos_walk_in_customer_id' => $customer->id,
        ]);

        return $customer;
    }

    protected function createStockedProduct(
        Setting $setting,
        Location $location,
        string $code,
        float $salePrice,
        bool $serialRequired
    ): Product {
        $createdBy = User::query()->value('id') ?? User::factory()->create()->id;

        $category = Category::firstOrCreate(
            ['category_code' => $code . '-CAT'],
            [
                'category_name' => $code . ' CATEGORY',
                'created_by' => $createdBy,
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

    protected function createSerialNumber(Product $product, Location $location, string $serialNumber, ?int $taxId): ProductSerialNumber
    {
        return ProductSerialNumber::query()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'serial_number' => $serialNumber,
            'tax_id' => $taxId,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);
    }

    /**
     * @return array
     */
    protected function seedPaymentMethods(Setting $setting, bool $enableForSetting = false): array
    {
        $index = $this->sequence++;
        $methods = [];

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

        $methods['cash'] = PaymentMethod::query()->create([
            'name' => 'CASH POS ' . $index,
            'coa_id' => $cashCoaId,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        $methods['transfer'] = PaymentMethod::query()->create([
            'name' => 'TRANSFER POS ' . $index,
            'coa_id' => $transferCoaId,
            'is_cash' => false,
            'requires_reference' => true,
        ]);

        $methods['qris'] = PaymentMethod::query()->create([
            'name' => 'QRIS POS ' . $index,
            'coa_id' => $qrisCoaId,
            'is_cash' => false,
            'requires_reference' => true,
        ]);

        if ($enableForSetting) {
            $timestamp = now();
            foreach ($methods as $method) {
                DB::table('setting_pos_payment_methods')->updateOrInsert(
                    [
                        'setting_id' => $setting->id,
                        'payment_method_id' => $method->id,
                    ],
                    [
                        'is_enabled' => true,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]
                );
            }
        }

        return $methods;
    }

    protected function addCartLine(User $cashier, Setting $setting, int $productId, int $qty): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $productId,
                'qty' => $qty,
            ])
            ->assertOk();
    }

    protected function selectCustomerInCart(User $cashier, Setting $setting, Customer $customer): void
    {
        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $customer->id,
            ])
            ->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    protected function cartSnapshot(User $cashier, Setting $setting): array
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
    protected function finalize(User $cashier, Setting $setting, array $payload)
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), $payload);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $payment
     */
    protected function payloadHash(
        int $settingId,
        int $sessionId,
        int $terminalId,
        int $cashierUserId,
        int $customerId,
        array $snapshot,
        array $payment
    ): string {
        // Check if this is a multi-payment or single-payment
        $isMultiPayment = isset($payment['payments']) && is_array($payment['payments']);

        $normalized = [
            'setting_id' => $settingId,
            'pos_session_id' => $sessionId,
            'terminal_id' => $terminalId,
            'cashier_user_id' => $cashierUserId,
            'customer_id' => $customerId,
            'cart' => $this->normalizeSnapshotForHash([
                'lines' => $snapshot['lines'] ?? [],
                'totals' => $snapshot['totals'] ?? [],
                'bill_discount' => $snapshot['bill_discount'] ?? [],
            ]),
        ];

        // Include payment hash - either canonical multi-payment or legacy single payment
        if ($isMultiPayment) {
            // For multi-payment, compute canonical hash from payments array (must match PosCheckoutPaymentNormalizationService::getCanonicalPaymentHash)
            $canonical = [];
            foreach ($payment['payments'] ?? [] as $p) {
                // Convert amount_paid (in units) to amount_minor_units (in cents) to match service logic
                $amountMinorUnits = (int) round(((float) ($p['amount_paid'] ?? 0)) * 100);
                $canonical[] = [
                    (int) $p['payment_method_id'],
                    $amountMinorUnits,
                    $p['reference'] ?? '',
                ];
            }
            // Sort by payment_method_id, then amount for deterministic ordering (must match service)
            usort($canonical, function (array $a, array $b) {
                if ($a[0] !== $b[0]) {
                    return $a[0] <=> $b[0];
                }
                if ($a[1] !== $b[1]) {
                    return $a[1] <=> $b[1];
                }
                return strcmp($a[2], $b[2]);
            });
            $canonicalHash = hash('sha256', json_encode($canonical));

            $normalized['payment'] = [
                'is_multi_payment' => true,
                'canonical_hash' => $canonicalHash,
            ];
        } else {
            $normalized['payment'] = [
                'is_multi_payment' => false,
                'method_code' => strtolower((string) ($payment['method_code'] ?? '')),
                'amount_paid' => round((float) ($payment['amount_paid'] ?? 0), 2),
                'reference' => $payment['reference'] ?? null,
            ];
        }

        return hash(
            'sha256',
            json_encode($this->canonicalize($normalized), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
        );
    }

    /**
     * @return mixed
     */
    protected function canonicalize(mixed $value): mixed
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

    /**
     * @param  array<string, mixed>  $snapshotPart
     * @return array<string, mixed>
     */
    protected function normalizeSnapshotForHash(array $snapshotPart): array
    {
        $encoded = json_encode($snapshotPart);
        if (! is_string($encoded)) {
            return $snapshotPart;
        }

        $decoded = json_decode($encoded, true);

        return is_array($decoded) ? $decoded : $snapshotPart;
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
