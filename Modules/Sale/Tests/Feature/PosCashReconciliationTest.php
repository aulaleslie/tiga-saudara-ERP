<?php

namespace Modules\Sale\Tests\Feature;

use App\Support\PosSessionManager;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;

class PosCashReconciliationTest extends PosDraftFeatureTestCase
{
    public function test_expected_cash_includes_opening_float_and_cash_sales(): void
    {
        $sale = $this->createSaleForSession(25000);

        SalePayment::create([
            'date' => now()->format('Y-m-d'),
            'reference' => 'INV/' . $sale->reference,
            'amount' => 25000,
            'sale_id' => $sale->id,
            'pos_session_id' => $this->posSession->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_method' => $this->cashMethod->name,
        ]);

        $expectedCash = app(PosSessionManager::class)->calculateExpectedCash($this->posSession->fresh());

        $this->assertSame(125000.0, $expectedCash);
    }

    public function test_close_session_persists_discrepancy_consistently(): void
    {
        $sale = $this->createSaleForSession(30000);

        SalePayment::create([
            'date' => now()->format('Y-m-d'),
            'reference' => 'INV/' . $sale->reference,
            'amount' => 30000,
            'sale_id' => $sale->id,
            'pos_session_id' => $this->posSession->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_method' => $this->cashMethod->name,
        ]);

        $closed = app(PosSessionManager::class)->close(128000, null, 'password');

        $this->assertSame('closed', $closed->status);
        $this->assertSame('130000.00', (string) $closed->expected_cash);
        $this->assertSame('-2000.00', (string) $closed->discrepancy);
    }

    public function test_unauthorized_user_cannot_access_cash_movement_pages(): void
    {
        auth()->logout();

        $response = $this->get(route('app.pos.cash-pickup'));
        $response->assertRedirect('/login');
    }

    private function createSaleForSession(float $total): Sale
    {
        return Sale::create([
            'setting_id' => $this->setting->id,
            'date' => now()->format('Y-m-d'),
            'reference' => 'SL-POS-' . mt_rand(1000, 9999),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => $total,
            'paid_amount' => 0,
            'due_amount' => $total,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
            'payment_method' => $this->cashMethod->name,
            'pos_session_id' => $this->posSession->id,
        ]);
    }
}
