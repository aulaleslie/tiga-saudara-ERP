<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Modules\Pos\Entities\PosOwnerPaymentTransfer;
use Modules\Pos\Entities\PosReplacementAccountingRepairAudit;
use Modules\Pos\Tests\Feature\Support\BuildsSyntheticTransaction10717Fixture;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalePayment;

class PosOwnerPaymentTransferAndRepairAuditModelTest extends PosTransactionFeatureTestCase
{
    use BuildsSyntheticTransaction10717Fixture;

    /** @test */
    public function fresh_migrations_create_owner_payment_transfers_and_repair_audits_tables(): void
    {
        $this->assertTrue(Schema::hasTable('pos_owner_payment_transfers'));
        $this->assertTrue(Schema::hasTable('pos_replacement_accounting_repair_audits'));

        $this->assertTrue(Schema::hasColumns('pos_owner_payment_transfers', [
            'id',
            'execution_key',
            'pos_transaction_id',
            'pos_checkout_id',
            'pos_return_id',
            'pos_return_line_id',
            'sale_return_id',
            'sale_return_detail_id',
            'source_sale_id',
            'replacement_sale_id',
            'original_sale_payment_id',
            'predecessor_transfer_id',
            'invalidated_synthetic_payment_id',
            'payment_method_id',
            'payment_method',
            'amount',
            'amount_minor',
            'transfer_source',
            'actor_id',
            'executed_at',
            'metadata',
        ]));

        $this->assertTrue(Schema::hasColumns('pos_replacement_accounting_repair_audits', [
            'id',
            'setting_id',
            'pos_transaction_id',
            'pos_checkout_id',
            'pos_return_id',
            'actor_id',
            'reason',
            'status',
            'evidence_fingerprint',
            'source_sale_ids',
            'replacement_sale_ids',
            'original_payment_ids',
            'invalidated_payment_ids',
            'transfer_ids',
            'before_values',
            'after_values',
        ]));
    }

    /** @test */
    public function owner_payment_transfer_enforces_unique_execution_lineage_and_amount_bounds(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('legacy_stale_duplicated');

        $sourceSale = $fixture['source_sale'];
        $replacementSale = $fixture['replacement_sale'];
        $originalPayment = $fixture['source_payment'];
        $detail = $fixture['sale_return_detail_printer'];

        $transfer = PosOwnerPaymentTransfer::query()->create([
            'pos_transaction_id' => $fixture['transaction']->id,
            'pos_checkout_id' => $fixture['checkout']->id,
            'pos_return_id' => $fixture['pos_return']->id,
            'pos_return_line_id' => $fixture['return_line_printer']->id,
            'sale_return_id' => $fixture['sale_return']->id,
            'sale_return_detail_id' => $detail->id,
            'source_sale_id' => $sourceSale->id,
            'replacement_sale_id' => $replacementSale->id,
            'original_sale_payment_id' => $originalPayment->id,
            'payment_method' => 'CASH',
            'amount' => 2225000.00,
            'amount_minor' => 222500000,
            'actor_id' => $fixture['cashier']->id,
            'executed_at' => now(),
        ]);

        $this->assertSame(
            PosOwnerPaymentTransfer::buildExecutionKey($detail->id, $originalPayment->id, null),
            $transfer->execution_key
        );

        // Duplicate execution key for same return detail + original payment must fail
        try {
            PosOwnerPaymentTransfer::query()->create([
                'pos_transaction_id' => $fixture['transaction']->id,
                'pos_checkout_id' => $fixture['checkout']->id,
                'pos_return_id' => $fixture['pos_return']->id,
                'pos_return_line_id' => $fixture['return_line_printer']->id,
                'sale_return_id' => $fixture['sale_return']->id,
                'sale_return_detail_id' => $detail->id,
                'source_sale_id' => $sourceSale->id,
                'replacement_sale_id' => $replacementSale->id,
                'original_sale_payment_id' => $originalPayment->id,
                'payment_method' => 'CASH',
                'amount' => 1000.00,
                'amount_minor' => 100000,
                'actor_id' => $fixture['cashier']->id,
                'executed_at' => now(),
            ]);
            $this->fail('Duplicate execution lineage should have been rejected.');
        } catch (QueryException|\InvalidArgumentException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // Another return detail attempting to transfer more than remaining available on original payment (45,000 left) must fail
        try {
            PosOwnerPaymentTransfer::query()->create([
                'pos_transaction_id' => $fixture['transaction']->id,
                'pos_checkout_id' => $fixture['checkout']->id,
                'pos_return_id' => $fixture['pos_return']->id,
                'sale_return_detail_id' => $detail->id + 999,
                'source_sale_id' => $sourceSale->id,
                'replacement_sale_id' => $replacementSale->id,
                'original_sale_payment_id' => $originalPayment->id,
                'payment_method' => 'CASH',
                'amount' => 50000.00,
                'amount_minor' => 5000000,
                'actor_id' => $fixture['cashier']->id,
                'executed_at' => now(),
            ]);
            $this->fail('Transfer exceeding original payment bound should have been rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('exceeds available amount', $e->getMessage());
        }
    }

    /** @test */
    public function predecessor_transfer_chain_enforces_predecessor_amount_bounds(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('legacy_stale_duplicated');

        $sourceSale = $fixture['source_sale'];
        $replacementSaleB = $fixture['replacement_sale'];
        $originalPayment = $fixture['source_payment'];
        $detail1 = $fixture['sale_return_detail_printer'];

        $settingC = $this->createSetting('Owner C');
        $replacementSaleC = Sale::query()->create([
            'setting_id' => $settingC->id,
            'customer_id' => $fixture['customer_a']->id,
            'customer_name' => $fixture['customer_a']->customer_name,
            'reference' => 'SO-OWNER-C-' . uniqid(),
            'total_amount' => 2225000,
            'paid_amount' => 2225000,
            'due_amount' => 0,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Paid',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        $transfer1 = PosOwnerPaymentTransfer::query()->create([
            'pos_transaction_id' => $fixture['transaction']->id,
            'pos_checkout_id' => $fixture['checkout']->id,
            'pos_return_id' => $fixture['pos_return']->id,
            'sale_return_detail_id' => $detail1->id,
            'source_sale_id' => $sourceSale->id,
            'replacement_sale_id' => $replacementSaleB->id,
            'original_sale_payment_id' => $originalPayment->id,
            'payment_method' => 'CASH',
            'amount' => 2225000.00,
            'amount_minor' => 222500000,
            'actor_id' => $fixture['cashier']->id,
            'executed_at' => now(),
        ]);

        // Valid successor transfer from Owner B -> Owner C backed by transfer1
        $transfer2 = PosOwnerPaymentTransfer::query()->create([
            'pos_transaction_id' => $fixture['transaction']->id,
            'pos_checkout_id' => $fixture['checkout']->id,
            'pos_return_id' => $fixture['pos_return']->id,
            'sale_return_detail_id' => $detail1->id + 1,
            'source_sale_id' => $replacementSaleB->id,
            'replacement_sale_id' => $replacementSaleC->id,
            'original_sale_payment_id' => $originalPayment->id,
            'predecessor_transfer_id' => $transfer1->id,
            'payment_method' => 'CASH',
            'amount' => 2225000.00,
            'amount_minor' => 222500000,
            'actor_id' => $fixture['cashier']->id,
            'executed_at' => now(),
        ]);

        $this->assertSame($transfer1->id, (int) $transfer2->predecessor_transfer_id);

        // Attempting to transfer more from transfer1 when already exhausted must fail
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds available amount on predecessor transfer');

        PosOwnerPaymentTransfer::query()->create([
            'pos_transaction_id' => $fixture['transaction']->id,
            'pos_checkout_id' => $fixture['checkout']->id,
            'pos_return_id' => $fixture['pos_return']->id,
            'sale_return_detail_id' => $detail1->id + 2,
            'source_sale_id' => $replacementSaleB->id,
            'replacement_sale_id' => $replacementSaleC->id,
            'original_sale_payment_id' => $originalPayment->id,
            'predecessor_transfer_id' => $transfer1->id,
            'payment_method' => 'CASH',
            'amount' => 1.00,
            'amount_minor' => 100,
            'actor_id' => $fixture['cashier']->id,
            'executed_at' => now(),
        ]);
    }

    /** @test */
    public function repair_audit_persists_before_after_evidence_and_enforces_unique_fingerprint_per_transaction(): void
    {
        $fixture = $this->buildSyntheticTransaction10717Fixture('legacy_stale_duplicated');

        $audit = PosReplacementAccountingRepairAudit::query()->create([
            'setting_id' => $fixture['setting_a']->id,
            'pos_transaction_id' => $fixture['transaction']->id,
            'pos_checkout_id' => $fixture['checkout']->id,
            'pos_return_id' => $fixture['pos_return']->id,
            'actor_id' => $fixture['cashier']->id,
            'reason' => 'Repair 10717 duplicate replacement header and payment',
            'evidence_fingerprint' => str_repeat('a', 64),
            'source_sale_ids' => [$fixture['source_sale']->id],
            'replacement_sale_ids' => [$fixture['replacement_sale']->id],
            'original_payment_ids' => [$fixture['source_payment']->id],
            'invalidated_payment_ids' => [$fixture['replacement_payment']->id],
            'transfer_ids' => [1],
            'before_values' => ['total_amount' => 4825000],
            'after_values' => ['total_amount' => 2600000],
        ]);

        $this->assertSame(4825000, $audit->before_values['total_amount']);
        $this->assertSame(2600000, $audit->after_values['total_amount']);

        $this->expectException(QueryException::class);

        PosReplacementAccountingRepairAudit::query()->create([
            'setting_id' => $fixture['setting_a']->id,
            'pos_transaction_id' => $fixture['transaction']->id,
            'pos_checkout_id' => $fixture['checkout']->id,
            'pos_return_id' => $fixture['pos_return']->id,
            'actor_id' => $fixture['cashier']->id,
            'reason' => 'Duplicate apply attempt',
            'evidence_fingerprint' => str_repeat('a', 64),
            'before_values' => ['total_amount' => 4825000],
            'after_values' => ['total_amount' => 2600000],
        ]);
    }
}
