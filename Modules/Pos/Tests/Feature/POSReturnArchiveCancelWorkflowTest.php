<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Spatie\Permission\Models\Permission;

class POSReturnArchiveCancelWorkflowTest extends PosTransactionFeatureTestCase
{
    protected PosReturnLifecycleService $service;

    protected $setting;

    protected $location;

    protected $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PosReturnLifecycleService::class);
        $this->setting = $this->createSetting('POS Return Archive Cancel Test');
        [, $this->location] = $this->createTerminalWithLocation($this->setting);

        Permission::findOrCreate('pos.access', 'web');

        $this->actor = $this->createUserForSetting($this->setting, 'POS Return Archive Actor', [
            'pos.access',
        ]);
    }

    /** @test */
    public function it_can_archive_an_approved_return_before_receiving()
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $saleReturn] = $this->createApprovedReturn();

        $this->service->archive($posReturn->id, 'Dokumen duplikat');

        $posReturn->refresh();
        $saleReturn->refresh();

        $this->assertEquals(PosReturn::STATUS_ARCHIVED, $posReturn->status);
        $this->assertTrue($posReturn->is_reversed);
        $this->assertEquals($this->actor->id, $posReturn->archived_by);
        $this->assertEquals('Dokumen duplikat', $posReturn->archive_reason);
        $this->assertNotNull($posReturn->archived_at);
        $this->assertNotNull($saleReturn->archived_at);
    }

    /** @test */
    public function it_can_cancel_an_approved_return_before_receiving()
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn, $saleReturn] = $this->createApprovedReturn();

        $this->service->cancel($posReturn->id, 'Pelanggan membatalkan retur');

        $posReturn->refresh();
        $saleReturn->refresh();

        $this->assertEquals(PosReturn::STATUS_CANCELLED, $posReturn->status);
        $this->assertTrue($posReturn->is_reversed);
        $this->assertEquals($this->actor->id, $posReturn->cancelled_by);
        $this->assertEquals('Pelanggan membatalkan retur', $posReturn->cancel_reason);
        $this->assertNotNull($posReturn->cancelled_at);
        $this->assertSame('CANCELLED', $saleReturn->status);
    }

    /** @test */
    public function it_blocks_archive_after_receiving_has_started()
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn] = $this->createApprovedReturn([
            'status' => PosReturn::STATUS_AWAITING_SETTLEMENT,
            'received_at' => now(),
            'received_by' => $this->actor->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Retur POS yang sudah diterima, diselesaikan, atau dikirim tidak dapat diarsipkan.');

        $this->service->archive($posReturn->id, 'Should fail');
    }

    /** @test */
    public function it_blocks_cancel_after_completion()
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn] = $this->createApprovedReturn([
            'status' => PosReturn::STATUS_COMPLETED,
            'received_at' => now(),
            'received_by' => $this->actor->id,
            'settled_at' => now(),
            'settled_by' => $this->actor->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Retur POS yang sudah diterima, diselesaikan, atau dikirim tidak dapat dibatalkan.');

        $this->service->cancel($posReturn->id, 'Should fail');
    }

    /**
     * @return array{0: PosReturn, 1: SaleReturn}
     */
    protected function createApprovedReturn(array $overrides = []): array
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
            'status' => 'DISPATCHED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        $posReturn = PosReturn::query()->create(array_merge([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-' . uniqid(),
            'receipt_number' => 'RCP-' . uniqid(),
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'reference' => 'PR-' . uniqid(),
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_APPROVED,
            'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
            'total_amount' => 600,
            'approved_by' => $this->actor->id,
            'approved_at' => now(),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ], $overrides));

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
            'paid_amount' => 0,
            'due_amount' => 600,
            'status' => 'AWAITING RECEIVING',
            'approval_status' => 'APPROVED',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
            'approved_by' => $this->actor->id,
            'approved_at' => now(),
        ]);

        return [$posReturn, $saleReturn];
    }
}