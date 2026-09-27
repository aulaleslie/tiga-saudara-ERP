<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Currency\Entities\Currency;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseRejectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $this->setting = Setting::create([
            'company_name'              => 'Test Company',
            'company_email'             => 'test@example.com',
            'company_phone'             => '1234567890',
            'default_currency_id'       => $this->currency->id,
            'default_currency_position' => 'prefix',
            'notification_email'        => 'test@example.com',
            'footer_text'               => 'Footer',
            'company_address'           => '123 Street',
        ]);

        $this->user = User::factory()->create();

        \Modules\People\Entities\Supplier::create([
            'id' => 1,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);
    }

    public function test_purchase_rejection_captures_reason(): void
    {
        Gate::shouldReceive('denies')->andReturnFalse();
        Gate::shouldReceive('allows')->andReturnTrue();
        Gate::shouldReceive('any')->andReturnTrue();
        Gate::shouldReceive('check')->andReturnTrue();

        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(7),
            'supplier_id' => 1,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_WAITING_APPROVAL,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        $rejectionReason = 'Incorrect price on items';

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->patch(route('purchases.updateStatus', $purchase->id), [
                'status' => Purchase::STATUS_REJECTED,
                'rejection_note' => $rejectionReason,
            ]);

        $response->assertStatus(302);
        $purchase->refresh();

        $this->assertEquals(Purchase::STATUS_REJECTED, $purchase->status);
        $this->assertEquals($rejectionReason, $purchase->rejection_note);
    }

    public function test_purchase_acknowledgement_resets_to_draft(): void
    {
        Gate::shouldReceive('denies')->andReturnFalse();
        Gate::shouldReceive('allows')->andReturnTrue();
        Gate::shouldReceive('any')->andReturnTrue();
        Gate::shouldReceive('check')->andReturnTrue();

        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(7),
            'supplier_id' => 1,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_REJECTED,
            'rejection_note' => 'Please fix unit price',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->patch(route('purchases.updateStatus', $purchase->id), [
                'status' => Purchase::STATUS_DRAFTED,
            ]);

        $response->assertStatus(302);
        $purchase->refresh();

        $this->assertEquals(Purchase::STATUS_DRAFTED, $purchase->status);
        // Note: We decide whether to keep or clear rejection_note.
        // In my current implementation, I don't clear it.
    }

    public function test_rejection_requires_reason(): void
    {
        Gate::shouldReceive('denies')->andReturnFalse();
        Gate::shouldReceive('allows')->andReturnTrue();
        Gate::shouldReceive('any')->andReturnTrue();
        Gate::shouldReceive('check')->andReturnTrue();

        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(7),
            'supplier_id' => 1,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_WAITING_APPROVAL,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->patch(route('purchases.updateStatus', $purchase->id), [
                'status' => Purchase::STATUS_REJECTED,
                'rejection_note' => '', // Empty reason
            ]);

        $response->assertSessionHasErrors('rejection_note');
        $purchase->refresh();
        $this->assertEquals(Purchase::STATUS_WAITING_APPROVAL, $purchase->status);
    }
}
