<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTransaction;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GlobalPosPaymentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Customer $customer;
    protected Setting $setting1;
    protected Setting $setting2;
    protected Location $location1;
    protected PosTerminal $terminal;
    protected PosSession $session;
    protected PaymentMethod $paymentMethod;
    protected PosTransaction $transaction;
    protected Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting1 = Setting::firstOrCreate(['id' => 1], [
            'company_name' => 'Setting 1',
            'company_email' => 's1@example.com',
            'company_phone' => '0811',
            'company_address' => 'Addr 1',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 's1@example.com',
            'footer_text' => 'F1',
            'document_prefix' => 'D1',
            'purchase_prefix_document' => 'P1',
            'sale_prefix_document' => 'S1',
            'pos_enabled' => true,
        ]);

        $this->setting2 = Setting::firstOrCreate(['id' => 2], [
            'company_name' => 'Setting 2',
            'company_email' => 's2@example.com',
            'company_phone' => '0812',
            'company_address' => 'Addr 2',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 's2@example.com',
            'footer_text' => 'F2',
            'document_prefix' => 'D2',
            'purchase_prefix_document' => 'P2',
            'sale_prefix_document' => 'S2',
            'pos_enabled' => true,
        ]);

        $this->location1 = Location::firstOrCreate(['id' => 10], ['name' => 'Loc 10', 'setting_id' => 1]);

        $coa = ChartOfAccount::firstOrCreate(
            ['setting_id' => $this->setting1->id, 'account_number' => '1100-TEST-1'],
            [
                'name' => 'Cash Account Test',
                'account_number' => '1100-TEST-1',
                'category' => 'Kas & Bank',
                'setting_id' => $this->setting1->id,
            ]
        );

        $this->paymentMethod = PaymentMethod::firstOrCreate(
            ['name' => 'Cash'],
            ['name' => 'Cash', 'is_cash' => true, 'coa_id' => $coa->id]
        );

        $this->terminal = PosTerminal::create([
            'setting_id' => $this->setting1->id,
            'name' => 'Terminal 1',
            'code' => 'T1',
            'is_active' => true,
        ]);

        $this->user = User::factory()->create();

        $this->session = PosSession::create([
            'setting_id' => $this->setting1->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => 'OPEN',
            'opened_at' => now(),
            'opening_float_total' => 0,
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Customer Auth',
            'customer_phone' => '08123456789',
            'customer_email' => 'auth@example.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Street',
        ]);

        $this->sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-AUTH-01',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => Sale::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Cash',
            'setting_id' => $this->setting1->id,
        ]);

        $this->transaction = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-AUTH-01',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $this->transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'idempotency_key' => uniqid(),
            'payload_hash' => 'hash_auth',
            'subtotal' => 100000,
            'grand_total' => 100000,
            'paid_total' => 0,
            'sale_id' => $this->sale->id,
            'receipt_number' => 'RCP-AUTH-01',
        ]);
        $this->transaction->update(['completed_checkout_id' => $checkout->id]);

        // Setup permissions
        Permission::findOrCreate('posPayments.global.access');
        Permission::findOrCreate('posPayments.global.create');
        Permission::findOrCreate('posPayments.global.history');
        Permission::findOrCreate('pos.receipts.reprint');
    }

    public function test_unauthorized_user_denied_access()
    {
        $unauthorized = User::factory()->create();

        $this->actingAs($unauthorized)
            ->get(route('pos.global-payments.index'))
            ->assertStatus(403);

        $this->actingAs($unauthorized)
            ->get(route('pos.global-payments.show', $this->transaction->id))
            ->assertStatus(403);

        $this->actingAs($unauthorized)
            ->get(route('pos.global-payments.create', $this->transaction->id))
            ->assertStatus(403);
    }

    public function test_view_only_user_can_access_list_and_detail_but_cannot_create()
    {
        $viewOnlyUser = User::factory()->create();
        $viewOnlyUser->givePermissionTo('posPayments.global.access');

        // Can access index
        $this->actingAs($viewOnlyUser)
            ->get(route('pos.global-payments.index'))
            ->assertStatus(200);

        // Can access show
        $this->actingAs($viewOnlyUser)
            ->get(route('pos.global-payments.show', $this->transaction->id))
            ->assertStatus(200);

        // Forbidden on create
        $this->actingAs($viewOnlyUser)
            ->get(route('pos.global-payments.create', $this->transaction->id))
            ->assertStatus(403);

        // Forbidden on store
        $this->actingAs($viewOnlyUser)
            ->post(route('pos.global-payments.store', $this->transaction->id), [
                'reference' => 'PAY-DENY',
                'date' => now()->toDateString(),
                'payment_method_id' => $this->paymentMethod->id,
                'allocations' => [$this->transaction->id => 50000],
            ])
            ->assertStatus(403);
    }

    public function test_show_renders_combined_print_history_with_actor_names_and_timestamps()
    {
        $viewOnlyUser = User::factory()->create();
        $viewOnlyUser->givePermissionTo('posPayments.global.access');

        $printerA = User::factory()->create(['name' => 'Kasir Print Satu']);
        $printerB = User::factory()->create(['name' => 'Kasir Print Dua']);
        $printerLegacy = User::factory()->create(['name' => 'Kasir Legacy Checkout']);

        $checkout = $this->transaction->completedCheckout;

        // Transaction-level PRINT log (ordinary receipt printing / global reprint path).
        \Modules\Pos\Entities\PosReceiptPrintLog::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $this->transaction->id,
            'print_type' => \Modules\Pos\Entities\PosReceiptPrintLog::TYPE_PRINT,
            'printed_by' => $printerA->id,
            'printed_at' => now()->subMinutes(10),
        ]);

        // Transaction-level REPRINT log (e.g. via global-payments.receipt.reprint).
        \Modules\Pos\Entities\PosReceiptPrintLog::create([
            'setting_id' => $this->setting1->id,
            'pos_transaction_id' => $this->transaction->id,
            'print_type' => \Modules\Pos\Entities\PosReceiptPrintLog::TYPE_REPRINT,
            'printed_by' => $printerB->id,
            'printed_at' => now()->subMinutes(5),
        ]);

        // Legacy checkout-scoped log (from the setting-scoped sell flow's logPrint(),
        // which never sets pos_transaction_id) must still surface in combined history.
        \Modules\Pos\Entities\PosReceiptPrintLog::create([
            'setting_id' => $this->setting1->id,
            'pos_checkout_id' => $checkout->id,
            'print_type' => \Modules\Pos\Entities\PosReceiptPrintLog::TYPE_PRINT,
            'printed_by' => $printerLegacy->id,
            'printed_at' => now()->subMinutes(20),
        ]);

        $response = $this->actingAs($viewOnlyUser)
            ->get(route('pos.global-payments.show', $this->transaction->id))
            ->assertStatus(200);

        $response->assertSee(strtoupper('Kasir Print Satu'));
        $response->assertSee(strtoupper('Kasir Print Dua'));
        $response->assertSee(strtoupper('Kasir Legacy Checkout'));
        $response->assertSee('REPRINT');
        $response->assertSee(now()->subMinutes(20)->format('d/m/Y'));
    }

    public function test_create_authorized_user_can_access_create_and_store()
    {
        $creatorUser = User::factory()->create();
        $creatorUser->givePermissionTo(['posPayments.global.access', 'posPayments.global.create']);

        $this->actingAs($creatorUser)
            ->get(route('pos.global-payments.create', $this->transaction->id))
            ->assertStatus(200);

        $this->actingAs($creatorUser)
            ->post(route('pos.global-payments.store', $this->transaction->id), [
                'reference' => 'PAY-ALLOW-01',
                'date' => now()->toDateString(),
                'payment_method_id' => $this->paymentMethod->id,
                'allocations' => [$this->transaction->id => 50000],
            ])
            ->assertRedirect(route('pos.global-payments.index'));
    }

    public function test_preview_endpoint_returns_the_nested_json_contract_the_frontend_relies_on()
    {
        $creatorUser = User::factory()->create();
        $creatorUser->givePermissionTo(['posPayments.global.access', 'posPayments.global.create']);

        $response = $this->actingAs($creatorUser)
            ->postJson(route('pos.global-payments.preview', $this->transaction->id), [
                'payment_method_id' => $this->paymentMethod->id,
                'allocations' => [$this->transaction->id => 50000],
            ])
            ->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'transactions' => [
                        '*' => [
                            'pos_transaction_id',
                            'code',
                            'receipt_number',
                            'setting_name',
                            'setting_id',
                            'total_amount',
                            'effective_paid',
                            'live_due',
                            'requested_amount',
                            'planned_allocations' => [
                                '*' => [
                                    'sale_id',
                                    'sale_reference',
                                    'setting_id',
                                    'setting_name',
                                    'total_amount',
                                    'live_due',
                                    'allocated_amount',
                                ],
                            ],
                        ],
                    ],
                    'total_requested',
                    'total_planned',
                ],
            ]);

        // Lock the exact shape the frontend (create.blade.php) consumes: nested under
        // data.transactions[].planned_allocations[], not a flat data.expanded_allocations
        // array. A regression here previously broke the preview button silently because
        // the frontend read a key ("expanded_allocations") the backend never returned.
        $data = $response->json('data');
        $this->assertArrayNotHasKey('expanded_allocations', $data);

        $transaction = $data['transactions'][0];
        $this->assertSame($this->transaction->code, $transaction['code']);

        $allocation = $transaction['planned_allocations'][0];
        $this->assertSame($this->sale->reference, $allocation['sale_reference']);
        $this->assertEquals(50000.0, $allocation['allocated_amount']);
    }

    public function test_receipt_reprint_permission_matrix()
    {
        $userWithGlobalOnly = User::factory()->create();
        $userWithGlobalOnly->givePermissionTo('posPayments.global.access');

        $userWithReprintOnly = User::factory()->create();
        $userWithReprintOnly->givePermissionTo('pos.receipts.reprint');

        $userWithBoth = User::factory()->create();
        $userWithBoth->givePermissionTo(['posPayments.global.access', 'pos.receipts.reprint']);

        // Missing pos.receipts.reprint -> 403
        $this->actingAs($userWithGlobalOnly)
            ->post(route('pos.global-payments.receipt.reprint', $this->transaction->id))
            ->assertStatus(403);

        // Missing posPayments.global.access -> 403
        $this->actingAs($userWithReprintOnly)
            ->post(route('pos.global-payments.receipt.reprint', $this->transaction->id))
            ->assertStatus(403);

        // Both permissions -> 200
        $this->actingAs($userWithBoth)
            ->post(route('pos.global-payments.receipt.reprint', $this->transaction->id))
            ->assertStatus(200);
    }

    public function test_receipt_reprint_rejects_transaction_lacking_posted_checkout_or_resolvable_sale()
    {
        $userWithBoth = User::factory()->create();
        $userWithBoth->givePermissionTo(['posPayments.global.access', 'pos.receipts.reprint']);

        // A COMPLETED transaction with no completed_checkout_id at all: lifecycle status
        // alone must not be enough to authorize a reprint.
        $incompleteTransaction = PosTransaction::create([
            'setting_id' => $this->setting1->id,
            'code' => 'TRX-NO-CHECKOUT',
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $this->actingAs($userWithBoth)
            ->post(route('pos.global-payments.receipt.reprint', $incompleteTransaction->id))
            ->assertStatus(422);
    }
}
