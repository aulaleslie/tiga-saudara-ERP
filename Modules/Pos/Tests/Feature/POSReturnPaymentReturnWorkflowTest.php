<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Spatie\Permission\Models\Permission;

class POSReturnPaymentReturnWorkflowTest extends PosTransactionFeatureTestCase
{
    protected PosReturnLifecycleService $service;

    protected $setting;

    protected $location;

    protected $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PosReturnLifecycleService::class);
        $this->setting = $this->createSetting('POS Return Payment Workflow Test');
        [, $this->location] = $this->createTerminalWithLocation($this->setting);

        Permission::findOrCreate('pos.access', 'web');

        $this->actor = $this->createUserForSetting($this->setting, 'POS Return Settlement Actor', [
            'pos.access',
        ]);
    }

    /** @test */
    public function it_can_settle_a_cash_return_up_to_the_remaining_cap()
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $saleReturn] = $this->createAwaitingSettlementReturn();

        $this->service->settlePaymentReturn($posReturn->id);

        $posReturn->refresh();
        $saleReturn->refresh();
        $payment = SaleReturnPayment::query()->where('sale_return_id', $saleReturn->id)->first();

        $this->assertEquals(PosReturn::STATUS_COMPLETED, $posReturn->status);
        $this->assertNotNull($payment);
        $this->assertSame(600.0, (float) $payment->amount);
        $this->assertSame(600.0, (float) $saleReturn->paid_amount);
        $this->assertSame(0.0, (float) $saleReturn->due_amount);
    }

    /** @test */
    public function it_blocks_cash_return_settlement_when_the_refund_cap_is_exhausted()
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $saleReturn] = $this->createAwaitingSettlementReturn([
            'paid_amount' => 600,
            'due_amount' => 0,
        ]);

        SaleReturnPayment::query()->create([
            'sale_return_id' => $saleReturn->id,
            'amount' => 600,
            'date' => now()->toDateString(),
            'reference' => 'SRPAY-EXISTING-' . uniqid(),
            'payment_method' => 'CASH',
            'note' => 'Existing refund',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tidak ada sisa nominal pengembalian tunai yang dapat diproses.');

        $this->service->settlePaymentReturn($posReturn->id);
    }

    /** @test */
    public function it_blocks_cash_settlement_for_product_replacement_returns()
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn] = $this->createAwaitingSettlementReturn([
            'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Hanya retur dengan opsi kembali uang yang dapat diproses sebagai pengembalian tunai.');

        $this->service->settlePaymentReturn($posReturn->id);
    }

    /**
     * @return array{0: PosReturn, 1: SaleReturn}
     */
    protected function createAwaitingSettlementReturn(array $overrides = []): array
    {
        $sale = Sale::query()->create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SO-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 600,
            'paid_amount' => 600,
            'due_amount' => 0,
            'status' => 'COMPLETED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        $posReturn = PosReturn::query()->create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-' . uniqid(),
            'receipt_number' => 'RCP-' . uniqid(),
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'reference' => 'PR-' . uniqid(),
            'return_option' => $overrides['return_option'] ?? PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_AWAITING_SETTLEMENT,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 600,
            'received_by' => $this->actor->id,
            'received_at' => now(),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);

        $saleReturn = SaleReturn::query()->create([
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'pos_return_id' => $posReturn->id,
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'return_type' => 'Cash Return',
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SR-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 600,
            'paid_amount' => $overrides['paid_amount'] ?? 0,
            'due_amount' => $overrides['due_amount'] ?? 600,
            'status' => 'AWAITING SETTLEMENT',
            'approval_status' => 'APPROVED',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
            'received_by' => $this->actor->id,
            'received_at' => now(),
        ]);

        return [$posReturn, $saleReturn];
    }
}