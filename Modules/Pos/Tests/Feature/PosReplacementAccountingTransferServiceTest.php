<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Services\PosReplacementAccountingTransferService;
use Modules\Pos\Tests\Feature\Support\BuildsSyntheticTransaction10717Fixture;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\SalePayment;
use Modules\SalesReturn\Entities\SalePaymentCreditApplication;

class PosReplacementAccountingTransferServiceTest extends PosTransactionFeatureTestCase
{
    use BuildsSyntheticTransaction10717Fixture;

    protected PosReplacementAccountingTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PosReplacementAccountingTransferService::class);
    }

    /** @test */
    public function plans_fully_paid_cross_owner_replacement_from_pre_execution_evidence(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('pending_approval');

        $sourceSale = $fixture['source_sale'];
        $detailPrinter = $fixture['sale_return_detail_printer'];

        $preState = $this->service->capturePreExecutionSaleState($sourceSale);
        $plan = $this->service->planSourceSaleTransfers(
            $sourceSale,
            $preState,
            collect([$detailPrinter])
        );

        $this->assertSame(227000000, $plan['source_total_before_minor']);
        $this->assertSame(222500000, $plan['total_transferred_charge_minor']);
        $this->assertSame(4500000, $plan['source_total_after_minor']);
        $this->assertSame(222500000, $plan['total_transferred_paid_minor']);
        $this->assertSame(4500000, $plan['source_paid_after_minor']);
        $this->assertSame(0, $plan['source_due_after_minor']);

        $detailPlan = $plan['detail_plans'][$detailPrinter->id];
        $this->assertSame(222500000, $detailPlan['charge_minor']);
        $this->assertSame(222500000, $detailPlan['paid_minor']);
        $this->assertSame(0, $detailPlan['due_minor']);
        $this->assertCount(1, $detailPlan['payment_allocations']);
        $this->assertSame($fixture['source_payment']->id, $detailPlan['payment_allocations'][0]['original_sale_payment_id']);
        $this->assertSame(222500000, $detailPlan['payment_allocations'][0]['amount_minor']);
    }

    /** @test */
    public function plans_unpaid_cross_owner_replacement_moving_entire_charge_as_debt(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('pending_approval', [
            'source_paid_initial' => 0,
        ]);

        $sourceSale = $fixture['source_sale'];
        $detailPrinter = $fixture['sale_return_detail_printer'];

        $preState = $this->service->capturePreExecutionSaleState($sourceSale);
        $plan = $this->service->planSourceSaleTransfers(
            $sourceSale,
            $preState,
            collect([$detailPrinter])
        );

        $this->assertSame(227000000, $plan['source_total_before_minor']);
        $this->assertSame(222500000, $plan['total_transferred_charge_minor']);
        $this->assertSame(4500000, $plan['source_total_after_minor']);
        $this->assertSame(0, $plan['total_transferred_paid_minor']);
        $this->assertSame(0, $plan['source_paid_after_minor']);
        $this->assertSame(4500000, $plan['source_due_after_minor']);

        $detailPlan = $plan['detail_plans'][$detailPrinter->id];
        $this->assertSame(222500000, $detailPlan['charge_minor']);
        $this->assertSame(0, $detailPlan['paid_minor']);
        $this->assertSame(222500000, $detailPlan['due_minor']);
        $this->assertEmpty($detailPlan['payment_allocations']);
    }

    /** @test */
    public function plans_partially_paid_multi_method_source_with_deterministic_minor_unit_allocation(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('pending_approval', [
            'source_paid_initial' => 0,
        ]);

        $sourceSale = $fixture['source_sale'];
        // Total = Rp2,270,000. Partial paid = Rp1,135,000 (exactly 50%), split as Rp500,000 CASH + Rp635,000 TRANSFER
        $payCash = SalePayment::query()->create([
            'sale_id' => $sourceSale->id,
            'amount' => 500000.00,
            'date' => now()->toDateString(),
            'reference' => 'PAY-CASH-1',
            'payment_method' => 'CASH',
            'stage_order' => 1,
            'status' => SalePayment::STATUS_ACTIVE,
        ]);
        $payTransfer = SalePayment::query()->create([
            'sale_id' => $sourceSale->id,
            'amount' => 635000.00,
            'date' => now()->toDateString(),
            'reference' => 'PAY-TRF-2',
            'payment_method' => 'TRANSFER',
            'stage_order' => 2,
            'status' => SalePayment::STATUS_ACTIVE,
        ]);
        $sourceSale->update([
            'paid_amount' => 1135000.00,
            'due_amount' => 1135000.00,
            'payment_status' => 'Partial',
        ]);

        $detailPrinter = $fixture['sale_return_detail_printer'];
        $preState = $this->service->capturePreExecutionSaleState($sourceSale->fresh());
        $plan = $this->service->planSourceSaleTransfers(
            $sourceSale,
            $preState,
            collect([$detailPrinter])
        );

        // Printer is 2,225,000 (50% = 1,112,500), Paper is 45,000 (50% = 22,500)
        $this->assertSame(111250000, $plan['total_transferred_paid_minor']);
        $this->assertSame(2250000, $plan['source_paid_after_minor']);
        $this->assertSame(2250000, $plan['source_due_after_minor']);

        $detailPlan = $plan['detail_plans'][$detailPrinter->id];
        $this->assertSame(222500000, $detailPlan['charge_minor']);
        $this->assertSame(111250000, $detailPlan['paid_minor']);
        $this->assertSame(111250000, $detailPlan['due_minor']);

        // Method distribution: consumes Rp500,000 CASH first (stage_order 1), then Rp612,500 from TRANSFER (stage_order 2)
        $this->assertCount(2, $detailPlan['payment_allocations']);
        $this->assertSame($payCash->id, $detailPlan['payment_allocations'][0]['original_sale_payment_id']);
        $this->assertSame('CASH', $detailPlan['payment_allocations'][0]['payment_method']);
        $this->assertSame(50000000, $detailPlan['payment_allocations'][0]['amount_minor']);

        $this->assertSame($payTransfer->id, $detailPlan['payment_allocations'][1]['original_sale_payment_id']);
        $this->assertSame('TRANSFER', $detailPlan['payment_allocations'][1]['payment_method']);
        $this->assertSame(61250000, $detailPlan['payment_allocations'][1]['amount_minor']);
    }

    /** @test */
    public function preserves_header_discount_and_rounding_remainder_deterministically_in_minor_units(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('pending_approval');
        $sourceSale = $fixture['source_sale'];
        $detailPrinter = $fixture['sale_return_detail_printer'];

        // Apply a Rp70,000 header discount on sourceSale so net total becomes Rp2,200,000 (and paid Rp2,200,000)
        $sourceSale->update([
            'discount_amount' => 70000.00,
            'total_amount' => 2200000.00,
            'paid_amount' => 2200000.00,
            'due_amount' => 0.00,
        ]);
        $fixture['source_payment']->update(['amount' => 2200000.00]);

        $preState = $this->service->capturePreExecutionSaleState($sourceSale->fresh());
        $plan = $this->service->planSourceSaleTransfers(
            $sourceSale,
            $preState,
            collect([$detailPrinter])
        );

        // Gross rows: printer 222,500,000 minor + paper 4,500,000 minor = 227,000,000 minor.
        // Discount 7,000,000 minor allocated proportionally:
        // printer discount share = floor(7,000,000 * 222,500,000 / 227,000,000) = 6,861,233 minor (remainder 0.48)
        // paper discount share = floor(7,000,000 * 4,500,000 / 227,000,000) = 138,766 minor (remainder 0.52 -> gets +1 = 138,767)
        // Net printer charge = 222,500,000 - 6,861,233 = 215,638,767 minor
        // Net paper retained = 4,500,000 - 138,767 = 4,361,233 minor
        // Sum = 220,000,000 minor (exact conservation!)
        $this->assertSame(
            220000000,
            $plan['total_transferred_charge_minor'] + $plan['source_total_after_minor']
        );
        $this->assertSame(215638767, $plan['total_transferred_charge_minor']);
        $this->assertSame(4361233, $plan['source_total_after_minor']);
        $this->assertSame(215638767, $plan['total_transferred_paid_minor']);
        $this->assertSame(4361233, $plan['source_paid_after_minor']);
    }

    /** @test */
    public function blocks_unsupported_paid_header_without_payment_evidence_or_with_credit_applications(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('pending_approval');
        $sourceSale = $fixture['source_sale'];
        $detailPrinter = $fixture['sale_return_detail_printer'];

        // Case 1: Active payment has a SalePaymentCreditApplication attached
        $customerCredit = \Modules\SalesReturn\Entities\CustomerCredit::query()->create([
            'customer_id' => $fixture['customer_a']->id,
            'sale_return_id' => $fixture['sale_return']->id,
            'amount' => 50000.00,
            'remaining_amount' => 0.00,
            'status' => 'CLOSED',
        ]);

        SalePaymentCreditApplication::query()->create([
            'sale_payment_id' => $fixture['source_payment']->id,
            'customer_credit_id' => $customerCredit->id,
            'amount' => 50000.00,
        ]);

        $preStateWithCredit = $this->service->capturePreExecutionSaleState($sourceSale->fresh());

        try {
            $this->service->planSourceSaleTransfers($sourceSale, $preStateWithCredit, collect([$detailPrinter]));
            $this->fail('Should have blocked transfer when credit applications are present.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('kredit retur', $e->getMessage());
        }

        // Case 2: Source sale claims paid_amount > 0 but has no active SalePayments or incoming transfers
        SalePaymentCreditApplication::query()->where('sale_payment_id', $fixture['source_payment']->id)->delete();
        $fixture['source_payment']->update(['status' => SalePayment::STATUS_INVALIDATED]);

        $preStateNoPayments = $this->service->capturePreExecutionSaleState($sourceSale->fresh());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tanpa bukti pembayaran aktif');

        $this->service->planSourceSaleTransfers($sourceSale, $preStateNoPayments, collect([$detailPrinter]));
    }

    /** @test */
    public function owner_payment_transfers_conserve_collection_reports_owner_balances_and_receipt_method_breakdown_once(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('pending_approval');

        $sourceSale = $fixture['source_sale'];
        $unrelatedSale = $fixture['unrelated_sale'];
        $detailPrinter = $fixture['sale_return_detail_printer'];
        $settingA = $fixture['setting_a'];
        $settingB = $fixture['setting_b'];
        $settingUnrelated = $fixture['setting_unrelated'];

        // Simulate conserved cross-owner replacement state
        $sourceSale->update([
            'total_amount' => 45000.00,
            'paid_amount' => 45000.00,
            'due_amount' => 0.00,
            'payment_status' => 'Paid',
        ]);
        $fixture['detail_printer']->update([
            'quantity' => 0,
            'sub_total' => 0,
        ]);

        $replacementSale = \Modules\Sale\Entities\Sale::query()->create([
            'setting_id' => $settingB->id,
            'customer_id' => $fixture['customer_b']->id,
            'customer_name' => $fixture['customer_b']->customer_name,
            'reference' => 'SO-REPL-10717',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 2225000.00,
            'paid_amount' => 2225000.00,
            'due_amount' => 0.00,
            'status' => \Modules\Sale\Entities\Sale::STATUS_DISPATCHED,
            'payment_status' => 'Paid',
            'payment_method' => 'CASH',
            'date' => $sourceSale->date,
        ]);

        $transfer = \Modules\Pos\Entities\PosOwnerPaymentTransfer::query()->create([
            'pos_transaction_id' => $fixture['transaction']->id,
            'pos_checkout_id' => $fixture['checkout']->id,
            'pos_return_id' => $fixture['pos_return']->id,
            'pos_return_line_id' => $fixture['return_line_printer']->id,
            'sale_return_id' => $fixture['sale_return']->id,
            'sale_return_detail_id' => $detailPrinter->id,
            'source_sale_id' => $sourceSale->id,
            'replacement_sale_id' => $replacementSale->id,
            'original_sale_payment_id' => $fixture['source_payment']->id,
            'payment_method' => 'CASH',
            'amount' => 2225000.00,
            'amount_minor' => 222500000,
            'transfer_source' => \Modules\Pos\Entities\PosOwnerPaymentTransfer::SOURCE_LIVE_EXECUTION,
            'actor_id' => $fixture['cashier']->id,
            'executed_at' => now(),
        ]);

        $this->assertNotNull($transfer->id);

        // 1. Raw SalePayment collection count & sum across checkout group counts customer payment once (Rp2,600,000)
        $activePaymentsSum = (float) SalePayment::query()
            ->whereIn('sale_id', [$sourceSale->id, $replacementSale->id, $unrelatedSale->id])
            ->where('status', SalePayment::STATUS_ACTIVE)
            ->sum('amount');
        $this->assertEquals(2600000.00, $activePaymentsSum);

        // 2. Effective payment breakdown by method across all 3 sales totals Rp2,600,000 once
        $breakdown = $this->service->resolveEffectivePaymentBreakdownForSales([
            $sourceSale->fresh(),
            $replacementSale->fresh(),
            $unrelatedSale->fresh(),
        ]);
        $this->assertCount(1, $breakdown);
        $this->assertSame('CASH', $breakdown[0]['method_name']);
        $this->assertEquals(2600000.00, $breakdown[0]['amount']);

        // 3. Per-owner OperationalCashFlowReportService reflects allocated cash without double-counting
        $cashFlowService = app(\App\Services\Reports\OperationalCashFlowReportService::class);
        $dateStr = $sourceSale->date instanceof \Carbon\CarbonInterface
            ? $sourceSale->date->toDateString()
            : (string) $sourceSale->date;
        $cfFilter = new \App\Services\Reports\OperationalCashFlowReportFilterData(
            startDate: $dateStr,
            endDate: $dateStr
        );

        $cfOwnerA = $cashFlowService->generate($settingA->id, $cfFilter);
        $cfOwnerB = $cashFlowService->generate($settingB->id, $cfFilter);
        $cfAll = $cashFlowService->generate([$settingA->id, $settingB->id, $settingUnrelated->id], $cfFilter);

        $this->assertEquals(45000.00, $cfOwnerA->operatingActivities->rows[0]->amount);
        $this->assertEquals(2225000.00, $cfOwnerB->operatingActivities->rows[0]->amount);
        $this->assertEquals(2600000.00, $cfAll->operatingActivities->rows[0]->amount);

        // 4. SaleReportQueryService header query reflects derived_active_paid per owner
        $saleReportService = app(\App\Services\Reports\SaleReportQueryService::class);
        $srFilterB = new \App\Services\Reports\SaleReportFilterData(
            startDate: $dateStr,
            endDate: $dateStr,
            reportMode: 'header',
            scopeSettingId: $settingB->id
        );
        $replRow = $saleReportService->build($srFilterB)->where('sales.id', $replacementSale->id)->first();
        $this->assertNotNull($replRow);
        $this->assertEquals(2225000.00, (float) $replRow->derived_active_paid);
    }
}
