<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosOwnerPaymentTransfer;
use Modules\Pos\Entities\PosReplacementAccountingRepairAudit;
use Modules\Pos\Services\PosReplacementAccountingRepairService;
use Modules\Pos\Tests\Feature\Support\BuildsSyntheticTransaction10717Fixture;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\SalesReturn\Entities\SaleReturn;

/** Integration checkpoint: unsupported standard/mixed histories block replacement repair (preview and apply). */
class PosReplacementRepairStandardHistoryBlockTest extends PosTransactionFeatureTestCase
{
    use BuildsSyntheticTransaction10717Fixture;

    private function evidenceSnapshot(): array
    {
        return [
            json_decode(json_encode(DB::table('sales')->orderBy('id')->get()), true),
            json_decode(json_encode(DB::table('sale_payments')->orderBy('id')->get()), true),
            DB::table('pos_owner_payment_transfers')->count(),
            DB::table('pos_replacement_accounting_repair_audits')->count(),
        ];
    }

    private function assertBlockedAndUntouched(array $fixture, string $needle): void
    {
        $service = app(PosReplacementAccountingRepairService::class);
        $settingId = $fixture['setting_a']->id;
        $before = $this->evidenceSnapshot();

        $plan = $service->plan($fixture['transaction']->id, $settingId);
        $this->assertSame(PosReplacementAccountingRepairService::CLASSIFICATION_BLOCKED, $plan['classification']);
        $this->assertStringContainsString($needle, $plan['reason']);

        try {
            $service->apply($fixture['transaction']->id, $settingId, $fixture['cashier']->id, 'attempt', str_repeat('a', 64));
            $this->fail('Apply must refuse an unsupported standard/mixed history.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Repair blocked', $e->getMessage());
        }

        $this->assertSame($before, $this->evidenceSnapshot());
        $this->assertSame(0, PosOwnerPaymentTransfer::query()->count());
        $this->assertSame(0, PosReplacementAccountingRepairAudit::query()->count());
    }

    /** @test */
    public function repair_refuses_when_a_reachable_sale_carries_a_standard_return(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('legacy_stale_duplicated');
        $service = app(PosReplacementAccountingRepairService::class);
        $this->assertSame(
            PosReplacementAccountingRepairService::CLASSIFICATION_REPAIRABLE,
            $service->plan($fixture['transaction']->id, $fixture['setting_a']->id)['classification']
        );

        DB::statement('PRAGMA defer_foreign_keys = ON');
        $template = $fixture['sale_return']->toArray();
        unset($template['id']);
        SaleReturn::query()->create(array_merge($template, [
            'sale_id' => $fixture['source_sale']->id,
            'pos_return_id' => null,
            'reference' => 'SR-STD-' . uniqid(),
        ]));

        $this->assertBlockedAndUntouched($fixture, 'tidak didukung');
    }

    /** @test */
    public function repair_refuses_when_a_reachable_replacement_sale_has_standard_return_adjustments(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('legacy_stale_duplicated');

        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('sale_return_adjustments')->insert([
            'sale_return_item_settlement_id' => 1, 'sale_return_id' => 1, 'sale_id' => $fixture['replacement_sale']->id,
            'sale_return_detail_id' => 1, 'quantity' => 1, 'committed_value' => 10, 'debt_reduction' => 10,
            'cash_refund' => 0, 'source_fingerprint' => str_repeat('a', 64), 'policy_version' => 1,
            'committed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertBlockedAndUntouched($fixture, 'tidak didukung');
    }

    /** @test */
    public function standard_history_appearing_between_valid_preview_and_locked_reconcile_is_refused_under_lock(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('legacy_stale_duplicated');
        $settingId = $fixture['setting_a']->id;
        $sourceSaleId = $fixture['source_sale']->id;
        $template = $fixture['sale_return']->toArray();
        unset($template['id']);

        $plain = app(PosReplacementAccountingRepairService::class);
        $preview = $plain->plan($fixture['transaction']->id, $settingId);
        $this->assertSame(PosReplacementAccountingRepairService::CLASSIFICATION_REPAIRABLE, $preview['classification']);
        $fingerprint = $preview['evidence_fingerprint'];

        // Calls: 1 = apply()'s initial preview (valid), 2 = locked reconcile (history injected just before it).
        $service = new class(new \Modules\Pos\Services\PosReachableSalesResolver(), new \Modules\Pos\Services\PosReplacementAccountingTransferService(), new \Modules\Pos\Services\PosSettlementProjectionService(), new \Modules\Pos\Services\PosReceiptReprintProjectionService()) extends PosReplacementAccountingRepairService {
            public int $calls = 0;
            public \Closure $inject;

            public function reconcile(\Modules\Pos\Entities\PosTransaction $transaction, int $settingId): array
            {
                if (++$this->calls === 2) {
                    ($this->inject)();
                }

                return parent::reconcile($transaction, $settingId);
            }
        };
        $service->inject = function () use ($template, $sourceSaleId) {
            DB::statement('PRAGMA defer_foreign_keys = ON');
            SaleReturn::query()->create(array_merge($template, [
                'sale_id' => $sourceSaleId, 'pos_return_id' => null, 'reference' => 'SR-LATE-' . uniqid(),
            ]));
        };

        $before = $this->evidenceSnapshot();
        try {
            $service->apply($fixture['transaction']->id, $settingId, $fixture['cashier']->id, 'race', $fingerprint);
            $this->fail('Locked revalidation must refuse the late standard history.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('under lock', $e->getMessage());
        }

        $this->assertSame(2, $service->calls);
        $this->assertSame($before, $this->evidenceSnapshot());
        $this->assertSame(0, PosOwnerPaymentTransfer::query()->count());
        $this->assertSame(0, PosReplacementAccountingRepairAudit::query()->count());
    }
}
