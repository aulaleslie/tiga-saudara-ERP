<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PurchasePaymentDataTableAndDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Supplier $supplier;
    protected PaymentMethod $paymentMethod;
    protected Purchase $purchase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::findOrCreate('purchasePayments.access', 'web');
        Permission::findOrCreate('purchasePayments.edit', 'web');
        Permission::findOrCreate('purchasePayments.delete', 'web');
        Permission::findOrCreate('purchasePayments.global.access', 'web');

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
        $this->user->givePermissionTo('purchasePayments.access');
        $this->user->givePermissionTo('purchasePayments.edit');
        $this->user->givePermissionTo('purchasePayments.delete');
        $this->user->givePermissionTo('purchasePayments.global.access');
        $this->actingAs($this->user);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@test.com',
            'supplier_phone' => '08123456789',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Supplier Address',
            'setting_id' => $this->setting->id,
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

        $this->purchase = Purchase::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'reference' => 'PO-TEST-001',
            'supplier_id' => $this->supplier->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'due_amount' => 0,
            'status' => Purchase::STATUS_RECEIVED,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);
    }

    /** @test */
    public function it_renders_escaped_note_and_empty_marker_in_datatable(): void
    {
        $paymentWithNote = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 60000,
            'date' => '2026-08-20',
            'reference' => 'PPAY-WITH-NOTE',
            'payment_method' => 'Cash',
            'note' => '<b>Safe Purchase Note</b>',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $paymentWithoutNote = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 40000,
            'date' => '2026-08-20',
            'reference' => 'PPAY-NO-NOTE',
            'payment_method' => 'Cash',
            'note' => null,
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $ajaxUrl = route('datatable.purchase_payments', $this->purchase->id);
        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->getJson($ajaxUrl, [
                'HTTP_X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        $rowWithNote = collect($data)->where('reference', 'PPAY-WITH-NOTE')->first();
        $rowWithoutNote = collect($data)->where('reference', 'PPAY-NO-NOTE')->first();

        $this->assertNotNull($rowWithNote);
        $this->assertNotNull($rowWithoutNote);
        $this->assertSame('&lt;b&gt;Safe Purchase Note&lt;/b&gt;', $rowWithNote['note']);
        $this->assertSame('-', $rowWithoutNote['note']);

        // View action (eye) instead of pencil
        $this->assertStringContainsString('bi-eye', $rowWithNote['action']);
        $this->assertStringNotContainsString('bi-pencil', $rowWithNote['action']);

        // Verify globalMode is purely read-only even for users with edit/delete permissions
        $globalResponse = $this->withSession(['setting_id' => $this->setting->id])
            ->getJson(route('purchases.global-payments.history', $this->purchase->id), [
                'HTTP_X-Requested-With' => 'XMLHttpRequest',
            ]);

        $globalResponse->assertStatus(200);
        $globalData = $globalResponse->json('data');
        $globalRow = collect($globalData)->where('reference', 'PPAY-WITH-NOTE')->first();

        $this->assertNotNull($globalRow);
        $this->assertStringContainsString('purchases/global-payments/' . $this->purchase->id, $globalRow['action']);
        $this->assertStringNotContainsString('purchase-payments.destroy', $globalRow['action']);
        $this->assertStringNotContainsString('bi-trash', $globalRow['action']);

        // Assert note column definition includes payment-note CSS class
        $dataTable = new \Modules\Purchase\DataTables\PurchasePaymentsDataTable();
        $columns = collect($dataTable->html()->getColumns());
        $noteCol = $columns->firstWhere('name', 'note') ?? $columns->firstWhere('data', 'note');
        $this->assertNotNull($noteCol);
        $this->assertStringContainsString('payment-note', $noteCol->className);
    }

    /** @test */
    public function it_deletes_eligible_active_payment_directly_without_prior_invalidation(): void
    {
        $payment1 = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 60000,
            'date' => '2026-08-20',
            'reference' => 'PPAY-DEL-1',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $payment2 = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 40000,
            'date' => '2026-08-20',
            'reference' => 'PPAY-DEL-2',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        // Direct delete of active payment2 (40,000) -> purchase paid becomes 60,000, due 40,000, status PARTIAL
        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->delete(route('purchase-payments.destroy', $payment2->id));

        $response->assertRedirect(route('purchases.index'));
        $this->assertDatabaseMissing('purchase_payments', ['id' => $payment2->id]);

        $this->purchase->refresh();
        $this->assertEquals(60000.0, (float) $this->purchase->paid_amount);
        $this->assertEquals(40000.0, (float) $this->purchase->due_amount);
        $this->assertSame('PARTIAL', strtoupper($this->purchase->payment_status));

        // Direct delete of payment1 (60,000) -> last payment deleted -> paid 0, due 100,000, status UNPAID
        $this->withSession(['setting_id' => $this->setting->id])
            ->delete(route('purchase-payments.destroy', $payment1->id));

        $this->purchase->refresh();
        $this->assertEquals(0.0, (float) $this->purchase->paid_amount);
        $this->assertEquals(100000.0, (float) $this->purchase->due_amount);
        $this->assertSame('UNPAID', strtoupper($this->purchase->payment_status));
    }

    /** @test */
    public function it_allows_deleting_manually_invalidated_payment(): void
    {
        $activePayment = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 50000,
            'date' => '2026-08-20',
            'reference' => 'PPAY-ACT',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $invalidPayment = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 50000,
            'date' => '2026-08-20',
            'reference' => 'PPAY-INV-MANUAL',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_INVALIDATED,
        ]);

        $this->purchase->reconcileFromActivePayments();

        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->delete(route('purchase-payments.destroy', $invalidPayment->id));

        $response->assertRedirect(route('purchases.index'));
        $this->assertDatabaseMissing('purchase_payments', ['id' => $invalidPayment->id]);

        $this->purchase->refresh();
        $this->assertEquals(50000.0, (float) $this->purchase->paid_amount);
        $this->assertEquals(50000.0, (float) $this->purchase->due_amount);
        $this->assertSame('PARTIAL', strtoupper($this->purchase->payment_status));
    }

    /** @test */
    public function it_rejects_deletion_of_protected_automated_lineage_purchase_payment(): void
    {
        $lineagePayment = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 20000,
            'date' => '2026-08-20',
            'reference' => 'PPAY-LINEAGE',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_INVALIDATED,
            'invalidation_source' => 'MODIFY_PURCHASE_SETTLEMENT',
            'invalidation_source_id' => 456,
        ]);

        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->delete(route('purchase-payments.destroy', $lineagePayment->id));

        $this->assertDatabaseHas('purchase_payments', ['id' => $lineagePayment->id]);
    }

    /** @test */
    public function it_rejects_deletion_when_purchase_is_archived(): void
    {
        $this->purchase->update([
            'archived_at' => now(),
            'archived_by' => $this->user->id,
        ]);

        $payment = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 50000,
            'date' => '2026-08-20',
            'reference' => 'PPAY-ARCHIVED',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->delete(route('purchase-payments.destroy', $payment->id));

        $response->assertStatus(403);
        $this->assertDatabaseHas('purchase_payments', ['id' => $payment->id]);
    }
}
