<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosSessionCashEvent;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Entities\PosSupervisorApproval;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Product\Entities\Product;
use Modules\People\Entities\Customer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSReportingPackTest extends TestCase
{
    use RefreshDatabase;

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
            'pos.reports.access',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_reports_page_loads_for_authorized_user(): void
    {
        $setting = $this->createSetting('BIZ A');
        $user = $this->createUserForSetting($setting, 'POS_MANAGER_ROLE', ['pos.access', 'pos.reports.access']);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.reports.index'));

        $response->assertOk();
        $response->assertSee('Laporan POS');
    }

    public function test_reports_page_contains_kpi_section_and_required_tabs(): void
    {
        $setting = $this->createSetting('BIZ KPI');
        $user = $this->createUserForSetting($setting, 'POS_MANAGER_KPI', ['pos.access', 'pos.reports.access']);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.reports.index'));

        $response->assertOk()
            ->assertSee('id="reports-kpi-grid"', false)
            ->assertSee('Total Penjualan')
            ->assertSee('Total Transaksi')
            ->assertSee('Rata-rata Basket')
            ->assertSee('Rasio Tunai')
            ->assertSee('Persetujuan Supervisor')
            ->assertSee('Penjualan Harian')
            ->assertSee('Ringkasan Kasir')
            ->assertSee('Metode Pembayaran')
            ->assertSee('Penjualan Produk')
            ->assertSee('Persetujuan Supervisor');
    }

    public function test_reports_page_script_wires_date_filters_for_refresh_requests(): void
    {
        $setting = $this->createSetting('BIZ DATE FILTER');
        $user = $this->createUserForSetting($setting, 'POS_MANAGER_DATE_FILTER', ['pos.access', 'pos.reports.access']);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.reports.index'));

        $response->assertOk()
            ->assertSee("url.searchParams.set('date_from', inputFrom.value);", false)
            ->assertSee("url.searchParams.set('date_to', inputTo.value);", false)
            ->assertSee("btnRefresh.addEventListener('click', loadAllTabs);", false);
    }

    public function test_reports_page_blocked_for_unauthorized_user(): void
    {
        $setting = $this->createSetting('BIZ A');
        $cashier = $this->createUserForSetting($setting, 'POS_CASHIER_ROLE', ['pos.access', 'pos.sell']);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.reports.index'));

        $response->assertForbidden();
    }

    public function test_daily_sales_api_returns_correct_aggregates(): void
    {
        $setting = $this->createSetting('BIZ A');
        $user = $this->createUserForSetting($setting, 'POS_MANAGER', ['pos.access', 'pos.reports.access']);
        
        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');
        
        // Today checkouts: 1 cash 150000, 1 transfer 200000
        $this->createCheckout($setting->id, $user->id, $today . ' 10:00:00', 150000, 'cash');
        $this->createCheckout($setting->id, $user->id, $today . ' 14:00:00', 200000, 'transfer');
        
        // Yesterday checkouts: 1 cash 100000
        $this->createCheckout($setting->id, $user->id, $yesterday . ' 10:00:00', 100000, 'cash');

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reports.daily-sales', [
                'date_from' => $yesterday,
                'date_to' => $today
            ]));

        $response->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment([
                'date' => $today,
                'transactions_count' => 2,
                'grand_total' => 350000,
                'cash_total' => 150000,
                'non_cash_total' => 200000,
            ])
            ->assertJsonFragment([
                'date' => $yesterday,
                'transactions_count' => 1,
                'grand_total' => 100000,
                'cash_total' => 100000,
                'non_cash_total' => 0,
            ]);
    }

    public function test_cashier_summary_api_groups_by_cashier(): void
    {
        $setting = $this->createSetting('BIZ A');
        $manager = $this->createUserForSetting($setting, 'POS_MANAGER', ['pos.access', 'pos.reports.access']);
        $cashier1 = $this->createUserForSetting($setting, 'CASHIER_1', ['pos.access', 'pos.sell']);
        $cashier2 = $this->createUserForSetting($setting, 'CASHIER_2', ['pos.access', 'pos.sell']);
        
        $today = now()->format('Y-m-d');
        
        $this->createCheckout($setting->id, $cashier1->id, $today . ' 10:00:00', 100000, 'cash');
        $this->createCheckout($setting->id, $cashier1->id, $today . ' 11:00:00', 50000, 'cash');
        $this->createCheckout($setting->id, $cashier2->id, $today . ' 12:00:00', 200000, 'transfer');

        $response = $this->actingAs($manager)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reports.cashier-summary', [
                'date_from' => $today,
                'date_to' => $today
            ]));

        $response->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment([
                'cashier_name' => $cashier1->name,
                'transactions_count' => 2,
                'grand_total' => 150000,
                'average_basket' => 75000,
            ])
            ->assertJsonFragment([
                'cashier_name' => $cashier2->name,
                'transactions_count' => 1,
                'grand_total' => 200000,
                'average_basket' => 200000,
            ]);
    }

    public function test_payment_method_summary_groups_by_method(): void
    {
        $setting = $this->createSetting('BIZ A');
        $manager = $this->createUserForSetting($setting, 'POS_MANAGER', ['pos.access', 'pos.reports.access']);
        
        $today = now()->format('Y-m-d');
        
        $this->createCheckout($setting->id, $manager->id, $today . ' 10:00:00', 100000, 'cash');
        $this->createCheckout($setting->id, $manager->id, $today . ' 11:00:00', 50000, 'cash');
        $this->createCheckout($setting->id, $manager->id, $today . ' 12:00:00', 200000, 'transfer');
        $this->createCheckout($setting->id, $manager->id, $today . ' 13:00:00', 150000, 'qris');

        $response = $this->actingAs($manager)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reports.payment-methods', [
                'date_from' => $today,
                'date_to' => $today
            ]));

        $response->assertOk()
            ->assertJsonCount(3)
            ->assertJsonFragment([
                'payment_method_label' => 'CASH',
                'transactions_count' => 2,
                'grand_total' => 150000,
            ])
            ->assertJsonFragment([
                'payment_method_label' => 'TRANSFER',
                'transactions_count' => 1,
                'grand_total' => 200000,
            ])
            ->assertJsonFragment([
                'payment_method_label' => 'QRIS',
                'transactions_count' => 1,
                'grand_total' => 150000,
            ]);
    }

    public function test_item_sales_summary_joins_sale_details(): void
    {
        $setting = $this->createSetting('BIZ A');
        $manager = $this->createUserForSetting($setting, 'POS_MANAGER', ['pos.access', 'pos.reports.access']);
        
        $today = now()->format('Y-m-d');
        
        $product1 = Product::create([
            'setting_id' => $setting->id,
            'product_code' => 'P1',
            'product_name' => 'Item 1',
            'product_price' => 50000,
            'product_cost' => 10000,
        ]);
        
        $product2 = Product::create([
            'setting_id' => $setting->id,
            'product_code' => 'P2',
            'product_name' => 'Item 2',
            'product_price' => 100000,
            'product_cost' => 20000,
        ]);

        $customer = Customer::create(['setting_id' => $setting->id, 'customer_name' => 'Test Cus', 'customer_email' => 'a@b.c', 'customer_phone' => '123']);

        $sale1 = Sale::forceCreate([
            'setting_id' => $setting->id, 
            'customer_id' => $customer->id, 
            'customer_name' => $customer->customer_name, 
            'reference' => 'S1', 
            'date' => $today, 
            'status' => Sale::STATUS_DISPATCHED,
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0, 
            'total_amount' => 200000, 'paid_amount' => 200000, 'due_amount' => 0,
            'payment_status' => 'Paid', 'payment_method' => 'Cash'
        ]);
        $sale1->saleDetails()->create([
            'product_id' => $product1->id, 'product_name' => 'Item 1', 'product_code' => 'P1',
            'quantity' => 2, 'price' => 50000, 'unit_price' => 50000, 'sub_total' => 100000,
            'product_discount_amount' => 0, 'product_discount_type' => 'fixed', 'product_tax_amount' => 0
        ]);
        $sale1->saleDetails()->create([
            'product_id' => $product2->id, 'product_name' => 'Item 2', 'product_code' => 'P2',
            'quantity' => 1, 'price' => 100000, 'unit_price' => 100000, 'sub_total' => 100000,
            'product_discount_amount' => 0, 'product_discount_type' => 'fixed', 'product_tax_amount' => 0
        ]);
        $this->createCheckout($setting->id, $manager->id, $today . ' 10:00:00', 200000, 'cash', $sale1->id);

        $sale2 = Sale::forceCreate([
            'setting_id' => $setting->id, 
            'customer_id' => $customer->id, 
            'customer_name' => $customer->customer_name, 
            'reference' => 'S2', 
            'date' => $today, 
            'status' => Sale::STATUS_DISPATCHED,
            'tax_percentage' => 0, 'tax_amount' => 0, 'discount_percentage' => 0, 'discount_amount' => 0, 'shipping_amount' => 0, 
            'total_amount' => 150000, 'paid_amount' => 150000, 'due_amount' => 0,
            'payment_status' => 'Paid', 'payment_method' => 'Cash'
        ]);
        $sale2->saleDetails()->create([
            'product_id' => $product1->id, 'product_name' => 'Item 1', 'product_code' => 'P1',
            'quantity' => 3, 'price' => 50000, 'unit_price' => 50000, 'sub_total' => 150000,
            'product_discount_amount' => 0, 'product_discount_type' => 'fixed', 'product_tax_amount' => 0
        ]);
        $this->createCheckout($setting->id, $manager->id, $today . ' 11:00:00', 150000, 'cash', $sale2->id);

        $response = $this->actingAs($manager)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reports.item-sales', [
                'date_from' => $today,
                'date_to' => $today
            ]));

        $response->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment([
                'product_code' => 'P1',
                'product_name' => 'ITEM 1',
                'quantity_sold' => 5,
                'gross_revenue' => 250000,
            ])
            ->assertJsonFragment([
                'product_code' => 'P2',
                'product_name' => 'ITEM 2',
                'quantity_sold' => 1,
                'gross_revenue' => 100000,
            ]);
    }

    public function test_daily_sales_with_single_payment_overpayment(): void
    {
        $setting = $this->createSetting('BIZ CHANGE');
        $user = $this->createUserForSetting($setting, 'POS_MANAGER', ['pos.access', 'pos.reports.access']);

        $today = now()->format('Y-m-d');

        // Checkout with 50,000 cash for 45,000 transaction = 5,000 change
        $this->createCheckout($setting->id, $user->id, $today . ' 10:00:00', 45000, 'cash', null, 5000);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reports.daily-sales', [
                'date_from' => $today,
                'date_to' => $today
            ]));

        $response->assertOk()
            ->assertJsonFragment([
                'date' => $today,
                'transactions_count' => 1,
                'grand_total' => 45000,
                'cash_total' => 40000, // 45,000 paid (grand_total) - 5,000 change = 40,000 actual cash received
                'non_cash_total' => 0,
            ]);
    }

    public function test_daily_sales_with_multi_payment_overpayment(): void
    {
        $setting = $this->createSetting('BIZ MULTI');
        $user = $this->createUserForSetting($setting, 'POS_MANAGER', ['pos.access', 'pos.reports.access']);

        $today = now()->format('Y-m-d');

        // Create terminal and session for multi-payment checkout
        $terminal = PosTerminal::firstOrCreate(['setting_id' => $setting->id], [
            'code' => 'T1', 'name' => 'T1', 'is_active' => true
        ]);
        $session = PosSession::firstOrCreate(['setting_id' => $setting->id, 'cashier_user_id' => $user->id], [
            'terminal_id' => $terminal->id, 'opening_float_total' => 0, 'status' => 'CLOSED',
            'opened_at' => now(), 'closed_at' => now()
        ]);

        // Get payment methods
        $accountId = bin2hex(random_bytes(4));
        $accountNumber = '1100-' . $setting->id . '-' . $accountId;
        $coa = ChartOfAccount::firstOrCreate(
            ['setting_id' => $setting->id, 'account_number' => $accountNumber],
            [
                'name' => 'Cash Account ' . $accountId,
                'account_number' => $accountNumber,
                'category' => 'Kas & Bank',
                'setting_id' => $setting->id,
            ]
        );

        $cashMethod = PaymentMethod::firstOrCreate(
            ['name' => 'CASH_MULTI'],
            ['name' => 'CASH_MULTI', 'is_cash' => true, 'coa_id' => $coa->id]
        );
        $qrisMethod = PaymentMethod::firstOrCreate(
            ['name' => 'QRIS_MULTI'],
            ['name' => 'QRIS_MULTI', 'is_cash' => false, 'coa_id' => $coa->id]
        );

        // Multi-payment: 50,000 cash + 30,000 QRIS = 80,000 paid for 75,000 transaction = 5,000 change
        $checkout = PosCheckout::create([
            'setting_id' => $setting->id,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $user->id,
            'status' => 'POSTED',
            'idempotency_key' => uniqid(),
            'payload_hash' => 'hash',
            'subtotal' => 75000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 75000,
            'paid_total' => 80000,
            'change_total' => 5000,
            'finalized_at' => Carbon::parse($today . ' 10:00:00'),
        ]);

        // Create payment entries
        $checkout->payments()->create([
            'payment_method_id' => $cashMethod->id,
            'amount_minor_units' => 5000000, // 50,000
            'sequence_order' => 1,
        ]);
        $checkout->payments()->create([
            'payment_method_id' => $qrisMethod->id,
            'amount_minor_units' => 3000000, // 30,000
            'sequence_order' => 2,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reports.daily-sales', [
                'date_from' => $today,
                'date_to' => $today
            ]));

        $response->assertOk()
            ->assertJsonFragment([
                'date' => $today,
                'transactions_count' => 1,
                'grand_total' => 75000,
                'cash_total' => 45000, // 50,000 cash - 5,000 change = 45,000
                'non_cash_total' => 30000,
            ]);
    }

    public function test_cashier_summary_with_change_adjusted_totals(): void
    {
        $setting = $this->createSetting('BIZ CASHIER CHANGE');
        $cashier1 = $this->createUserForSetting($setting, 'CASHIER_1', ['pos.access', 'pos.sell']);
        $manager = $this->createUserForSetting($setting, 'POS_MANAGER', ['pos.access', 'pos.reports.access']);

        $today = now()->format('Y-m-d');

        // Cashier 1: Two checkouts with change
        $this->createCheckout($setting->id, $cashier1->id, $today . ' 10:00:00', 95000, 'cash', null, 5000);  // 95K transaction - 5K change = 90K actual cash
        $this->createCheckout($setting->id, $cashier1->id, $today . ' 11:00:00', 90000, 'cash', null, 10000); // 90K transaction - 10K change = 80K actual cash

        $response = $this->actingAs($manager)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reports.cashier-summary', [
                'date_from' => $today,
                'date_to' => $today
            ]));

        $response->assertOk()
            ->assertJsonFragment([
                'cashier_name' => $cashier1->name,
                'transactions_count' => 2,
                'grand_total' => 185000, // 95K + 90K (original transaction amounts)
                'cash_total' => 170000, // (95K - 5K) + (90K - 10K) = 90K + 80K = 170K actual cash
                'average_basket' => 92500,
            ]);
    }

    public function test_reports_with_zero_change(): void
    {
        $setting = $this->createSetting('BIZ EXACT');
        $user = $this->createUserForSetting($setting, 'POS_MANAGER', ['pos.access', 'pos.reports.access']);

        $today = now()->format('Y-m-d');

        // Exact payment with no change
        $this->createCheckout($setting->id, $user->id, $today . ' 10:00:00', 100000, 'cash', null, 0);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reports.daily-sales', [
                'date_from' => $today,
                'date_to' => $today
            ]));

        $response->assertOk()
            ->assertJsonFragment([
                'date' => $today,
                'cash_total' => 100000, // 100,000 - 0 change = 100,000
            ]);
    }

    public function test_session_reconciliation_alignment_with_cash_events(): void
    {
        $setting = $this->createSetting('BIZ RECON');
        $cashier = $this->createUserForSetting($setting, 'CASHIER', ['pos.access', 'pos.sell']);
        $manager = $this->createUserForSetting($setting, 'POS_MANAGER', ['pos.access', 'pos.reports.access']);

        $today = now()->format('Y-m-d');

        // Create terminal and session
        $terminal = PosTerminal::firstOrCreate(['setting_id' => $setting->id], [
            'code' => 'T1', 'name' => 'T1', 'is_active' => true
        ]);
        $session = PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'opening_float_total' => 0,
            'status' => PosSession::STATUS_CLOSED,
            'opened_at' => Carbon::parse($today . ' 08:00:00'),
            'closed_at' => Carbon::parse($today . ' 17:00:00'),
        ]);

        // Create a checkout with change
        $checkout = $this->createCheckout($setting->id, $cashier->id, $today . ' 10:00:00', 90000, 'cash', null, 10000);
        $checkout->pos_session_id = $session->id;
        $checkout->save();

        // Verify reconciliation query returns correct pos_cash_sales_total
        // Expected: 90,000 (grand_total) - 10,000 (change) = 80,000 actual cash
        $service = app('Modules\Pos\Services\PosReconciliationService');
        $reconciliationData = $service->getSessionReconciliation($setting->id, $today, $today);

        $sessionReconciliation = collect($reconciliationData)->firstWhere('session_id', $session->id);

        $this->assertNotNull($sessionReconciliation, 'Session reconciliation data not found');
        // pos_cash_sales_total should be transaction amount minus change
        $this->assertEquals(80000, $sessionReconciliation['pos_cash_sales_total']);
    }

    public function test_supervisor_approvals_returns_filtered_log(): void
    {
        $setting = $this->createSetting('BIZ A');
        $manager = $this->createUserForSetting($setting, 'POS_MANAGER', ['pos.access', 'pos.reports.access']);
        $cashier = $this->createUserForSetting($setting, 'CASHIER_1', ['pos.access', 'pos.sell']);
        
        $today = now()->format('Y-m-d');
        
        PosSupervisorApproval::create([
            'setting_id' => $setting->id,
            'action_type' => PosSupervisorApproval::ACTION_PRICE_OVERRIDE,
            'target_type' => 'App\Models\TemporaryCartLine',
            'target_id' => '1',
            'requested_by' => $cashier->id,
            'approved_by' => $manager->id,
            'approval_result' => PosSupervisorApproval::RESULT_APPROVED,
            'reason' => 'VIP discount',
            'occurred_at' => Carbon::parse($today . ' 10:00:00'),
        ]);

        $response = $this->actingAs($manager)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reports.supervisor-approvals', [
                'date_from' => $today,
                'date_to' => $today
            ]));

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment([
                'action_type' => 'PRICE_OVERRIDE',
                'approval_result' => 'APPROVED',
                'requester_name' => $cashier->name,
                'approver_name' => $manager->name,
                'reason' => 'VIP DISCOUNT',
            ]);
    }

    public function test_reports_api_validates_date_params(): void
    {
        $setting = $this->createSetting('BIZ A');
        $manager = $this->createUserForSetting($setting, 'POS_MANAGER', ['pos.access', 'pos.reports.access']);
        
        $response = $this->actingAs($manager)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reports.daily-sales'));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_from', 'date_to']);

        $response = $this->actingAs($manager)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reports.daily-sales', [
                'date_from' => 'invalid-date',
                'date_to' => '2026-02-28',
            ]));
            
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_from']);
            
        $response = $this->actingAs($manager)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.reports.daily-sales', [
                'date_from' => '2026-02-28',
                'date_to' => '2026-02-27', // date_to is before date_from
            ]));
            
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_to']);
    }

    public function test_reports_scoped_to_current_setting(): void
    {
        $setting1 = $this->createSetting('BIZ A');
        $setting2 = $this->createSetting('BIZ B');
        $manager = $this->createUserForSetting($setting1, 'POS_MANAGER_1', ['pos.access', 'pos.reports.access']);
        $manager2 = $this->createUserForSetting($setting2, 'POS_MANAGER_2', ['pos.access', 'pos.reports.access']);
        
        $today = now()->format('Y-m-d');
        
        $this->createCheckout($setting1->id, $manager->id, $today . ' 10:00:00', 100000, 'cash');
        $this->createCheckout($setting2->id, $manager2->id, $today . ' 11:00:00', 200000, 'transfer');

        $response = $this->actingAs($manager)
            ->withSession(['setting_id' => $setting1->id])
            ->getJson(route('pos.reports.daily-sales', [
                'date_from' => $today,
                'date_to' => $today
            ]));

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment([
                'grand_total' => 100000,
            ])
            ->assertJsonMissing([
                'grand_total' => 200000,
            ]);
    }

    // --- Helpers ---

    private function createCheckout(int $settingId, int $cashierId, string $date, float $amount, string $paymentMethod, ?int $saleId = null, float $changeTotal = 0): PosCheckout
    {
        $terminal = PosTerminal::firstOrCreate(['setting_id' => $settingId], [
            'code' => 'T1', 'name' => 'T1', 'is_active' => true
        ]);
        $session = PosSession::firstOrCreate(['setting_id' => $settingId, 'cashier_user_id' => $cashierId], [
            'terminal_id' => $terminal->id, 'opening_float_total' => 0, 'status' => 'CLOSED',
            'opened_at' => now(), 'closed_at' => now()
        ]);

        // Get or create payment method based on code
        $methodName = match(strtolower($paymentMethod)) {
            'cash' => 'CASH',
            'transfer' => 'TRANSFER',
            'qris' => 'QRIS',
            default => strtoupper($paymentMethod),
        };

        // Create or get COA for this setting
        $accountId = bin2hex(random_bytes(4));
        $accountNumber = '1100-' . $settingId . '-' . $accountId;
        $coa = ChartOfAccount::firstOrCreate(
            ['setting_id' => $settingId, 'account_number' => $accountNumber],
            [
                'name' => 'Cash Account ' . $accountId,
                'account_number' => $accountNumber,
                'category' => 'Kas & Bank',
                'setting_id' => $settingId,
            ]
        );

        $paymentMethodRecord = PaymentMethod::firstOrCreate(
            ['name' => $methodName],
            [
                'name' => $methodName,
                'is_cash' => strtolower($paymentMethod) === 'cash',
                'coa_id' => $coa->id,
            ]
        );

        $checkout = PosCheckout::create([
            'setting_id' => $settingId,
            'pos_session_id' => $session->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashierId,
            'status' => 'POSTED',
            'idempotency_key' => uniqid(),
            'payload_hash' => 'hash',
            'subtotal' => $amount,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => $amount,
            'paid_total' => $amount + $changeTotal,
            'change_total' => $changeTotal,
            'payment_method_id' => $paymentMethodRecord->id,
            'sale_id' => $saleId,
            'finalized_at' => Carbon::parse($date),
        ]);

        // Create payment entry for single payment
        $checkout->payments()->create([
            'payment_method_id' => $paymentMethodRecord->id,
            'amount_minor_units' => (int)($amount * 100),
            'sequence_order' => 1,
        ]);

        return $checkout;
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
        $role = Role::firstOrCreate(['name' => $roleName . '-' . $setting->id]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }
}
