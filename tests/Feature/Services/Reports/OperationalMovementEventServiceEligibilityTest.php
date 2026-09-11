<?php

namespace Tests\Feature\Services\Reports;

use App\Services\Reports\OperationalMovementEventService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Tests\Feature\Services\Reports\Concerns\BuildsReportEligibilityFixtures;
use Tests\TestCase;

class OperationalMovementEventServiceEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsReportEligibilityFixtures;

    private Setting $setting;
    private Customer $customer;
    private Supplier $supplier;
    private OperationalMovementEventService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->makeSetting();
        $this->customer = $this->makeCustomer($this->setting);
        $this->supplier = $this->makeSupplier($this->setting);
        $this->service = new OperationalMovementEventService();

        session(['setting_id' => $this->setting->id]);
    }

    public function test_period_movements_include_dispatched_sale_and_received_purchase()
    {
        $sale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED, null, [
            'date' => Carbon::parse('2026-01-10'),
            'total_amount' => 1000,
        ]);
        $purchase = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED, null, [
            'date' => Carbon::parse('2026-01-10'),
            'total_amount' => 500,
        ]);

        $events = $this->service->getPeriodMovements($this->setting->id, '2026-01-01', '2026-01-31');

        $references = array_column($events, 'reference');

        $this->assertContains($sale->reference, $references);
        $this->assertContains($purchase->reference, $references);
    }

    public function test_period_movements_exclude_partial_fulfillment_sale_and_purchase()
    {
        $sale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED_PARTIALLY, null, [
            'date' => Carbon::parse('2026-01-10'),
            'total_amount' => 1000,
        ]);
        $purchase = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED_PARTIALLY, null, [
            'date' => Carbon::parse('2026-01-10'),
            'total_amount' => 500,
        ]);

        $events = $this->service->getPeriodMovements($this->setting->id, '2026-01-01', '2026-01-31');

        $references = array_column($events, 'reference');

        $this->assertNotContains($sale->reference, $references);
        $this->assertNotContains($purchase->reference, $references);
    }

    public function test_period_movements_exclude_archived_completed_full_return()
    {
        $sale = $this->makeSale($this->setting, $this->customer, Sale::STATUS_RETURNED, Carbon::parse('2026-01-20'), [
            'date' => Carbon::parse('2026-01-10'),
            'total_amount' => 1000,
        ]);
        $purchase = $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RETURNED, Carbon::parse('2026-01-20'), [
            'date' => Carbon::parse('2026-01-10'),
            'total_amount' => 500,
        ]);

        $events = $this->service->getPeriodMovements($this->setting->id, '2026-01-01', '2026-01-31');

        $references = array_column($events, 'reference');

        $this->assertNotContains($sale->reference, $references);
        $this->assertNotContains($purchase->reference, $references);
    }

    public function test_opening_balances_include_dispatched_sale_and_received_purchase_before_start()
    {
        $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED, null, [
            'date' => Carbon::parse('2025-12-10'),
            'total_amount' => 1000,
            'due_amount' => 1000,
        ]);
        $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED, null, [
            'date' => Carbon::parse('2025-12-10'),
            'total_amount' => 500,
            'due_amount' => 500,
        ]);

        $balances = $this->service->getOpeningBalances($this->setting->id, '2026-01-01');

        $this->assertArrayHasKey(\App\Services\Reports\OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE, $balances);
        $this->assertEquals(1000.0, $balances[\App\Services\Reports\OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE]['debit']);

        $this->assertArrayHasKey(\App\Services\Reports\OperationalGeneralLedgerBucketConfig::INVENTORY, $balances);
    }

    public function test_opening_balances_exclude_partial_fulfillment_sale_and_purchase_before_start()
    {
        $this->makeSale($this->setting, $this->customer, Sale::STATUS_DISPATCHED_PARTIALLY, null, [
            'date' => Carbon::parse('2025-12-10'),
            'total_amount' => 1000,
            'due_amount' => 1000,
        ]);
        $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RECEIVED_PARTIALLY, null, [
            'date' => Carbon::parse('2025-12-10'),
            'total_amount' => 500,
            'due_amount' => 500,
        ]);

        $balances = $this->service->getOpeningBalances($this->setting->id, '2026-01-01');

        $this->assertArrayNotHasKey(\App\Services\Reports\OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE, $balances);
        $this->assertArrayNotHasKey(\App\Services\Reports\OperationalGeneralLedgerBucketConfig::ACCOUNTS_PAYABLE, $balances);
    }

    public function test_opening_balances_exclude_archived_completed_full_return_before_start()
    {
        $this->makeSale($this->setting, $this->customer, Sale::STATUS_RETURNED, Carbon::parse('2025-12-15'), [
            'date' => Carbon::parse('2025-12-10'),
            'total_amount' => 1000,
            'due_amount' => 1000,
        ]);
        $this->makePurchase($this->setting, $this->supplier, Purchase::STATUS_RETURNED, Carbon::parse('2025-12-15'), [
            'date' => Carbon::parse('2025-12-10'),
            'total_amount' => 500,
            'due_amount' => 500,
        ]);

        $balances = $this->service->getOpeningBalances($this->setting->id, '2026-01-01');

        $this->assertArrayNotHasKey(\App\Services\Reports\OperationalGeneralLedgerBucketConfig::ACCOUNTS_RECEIVABLE, $balances);
        $this->assertArrayNotHasKey(\App\Services\Reports\OperationalGeneralLedgerBucketConfig::ACCOUNTS_PAYABLE, $balances);
    }
}
