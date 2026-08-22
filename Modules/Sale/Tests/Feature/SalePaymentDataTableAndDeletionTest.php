<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\CustomerCredit;
use Modules\SalesReturn\Entities\SalePaymentCreditApplication;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SalePaymentDataTableAndDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Customer $customer;
    protected PaymentMethod $paymentMethod;
    protected Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::findOrCreate('salePayments.access', 'web');
        Permission::findOrCreate('salePayments.edit', 'web');
        Permission::findOrCreate('salePayments.delete', 'web');

        Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '1234567890',
            'company_address' => 'Test Address',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notification@test.com',
            'footer_text' => 'Test Footer',
        ]);

        session(['setting_id' => $this->setting->id]);

        $this->user = User::factory()->create(['is_active' => 1]);
        $this->user->givePermissionTo('salePayments.access');
        $this->user->givePermissionTo('salePayments.edit');
        $this->user->givePermissionTo('salePayments.delete');
        $this->actingAs($this->user);

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@test.com',
            'customer_phone' => '08123456789',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Customer Address',
        ]);

        $coa = \Modules\Setting\Entities\ChartOfAccount::create([
            'account_number' => '1101',
            'name' => 'Cash on Hand',
            'category' => 'Kas & Bank',
            'setting_id' => $this->setting->id,
        ]);

        $this->paymentMethod = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $coa->id,
        ]);

        $this->sale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-TEST-001',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'status' => 'APPROVED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);
    }

    /** @test */
    public function it_renders_escaped_note_and_empty_marker_in_datatable(): void
    {
        $paymentWithNote = SalePayment::create([
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 60000,
            'date' => '2026-08-20',
            'reference' => 'SPAY-WITH-NOTE',
            'payment_method' => 'Cash',
            'note' => '<b>Safe Note</b>',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $paymentWithoutNote = SalePayment::create([
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 40000,
            'date' => '2026-08-20',
            'reference' => 'SPAY-NO-NOTE',
            'payment_method' => 'Cash',
            'note' => null,
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('sale-payments.index', $this->sale->id), [
                'HTTP_X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        $rowWithNote = collect($data)->where('reference', 'SPAY-WITH-NOTE')->first();
        $rowWithoutNote = collect($data)->where('reference', 'SPAY-NO-NOTE')->first();

        $this->assertNotNull($rowWithNote);
        $this->assertNotNull($rowWithoutNote);
        $this->assertSame('&lt;b&gt;Safe Note&lt;/b&gt;', $rowWithNote['note']);
        $this->assertSame('-', $rowWithoutNote['note']);

        // View action (eye) instead of pencil
        $this->assertStringContainsString('bi-eye', $rowWithNote['action']);
        $this->assertStringNotContainsString('bi-pencil', $rowWithNote['action']);

        // Assert note column definition includes payment-note CSS class
        $dataTable = new \Modules\Sale\DataTables\SalePaymentsDataTable();
        $columns = collect($dataTable->html()->getColumns());
        $noteCol = $columns->firstWhere('name', 'note') ?? $columns->firstWhere('data', 'note');
        $this->assertNotNull($noteCol);
        $this->assertStringContainsString('payment-note', $noteCol->className);
    }

    /** @test */
    public function it_deletes_eligible_active_payment_and_reconciles_parent_balances(): void
    {
        $payment1 = SalePayment::create([
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 60000,
            'date' => '2026-08-20',
            'reference' => 'SPAY-DEL-1',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $payment2 = SalePayment::create([
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 40000,
            'date' => '2026-08-20',
            'reference' => 'SPAY-DEL-2',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        // Delete payment2 (40,000) -> sale paid should become 60,000, due 40,000, status PARTIAL
        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->delete(route('sale-payments.destroy', $payment2->id));

        $response->assertRedirect(route('sales.index'));
        $this->assertDatabaseMissing('sale_payments', ['id' => $payment2->id]);

        $this->sale->refresh();
        $this->assertEquals(60000.0, (float) $this->sale->paid_amount);
        $this->assertEquals(40000.0, (float) $this->sale->due_amount);
        $this->assertSame('PARTIAL', strtoupper($this->sale->payment_status));

        // Delete payment1 (60,000) -> last payment deleted -> paid 0, due 100,000, status UNPAID
        $this->withSession(['setting_id' => $this->setting->id])
            ->delete(route('sale-payments.destroy', $payment1->id));

        $this->sale->refresh();
        $this->assertEquals(0.0, (float) $this->sale->paid_amount);
        $this->assertEquals(100000.0, (float) $this->sale->due_amount);
        $this->assertSame('UNPAID', strtoupper($this->sale->payment_status));
    }

    /** @test */
    public function it_repairs_parent_drift_upon_payment_deletion(): void
    {
        // Simulate corrupted sale header values
        $this->sale->update([
            'paid_amount' => 999999,
            'due_amount' => 0,
            'payment_status' => 'Paid',
        ]);

        $payment1 = SalePayment::create([
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 30000,
            'date' => '2026-08-20',
            'reference' => 'SPAY-DRIFT-1',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $payment2 = SalePayment::create([
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 20000,
            'date' => '2026-08-20',
            'reference' => 'SPAY-DRIFT-2',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $this->withSession(['setting_id' => $this->setting->id])
            ->delete(route('sale-payments.destroy', $payment2->id));

        $this->sale->refresh();
        // Canonical reconciliation derives exactly from remaining active payments (payment1: 30,000)
        $this->assertEquals(30000.0, (float) $this->sale->paid_amount);
        $this->assertEquals(70000.0, (float) $this->sale->due_amount);
        $this->assertSame('PARTIAL', strtoupper($this->sale->payment_status));
    }

    /** @test */
    public function it_rejects_deletion_of_protected_credit_or_automated_lineage_payment(): void
    {
        $creditPayment = SalePayment::create([
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 50000,
            'date' => '2026-08-20',
            'reference' => 'SPAY-CREDIT',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'SLR-001',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 50000,
            'paid_amount' => 0,
            'due_amount' => 50000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        // Attach credit application
        $credit = CustomerCredit::create([
            'customer_id' => $this->customer->id,
            'sale_return_id' => $saleReturn->id,
            'amount' => 50000,
            'remaining_amount' => 0,
            'status' => 'CLOSED',
        ]);

        SalePaymentCreditApplication::create([
            'sale_payment_id' => $creditPayment->id,
            'customer_credit_id' => $credit->id,
            'amount' => 50000,
        ]);

        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->delete(route('sale-payments.destroy', $creditPayment->id));

        $this->assertDatabaseHas('sale_payments', ['id' => $creditPayment->id]);

        // Automated invalidation lineage payment
        $lineagePayment = SalePayment::create([
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 20000,
            'date' => '2026-08-20',
            'reference' => 'SPAY-LINEAGE',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_INVALIDATED,
            'invalidation_source' => 'POS_RETURN_CASH_CORRECTION',
            'invalidation_source_id' => 123,
        ]);

        $response2 = $this->withSession(['setting_id' => $this->setting->id])
            ->delete(route('sale-payments.destroy', $lineagePayment->id));

        $this->assertDatabaseHas('sale_payments', ['id' => $lineagePayment->id]);
    }

    /** @test */
    public function it_rejects_deletion_when_sale_is_archived(): void
    {
        $this->sale->update([
            'archived_at' => now(),
            'archived_by' => $this->user->id,
        ]);

        $payment = SalePayment::create([
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 50000,
            'date' => '2026-08-20',
            'reference' => 'SPAY-ARCHIVED',
            'payment_method' => 'Cash',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->delete(route('sale-payments.destroy', $payment->id));

        $response->assertStatus(403);
        $this->assertDatabaseHas('sale_payments', ['id' => $payment->id]);
    }
}
