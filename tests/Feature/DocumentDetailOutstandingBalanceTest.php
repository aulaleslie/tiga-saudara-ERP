<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\CustomerCredit;
use Modules\SalesReturn\Entities\SalePaymentCreditApplication;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentDetailOutstandingBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting1;
    protected Setting $setting2;
    protected User $user;
    protected Supplier $supplier;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting1 = Setting::create([
            'id' => 1,
            'company_name' => 'Setting Alpha',
            'company_email' => 'alpha@test.com',
            'company_phone' => '111',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'alpha@test.com',
            'company_address' => 'Addr 1',
            'footer_text' => 'Footer 1'
        ]);

        $this->setting2 = Setting::create([
            'id' => 2,
            'company_name' => 'Setting Beta',
            'company_email' => 'beta@test.com',
            'company_phone' => '222',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'beta@test.com',
            'company_address' => 'Addr 2',
            'footer_text' => 'Footer 2'
        ]);

        session(['setting_id' => $this->setting1->id]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Supplier Alpha',
            'supplier_email' => 'supplier@alpha.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Alpha Address',
            'setting_id' => $this->setting1->id,
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Customer Alpha',
            'contact_name' => 'Contact Person',
            'setting_id' => $this->setting1->id,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        Permission::findOrCreate('purchases.show', 'web');
        Permission::findOrCreate('purchases.access', 'web');
        Permission::findOrCreate('purchases.received.correct', 'web');
        Permission::findOrCreate('purchases.reporting-date.override', 'web');
        Permission::findOrCreate('purchases.due-date.override', 'web');
        Permission::findOrCreate('purchasePayments.global.access', 'web');
        Permission::findOrCreate('sales.show', 'web');
        Permission::findOrCreate('sales.access', 'web');
        Permission::findOrCreate('sales.reporting-date.override', 'web');
        Permission::findOrCreate('sales.due-date.override', 'web');
        Permission::findOrCreate('salePayments.global.access', 'web');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /** @test */
    public function purchase_detail_displays_sisa_pembayaran_with_live_balance_without_payment_create_permission(): void
    {
        // Give only show/access (NO purchasePayments.create permission)
        $this->user->syncPermissions(['purchases.show', 'purchases.access']);

        $purchase = Purchase::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'reference' => 'PO-DETAIL-001',
            'supplier_id' => $this->supplier->id,
            'setting_id' => $this->setting1->id,
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'total_amount' => 1000000,
            'paid_amount' => 100000, // Stale header
            'due_amount' => 900000,  // Stale header
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 400000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-PO-001',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $response = $this->get(route('purchases.show', $purchase));
        $response->assertStatus(200);

        // Asserts Sisa Pembayaran label and formatted live balance
        $response->assertSee('Sisa Pembayaran');
        $response->assertSee(formatRupiah(600000));
        $response->assertDontSee(formatRupiah(900000));
    }

    /** @test */
    public function global_purchase_detail_displays_sisa_pembayaran_and_preserves_authorized_cross_setting_access(): void
    {
        $this->user->syncPermissions(['purchasePayments.global.access']);

        $supplier2 = Supplier::create([
            'supplier_name' => 'Supplier Beta',
            'supplier_email' => 'supplier@beta.com',
            'supplier_phone' => '87654321',
            'city' => 'Surabaya',
            'country' => 'Indonesia',
            'address' => 'Beta Address',
            'setting_id' => $this->setting2->id,
        ]);

        $purchase = Purchase::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'reference' => 'PO-GLOBAL-001',
            'supplier_id' => $supplier2->id,
            'setting_id' => $this->setting2->id,
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 1000000, // Fully settled
            'date' => now()->toDateString(),
            'reference' => 'PAY-PO-FULL',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $response = $this->get(route('purchases.global-payments.show', $purchase->id));
        $response->assertStatus(200);

        $response->assertSee('Sisa Pembayaran');
        $response->assertSee(formatRupiah(0)); // Explicit zero
    }

    /** @test */
    public function sales_detail_displays_sisa_tagihan_with_live_balance_without_payment_create_permission(): void
    {
        $this->user->syncPermissions(['sales.show', 'sales.access']);

        $sale = Sale::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'reference' => 'SO-DETAIL-001',
            'customer_id' => $this->customer->id,
            'customer_name' => 'Customer Alpha',
            'setting_id' => $this->setting1->id,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'total_amount' => 1000000,
            'paid_amount' => 100000, // Stale header
            'due_amount' => 900000,  // Stale header
        ]);

        $payment = SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 300000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-SO-001',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $saleReturn = SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'RET-DETAIL-1',
            'setting_id' => $this->setting1->id,
            'customer_id' => $this->customer->id,
            'customer_name' => 'Customer Alpha',
            'total_amount' => 200000,
            'paid_amount' => 0,
            'due_amount' => 200000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'status' => 'APPROVED',
        ]);

        $credit = CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 200000,
            'remaining_amount' => 200000,
            'status' => 'open',
        ]);

        SalePaymentCreditApplication::create([
            'sale_payment_id' => $payment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 200000,
        ]);

        $response = $this->get(route('sales.show', $sale));
        $response->assertStatus(200);

        $response->assertSee('Sisa Tagihan');
        $response->assertSee(format_currency(500000));
        $response->assertDontSee(format_currency(900000));
    }

    /** @test */
    public function global_sales_detail_displays_sisa_tagihan_and_preserves_authorized_cross_setting_access(): void
    {
        $this->user->syncPermissions(['salePayments.global.access']);

        $customer2 = Customer::create([
            'customer_name' => 'Customer Beta',
            'contact_name' => 'Beta Contact',
            'setting_id' => $this->setting2->id,
        ]);

        $sale = Sale::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'reference' => 'SO-GLOBAL-001',
            'customer_id' => $customer2->id,
            'customer_name' => 'Customer Beta',
            'setting_id' => $this->setting2->id,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 1000000, // Fully settled
            'date' => now()->toDateString(),
            'reference' => 'PAY-SO-FULL',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $response = $this->get(route('sales.global-payments.show', $sale->id));
        $response->assertStatus(200);

        $response->assertSee('Sisa Tagihan');
        $response->assertSee(format_currency(0)); // Explicit zero
    }

    /** @test */
    public function detail_viewing_does_not_mutate_or_reconcile_stored_records(): void
    {
        $this->user->syncPermissions(['purchases.show', 'purchases.access', 'sales.show', 'sales.access']);

        $purchase = Purchase::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'reference' => 'PO-IMMUTABLE-001',
            'supplier_id' => $this->supplier->id,
            'setting_id' => $this->setting1->id,
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'total_amount' => 1000000,
            'paid_amount' => 100000, // Stale
            'due_amount' => 900000,  // Stale
        ]);

        $sale = Sale::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'reference' => 'SO-IMMUTABLE-001',
            'customer_id' => $this->customer->id,
            'customer_name' => 'Customer Alpha',
            'setting_id' => $this->setting1->id,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'total_amount' => 1000000,
            'paid_amount' => 100000, // Stale
            'due_amount' => 900000,  // Stale
        ]);

        $this->get(route('purchases.show', $purchase))->assertStatus(200);
        $this->get(route('sales.show', $sale))->assertStatus(200);

        // Verify stored headers remain exactly as before
        $freshPurchase = Purchase::findOrFail($purchase->id);
        $this->assertEquals(100000, $freshPurchase->paid_amount);
        $this->assertEquals(900000, $freshPurchase->due_amount);
        $this->assertEquals('Unpaid', $freshPurchase->payment_status);

        $freshSale = Sale::findOrFail($sale->id);
        $this->assertEquals(100000, $freshSale->paid_amount);
        $this->assertEquals(900000, $freshSale->due_amount);
        $this->assertEquals('UNPAID', $freshSale->payment_status);
    }

    /** @test */
    public function unauthorized_normal_cross_business_access_remains_rejected(): void
    {
        $this->user->syncPermissions(['purchases.show', 'purchases.access', 'purchases.received.correct', 'sales.show', 'sales.access']);

        // Current session is setting1. Try to access setting2 via normal show routes
        $supplier2 = Supplier::create([
            'supplier_name' => 'Supplier Beta',
            'supplier_email' => 'beta@example.com',
            'supplier_phone' => '123',
            'city' => 'Surabaya',
            'country' => 'Indonesia',
            'address' => 'Beta',
            'setting_id' => $this->setting2->id,
        ]);

        $purchaseSetting2 = Purchase::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'reference' => 'PO-SETTING2',
            'supplier_id' => $supplier2->id,
            'setting_id' => $this->setting2->id,
            'payment_method' => 'Cash',
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Unpaid',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);

        $customer2 = Customer::create([
            'customer_name' => 'Customer Beta',
            'setting_id' => $this->setting2->id,
        ]);

        $saleSetting2 = Sale::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'reference' => 'SO-SETTING2',
            'customer_id' => $customer2->id,
            'customer_name' => 'Customer Beta',
            'setting_id' => $this->setting2->id,
            'payment_method' => 'Cash',
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
        ]);

        // Attempting cross-setting access without global access yields 404 (setting mismatch guard)
        $this->get(route('purchases.show', $purchaseSetting2))->assertStatus(404);
        $this->get(route('sales.show', $saleSetting2))->assertStatus(404);
    }
}
