<?php

namespace Tests\Unit\Services\Reports;

use App\Services\Reports\Concerns\FulfilledTransactionEligibility;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class FulfilledTransactionEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Customer $customer;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting = Setting::factory()->create();
        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        $this->supplier = Supplier::factory()->create(['setting_id' => $this->setting->id]);
    }

    private function makeSale(string $status, ?Carbon $archivedAt = null): Sale
    {
        static $counter = 0;
        $counter++;
        $date = Carbon::parse('2026-01-15');

        return Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'reference' => "SALE-{$counter}",
            'customer_name' => 'Test Customer',
            'date' => $date,
            'due_date' => $date->copy()->addDays(30),
            'status' => $status,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'archived_at' => $archivedAt,
        ]);
    }

    private function makePurchase(string $status, ?Carbon $archivedAt = null): Purchase
    {
        static $counter = 0;
        $counter++;
        $date = Carbon::parse('2026-01-15');

        return Purchase::create([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'reference' => "PURCHASE-{$counter}",
            'supplier_name' => 'Test Supplier',
            'date' => $date,
            'due_date' => $date->copy()->addDays(30),
            'status' => $status,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'archived_at' => $archivedAt,
        ]);
    }

    private function isSaleEligible(Sale $sale): bool
    {
        return DB::table('sales')
            ->where('id', $sale->id)
            ->whereRaw(FulfilledTransactionEligibility::saleSqlExpression('sales'))
            ->exists();
    }

    private function isPurchaseEligible(Purchase $purchase): bool
    {
        return DB::table('purchases')
            ->where('id', $purchase->id)
            ->whereRaw(FulfilledTransactionEligibility::purchaseSqlExpression('purchases'))
            ->exists();
    }

    public function test_dispatched_sale_is_eligible()
    {
        $sale = $this->makeSale(Sale::STATUS_DISPATCHED);

        $this->assertTrue($this->isSaleEligible($sale));
    }

    public function test_partially_dispatched_sale_is_not_eligible()
    {
        $sale = $this->makeSale(Sale::STATUS_DISPATCHED_PARTIALLY);

        $this->assertFalse($this->isSaleEligible($sale));
    }

    public function test_partially_returned_sale_is_eligible()
    {
        $sale = $this->makeSale(Sale::STATUS_RETURNED_PARTIALLY);

        $this->assertTrue($this->isSaleEligible($sale));
    }

    public function test_unfinished_full_return_sale_remains_eligible()
    {
        $sale = $this->makeSale(Sale::STATUS_RETURNED);

        $this->assertTrue($this->isSaleEligible($sale));
    }

    public function test_completed_full_return_sale_is_excluded()
    {
        $sale = $this->makeSale(Sale::STATUS_RETURNED, Carbon::parse('2026-02-01'));

        $this->assertFalse($this->isSaleEligible($sale));
    }

    public function test_predispatch_sale_statuses_are_not_eligible()
    {
        foreach ([Sale::STATUS_DRAFTED, Sale::STATUS_WAITING_APPROVAL, Sale::STATUS_APPROVED, Sale::STATUS_REJECTED] as $status) {
            $sale = $this->makeSale($status);

            $this->assertFalse($this->isSaleEligible($sale), "Expected status {$status} to be ineligible");
        }
    }

    public function test_received_purchase_is_eligible()
    {
        $purchase = $this->makePurchase(Purchase::STATUS_RECEIVED);

        $this->assertTrue($this->isPurchaseEligible($purchase));
    }

    public function test_partially_received_purchase_is_not_eligible()
    {
        $purchase = $this->makePurchase(Purchase::STATUS_RECEIVED_PARTIALLY);

        $this->assertFalse($this->isPurchaseEligible($purchase));
    }

    public function test_partially_returned_purchase_is_eligible()
    {
        $purchase = $this->makePurchase(Purchase::STATUS_RETURNED_PARTIALLY);

        $this->assertTrue($this->isPurchaseEligible($purchase));
    }

    public function test_unfinished_full_return_purchase_remains_eligible()
    {
        $purchase = $this->makePurchase(Purchase::STATUS_RETURNED);

        $this->assertTrue($this->isPurchaseEligible($purchase));
    }

    public function test_completed_full_return_purchase_is_excluded()
    {
        $purchase = $this->makePurchase(Purchase::STATUS_RETURNED, Carbon::parse('2026-02-01'));

        $this->assertFalse($this->isPurchaseEligible($purchase));
    }

    public function test_prereceipt_purchase_statuses_are_not_eligible()
    {
        foreach ([Purchase::STATUS_DRAFTED, Purchase::STATUS_WAITING_APPROVAL, Purchase::STATUS_APPROVED, Purchase::STATUS_REJECTED] as $status) {
            $purchase = $this->makePurchase($status);

            $this->assertFalse($this->isPurchaseEligible($purchase), "Expected status {$status} to be ineligible");
        }
    }
}
