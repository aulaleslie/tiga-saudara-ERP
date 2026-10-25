<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\People\Entities\Customer;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturnBalanceRepairAudit;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Services\PosReceiptReprintProjectionService;
use Modules\Pos\Services\PosReturnBalanceRepairService;
use Modules\Pos\Services\PosSettlementProjectionService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;

class PosReturnBalanceRepairApplyTest extends PosTransactionFeatureTestCase
{
    protected $setting;
    protected $location;
    protected $terminal;
    protected $session;
    protected $customer;
    protected $user;
    protected User $actor;
    protected PosReturnBalanceRepairService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PosReturnBalanceRepairService::class);
        $this->setting = $this->createSetting('Apply Test Store');

        $this->user = $this->createUserForSetting($this->setting, 'Cashier User', ['pos.access']);
        $this->actor = $this->createUserForSetting($this->setting, 'Supervisor Actor', ['pos.access']);
        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        [$this->terminal, $this->location] = $this->createTerminalWithLocation($this->setting);
        $this->session = $this->openSession($this->setting, $this->terminal, $this->user);
    }

    public function test_apply_requires_valid_actor(): void
    {
        $fixture = $this->createDriftFixture();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Valid actor ID is strictly required');

        $this->service->apply(
            $fixture['transaction']->id,
            $this->setting->id,
            actorId: null,
            reason: 'Valid audit reason'
        );
    }

    public function test_apply_requires_non_empty_reason(): void
    {
        $fixture = $this->createDriftFixture();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A non-empty audit reason is strictly required');

        $this->service->apply(
            $fixture['transaction']->id,
            $this->setting->id,
            actorId: $this->actor->id,
            reason: '   '
        );
    }

    public function test_apply_fails_and_rolls_back_when_evidence_fingerprint_changes(): void
    {
        $fixture = $this->createDriftFixture();

        $plan = $this->service->plan($fixture['transaction']->id, $this->setting->id);
        $staleFingerprint = 'stale_hash_' . str_repeat('0', 53);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Evidence fingerprint mismatch');

        $this->service->apply(
            $fixture['transaction']->id,
            $this->setting->id,
            actorId: $this->actor->id,
            reason: 'Testing stale fingerprint',
            expectedFingerprint: $staleFingerprint
        );

        // Verify zero changes
        $this->assertEquals(804999.60, (float) $fixture['sale']->fresh()->total_amount);
        $this->assertSame(0, PosReturnBalanceRepairAudit::count());
    }

    public function test_apply_rejects_when_child_records_change_without_parent_timestamp_update(): void
    {
        $fixture = $this->createDriftFixture();

        $plan = $this->service->plan($fixture['transaction']->id, $this->setting->id);
        $originalFingerprint = $plan['evidence_fingerprint'];

        // Parent timestamps before child mutation
        $origCheckoutUpdated = (string) $fixture['checkout']->fresh()->updated_at;
        $origTrxUpdated = (string) $fixture['transaction']->fresh()->updated_at;

        // Directly mutate child record (pos_checkout_sales) via DB facade without triggering parent touch/updated_at
        \Illuminate\Support\Facades\DB::table('pos_checkout_sales')
            ->where('pos_checkout_id', $fixture['checkout']->id)
            ->update(['grand_total' => 805500]);

        // Assert parent timestamps remain completely unchanged
        $this->assertSame($origCheckoutUpdated, (string) $fixture['checkout']->fresh()->updated_at);
        $this->assertSame($origTrxUpdated, (string) $fixture['transaction']->fresh()->updated_at);

        // Plan recalculated fresh must produce a different fingerprint!
        $freshPlan = $this->service->plan($fixture['transaction']->id, $this->setting->id);
        $this->assertNotSame($originalFingerprint, $freshPlan['evidence_fingerprint']);

        // Attempting to apply with original expected fingerprint must be rejected
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Evidence fingerprint mismatch');

        $this->service->apply(
            $fixture['transaction']->id,
            $this->setting->id,
            actorId: $this->actor->id,
            reason: 'Testing child mutation without parent timestamp update',
            expectedFingerprint: $originalFingerprint
        );
    }

    public function test_fingerprint_changes_when_child_transaction_line_changes(): void
    {
        $fixture = $this->createDriftFixture();

        $plan1 = $this->service->plan($fixture['transaction']->id, $this->setting->id);

        // Directly mutate a child transaction line without touching transaction updated_at
        \Illuminate\Support\Facades\DB::table('pos_transaction_lines')
            ->where('pos_transaction_id', $fixture['transaction']->id)
            ->where('line_no', 1)
            ->update(['unit_price' => 20000]);

        $plan2 = $this->service->plan($fixture['transaction']->id, $this->setting->id);
        $this->assertNotSame($plan1['evidence_fingerprint'], $plan2['evidence_fingerprint']);
    }

    public function test_apply_preserves_paid_amount_while_updating_header_due_and_status(): void
    {
        $fixture = $this->createDriftFixture();
        $plan = $this->service->plan($fixture['transaction']->id, $this->setting->id);

        $result = $this->service->apply(
            $fixture['transaction']->id,
            $this->setting->id,
            actorId: $this->actor->id,
            reason: 'Correcting Rp0.40 header drift',
            expectedFingerprint: $plan['evidence_fingerprint']
        );

        $this->assertSame(PosReturnBalanceRepairService::CLASSIFICATION_REPAIRED, $result['classification']);

        $refreshedSale = $fixture['sale']->fresh();
        $this->assertEquals(805000.00, (float) $refreshedSale->total_amount);
        $this->assertEquals(375000.00, (float) $refreshedSale->paid_amount); // Preserved exactly!
        $this->assertEquals(430000.00, (float) $refreshedSale->due_amount);
        $this->assertSame('PARTIAL', $refreshedSale->payment_status);
    }

    public function test_apply_persists_structured_audit_record_with_before_after_values_and_actor(): void
    {
        $fixture = $this->createDriftFixture();
        $plan = $this->service->plan($fixture['transaction']->id, $this->setting->id);

        $this->service->apply(
            $fixture['transaction']->id,
            $this->setting->id,
            actorId: $this->actor->id,
            reason: 'Audit persistence verification',
            expectedFingerprint: $plan['evidence_fingerprint']
        );

        $this->assertSame(1, PosReturnBalanceRepairAudit::count());
        /** @var PosReturnBalanceRepairAudit $audit */
        $audit = PosReturnBalanceRepairAudit::first();

        $this->assertSame($fixture['transaction']->id, (int) $audit->pos_transaction_id);
        $this->assertSame($fixture['checkout']->id, (int) $audit->pos_checkout_id);
        $this->assertSame($fixture['sale']->id, (int) $audit->sale_id);
        $this->assertSame($this->actor->id, (int) $audit->actor_id);
        $this->assertSame('Audit persistence verification', $audit->reason);
        $this->assertSame($plan['evidence_fingerprint'], $audit->evidence_fingerprint);

        $this->assertEquals(804999.60, (float) $audit->before_values['total_amount']);
        $this->assertEquals(429999.60, (float) $audit->before_values['due_amount']);
        $this->assertEquals(805000.00, (float) $audit->after_values['total_amount']);
        $this->assertEquals(430000.00, (float) $audit->after_values['due_amount']);
    }

    public function test_audit_rolls_back_atomically_if_transaction_fails(): void
    {
        $fixture = $this->createDriftFixture();

        // Bind mock settlement service that fails projection
        $mockSettlement = $this->createMock(PosSettlementProjectionService::class);
        $mockSettlement->method('project')
            ->willThrowException(new \RuntimeException('Simulated projection failure during apply'));

        $serviceWithFailingProjection = new PosReturnBalanceRepairService(
            settlementProjectionService: $mockSettlement
        );

        try {
            $serviceWithFailingProjection->apply(
                $fixture['transaction']->id,
                $this->setting->id,
                actorId: $this->actor->id,
                reason: 'Will fail on projection'
            );
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Simulated projection failure', $e->getMessage());
        }

        // Database records must be rolled back
        $this->assertEquals(804999.60, (float) $fixture['sale']->fresh()->total_amount);
        $this->assertSame(0, PosReturnBalanceRepairAudit::count());
    }

    public function test_idempotent_rerun_is_no_op_without_duplicate_audit(): void
    {
        $fixture = $this->createDriftFixture();

        // First apply
        $result1 = $this->service->apply(
            $fixture['transaction']->id,
            $this->setting->id,
            actorId: $this->actor->id,
            reason: 'First apply'
        );
        $this->assertSame(PosReturnBalanceRepairService::CLASSIFICATION_REPAIRED, $result1['classification']);
        $this->assertSame(1, PosReturnBalanceRepairAudit::count());

        // Second apply (Rerun)
        $result2 = $this->service->apply(
            $fixture['transaction']->id,
            $this->setting->id,
            actorId: $this->actor->id,
            reason: 'Rerun apply'
        );

        $this->assertSame(PosReturnBalanceRepairService::CLASSIFICATION_UNCHANGED, $result2['classification']);
        // Still exactly 1 audit record! Zero duplicate writes!
        $this->assertSame(1, PosReturnBalanceRepairAudit::count());
    }

    public function test_multiple_selected_groups_have_independent_atomic_outcomes(): void
    {
        $fixture1 = $this->createDriftFixture(code: 'TXN-GRP-1');
        $fixture2 = $this->createDriftFixture(code: 'TXN-GRP-2');

        // Mutate fixture2 into a blocked state (e.g. invalid discount)
        $fixture2['sale']->update(['discount_amount' => 5000]);

        // Run command across both candidates in apply mode
        $exitCode = $this->artisan('pos:repair-return-balances', [
            '--setting' => $this->setting->id,
            '--transaction' => [$fixture1['transaction']->id, $fixture2['transaction']->id],
            '--apply' => true,
            '--actor' => $this->actor->id,
            '--reason' => 'Batch apply independent outcomes test',
        ])
            ->expectsOutputToContain("Evaluating POS Transaction: {$fixture1['transaction']->id}")
            ->expectsOutputToContain('[REPAIRED]')
            ->expectsOutputToContain("Evaluating POS Transaction: {$fixture2['transaction']->id}")
            ->expectsOutputToContain('[BLOCKED]')
            ->assertExitCode(1)
            ->run();

        // Group 1 was successfully repaired and audited
        $this->assertEquals(805000.00, (float) $fixture1['sale']->fresh()->total_amount);
        $this->assertSame(1, PosReturnBalanceRepairAudit::where('pos_transaction_id', $fixture1['transaction']->id)->count());

        // Group 2 was blocked and made zero writes
        $this->assertEquals(804999.60, (float) $fixture2['sale']->fresh()->total_amount);
        $this->assertSame(0, PosReturnBalanceRepairAudit::where('pos_transaction_id', $fixture2['transaction']->id)->count());
    }

    protected function createDriftFixture(string $code = 'TXN-APPLY-DRIFT'): array
    {
        $notebook = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Notebook',
            'product_code' => 'NB-' . $code,
            'sale_price' => 15000,
        ]);

        $marker = $this->createStockedProduct($this->setting, $this->location, [
            'product_name' => 'Marker Whiteboard 120 Pcs',
            'product_code' => 'MK-' . $code,
            'sale_price' => 6583.33,
        ]);

        $transaction = PosTransaction::create([
            'setting_id' => $this->setting->id,
            'code' => $code,
            'status' => PosTransaction::STATUS_COMPLETED,
            'created_by' => $this->user->id,
            'owner_user_id' => $this->user->id,
            'last_saved_by' => $this->user->id,
            'source_pos_session_id' => $this->session->id,
            'customer_id' => $this->customer->id,
        ]);

        $checkout = PosCheckout::create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => $transaction->id,
            'pos_session_id' => $this->session->id,
            'terminal_id' => $this->terminal->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => PosCheckout::STATUS_POSTED,
            'grand_total' => 805000,
            'receipt_number' => 'RCP-' . $code,
            'idempotency_key' => 'IDEM-' . $code,
            'payload_hash' => 'HASH-' . $code,
        ]);
        $transaction->update(['completed_checkout_id' => $checkout->id]);

        $sale = Sale::create([
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 804999.60,
            'paid_amount' => 375000,
            'due_amount' => 429999.60,
            'date' => now()->toDateString(),
            'status' => 'DISPATCHED',
            'payment_status' => 'PARTIAL',
            'payment_method' => 'CASH',
            'reference' => 'SO-' . $code,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'amount' => 375000,
            'date' => now()->toDateString(),
            'reference' => 'PAY-' . $code,
            'payment_method' => 'CASH',
        ]);

        PosCheckoutSale::create([
            'pos_checkout_id' => $checkout->id,
            'sale_id' => $sale->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'grand_total' => 805000,
            'split_key' => 'SPLIT-' . $code,
            'tax_bucket' => 'NON_TAX',
        ]);

        $line1 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 1,
            'product_id' => $notebook->id,
            'product_name_snapshot' => $notebook->product_name,
            'qty' => 1,
            'unit_price' => 15000,
        ]);

        $line2 = PosTransactionLine::create([
            'pos_transaction_id' => $transaction->id,
            'line_no' => 2,
            'product_id' => $marker->id,
            'product_name_snapshot' => $marker->product_name,
            'qty' => 120,
            'unit_price' => 6583.33,
            'line_meta' => [
                'line_total_minor' => 79000000,
                'line_gross_minor' => 79000000,
            ],
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $notebook->id,
            'product_name' => 'Notebook',
            'product_code' => 'NB-' . $code,
            'pos_transaction_line_id' => $line1->id,
            'quantity' => 1,
            'price' => 15000,
            'unit_price' => 15000,
            'sub_total' => 15000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $marker->id,
            'product_name' => 'Marker Whiteboard 120 Pcs',
            'product_code' => 'MK-' . $code,
            'pos_transaction_line_id' => $line2->id,
            'quantity' => 120,
            'price' => 6583.33,
            'unit_price' => 6583.33,
            'sub_total' => 790000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        return [
            'transaction' => $transaction,
            'checkout' => $checkout,
            'sale' => $sale,
        ];
    }

    public function test_apply_rolls_back_if_receipt_reprint_projection_fails_under_lock(): void
    {
        $fixture = $this->createDriftFixture();

        // Create a service instance with a receipt reprint projection service that fails under lock
        $callCount = 0;
        $mockReprintService = $this->createMock(PosReceiptReprintProjectionService::class);
        $mockReprintService->expects($this->any())
            ->method('getCompletedReprintReceiptData')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount >= 3) {
                    throw new \Modules\Pos\Exceptions\PosReprintProjectionException('Simulated reprint projection failure');
                }
                return [
                    'grand_total' => 805000.0,
                    'outstanding_debt' => 430000.0,
                ];
            });

        $customService = new PosReturnBalanceRepairService(
            receiptProjectionService: $mockReprintService
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Receipt reprint projection failed after repair');

        try {
            $customService->apply(
                $fixture['transaction']->id,
                $this->setting->id,
                actorId: $this->actor->id,
                reason: 'Testing reprint projection failure rollback'
            );
        } finally {
            // Verify database state: zero mutations persisted, rollback succeeded
            $this->assertEquals(804999.60, (float) $fixture['sale']->fresh()->total_amount);
            $this->assertSame(0, PosReturnBalanceRepairAudit::count());
        }
    }
}
