<?php

namespace Modules\Pos\Tests\Feature;

use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Exceptions\PosReturnManualCorrectionRequiredException;
use Modules\Pos\Services\PosReturnLifecycleService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Spatie\Permission\Models\Permission;

class POSReturnManualCorrectionWorkflowTest extends PosTransactionFeatureTestCase
{
    protected $setting;

    protected $location;

    protected $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->createSetting('POS Return Manual Correction Test');
        [, $this->location] = $this->createTerminalWithLocation($this->setting);

        Permission::findOrCreate('pos.access', 'web');

        $this->actor = $this->createUserForSetting($this->setting, 'POS Return Manual Correction Actor', [
            'pos.access',
        ]);
    }

    /** @test */
    public function it_marks_the_return_for_manual_correction_when_a_non_rollbackable_external_effect_occurs(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        [$posReturn] = $this->createPendingApprovalReturn();

        $service = new class extends PosReturnLifecycleService {
            protected function syncApprovedSaleReturns(\Modules\Pos\Entities\PosReturn $posReturn, ?int $actorId, \Illuminate\Support\Carbon $approvedAt): void
            {
                parent::syncApprovedSaleReturns($posReturn, $actorId, $approvedAt);

                throw PosReturnManualCorrectionRequiredException::forAction(
                    'approve',
                    'Persetujuan eksternal sudah terlanjur diproses dan harus dikoreksi manual.'
                );
            }
        };

        try {
            $service->approve($posReturn->id);
            $this->fail('Expected manual correction failure was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('memerlukan koreksi manual teraudit', $exception->getMessage());
        }

        $posReturn->refresh();

        $this->assertSame(PosReturn::STATUS_MANUAL_CORRECTION_REQUIRED, $posReturn->status);
        $this->assertSame('approve', $posReturn->manual_correction_action);
        $this->assertSame('Persetujuan eksternal sudah terlanjur diproses dan harus dikoreksi manual.', $posReturn->manual_correction_reason);
        $this->assertSame($this->actor->id, $posReturn->manual_correction_required_by);
        $this->assertNotNull($posReturn->manual_correction_required_at);
    }

    /** @test */
    public function it_blocks_further_lifecycle_progress_until_manual_correction_is_cleared(): void
    {
        $this->actingAsInSetting($this->actor, $this->setting);

        $service = app(PosReturnLifecycleService::class);

        $cases = [
            [$this->createManualCorrectionBlockedReturn(['approval_status' => PosReturn::APPROVAL_STATUS_PENDING]), 'approve'],
            [$this->createManualCorrectionBlockedReturn(['approval_status' => PosReturn::APPROVAL_STATUS_PENDING]), 'reject'],
            [$this->createManualCorrectionBlockedReturn(['approval_status' => PosReturn::APPROVAL_STATUS_APPROVED]), 'receive'],
            [$this->createManualCorrectionBlockedReturn([
                'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
                'return_option' => PosReturn::OPTION_CASH_RETURN,
            ]), 'settlePaymentReturn'],
            [$this->createManualCorrectionBlockedReturn([
                'approval_status' => PosReturn::APPROVAL_STATUS_APPROVED,
                'return_option' => PosReturn::OPTION_PRODUCT_REPLACEMENT,
            ]), 'dispatchReplacement'],
            [$this->createManualCorrectionBlockedReturn(['approval_status' => PosReturn::APPROVAL_STATUS_APPROVED]), 'archive'],
            [$this->createManualCorrectionBlockedReturn(['approval_status' => PosReturn::APPROVAL_STATUS_APPROVED]), 'cancel'],
        ];

        foreach ($cases as [$posReturn, $method]) {
            try {
                if (in_array($method, ['reject', 'archive', 'cancel'], true)) {
                    $service->{$method}($posReturn->id, 'Should be blocked');
                } else {
                    $service->{$method}($posReturn->id);
                }

                $this->fail("Expected manual correction block for {$method} was not thrown.");
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Retur POS ini sedang diblokir dan memerlukan koreksi manual teraudit sebelum aksi lifecycle lain dijalankan.',
                    $exception->getMessage()
                );
            }
        }
    }

    /**
     * @return array{0: PosReturn, 1: SaleReturn}
     */
    protected function createPendingApprovalReturn(): array
    {
        $sale = $this->createSale();
        $posReturn = PosReturn::query()->create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-' . uniqid(),
            'receipt_number' => 'RCP-' . uniqid(),
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'reference' => 'PR-' . uniqid(),
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_PENDING_APPROVAL,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => 500,
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
            'total_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'status' => 'PENDING APPROVAL',
            'approval_status' => 'PENDING',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        return [$posReturn, $saleReturn];
    }

    protected function createManualCorrectionBlockedReturn(array $overrides = []): PosReturn
    {
        return PosReturn::query()->create(array_merge([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-' . uniqid(),
            'receipt_number' => 'RCP-' . uniqid(),
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . uniqid(),
            'reference' => 'PR-' . uniqid(),
            'return_option' => PosReturn::OPTION_CASH_RETURN,
            'status' => PosReturn::STATUS_MANUAL_CORRECTION_REQUIRED,
            'approval_status' => PosReturn::APPROVAL_STATUS_PENDING,
            'total_amount' => 500,
            'manual_correction_action' => 'receive',
            'manual_correction_reason' => 'External refund already applied.',
            'manual_correction_required_by' => $this->actor->id,
            'manual_correction_required_at' => now(),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ], $overrides));
    }

    protected function createSale(): Sale
    {
        return Sale::query()->create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Customer',
            'reference' => 'SO-' . uniqid(),
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 500,
            'due_amount' => 0,
            'status' => 'COMPLETED',
            'payment_status' => 'PAID',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);
    }
}