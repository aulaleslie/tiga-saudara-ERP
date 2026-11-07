<?php

namespace Tests\Feature\SalesReturn;

use App\Support\SalesReturn\SalesReturnWorkflowException;
use App\Support\SalesReturn\SalesReturnWorkflowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Sale\Entities\Sale;
use Tests\TestCase;

/**
 * Integration checkpoint (main merge): ownership through persisted replacement lineage,
 * combined live-due parity, and populated-ledger rollback protection.
 */
class MainIntegrationOwnershipTest extends TestCase
{
    use RefreshDatabase, BuildsWorkflowFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableForeignKeys();
    }

    private function linkReplacementLineage(Sale $sale): void
    {
        DB::table('pos_return_lines')->insert(['id' => 501, 'pos_return_id' => 1, 'pos_checkout_sale_id' => 1, 'sale_id' => $sale->id, 'sale_detail_id' => 1, 'source_setting_id' => 1, 'source_location_id' => 1, 'product_id' => 1, 'stock_behavior' => 'stock_managed', 'product_name' => 'P', 'product_code' => 'P1', 'quantity' => 1, 'unit_price' => 1, 'line_total' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('dispatch_details')->insert([
            'id' => 601, 'dispatch_id' => 1, 'product_id' => 1, 'dispatched_quantity' => 1, 'sale_id' => $sale->id, 'pos_return_line_id' => 501,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @test */
    public function generated_replacement_sale_is_pos_before_any_return_and_standard_paths_refuse_it(): void
    {
        $replacement = $this->makeSale('repl');
        $this->linkReplacementLineage($replacement);
        $resolver = app(SalesReturnWorkflowResolver::class);

        $this->assertSame('pos', $resolver->classifySale($replacement->id));

        try {
            $resolver->assertSaleAllowsStandardReturn($replacement->id);
            $this->fail('Standard lookup/create must refuse a POS replacement sale.');
        } catch (SalesReturnWorkflowException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    /** @test */
    public function replacement_sale_with_historical_standard_claim_is_mixed_and_refuses_mutation(): void
    {
        $replacement = $this->makeSale('mixed');
        $standard = $this->makeReturn($replacement);
        $this->assertSame('standard', app(SalesReturnWorkflowResolver::class)->classifySale($replacement->id));

        $this->linkReplacementLineage($replacement);
        $resolver = app(SalesReturnWorkflowResolver::class);
        $snapshot = $standard->fresh()->toArray();

        $this->assertSame('mixed', $resolver->classifySale($replacement->id));
        $this->assertSame('mixed', $resolver->classifyReturn($standard));

        $this->expectException(SalesReturnWorkflowException::class);
        try {
            $resolver->assertStandardMutable($standard);
        } finally {
            $this->assertSame($snapshot, $standard->fresh()->toArray());
        }
    }

    /** @test */
    public function reference_or_note_text_never_classifies_a_sale_as_pos(): void
    {
        $sale = $this->makeSale('text');
        $sale->update(['note' => 'Penggantian retur POS RET-1', 'reference' => 'POS-REPL-1']);

        $this->assertSame('standard', app(SalesReturnWorkflowResolver::class)->classifySale($sale->id));
    }

    /** @test */
    public function live_due_query_filters_ordering_and_php_projections_agree_across_transfers_and_standard_reductions(): void
    {
        // Expected: 1000 - 300 paid - 200 incoming + 50 outgoing - 100 debt reduction = 450
        $sale = $this->makeSale('due');
        $sale->update(['total_amount' => 1000]);
        $other = $this->makeSale('other'); // untouched: due = 1000
        $other->update(['total_amount' => 1000]);

        $paymentId = DB::table('sale_payments')->insertGetId([
            'sale_id' => $sale->id, 'amount' => 300, 'date' => now()->toDateString(), 'reference' => 'P-' . uniqid(),
            'payment_method' => 'CASH', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $transfer = fn (array $o) => DB::table('pos_owner_payment_transfers')->insert(array_merge([
            'execution_key' => 'K-' . uniqid(), 'pos_return_id' => 1, 'sale_return_detail_id' => 1,
            'original_sale_payment_id' => $paymentId, 'payment_method' => 'CASH', 'amount_minor' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $o));
        $transfer(['source_sale_id' => 9001, 'replacement_sale_id' => $sale->id, 'amount' => 200, 'amount_minor' => 20000]);
        $transfer(['source_sale_id' => $sale->id, 'replacement_sale_id' => 9002, 'amount' => 50, 'amount_minor' => 5000]);
        DB::table('sale_return_adjustments')->insert([
            'sale_return_item_settlement_id' => 1, 'sale_return_id' => 1, 'sale_id' => $sale->id,
            'sale_return_detail_id' => 1, 'quantity' => 1, 'committed_value' => 100, 'debt_reduction' => 100,
            'cash_refund' => 0, 'source_fingerprint' => str_repeat('a', 64), 'policy_version' => 1,
            'committed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $raw = DB::selectOne('SELECT ('.Sale::canonicalLiveDueFormula().') AS due FROM sales WHERE id = ?', array_merge(Sale::canonicalLiveDueFormulaBindings(), [$sale->id]));
        $this->assertEquals(450.0, (float) $raw->due, 'raw formula');

        // SQL filters
        $this->assertTrue(Sale::query()->whereKey($sale->id)->whereLiveDueAmountGreaterThan(449)->exists());
        $this->assertFalse(Sale::query()->whereKey($sale->id)->whereLiveDueAmountGreaterThan(450)->exists());
        $this->assertTrue(Sale::query()->whereKey($sale->id)->whereLiveDueAmountLessThanOrEqual(450)->exists());
        $this->assertFalse(Sale::query()->whereKey($sale->id)->whereLiveDueAmountLessThanOrEqual(449)->exists());

        // SQL ordering (clamped formula evaluates twice: 8 bindings) — 450 sorts before 1000.
        $asc = Sale::query()->whereIn('id', [$sale->id, $other->id])->orderByLiveDueAmount('asc')->pluck('id')->all();
        $this->assertSame([$sale->id, $other->id], $asc);
        $desc = Sale::query()->whereIn('id', [$sale->id, $other->id])->orderByLiveDueAmount('desc')->pluck('id')->all();
        $this->assertSame([$other->id, $sale->id], $desc);

        // PHP projections: plain, aggregate-loaded, relation-loaded
        $plain = Sale::query()->find($sale->id);
        $aggregate = Sale::query()->withLiveSettlementAggregates()->find($sale->id);
        $relation = Sale::query()->with(['salePayments.creditApplications', 'incomingOwnerPaymentTransfers.originalSalePayment', 'outgoingOwnerPaymentTransfers.originalSalePayment'])->find($sale->id);

        foreach ([$plain, $aggregate, $relation] as $projection) {
            $this->assertEquals(450.0, $projection->live_due_amount);
            $this->assertEquals(450.0, $projection->getEffectivePaidAmount());
            $this->assertEquals(100.0, $projection->getCommittedDebtReduction());
        }

        $plain->reconcileFromActivePayments();
        $this->assertEquals(450.0, (float) $plain->fresh()->due_amount);
    }

    /** @test */
    public function populated_incoming_ledgers_cannot_be_destructively_rolled_back(): void
    {
        $transfers = require base_path('Modules/Pos/Database/Migrations/2026_11_07_000001_create_pos_owner_payment_transfers_and_repair_audits_tables.php');
        $reversals = require base_path('Modules/Pos/Database/Migrations/2026_11_07_000002_create_pos_owner_payment_transfer_reversals_table.php');

        DB::table('pos_owner_payment_transfer_reversals')->insert([
            'transfer_id' => 1, 'reduced_minor' => 1, 'amount_minor_before' => 2, 'amount_minor_after' => 1,
            'transfer_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $reversals->down();
            $this->fail('Populated reversal ledger must refuse rollback.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Rollback refused', $e->getMessage());
        }
        $this->assertSame(1, DB::table('pos_owner_payment_transfer_reversals')->count());

        DB::table('pos_owner_payment_transfers')->insert([
            'execution_key' => 'K-1', 'pos_return_id' => 1, 'sale_return_detail_id' => 1, 'source_sale_id' => 1,
            'replacement_sale_id' => 2, 'original_sale_payment_id' => 1, 'payment_method' => 'CASH',
            'amount' => 1, 'amount_minor' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $transfers->down();
            $this->fail('Populated transfer ledger must refuse rollback.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Rollback refused', $e->getMessage());
        }
        $this->assertSame(1, DB::table('pos_owner_payment_transfers')->count());

        // Empty ledgers still roll back cleanly.
        DB::table('pos_owner_payment_transfer_reversals')->delete();
        $reversals->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('pos_owner_payment_transfer_reversals'));
    }
}
