<?php

namespace Tests\Feature\SalesReturn;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SettlementAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');

        Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@test.com',
            'company_phone' => '123',
            'notification_email' => 'test@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        session(['setting_id' => 1]);
        
        // Define gates for testing
        Gate::define('saleReturns.edit', fn() => true);
    }

    protected function createSaleReturn(string $approvalStatus = 'approved', string $status = 'Awaiting Receiving'): SaleReturn
    {
        return SaleReturn::create([
            'setting_id' => 1,
            'reference' => 'SR-001',
            'date' => now(),
            'customer_id' => 1,
            'customer_name' => 'Test Customer',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => $approvalStatus,
            'status' => $status,
        ]);
    }

    /** @test */
    public function settlement_is_blocked_before_receiving()
    {
        $user = User::factory()->create();
        $saleReturn = $this->createSaleReturn('approved', 'Awaiting Receiving');

        $response = $this->actingAs($user)->get(route('sale-returns.settlement', $saleReturn));

        // Before implementation, this might pass (200 OK) if 'approved' check passes
        // After implementation, it should redirect to show page with error toast
        $response->assertStatus(302);
        $response->assertRedirect(route('sale-returns.show', $saleReturn));
    }

    /** @test */
    public function settlement_is_allowed_after_receiving()
    {
        $user = User::factory()->create();
        $saleReturn = $this->createSaleReturn('approved', 'Awaiting Settlement');
        // Simulate received
        $saleReturn->update(['received_at' => now()]);

        $response = $this->actingAs($user)->get(route('sale-returns.settlement', $saleReturn));

        $response->assertStatus(200);
    }
}
