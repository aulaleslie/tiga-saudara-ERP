<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SalePaymentMaintenanceTest extends TestCase
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
    public function it_renders_read_only_payment_details_for_authorized_user(): void
    {
        $payment = SalePayment::create([
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 50000,
            'date' => '2026-08-20',
            'reference' => 'SPAY-001',
            'payment_method' => 'Cash',
            'note' => 'Original Payment Note',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->get(route('sale-payments.edit', [$this->sale->id, $payment->id]));

        $response->assertStatus(200);
        $response->assertSee('SPAY-001');
        $response->assertSee('Original Payment Note');
        // Should not render editable amount input
        $response->assertDontSee('name="amount"', false);
    }

    /** @test */
    public function it_allows_authorized_user_to_update_note_only_on_active_payment(): void
    {
        $payment = SalePayment::create([
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 50000,
            'date' => '2026-08-20',
            'reference' => 'SPAY-001',
            'payment_method' => 'Cash',
            'note' => 'Old Note',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->patch(route('sale-payments.update', $payment->id), [
                'note' => 'Updated Note Content',
                'amount' => 999999, // Should be ignored
                'reference' => 'TAMPERED-REF', // Should be ignored
                'date' => '2020-01-01', // Should be ignored
                'sale_id' => 999, // Should be ignored
                'payment_method_id' => 999, // Should be ignored
            ]);

        $response->assertRedirect(route('sales.show', $this->sale->id));
        $payment->refresh();
        $this->assertSame('Updated Note Content', $payment->note);
        $this->assertEquals(50000.0, (float) $payment->amount);
        $this->assertSame('SPAY-001', $payment->reference);
        $this->assertSame($this->sale->id, (int) $payment->sale_id);
    }

    /** @test */
    public function it_normalizes_empty_note_to_null(): void
    {
        $payment = SalePayment::create([
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 50000,
            'date' => '2026-08-20',
            'reference' => 'SPAY-001',
            'payment_method' => 'Cash',
            'note' => 'Old Note',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->patch(route('sale-payments.update', $payment->id), [
                'note' => '',
            ]);

        $response->assertRedirect(route('sales.show', $this->sale->id));
        $payment->refresh();
        $this->assertNull($payment->note);
    }

    /** @test */
    public function it_fails_validation_and_retains_submitted_input_when_note_exceeds_max_length(): void
    {
        $payment = SalePayment::create([
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 50000,
            'date' => '2026-08-20',
            'reference' => 'SPAY-001',
            'payment_method' => 'Cash',
            'note' => 'Old Note',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $tooLongNote = str_repeat('a', 1001);

        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->from(route('sale-payments.edit', [$this->sale->id, $payment->id]))
            ->patch(route('sale-payments.update', $payment->id), [
                'note' => $tooLongNote,
            ]);

        $response->assertRedirect(route('sale-payments.edit', [$this->sale->id, $payment->id]));
        $response->assertSessionHasErrors('note');
        $payment->refresh();
        $this->assertSame('Old Note', $payment->note);
    }

    /** @test */
    public function it_forbids_note_update_when_user_lacks_edit_permission(): void
    {
        $unauthorizedUser = User::factory()->create(['is_active' => 1]);
        $unauthorizedUser->givePermissionTo('salePayments.access');
        $this->actingAs($unauthorizedUser);

        $payment = SalePayment::create([
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 50000,
            'date' => '2026-08-20',
            'reference' => 'SPAY-001',
            'payment_method' => 'Cash',
            'note' => 'Old Note',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->patch(route('sale-payments.update', $payment->id), [
                'note' => 'Unauthorized edit',
            ]);

        $response->assertStatus(403);
        $payment->refresh();
        $this->assertSame('Old Note', $payment->note);
    }

    /** @test */
    public function it_rejects_note_update_on_invalidated_payment(): void
    {
        $payment = SalePayment::create([
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 50000,
            'date' => '2026-08-20',
            'reference' => 'SPAY-001',
            'payment_method' => 'Cash',
            'note' => 'Old Note',
            'status' => SalePayment::STATUS_INVALIDATED,
        ]);

        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->patch(route('sale-payments.update', $payment->id), [
                'note' => 'Modified Note',
            ]);

        $response->assertStatus(403);
        $payment->refresh();
        $this->assertSame('Old Note', $payment->note);
    }

    /** @test */
    public function it_rejects_detail_and_update_for_archived_sale(): void
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
            'reference' => 'SPAY-001',
            'payment_method' => 'Cash',
            'note' => 'Old Note',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        // Edit/detail view should either fail or not allow mutation
        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->patch(route('sale-payments.update', $payment->id), [
                'note' => 'Modified Note',
            ]);

        $response->assertStatus(403);
        $payment->refresh();
        $this->assertSame('Old Note', $payment->note);
    }

    /** @test */
    public function it_enforces_route_parent_and_active_setting_ownership(): void
    {
        $otherSale = Sale::create([
            'date' => now()->toDateString(),
            'reference' => 'SL-OTHER-001',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50000,
            'paid_amount' => 50000,
            'due_amount' => 0,
            'status' => 'APPROVED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        $payment = SalePayment::create([
            'sale_id' => $this->sale->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 50000,
            'date' => '2026-08-20',
            'reference' => 'SPAY-001',
            'payment_method' => 'Cash',
            'note' => 'Old Note',
            'status' => SalePayment::STATUS_ACTIVE,
        ]);

        // Mismatched sale_id in route vs payment's real parent
        $response = $this->withSession(['setting_id' => $this->setting->id])
            ->get(route('sale-payments.edit', [$otherSale->id, $payment->id]));

        $response->assertStatus(404);

        // Cross setting
        $otherSetting = Setting::create([
            'id' => 2,
            'company_name' => 'Other Company',
            'company_email' => 'other@company.com',
            'company_phone' => '123456',
            'company_address' => 'Other Address',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'other@company.com',
            'footer_text' => 'Other Footer',
        ]);

        $response = $this->withSession(['setting_id' => $otherSetting->id])
            ->get(route('sale-payments.edit', [$this->sale->id, $payment->id]));

        $response->assertStatus(404);

        $response = $this->withSession(['setting_id' => $otherSetting->id])
            ->patch(route('sale-payments.update', $payment->id), [
                'note' => 'Cross setting update',
            ]);

        $response->assertStatus(404);
    }
}
