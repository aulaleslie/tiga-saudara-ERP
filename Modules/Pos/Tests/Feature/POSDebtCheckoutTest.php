<?php

namespace Modules\Pos\Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Support\SalesLocationResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosActionApprovalRequest;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\Exceptions\PosCheckoutValidationException;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\PaymentTerm;
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

/**
 * @group pos-checkout-debt
 */
class POSDebtCheckoutTest extends TestCase
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
            'pos.checkout.debt',
            'pos.checkout.debt.request',
            'pos.supervisor.approval',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_debt_zero_down_payment_posts_unpaid(): void
    {
        $context = $this->createSplitCheckoutContext();
        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 1); // price 100k
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);
        
        $term = PaymentTerm::query()->create(['name' => 'Net 30', 'longevity' => 30]);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-DEBT-001',
            'is_debt' => true,
            'payment_term_id' => $term->id,
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 0, // Zero DP
            ],
        ]);

        if ($response->status() !== 201) {
            $response->dump();
        }
        $response->assertStatus(201);
        
        $sales = Sale::query()->get();
        $this->assertCount(1, $sales); // due to split posting in context

        foreach ($sales as $sale) {
            $this->assertEquals(0, $sale->paid_amount);
            $this->assertEquals($sale->total_amount, $sale->due_amount); // total_amount = grand_total
            $this->assertEquals('UNPAID', strtoupper($sale->payment_status));
            $this->assertEquals($term->id, $sale->payment_term_id);
            $this->assertEquals(Carbon::today()->addDays($term->longevity)->format('Y-m-d'), Carbon::parse($sale->due_date)->format('Y-m-d'));
            
            $this->assertEquals(0, $sale->salePayments()->count());
        }
    }

    public function test_debt_partial_down_payment_posts_partial(): void
    {
        $context = $this->createSplitCheckoutContext(false);
        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 2); // grand total 200k
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);
        
        $term = PaymentTerm::query()->create(['name' => 'Net 15', 'longevity' => 15]);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-DEBT-002',
            'is_debt' => true,
            'payment_term_id' => $term->id,
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 50000, // Partial DP
            ],
        ]);

        $response->assertStatus(201);
        
        $sales = Sale::query()->get();
        $this->assertCount(1, $sales); // only one group configured
        $sale = $sales->first();

        $this->assertEquals(50000, $sale->paid_amount);
        $this->assertEquals(150000, $sale->due_amount); // 200k - 50k
        $this->assertEquals('PARTIAL', strtoupper($sale->payment_status));
        $this->assertEquals($term->id, $sale->payment_term_id);
        
        $payments = $sale->salePayments()->get();
        $this->assertCount(1, $payments);
        $this->assertEquals(50000, $payments->first()->amount);
    }

    public function test_debt_missing_term_throws_validation_exception(): void
    {
        $context = $this->createSplitCheckoutContext(false);
        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-DEBT-003',
            'is_debt' => true,
            'payment_term_id' => null, // Missing term
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 0,
            ],
        ]);
        
        // Assert it is caught by HTTP exception handler as validation error or custom error
        $response->assertStatus(422)
                 ->assertJsonPath('code', 'PAYMENT_INVALID');
    }

    public function test_debt_walk_in_customer_posts_unpaid(): void
    {
        $context = $this->createSplitCheckoutContext(false);
        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 1); // price 100k
        // Do not select a customer explicitly; cart resolves the configured default walk-in customer.

        $term = PaymentTerm::query()->create(['name' => 'Net 30', 'longevity' => 30]);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-DEBT-WALKIN-001',
            'is_debt' => true,
            'payment_term_id' => $term->id,
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 0, // Zero DP
            ],
        ]);

        if ($response->status() !== 201) {
            $response->dump();
        }
        $response->assertStatus(201);

        $sales = Sale::query()->get();
        $this->assertCount(1, $sales);

        $sale = $sales->first();
        $this->assertEquals(0, $sale->paid_amount);
        $this->assertEquals($sale->total_amount, $sale->due_amount);
        $this->assertEquals('UNPAID', strtoupper($sale->payment_status));
        $this->assertEquals($context['customer']->id, $sale->customer_id);
    }

    public function test_debt_inactive_walk_in_customer_throws_validation_exception(): void
    {
        $context = $this->createSplitCheckoutContext(false);
        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 1);
        // Do not select a customer explicitly; cart resolves the configured default walk-in customer.
        $context['customer']->update(['is_active' => false]);

        $term = PaymentTerm::query()->create(['name' => 'Net 30', 'longevity' => 30]);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-DEBT-WALKIN-002',
            'is_debt' => true,
            'payment_term_id' => $term->id,
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 0,
            ],
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('code', 'CUSTOMER_INVALID');
    }

    public function test_debt_missing_customer_throws_validation_exception(): void
    {
        $context = $this->createSplitCheckoutContext(false);
        $context['setting']->update(['pos_walk_in_customer_id' => null]);
        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 1);
        // Do not select customer (guest) and no default walk-in customer is configured.

        $term = PaymentTerm::query()->create(['name' => 'Net 30', 'longevity' => 30]);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-DEBT-004',
            'is_debt' => true,
            'payment_term_id' => $term->id,
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 0,
            ],
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('code', 'CUSTOMER_REQUIRED');
    }

    public function test_debt_down_payment_exceeding_or_equal_grand_total_rejected(): void
    {
        $context = $this->createSplitCheckoutContext(false);
        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 1); // 100k
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);
        
        $term = PaymentTerm::query()->create(['name' => 'Net 30', 'longevity' => 30]);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-DEBT-005',
            'is_debt' => true,
            'payment_term_id' => $term->id,
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 100000, // Equal to grand total
            ],
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('code', 'PAYMENT_INVALID');
    }

    public function test_debt_authorization_flows(): void
    {
        $context = $this->createSplitCheckoutContext(false, [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
            'pos.checkout.debt.request',
        ]);
        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);
        $term = PaymentTerm::query()->create(['name' => 'Net 30', 'longevity' => 30]);

        // 1. Cashier with pos.checkout.debt.request (without pos.checkout.debt) and no token -> APPROVAL_REQUIRED
        $response1 = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-DEBT-AUTH-001',
            'is_debt' => true,
            'payment_term_id' => $term->id,
            'payment' => ['payment_method_id' => $context['methods']['cash']->id, 'amount_paid' => 0],
        ]);
        $response1->assertStatus(422)
                 ->assertJsonPath('code', 'APPROVAL_REQUIRED');

        // 2. Obtain approval token
        $supervisor = $this->createUserForSetting($context['setting'], 'DEBT SUP', ['pos.access', 'pos.supervisor.approval', 'pos.checkout.debt']);
        
        $reqRes = $this->actingAs($context['cashier'])->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.approval-requests.store'), [
                'action_type' => 'CHECKOUT_AS_DEBT',
                'target_type' => 'pos_cart',
                'target_id' => 0,
                'payload' => []
            ]);
        $reqRes->assertStatus(201);
        $requestId = $reqRes->json('request_id');

        $this->actingAs($supervisor)->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.supervisor.approval-requests.approve', ['id' => $requestId]))
            ->assertStatus(200);

        $statusRes = $this->actingAs($context['cashier'])->withSession(['setting_id' => $context['setting']->id])
            ->getJson(route('pos.sell.approval-requests.show', ['id' => $requestId]));
        $token = $statusRes->json('approval_token');

        // 3. Cashier with token -> succeeds
        $response2 = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-DEBT-AUTH-002',
            'is_debt' => true,
            'payment_term_id' => $term->id,
            'approval_token' => $token,
            'payment' => ['payment_method_id' => $context['methods']['cash']->id, 'amount_paid' => 0],
        ]);
        $response2->assertStatus(201);

        // 4. Cashier with direct permission -> succeeds without token
        $cashierWithPerm = $this->createUserForSetting($context['setting'], 'DEBT CASHIER 2', ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment', 'pos.checkout.debt']);
        $newTerminal = \Modules\Pos\Entities\PosTerminal::query()->create(['name' => 'TERM 2', 'code' => 'TERM2', 'setting_id' => $context['setting']->id, 'location_id' => $context['terminal_location']->id]); \Modules\Pos\Entities\PosTerminalPolicy::query()->create(['terminal_id' => $newTerminal->id, 'receipt_header' => 'H', 'receipt_footer' => 'F', 'max_discount_percentage' => 100]);
        \Illuminate\Support\Facades\Cache::flush();
        $this->openSession($context['setting'], $newTerminal, $cashierWithPerm);

        $this->addCartLine($cashierWithPerm, $context['setting'], $context['product']->id, 1);
        $this->selectCustomerInCart($cashierWithPerm, $context['setting'], $context['customer']);
        
        $response3 = $this->finalize($cashierWithPerm, $context['setting'], [
            'idempotency_key' => 'K-DEBT-AUTH-003',
            'is_debt' => true,
            'payment_term_id' => $term->id,
            'payment' => ['payment_method_id' => $context['methods']['cash']->id, 'amount_paid' => 0],
        ]);
        $response3->assertStatus(201);

        // 5. Cashier with BOTH pos.checkout.debt and pos.checkout.debt.request -> direct permission takes precedence (no approval token required)
        $cashierWithBoth = $this->createUserForSetting($context['setting'], 'DEBT CASHIER BOTH', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.debt',
            'pos.checkout.debt.request',
        ]);
        $terminal3 = \Modules\Pos\Entities\PosTerminal::query()->create(['name' => 'TERM 3', 'code' => 'TERM3', 'setting_id' => $context['setting']->id, 'location_id' => $context['terminal_location']->id]);
        \Modules\Pos\Entities\PosTerminalPolicy::query()->create(['terminal_id' => $terminal3->id, 'receipt_header' => 'H', 'receipt_footer' => 'F', 'max_discount_percentage' => 100]);
        \Illuminate\Support\Facades\Cache::flush();
        $this->openSession($context['setting'], $terminal3, $cashierWithBoth);

        $this->addCartLine($cashierWithBoth, $context['setting'], $context['product']->id, 1);
        $this->selectCustomerInCart($cashierWithBoth, $context['setting'], $context['customer']);

        $response4 = $this->finalize($cashierWithBoth, $context['setting'], [
            'idempotency_key' => 'K-DEBT-AUTH-004',
            'is_debt' => true,
            'payment_term_id' => $term->id,
            'payment' => ['payment_method_id' => $context['methods']['cash']->id, 'amount_paid' => 0],
        ]);
        $response4->assertStatus(201);
    }

    public function test_debt_approval_request_and_token_consumption_denied_without_debt_or_request_permission_and_when_revoked(): void
    {
        $context = $this->createSplitCheckoutContext(false, [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
        ]);
        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);
        $term = PaymentTerm::query()->create(['name' => 'Net 30 Denied', 'longevity' => 30]);

        // 1. Payment-only cashier cannot create a CHECKOUT_AS_DEBT approval request
        $deniedReq = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.approval-requests.store'), [
                'action_type' => 'CHECKOUT_AS_DEBT',
                'target_type' => 'pos_cart',
                'target_id' => 0,
                'payload' => [],
            ]);
        $deniedReq->assertStatus(403)
            ->assertJsonPath('code', 'DEBT_PERMISSION_REQUIRED');
        $this->assertSame(0, PosActionApprovalRequest::query()->count());

        // 2. Payment-only cashier cannot finalize Kasbon checkout
        $deniedFinalize = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-DEBT-DENIED-001',
            'is_debt' => true,
            'payment_term_id' => $term->id,
            'payment' => ['payment_method_id' => $context['methods']['cash']->id, 'amount_paid' => 0],
        ]);
        $deniedFinalize->assertStatus(403);

        // 3. Grant pos.checkout.debt.request, stage Kasbon DP, create & approve request
        $role = $context['cashier']->roles()->first();
        $role->givePermissionTo('pos.checkout.debt.request');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $context['cashier']->unsetRelation('roles')->unsetRelation('permissions');

        $cartToken = \Illuminate\Support\Str::uuid()->toString();
        $stageRes = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson('/pos/sell/checkout/stage-payment', [
                'cart_token' => $cartToken,
                'payment_method_id' => $context['methods']['cash']->id,
                'amount' => 20000,
                'grand_total' => 100000,
                'flow' => 'kasbon',
                'is_debt' => true,
                'payment_term_id' => $term->id,
            ]);
        $stageRes->assertStatus(201);

        $supervisor = $this->createUserForSetting($context['setting'], 'DEBT SUP REVOKED', [
            'pos.access',
            'pos.supervisor.approval',
            'pos.checkout.debt',
        ]);

        $reqRes = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.approval-requests.store'), [
                'action_type' => 'CHECKOUT_AS_DEBT',
                'target_type' => 'pos_session',
                'target_id' => $context['session']->id,
                'payload' => ['cart_token' => $cartToken],
            ]);
        $reqRes->assertStatus(201);
        $requestId = (int) $reqRes->json('request_id');

        $this->actingAs($supervisor)
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.supervisor.approval-requests.approve', ['id' => $requestId]))
            ->assertStatus(200);

        $statusRes = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->getJson(route('pos.sell.approval-requests.show', ['id' => $requestId]));
        $token = (string) $statusRes->json('approval_token');
        $this->assertNotEmpty($token);

        // 4. Revoke pos.checkout.debt.request BEFORE completion
        $role->revokePermissionTo('pos.checkout.debt.request');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $context['cashier']->unsetRelation('roles')->unsetRelation('permissions');

        // 5. Attempting to finalize with the valid token must be rejected with 403 and NOT consume the token
        $revokedFinalize = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-DEBT-REVOKED-001',
            'cart_token' => $cartToken,
            'flow' => 'kasbon',
            'is_debt' => true,
            'payment_term_id' => $term->id,
            'approval_token' => $token,
        ]);
        $revokedFinalize->assertStatus(403);

        $approvalRecord = PosActionApprovalRequest::query()->findOrFail($requestId);
        $this->assertSame(PosActionApprovalRequest::STATUS_APPROVED, $approvalRecord->status);
        $this->assertNull($approvalRecord->consumed_at, 'Approval token must not be consumed when user lacks debt permission');

        // Direct service invocation also rejects without consuming the token
        $authService = app(\Modules\Pos\Services\PosCartActionAuthorizationService::class);
        try {
            $authService->authorizeWithoutConsuming(
                $context['cashier'],
                'CHECKOUT_AS_DEBT',
                $token,
                ['setting_id' => $context['setting']->id]
            );
            $this->fail('Expected DomainException DEBT_PERMISSION_REQUIRED was not thrown.');
        } catch (\DomainException $e) {
            $this->assertSame('DEBT_PERMISSION_REQUIRED', $e->getMessage());
        }

        try {
            $authService->authorize(
                $context['cashier'],
                'CHECKOUT_AS_DEBT',
                $token
            );
            $this->fail('Expected DomainException DEBT_PERMISSION_REQUIRED was not thrown.');
        } catch (\DomainException $e) {
            $this->assertSame('DEBT_PERMISSION_REQUIRED', $e->getMessage());
        }

        $approvalRecord->refresh();
        $this->assertNull($approvalRecord->consumed_at);
    }

    public function test_split_debt_allocation_reconciles_per_sale(): void
    {
        $context = $this->createSplitCheckoutContext(true); // multi-group
        
        // Force location A to have only 1 stock, so qty=2 forces a split to location B
        \Modules\Product\Entities\ProductStock::where('location_id', $context['terminal_location']->id)->update([
            'quantity' => 1,
            'quantity_tax' => 1,
        ]);

        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 2); // total 200k (100k per location)
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);
        $term = PaymentTerm::query()->create(['name' => 'Net 30', 'longevity' => 30]);

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-DEBT-SPLIT-001',
            'is_debt' => true,
            'payment_term_id' => $term->id,
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 50000, // 50k DP, 200k total
            ],
        ]);

        $response->assertStatus(201);

        $payload = $response->json();
        $saleIds = array_values(array_map(
            static fn (array $group): int => (int) ($group['sale_id'] ?? 0),
            $payload['split_groups'] ?? []
        ));
        $sales = Sale::query()->whereIn('id', $saleIds)->get();

        $this->assertCount(2, $sales);
        
        $sumPaidAmount = $sales->sum('paid_amount');
        $this->assertEquals(50000, $sumPaidAmount, 'Sum of paid_amount across split sales must equal the DP');

        foreach ($sales as $sale) {
            $this->assertEquals(
                round($sale->total_amount - $sale->paid_amount, 2),
                round($sale->due_amount, 2),
                'Each split sale must reconcile its due_amount based on its allocated paid_amount'
            );
        }
    }

    public function test_large_value_split_kasbon_posting_regression(): void
    {
        $context = $this->createSplitCheckoutContext(true);

        // Product 1 (Group 1): 39,385,000 fulfilled only by location A
        $product1 = $this->createCustomPricedSplitStockProduct(
            $context['setting'],
            $context['terminal_location'],
            $context['source_location'],
            $context['tax'],
            39385000,
            1, // stock at A
            0  // stock at B
        );

        // Product 2 (Group 2): 13,600,000 fulfilled only by location B
        $product2 = $this->createCustomPricedSplitStockProduct(
            $context['setting'],
            $context['terminal_location'],
            $context['source_location'],
            $context['tax'],
            13600000,
            0, // stock at A
            1  // stock at B
        );

        $this->addCartLine($context['cashier'], $context['setting'], $product1->id, 1);
        $this->addCartLine($context['cashier'], $context['setting'], $product2->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $term = PaymentTerm::query()->create(['name' => 'Net 30 Large', 'longevity' => 30]);

        $initialExpectedCash = (float) DB::table('pos_sessions')
            ->where('id', $context['session']->id)
            ->value('expected_cash_total');

        $response = $this->finalize($context['cashier'], $context['setting'], [
            'idempotency_key' => 'K-DEBT-LARGE-REGRESSION-001',
            'is_debt' => true,
            'payment_term_id' => $term->id,
            'payment' => [
                'payment_method_id' => $context['methods']['cash']->id,
                'amount_paid' => 30000000,
            ],
        ]);

        $response->assertStatus(201);

        $payload = $response->json();
        $saleIds = array_values(array_map(
            static fn (array $group): int => (int) ($group['sale_id'] ?? 0),
            $payload['split_groups'] ?? []
        ));
        $sales = Sale::query()->whereIn('id', $saleIds)->get();

        $this->assertCount(2, $sales);

        $sumPaidAmount = (float) $sales->sum('paid_amount');
        $sumDueAmount = (float) $sales->sum('due_amount');

        $this->assertEquals(30000000.0, round($sumPaidAmount, 2), 'Allocated payments must total Rp30,000,000');
        $this->assertEquals(22985000.0, round($sumDueAmount, 2), 'Outstanding debt must total Rp22,985,000');

        $expectedDueDate = Carbon::today()->addDays($term->longevity)->format('Y-m-d');

        foreach ($sales as $sale) {
            $this->assertEquals('PARTIAL', strtoupper($sale->payment_status));
            $this->assertEquals($term->id, $sale->payment_term_id, 'Payment term must propagate to each split sale');
            $this->assertEquals($expectedDueDate, Carbon::parse($sale->due_date)->format('Y-m-d'));
            $this->assertEquals(
                round($sale->total_amount - $sale->paid_amount, 2),
                round($sale->due_amount, 2),
                'Each split sale must reconcile its due_amount based on its allocated paid_amount'
            );
        }

        // Cash event must reflect actual cash received (Rp30,000,000), not total amount (Rp52,985,000)
        $checkoutId = (int) ($payload['pos_checkout_id'] ?? 0);
        $this->assertDatabaseHas('pos_session_cash_events', [
            'setting_id' => $context['setting']->id,
            'pos_session_id' => $context['session']->id,
            'event_type' => PosSessionCashEvent::EVENT_CASH_SALE_IN,
            'direction' => PosSessionCashEvent::DIRECTION_IN,
            'amount' => 30000000,
            'reference_id' => $checkoutId,
        ]);

        $updatedExpectedCash = (float) DB::table('pos_sessions')
            ->where('id', $context['session']->id)
            ->value('expected_cash_total');

        $this->assertEquals(
            round($initialExpectedCash + 30000000, 2),
            round($updatedExpectedCash, 2),
            'Expected cash total must increase by actual cash received'
        );
    }

    private function createCustomPricedSplitStockProduct(
        Setting $setting,
        Location $locationA,
        Location $locationB,
        Tax $tax,
        float $price,
        int $qtyA,
        int $qtyB
    ): Product {
        $createdBy = User::query()->value('id') ?? User::factory()->create()->id;
        $index = $this->sequence++;

        $category = Category::firstOrCreate(
            ['category_code' => 'POS-CAT-LG-' . $index],
            [
                'category_name' => 'POS CATEGORY ' . $index,
                'created_by' => $createdBy,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'POS UNIT ' . $index,
            'short_name' => 'PU' . $index,
        ]);

        $product = Product::query()->create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => 'POS LARGE PRODUCT ' . $index,
            'product_code' => 'POS-LG-' . $index,
            'barcode' => 'POS-LG-BAR-' . $index,
            'product_quantity' => $qtyA + $qtyB,
            'product_cost' => round($price / 2, 2),
            'product_price' => $price,
            'product_unit' => 'PU' . $index,
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        if ($qtyA > 0) {
            ProductStock::query()->create([
                'product_id' => $product->id,
                'location_id' => $locationA->id,
                'quantity' => $qtyA,
                'quantity_non_tax' => 0,
                'quantity_tax' => $qtyA,
                'broken_quantity_non_tax' => 0,
                'broken_quantity_tax' => 0,
                'broken_quantity' => 0,
                'tax_id' => $tax->id,
            ]);
        }

        if ($qtyB > 0) {
            ProductStock::query()->create([
                'product_id' => $product->id,
                'location_id' => $locationB->id,
                'quantity' => $qtyB,
                'quantity_non_tax' => 0,
                'quantity_tax' => $qtyB,
                'broken_quantity_non_tax' => 0,
                'broken_quantity_tax' => 0,
                'broken_quantity' => 0,
                'tax_id' => $tax->id,
            ]);
        }

        ProductPrice::query()->updateOrCreate([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
        ], [
            'sale_price' => $price,
            'tier_1_price' => null,
            'tier_2_price' => null,
            'last_purchase_price' => round($price / 2, 2),
            'average_purchase_price' => round($price / 2, 2),
            'purchase_tax_id' => null,
            'sale_tax_id' => $tax->id,
        ]);

        return $product;
    }

    private function createSplitCheckoutContext(bool $multiGroup = true, array $cashierPermissions = null): array
    {
        if ($cashierPermissions === null) {
            $cashierPermissions = [
                'pos.access',
                'pos.sell',
                'pos.sessions.open',
                'pos.checkout.payment',
                'pos.checkout.debt'
            ];
        }

        $setting = $this->createSetting('POS SPLIT TERMINAL BIZ', 'TNC', 'JL');
        $sourceSetting = $multiGroup ? $this->createSetting('POS SPLIT SOURCE BIZ', 'TOP', 'JL') : $setting;
        $cashier = $this->createUserForSetting($setting, 'pos split cashier', $cashierPermissions);
        $sourceLocation = Location::create([
            'name' => 'SPLIT SOURCE LOC ' . $this->sequence++,
            'setting_id' => $sourceSetting->id,
        ]);
        [$terminal, $locations] = $this->createTerminalAndSaleLocations($setting, $sourceLocation);
        $methods = $this->seedPaymentMethods($setting, true);
        $session = $this->openSession($setting, $terminal, $cashier);
        $customer = $this->assignDefaultWalkInCustomer($setting);
        if ($multiGroup) {
            $this->assignDefaultWalkInCustomer($sourceSetting);
        }
        $tax = Tax::query()->create([
            'name' => 'VAT 11',
            'value' => 11,
            'is_default' => true,
        ]);
        $product = $this->createSplitStockProduct($setting, $locations[0], $locations[1], $tax, $multiGroup);

        return [
            'setting' => $setting,
            'cashier' => $cashier,
            'terminal' => $terminal,
            'session' => $session,
            'methods' => $methods,
            'customer' => $customer,
            'source_setting' => $sourceSetting,
            'terminal_location' => $locations[0],
            'source_location' => $locations[1],
            'product' => $product,
            'tax' => $tax,
        ];
    }

    private function createSetting(string $name, string $documentPrefix = 'DOC', string $salePrefix = 'SO'): Setting
    {
        $suffix = $this->sequence++;
        return Setting::create([
            'company_name' => $name . ' ' . $suffix,
            'company_email' => "pos.split.{$suffix}@example.com",
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => $documentPrefix,
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => $salePrefix,
            'pos_enabled' => true,
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

    private function createTerminalAndSaleLocations(Setting $setting, Location $sourceLocation): array
    {
        $index = $this->sequence++;

        $locationA = Location::create([
            'name' => 'SPLIT LOC A ' . $index,
            'setting_id' => $setting->id,
        ]);

        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $setting->id, 'location_id' => $locationA->id],
            ['is_enabled' => true, 'position' => 1]
        );
        SettingSaleLocation::updateOrCreate(
            ['setting_id' => $setting->id, 'location_id' => $sourceLocation->id],
            ['is_enabled' => true, 'position' => 2]
        );
        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-SPLIT-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Split Terminal ' . $index,
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

        return [$terminal, [$locationA, $sourceLocation]];
    }

    private function openSession(Setting $setting, PosTerminal $terminal, User $cashier)
    {
        /** @var PosSessionLifecycleService $sessionLifecycle */
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

    private function createSplitStockProduct(Setting $setting, Location $locationA, Location $locationB, Tax $tax, bool $multiGroup = true): Product
    {
        $createdBy = User::query()->value('id') ?? User::factory()->create()->id;
        $index = $this->sequence++;

        $category = Category::firstOrCreate(
            ['category_code' => 'POS-SPLIT-CAT-' . $index],
            [
                'category_name' => 'POS SPLIT CATEGORY ' . $index,
                'created_by' => $createdBy,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'POS UNIT SPLIT',
            'short_name' => 'PUS',
        ]);

        $product = Product::query()->create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => 'POS SPLIT PRODUCT ' . $index,
            'product_code' => 'POS-SPLIT-' . $index,
            'barcode' => 'POS-SPLIT-BAR-' . $index,
            'product_quantity' => 11,
            'product_cost' => 50000,
            'product_price' => 100000,
            'product_unit' => 'PUS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        ProductStock::query()->create([
            'product_id' => $product->id,
            'location_id' => $locationA->id,
            'quantity' => 10,
            'quantity_non_tax' => 0,
            'quantity_tax' => 10,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => $tax->id,
        ]);

        if ($multiGroup) {
            ProductStock::query()->create([
                'product_id' => $product->id,
                'location_id' => $locationB->id,
                'quantity' => 10,
                'quantity_non_tax' => 0,
                'quantity_tax' => 10,
                'broken_quantity_non_tax' => 0,
                'broken_quantity_tax' => 0,
                'broken_quantity' => 0,
                'tax_id' => $tax->id,
            ]);
        }

        ProductPrice::query()->updateOrCreate([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
        ], [
            'sale_price' => 100000,
            'tier_1_price' => null,
            'tier_2_price' => null,
            'last_purchase_price' => 50000,
            'average_purchase_price' => 50000,
            'purchase_tax_id' => null,
            'sale_tax_id' => $tax->id,
        ]);

        return $product;
    }

    private function seedPaymentMethods(Setting $setting, bool $enableForSetting = false): array
    {
        $index = $this->sequence++;
        $methods = [];

        $cashCoaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'POS SPLIT COA CASH ' . $index,
            'account_number' => 'POS-SPLIT-CASH-' . $index,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $methods['cash'] = PaymentMethod::query()->create([
            'name' => 'CASH SPLIT ' . $index,
            'coa_id' => $cashCoaId,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        $nonCashCoaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'POS SPLIT COA TRANSFER ' . $index,
            'account_number' => 'POS-SPLIT-TRF-' . $index,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $methods['non_cash'] = PaymentMethod::query()->create([
            'name' => 'TRANSFER SPLIT ' . $index,
            'coa_id' => $nonCashCoaId,
            'is_cash' => false,
            'requires_reference' => false,
        ]);

        if ($enableForSetting) {
            foreach ([$methods['cash'], $methods['non_cash']] as $pm) {
                DB::table('setting_pos_payment_methods')->updateOrInsert(
                    [
                        'setting_id' => $setting->id,
                        'payment_method_id' => $pm->id,
                    ],
                    [
                        'is_enabled' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        return $methods;
    }

    private function addCartLine(User $cashier, Setting $setting, int $productId, int $qty): void
    {
        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $productId,
                'qty' => $qty,
            ]);
        if ($response->status() !== 200) {
            dd($response->json());
        }
        $response->assertStatus(200);
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

    public function test_staged_debt_zero_down_payment_preserves_term_and_due_date(): void
    {
        $context = $this->createSplitCheckoutContext(false);
        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 1);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);
        
        $term = PaymentTerm::query()->create(['name' => 'Net 10 Staged', 'longevity' => 10]);
        $cartToken = \Illuminate\Support\Str::uuid()->toString();

        $syncResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson('/pos/sell/checkout/sync-debt-state', [
                'cart_token' => $cartToken,
                'grand_total' => 100000,
                'is_debt' => true,
                'payment_term_id' => $term->id,
            ]);
        $syncResponse->assertStatus(200);

        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.finalize'), [
                'idempotency_key' => 'K-DEBT-STAGED-001',
                'cart_token' => $cartToken,
            ]);

        $response->assertStatus(201);
        
        $sales = Sale::query()->get();
        $this->assertCount(1, $sales); 

        foreach ($sales as $sale) {
            $this->assertEquals(0, $sale->paid_amount);
            $this->assertEquals($sale->total_amount, $sale->due_amount); 
            $this->assertEquals('UNPAID', strtoupper($sale->payment_status));
            $this->assertEquals($term->id, $sale->payment_term_id);
            $this->assertEquals(Carbon::today()->addDays($term->longevity)->format('Y-m-d'), Carbon::parse($sale->due_date)->format('Y-m-d'));
            
            $this->assertEquals(0, $sale->salePayments()->count());
        }
    }

    public function test_staged_debt_partial_down_payment_preserves_term_and_due_date(): void
    {
        $context = $this->createSplitCheckoutContext(false);
        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 2);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);
        
        $term = PaymentTerm::query()->create(['name' => 'Net 15 Staged', 'longevity' => 15]);
        $cartToken = \Illuminate\Support\Str::uuid()->toString();

        $stageResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson('/pos/sell/checkout/stage-payment', [
                'cart_token' => $cartToken,
                'payment_method_id' => $context['methods']['cash']->id,
                'amount' => 50000,
                'grand_total' => 200000,
                'is_debt' => true,
                'payment_term_id' => $term->id,
            ]);
        $stageResponse->assertStatus(201);

        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.finalize'), [
                'idempotency_key' => 'K-DEBT-STAGED-002',
                'cart_token' => $cartToken,
            ]);

        if ($response->status() !== 201) {
            $response->dump();
        }
        $response->assertStatus(201);
        
        $sales = Sale::query()->get();
        $this->assertCount(1, $sales); 
        $sale = $sales->first();

        $this->assertEquals(50000, $sale->paid_amount);
        $this->assertEquals(150000, $sale->due_amount); 
        $this->assertEquals('PARTIAL', strtoupper($sale->payment_status));
        $this->assertEquals($term->id, $sale->payment_term_id);
        $this->assertEquals(Carbon::today()->addDays($term->longevity)->format('Y-m-d'), Carbon::parse($sale->due_date)->format('Y-m-d'));
        
        $payments = $sale->salePayments()->get();
        $this->assertCount(1, $payments);
        $this->assertEquals(50000, $payments->first()->amount);
    }

    public function test_split_owner_debt_preserves_term_and_due_date_across_all_sales(): void
    {
        $context = $this->createSplitCheckoutContext(true);
        // Product has 10 in locA, 10 in locB. Buy 15 to trigger split.
        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 15);
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);
        
        $term = PaymentTerm::query()->create(['name' => 'Net 20 Split', 'longevity' => 20]);
        $cartToken = \Illuminate\Support\Str::uuid()->toString();

        $syncResponse = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson('/pos/sell/checkout/sync-debt-state', [
                'cart_token' => $cartToken,
                'grand_total' => 1500000, // 15 * 100k
                'is_debt' => true,
                'payment_term_id' => $term->id,
            ]);
        $syncResponse->assertStatus(200);

        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.finalize'), [
                'idempotency_key' => 'K-DEBT-SPLIT-001',
                'cart_token' => $cartToken,
            ]);

        $response->assertStatus(201);
        
        $sales = Sale::query()->get();
        $this->assertCount(2, $sales); 

        foreach ($sales as $sale) {
            $this->assertEquals(0, $sale->paid_amount);
            $this->assertEquals($sale->total_amount, $sale->due_amount); 
            $this->assertEquals('UNPAID', strtoupper($sale->payment_status));
            $this->assertEquals($term->id, $sale->payment_term_id);
            $this->assertEquals(Carbon::today()->addDays($term->longevity)->format('Y-m-d'), Carbon::parse($sale->due_date)->format('Y-m-d'));
            
            $this->assertEquals(0, $sale->salePayments()->count());
        }
    }

    public function test_kasbon_mixed_cash_and_transfer_multiple_down_payments_reconciles(): void
    {
        $context = $this->createSplitCheckoutContext(false);
        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 5); // 5 * 100k = 500k
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $term = PaymentTerm::query()->create(['name' => 'Net 30 Multi DP', 'longevity' => 30]);
        $cartToken = \Illuminate\Support\Str::uuid()->toString();

        // Stage 1: Transfer DP 150k
        $stage1 = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson('/pos/sell/checkout/stage-payment', [
                'cart_token' => $cartToken,
                'payment_method_id' => $context['methods']['non_cash']->id,
                'amount' => 150000,
                'edc_reference' => 'EDC-DP-001',
                'grand_total' => 500000,
                'flow' => 'kasbon',
                'is_debt' => true,
                'payment_term_id' => $term->id,
            ]);
        $stage1->assertStatus(201);
        $this->assertEquals(350000, $stage1->json('payment_chain.remainder'));

        // Stage 2: Cash DP 100k
        $stage2 = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson('/pos/sell/checkout/stage-payment', [
                'cart_token' => $cartToken,
                'payment_method_id' => $context['methods']['cash']->id,
                'amount' => 100000,
                'grand_total' => 500000,
                'flow' => 'kasbon',
                'is_debt' => true,
                'payment_term_id' => $term->id,
            ]);
        $stage2->assertStatus(201);
        $this->assertEquals(250000, $stage2->json('payment_chain.remainder'));

        // Finalize Kasbon
        $response = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.finalize'), [
                'idempotency_key' => 'K-DEBT-MULTI-DP-001',
                'cart_token' => $cartToken,
                'flow' => 'kasbon',
                'is_debt' => true,
                'payment_term_id' => $term->id,
            ]);

        $response->assertStatus(201);

        $sales = Sale::query()->get();
        $this->assertCount(1, $sales);
        $sale = $sales->first();

        $this->assertEquals(250000, $sale->paid_amount);
        $this->assertEquals(250000, $sale->due_amount);
        $this->assertEquals('PARTIAL', strtoupper($sale->payment_status));
        $this->assertEquals($term->id, $sale->payment_term_id);
        $this->assertEquals(Carbon::today()->addDays($term->longevity)->format('Y-m-d'), Carbon::parse($sale->due_date)->format('Y-m-d'));

        // Payments: 2 records (150k non-cash, 100k cash)
        $payments = $sale->salePayments()->get();
        $this->assertCount(2, $payments);
        $this->assertEquals(250000, $payments->sum('amount'));

        // Cash events: only 100k cash received
        $cashEvents = PosSessionCashEvent::query()
            ->where('pos_session_id', $context['session']->id)
            ->where('event_type', PosSessionCashEvent::EVENT_CASH_SALE_IN)
            ->get();
        $this->assertEquals(100000, $cashEvents->sum('amount'));
    }

    public function test_kasbon_down_payment_equal_or_exceeding_total_is_rejected(): void
    {
        $context = $this->createSplitCheckoutContext(false);
        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 1); // 1 * 100k = 100k
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $term = PaymentTerm::query()->create(['name' => 'Net 15 Reject', 'longevity' => 15]);
        $cartToken = \Illuminate\Support\Str::uuid()->toString();

        // 1. Single DP equal to grand total is rejected
        $reject1 = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson('/pos/sell/checkout/stage-payment', [
                'cart_token' => $cartToken,
                'payment_method_id' => $context['methods']['cash']->id,
                'amount' => 100000,
                'grand_total' => 100000,
                'flow' => 'kasbon',
                'is_debt' => true,
                'payment_term_id' => $term->id,
            ]);
        $reject1->assertStatus(422);
        $reject1->assertJsonPath('code', 'DEBT_DOWN_PAYMENT_EXCEEDS_TOTAL');

        // 2. Stage 60k succeeds
        $stageOk = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson('/pos/sell/checkout/stage-payment', [
                'cart_token' => $cartToken,
                'payment_method_id' => $context['methods']['non_cash']->id,
                'amount' => 60000,
                'grand_total' => 100000,
                'flow' => 'kasbon',
                'is_debt' => true,
                'payment_term_id' => $term->id,
            ]);
        $stageOk->assertStatus(201);

        // 3. Staging 40k (bringing total to 100k) is rejected
        $reject2 = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson('/pos/sell/checkout/stage-payment', [
                'cart_token' => $cartToken,
                'payment_method_id' => $context['methods']['cash']->id,
                'amount' => 40000,
                'grand_total' => 100000,
                'flow' => 'kasbon',
                'is_debt' => true,
                'payment_term_id' => $term->id,
            ]);
        $reject2->assertStatus(422);
        $reject2->assertJsonPath('code', 'DEBT_DOWN_PAYMENT_EXCEEDS_TOTAL');
    }

    public function test_kasbon_staging_transfer_and_cash_without_term_succeeds_and_completion_requires_term(): void
    {
        $context = $this->createSplitCheckoutContext(false);
        $this->addCartLine($context['cashier'], $context['setting'], $context['product']->id, 5); // 5 * 100k = 500k
        $this->selectCustomerInCart($context['cashier'], $context['setting'], $context['customer']);

        $term = PaymentTerm::query()->create(['name' => 'Net 30 Terms Free Staging', 'longevity' => 30]);
        $cartToken = \Illuminate\Support\Str::uuid()->toString();

        // 1. Stage Transfer DP 150k WITHOUT payment_term_id -> must succeed
        $stage1 = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson('/pos/sell/checkout/stage-payment', [
                'cart_token' => $cartToken,
                'payment_method_id' => $context['methods']['non_cash']->id,
                'amount' => 150000,
                'edc_reference' => 'EDC-DP-NO-TERM',
                'grand_total' => 500000,
                'flow' => 'kasbon',
                'is_debt' => true,
            ]);
        $stage1->assertStatus(201);
        $this->assertEquals(350000, $stage1->json('payment_chain.remainder'));
        $this->assertNull($stage1->json('payment_chain.payment_term_id'));

        // 2. Stage Cash DP 100k WITHOUT payment_term_id -> must succeed
        $stage2 = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson('/pos/sell/checkout/stage-payment', [
                'cart_token' => $cartToken,
                'payment_method_id' => $context['methods']['cash']->id,
                'amount' => 100000,
                'grand_total' => 500000,
                'flow' => 'kasbon',
                'is_debt' => true,
            ]);
        $stage2->assertStatus(201);
        $this->assertEquals(250000, $stage2->json('payment_chain.remainder'));

        // 3. Attempt completion/finalize WITHOUT payment_term_id -> Must be rejected with 422
        $rejectFinalize = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.finalize'), [
                'idempotency_key' => 'K-DEBT-NO-TERM-FAIL',
                'cart_token' => $cartToken,
                'flow' => 'kasbon',
                'is_debt' => true,
            ]);
        $rejectFinalize->assertStatus(422);
        $this->assertEquals('PAYMENT_INVALID', $rejectFinalize->json('code'));
        $this->assertEquals('Syarat pembayaran (Term) harus dipilih untuk utang.', $rejectFinalize->json('message'));

        // 4. Complete/finalize WITH payment_term_id -> Succeeds
        $successFinalize = $this->actingAs($context['cashier'])
            ->withSession(['setting_id' => $context['setting']->id])
            ->postJson(route('pos.sell.checkout.finalize'), [
                'idempotency_key' => 'K-DEBT-NO-TERM-SUCCESS',
                'cart_token' => $cartToken,
                'flow' => 'kasbon',
                'is_debt' => true,
                'payment_term_id' => $term->id,
            ]);
        $successFinalize->assertStatus(201);

        $sales = Sale::query()->get();
        $this->assertCount(1, $sales);
        $sale = $sales->first();

        $this->assertEquals(250000, $sale->paid_amount);
        $this->assertEquals(250000, $sale->due_amount);
        $this->assertEquals('PARTIAL', strtoupper($sale->payment_status));
        $this->assertEquals($term->id, $sale->payment_term_id);
    }

    private function finalize(User $cashier, Setting $setting, array $payload)
    {
        return $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.checkout.finalize'), $payload);
    }
}
