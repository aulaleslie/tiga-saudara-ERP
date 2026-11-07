<?php

namespace Tests\Feature\SalesReturn;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Models\User;
use App\Services\Notification\DocumentNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement as Settlement;
use Tests\TestCase;

/** Group 8: the read-only `sale-returns:audit` command and its zero-write guarantee. */
class SaleReturnAuditTest extends TestCase
{
    use RefreshDatabase, BuildsStandardFlow, BuildsFinancialFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBase();
        $this->withoutMiddleware(CheckUserRoleForSetting::class);
        Gate::before(fn ($user, $ability) => true);
        $this->actingAs(User::factory()->create());
        $this->app->instance(DocumentNotificationService::class, \Mockery::mock(DocumentNotificationService::class)->shouldIgnoreMissing());
    }

    private function http(): self
    {
        return $this->withSession(['setting_id' => 1]);
    }

    /** Runs the real command class into a private buffer (no dependence on the console kernel's shared state). */
    private function runAudit(array $params): array
    {
        // RefreshDatabase's migrate:fresh (first test of a process) leaves a mocked console OutputStyle bound
        // in the container; drop it so this command writes to the real buffer.
        unset($this->app[\Illuminate\Console\OutputStyle::class]);
        $command = app(\Modules\SalesReturn\Console\SaleReturnsAuditCommand::class);
        $command->setLaravel($this->app);
        $buffer = new \Symfony\Component\Console\Output\BufferedOutput();
        $code = $command->run(new \Symfony\Component\Console\Input\ArrayInput($params), $buffer);

        return [$code, $buffer->fetch()];
    }

    /** @return array<string, mixed> */
    private function audit(array $options = []): array
    {
        [$code, $out] = $this->runAudit(array_merge(['--setting' => 1, '--json' => true], $options));
        $this->assertSame(0, $code);

        return json_decode($out, true);
    }

    /** @return array<int, string> finding codes */
    private function codes(array $report, ?int $returnId = null, ?string $classification = null): array
    {
        return collect($report['findings'])
            ->when($returnId, fn ($c) => $c->where('sale_return_id', $returnId))
            ->when($classification, fn ($c) => $c->where('classification', $classification))
            ->pluck('code')->values()->all();
    }

    private function receivedAndCashSettled(array $s): SaleReturn
    {
        $line = $s['lines'][0];
        $ids = collect($line['serials'])->pluck('id')->all();
        $return = $this->makeReturnFor($line, count($ids), ['approval_status' => 'approved', 'status' => 'Awaiting Receiving'], $ids, 1000);
        $this->http()->post(route('sale-returns.receive', $return))->assertRedirect();
        foreach ($ids as $id) {
            $detailId = \Modules\SalesReturn\Entities\SaleReturnDetail::query()->where('sale_return_id', $return->id)->value('id');
            $row = Settlement::create(['sale_return_id' => $return->id, 'sale_return_detail_id' => $detailId, 'product_serial_number_id' => $id, 'method' => 'CASH_REFUND', 'nominal' => 1000, 'status' => Settlement::STATUS_SUBMITTED]);
            $d = app(\App\Support\SalesReturn\StandardFinancialService::class)->resolve($row->fresh());
            $row->forceFill(['nominal' => $d['value'], 'reviewed_value' => $d['value'], 'reviewed_fingerprint' => $d['fingerprint']])->save();
            $this->http()->post(route('sale-return-settlements.item.approve', $row))->assertRedirect();
        }

        return $return->fresh();
    }

    private function snapshot(): string
    {
        $tables = collect(DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name"))->pluck('name');

        return md5($tables->map(fn ($t) => $t . ':' . DB::table($t)->get()->toJson())->implode('|'));
    }

    // ------------------------------------------------------------------ findings

    /** @test */
    public function a_clean_new_policy_return_has_no_findings(): void
    {
        $s = $this->sale([['qty' => 2, 'sub' => 2000]], paid: 2000, serial: true);
        $return = $this->receivedAndCashSettled($s);

        $report = $this->audit();

        $this->assertSame([], $this->codes($report, $return->id), json_encode($report['findings']));
        $this->assertSame(1, $report['summary']['returns_audited']);
    }

    /** @test */
    public function receiving_evidence_findings_are_proven_and_legacy_records_are_ambiguous_or_suspected(): void
    {
        $s = $this->sale([['qty' => 1, 'sub' => 1000]], paid: 1000, serial: true);
        $line = $s['lines'][0];

        // received status without received_at
        $noStamp = $this->makeReturnFor($line, 1, ['approval_status' => 'approved', 'status' => 'Awaiting Settlement'], [$line['serials'][0]->id], 1000);
        // legacy received return: no policy marker, no custody, no receiving stock transaction
        $s2 = $this->sale([['qty' => 1, 'sub' => 1000]], paid: 1000, serial: true);
        $legacy = $this->makeReturnFor($s2['lines'][0], 1, ['approval_status' => 'approved', 'status' => 'Awaiting Settlement', 'received_at' => now()], [$s2['lines'][0]['serials'][0]->id], 1000);
        // new-policy return that nevertheless credited sellable stock at receiving
        $s3 = $this->sale([['qty' => 1, 'sub' => 1000]], paid: 1000, serial: true);
        $newPolicy = $this->makeReturnFor($s3['lines'][0], 1, ['approval_status' => 'approved', 'status' => 'Awaiting Settlement', 'received_at' => now(), 'custody_policy_version' => 1, 'custody_policy_applied_at' => now()], [$s3['lines'][0]['serials'][0]->id], 1000);
        \Modules\Product\Entities\Transaction::create([
            'product_id' => $s3['lines'][0]['product']->id, 'setting_id' => 1, 'location_id' => 1, 'type' => 'SALE_RETURN_GOOD_NON_TAX', 'quantity' => 1,
            'current_quantity' => 1, 'broken_quantity' => 0, 'previous_quantity' => 0, 'after_quantity' => 1, 'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 1, 'quantity_tax' => 0, 'quantity_non_tax' => 1, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0,
            'user_id' => auth()->id(), 'reason' => 'PENERIMAAN RETUR PENJUALAN: ' . $newPolicy->reference,
        ]);

        $report = $this->audit();

        $this->assertContains('RECEIVED_WITHOUT_RECEIVED_AT', $this->codes($report, $noStamp->id, 'proven'));
        $this->assertContains('LEGACY_RECEIVED_WITHOUT_CUSTODY', $this->codes($report, $legacy->id, 'ambiguous'));
        $this->assertContains('LEGACY_RECEIVED_WITHOUT_STOCK_TRANSACTION', $this->codes($report, $legacy->id, 'suspected'));
        $this->assertContains('NEW_POLICY_WITHOUT_CUSTODY', $this->codes($report, $newPolicy->id, 'proven'));
        $this->assertContains('NEW_POLICY_RECEIVING_CREDITED_STOCK', $this->codes($report, $newPolicy->id, 'proven'));
        $legacyFinding = collect($report['findings'])->firstWhere('code', 'LEGACY_RECEIVED_WITHOUT_CUSTODY');
        $this->assertSame('legacy', $legacyFinding['record_policy']);
    }

    /** @test */
    public function occurrence_findings_cover_duplicates_cardinality_and_unmatched_settlements(): void
    {
        $s = $this->sale([['qty' => 3, 'sub' => 3000]], paid: 0, serial: true);
        $line = $s['lines'][0];
        $ids = collect($line['serials'])->pluck('id')->all();
        $return = $this->makeReturnFor($line, 3, ['approval_status' => 'approved', 'status' => 'Awaiting Settlement', 'received_at' => now()], [$ids[0], $ids[1]], 1000);
        $detail = \Modules\SalesReturn\Entities\SaleReturnDetail::query()->where('sale_return_id', $return->id)->first();   // quantity 3, two serial ids

        $report = $this->audit();
        $this->assertContains('SERIAL_CARDINALITY_MISMATCH', $this->codes($report, $return->id, 'proven'));

        // fix cardinality, then add a settlement for a serial nobody received
        $detail->forceFill(['quantity' => 2])->save();
        Settlement::create(['sale_return_id' => $return->id, 'sale_return_detail_id' => $detail->id, 'product_serial_number_id' => $ids[2], 'method' => 'CASH_REFUND', 'status' => 'APPROVED', 'nominal' => 1]);
        $this->assertContains('UNMATCHED_SETTLEMENT_OCCURRENCE', $this->codes($this->audit(), $return->id, 'proven'));

        // duplicate finals for one non-serial occurrence (the unique key does not cover NULL serials)
        $plain = $this->sale([['qty' => 2, 'sub' => 2000]], paid: 0);
        $plainReturn = $this->makeReturnFor($plain['lines'][0], 2, ['approval_status' => 'approved', 'status' => 'Awaiting Settlement', 'received_at' => now()], [], 1000);
        $plainDetail = \Modules\SalesReturn\Entities\SaleReturnDetail::query()->where('sale_return_id', $plainReturn->id)->first();
        foreach ([1, 2] as $_) {
            Settlement::create(['sale_return_id' => $plainReturn->id, 'sale_return_detail_id' => $plainDetail->id, 'method' => 'CASH_REFUND', 'status' => 'APPROVED', 'nominal' => 1]);
        }
        $this->assertContains('DUPLICATE_FINAL_SETTLEMENTS', $this->codes($this->audit(), $plainReturn->id, 'proven'));
    }

    /** @test */
    public function financial_evidence_findings_distinguish_legacy_from_new_policy(): void
    {
        $s = $this->sale([['qty' => 2, 'sub' => 2000]], paid: 2000, serial: true);
        $line = $s['lines'][0];
        $ids = collect($line['serials'])->pluck('id')->all();

        $mk = function (array $overrides, int $serialId) use ($line) {
            $return = $this->makeReturnFor($line, 1, array_merge(['approval_status' => 'approved', 'status' => 'Awaiting Settlement', 'received_at' => now()], $overrides), [$serialId], 1000);
            $detailId = \Modules\SalesReturn\Entities\SaleReturnDetail::query()->where('sale_return_id', $return->id)->value('id');
            Settlement::create(['sale_return_id' => $return->id, 'sale_return_detail_id' => $detailId, 'product_serial_number_id' => $serialId, 'method' => 'CASH_REFUND', 'status' => 'APPROVED', 'nominal' => 1000]);

            return $return;
        };
        $legacy = $mk([], $ids[0]);
        $modern = $mk(['custody_policy_version' => 1, 'custody_policy_applied_at' => now()], $ids[1]);

        $report = $this->audit();
        $this->assertContains('CASH_APPROVED_WITHOUT_ADJUSTMENT', $this->codes($report, $legacy->id, 'ambiguous'), 'legacy: cannot reconstruct');
        $this->assertContains('CASH_APPROVED_WITHOUT_ADJUSTMENT', $this->codes($report, $modern->id, 'proven'), 'new policy: evidence is required');
    }

    /** @test */
    public function contradictory_payout_and_duplicate_payout_evidence_is_reported(): void
    {
        $s = $this->sale([['qty' => 2, 'sub' => 2000]], paid: 2000, serial: true);
        $return = $this->receivedAndCashSettled($s);

        // delete one linked payout: the adjustment now claims cash that has no payout
        $payment = DB::table('sale_return_payments')->where('sale_return_id', $return->id)->first();
        DB::table('sale_return_payments')->where('id', $payment->id)->delete();
        // two unlinked payouts with the same amount and date
        foreach ([1, 2] as $_) {
            DB::table('sale_return_payments')->insert(['sale_return_id' => $return->id, 'amount' => 50, 'date' => '2026-01-02', 'reference' => 'X', 'payment_method' => 'CASH', 'created_at' => now(), 'updated_at' => now()]);
        }

        $report = $this->audit();
        $this->assertContains('PAYOUT_EVIDENCE_CONTRADICTION', $this->codes($report, $return->id, 'proven'));
        $this->assertContains('POSSIBLE_DUPLICATE_PAYOUT', $this->codes($report, $return->id, 'suspected'));
    }

    /** @test */
    public function premature_completion_and_archival_are_classified_by_record_policy(): void
    {
        $s = $this->sale([['qty' => 2, 'sub' => 2000]], paid: 2000, serial: true);
        $line = $s['lines'][0];
        $ids = collect($line['serials'])->pluck('id')->all();
        $legacy = $this->makeReturnFor($line, 1, ['approval_status' => 'approved', 'status' => 'Completed', 'received_at' => now()], [$ids[0]], 1000);
        $modern = $this->makeReturnFor($line, 1, ['approval_status' => 'approved', 'status' => 'Completed', 'received_at' => now(), 'custody_policy_version' => 1], [$ids[1]], 1000);
        DB::table('sales')->where('id', $s['sale']->id)->update(['status' => 'RETURNED', 'archived_at' => now()]);

        $report = $this->audit();
        $this->assertContains('PREMATURE_COMPLETION', $this->codes($report, $legacy->id, 'suspected'));
        $this->assertContains('PREMATURE_COMPLETION', $this->codes($report, $modern->id, 'proven'));
        $archival = collect($report['findings'])->firstWhere('code', 'PREMATURE_ARCHIVAL');
        $this->assertSame('proven', $archival['classification'], 'a new-policy return contributes: the current rules apply');
        $this->assertSame($s['sale']->id, $archival['sale_id'], 'archived sales are included');
    }

    // ------------------------------------------------------------------ scope, filters, interface

    /** @test */
    public function the_audit_is_business_scoped_filterable_and_requires_a_scope(): void
    {
        $s = $this->sale([['qty' => 1, 'sub' => 1000]], paid: 1000, serial: true);
        $a = $this->makeReturnFor($s['lines'][0], 1, ['approval_status' => 'approved', 'status' => 'Awaiting Settlement'], [$s['lines'][0]['serials'][0]->id], 1000);   // no received_at
        $other = $this->sale([['qty' => 1, 'sub' => 1000]], paid: 1000, serial: true);
        $b = $this->makeReturnFor($other['lines'][0], 1, ['approval_status' => 'approved', 'status' => 'Awaiting Settlement'], [$other['lines'][0]['serials'][0]->id], 1000);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_unique(array_filter(array_column($this->audit()['findings'], 'sale_return_id'))));
        $this->assertSame([$a->id], array_values(array_unique(array_filter(array_column($this->audit(['--return' => $a->id])['findings'], 'sale_return_id')))));
        $this->assertSame([$b->id], array_values(array_unique(array_filter(array_column($this->audit(['--sale' => $other['sale']->id])['findings'], 'sale_return_id')))));

        // another business sees none of this
        $this->seedSetting(3);
        [, $out3] = $this->runAudit(['--setting' => 3, '--json' => true]);
        $this->assertSame(0, json_decode($out3, true)['summary']['returns_audited']);

        // scope is mandatory, and there is no apply / repair mode
        $this->assertNotSame(0, $this->runAudit(['--json' => true])[0]);
        $options = array_keys(app(\Modules\SalesReturn\Console\SaleReturnsAuditCommand::class)->getDefinition()->getOptions());
        $this->assertSame([], array_values(array_intersect($options, ['apply', 'fix', 'repair', 'force', 'write', 'commit'])));
        $this->expectException(\Symfony\Component\Console\Exception\InvalidOptionException::class);
        $this->runAudit(['--setting' => 1, '--fix' => true]);
    }

    /** @test */
    public function the_human_readable_output_groups_findings_by_classification(): void
    {
        $s = $this->sale([['qty' => 1, 'sub' => 1000]], paid: 1000, serial: true);
        $this->makeReturnFor($s['lines'][0], 1, ['approval_status' => 'approved', 'status' => 'Awaiting Settlement'], [$s['lines'][0]['serials'][0]->id], 1000);

        [$code, $text] = $this->runAudit(['--setting' => 1]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('read-only; no data was changed', $text);
        $this->assertStringContainsString('PROVEN (', $text);
        $this->assertStringContainsString('RECEIVED_WITHOUT_RECEIVED_AT', $text);
    }

    // ------------------------------------------------------------------ review follow-up

    private function receivingTransaction(int $productId, int $settingId, string $reason): void
    {
        \Modules\Product\Entities\Transaction::create([
            'product_id' => $productId, 'setting_id' => $settingId, 'location_id' => 1, 'type' => 'SALE_RETURN_GOOD_NON_TAX', 'quantity' => 1,
            'current_quantity' => 1, 'broken_quantity' => 0, 'previous_quantity' => 0, 'after_quantity' => 1, 'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 1, 'quantity_tax' => 0, 'quantity_non_tax' => 1, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0,
            'user_id' => auth()->id(), 'reason' => $reason,
        ]);
    }

    /** @test */
    public function stock_evidence_is_scoped_to_the_business_and_matched_exactly(): void
    {
        $s = $this->sale([['qty' => 1, 'sub' => 1000]], paid: 1000, serial: true);
        $line = $s['lines'][0];
        $newPolicy = $this->makeReturnFor($line, 1, ['approval_status' => 'approved', 'status' => 'Awaiting Settlement', 'received_at' => now(), 'custody_policy_version' => 1], [$line['serials'][0]->id], 1000);
        $reason = 'PENERIMAAN RETUR PENJUALAN: ' . $newPolicy->reference;

        // another business's transaction with the very same reason, and a lookalike whose reason merely ENDS the same way
        $this->receivingTransaction($line['product']->id, 2, $reason);
        $this->receivingTransaction($line['product']->id, 1, 'X' . $reason);
        $this->receivingTransaction($line['product']->id, 1, 'PENERIMAAN RETUR PENJUALAN: PREFIX-' . $newPolicy->reference);
        $this->assertNotContains('NEW_POLICY_RECEIVING_CREDITED_STOCK', $this->codes($this->audit(), $newPolicy->id), 'foreign / lookalike transactions are not evidence');

        // the same business, exact reason, the return's own product: evidence
        $this->receivingTransaction($line['product']->id, 1, $reason);
        $this->assertContains('NEW_POLICY_RECEIVING_CREDITED_STOCK', $this->codes($this->audit(), $newPolicy->id, 'proven'));
    }

    /** @test */
    public function credit_applications_count_as_refund_evidence_like_the_financial_service(): void
    {
        $s = $this->sale([['qty' => 1, 'sub' => 1000]], paid: 1000, serial: true);
        $return = $this->makeReturnFor($s['lines'][0], 1, ['approval_status' => 'approved', 'status' => 'Awaiting Settlement', 'received_at' => now()], [$s['lines'][0]['serials'][0]->id], 1000);
        $paymentId = DB::table('sale_payments')->where('sale_id', $s['sale']->id)->value('id');
        $creditId = DB::table('customer_credits')->insertGetId(['customer_id' => $s['sale']->customer_id, 'sale_return_id' => $return->id, 'amount' => 500, 'remaining_amount' => 0, 'status' => 'closed', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sale_payment_credit_applications')->insert(['sale_payment_id' => $paymentId, 'customer_credit_id' => $creditId, 'amount' => 500, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sale_return_payments')->insert(['sale_return_id' => $return->id, 'amount' => 1400, 'date' => '2026-01-02', 'reference' => 'RC', 'payment_method' => 'CASH', 'created_at' => now(), 'updated_at' => now()]);

        // 1,000 cash + 500 credit were paid: a 1,400 refund is within the refundable value the service allows.
        $codes = collect($this->audit()['findings'])->where('sale_id', $s['sale']->id)->pluck('code')->all();
        $this->assertNotContains('REFUNDS_EXCEED_PAYMENTS', $codes);
        $this->assertNotContains('REFUNDS_EXCEED_ACTIVE_PAYMENTS', $codes);

        // beyond cash + credit it is still proven
        DB::table('sale_return_payments')->insert(['sale_return_id' => $return->id, 'amount' => 200, 'date' => '2026-01-03', 'reference' => 'RC2', 'payment_method' => 'CASH', 'created_at' => now(), 'updated_at' => now()]);
        $codes = collect($this->audit()['findings'])->where('sale_id', $s['sale']->id)->pluck('code')->all();
        $this->assertContains('REFUNDS_EXCEED_PAYMENTS', $codes);
    }

    /** @test */
    public function refund_grading_uses_historical_payment_authority(): void
    {
        $s = $this->sale([['qty' => 1, 'sub' => 1000]], paid: 1000, serial: true);
        $return = $this->makeReturnFor($s['lines'][0], 1, ['approval_status' => 'approved', 'status' => 'Awaiting Settlement', 'received_at' => now()], [$s['lines'][0]['serials'][0]->id], 1000);
        $payout = fn (float $amount) => DB::table('sale_return_payments')->insert(['sale_return_id' => $return->id, 'amount' => $amount, 'date' => '2026-01-02', 'reference' => 'R' . $amount, 'payment_method' => 'CASH', 'created_at' => now(), 'updated_at' => now()]);

        // the 1,000 payment was legitimately invalidated later; a 600 refund was paid out while it was active
        DB::table('sale_payments')->where('sale_id', $s['sale']->id)->update(['status' => 'INVALIDATED']);
        $payout(600);
        $codes = collect($this->audit()['findings'])->where('sale_id', $s['sale']->id);
        $this->assertNotContains('REFUNDS_EXCEED_PAYMENTS', $codes->pluck('code')->all(), 'cannot be proven against today\'s active payments');
        $this->assertSame('ambiguous', $codes->firstWhere('code', 'REFUNDS_EXCEED_ACTIVE_PAYMENTS')['classification']);

        // more refunded than was EVER paid is proven
        $payout(600);
        $codes = collect($this->audit()['findings'])->where('sale_id', $s['sale']->id);
        $this->assertSame('proven', $codes->firstWhere('code', 'REFUNDS_EXCEED_PAYMENTS')['classification']);
    }

    /** @test */
    public function aggregate_excess_claims_and_duplicate_serial_claims_are_audited(): void
    {
        // two returns each claim the only dispatched unit (non-serial)
        $s = $this->sale([['qty' => 1, 'sub' => 1000]], paid: 1000);
        $a = $this->makeReturnFor($s['lines'][0], 1, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving'], [], 1000);
        $b = $this->makeReturnFor($s['lines'][0], 1, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving'], [], 1000);
        $f = collect($this->audit(['--sale' => $s['sale']->id])['findings'])->firstWhere('code', 'EXCESS_CLAIM_ON_DISPATCH_LINE');
        $this->assertSame('proven', $f['classification']);
        $this->assertEquals(2.0, $f['evidence']['claimed']);
        $this->assertEquals(1.0, $f['evidence']['dispatched']);

        // a rejected return claims nothing
        $b->forceFill(['approval_status' => 'rejected'])->save();
        $this->assertNotContains('EXCESS_CLAIM_ON_DISPATCH_LINE', $this->codes($this->audit(['--sale' => $s['sale']->id])));

        // the same serial claimed by two returns
        $t = $this->sale([['qty' => 2, 'sub' => 2000]], paid: 2000, serial: true);
        $serial = $t['lines'][0]['serials'][0]->id;
        $this->makeReturnFor($t['lines'][0], 1, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving'], [$serial], 1000);
        $this->makeReturnFor($t['lines'][0], 1, ['approval_status' => 'approved', 'status' => 'Awaiting Receiving'], [$serial], 1000);
        $dup = collect($this->audit(['--sale' => $t['sale']->id])['findings'])->firstWhere('code', 'SERIAL_CLAIMED_BY_SEVERAL_RETURNS');
        $this->assertSame('proven', $dup['classification']);
        $this->assertSame($serial, $dup['evidence']['serial_id']);
    }

    /** @test */
    public function malformed_or_unknown_filters_are_rejected_instead_of_widening_the_scope(): void
    {
        $s = $this->sale([['qty' => 1, 'sub' => 1000]], paid: 1000, serial: true);
        $return = $this->makeReturnFor($s['lines'][0], 1, ['approval_status' => 'approved', 'status' => 'Awaiting Settlement'], [$s['lines'][0]['serials'][0]->id], 1000);

        foreach ([['--sale' => 'abc'], ['--sale' => '0'], ['--sale' => '-3'], ['--sale' => '1.5'], ['--return' => 'abc'], ['--return' => '0'], ['--setting' => 'abc'], ['--setting' => '0']] as $bad) {
            [$code, $out] = $this->runAudit(array_merge(['--setting' => 1, '--json' => true], $bad));
            $this->assertNotSame(0, $code, json_encode($bad));
            $this->assertNull(json_decode($out, true), 'no report is produced for ' . json_encode($bad));
        }

        // well-formed but unknown (or foreign) ids are also rejected
        $this->seedSetting(3);
        $this->assertNotSame(0, $this->runAudit(['--setting' => 1, '--json' => true, '--sale' => 99999])[0]);
        $this->assertNotSame(0, $this->runAudit(['--setting' => 1, '--json' => true, '--return' => 99999])[0]);
        $this->assertNotSame(0, $this->runAudit(['--setting' => 3, '--json' => true, '--sale' => $s['sale']->id])[0], 'another business\'s sale is not visible');
        $this->assertNotSame(0, $this->runAudit(['--setting' => 99999, '--json' => true])[0]);

        // and valid filters still work
        $this->assertSame(0, $this->runAudit(['--setting' => 1, '--json' => true, '--sale' => $s['sale']->id, '--return' => $return->id])[0]);
    }

    // ------------------------------------------------------------------ zero writes

    /** @test */
    public function audit_display_preview_and_migration_cause_zero_domain_writes(): void
    {
        $s = $this->sale([['qty' => 2, 'sub' => 2000]], paid: 1000, serial: true);
        $return = $this->receivedAndCashSettled($s);
        // an archived source and a deliberately broken record, so the audit has real work to do
        DB::table('sales')->where('id', $s['sale']->id)->update(['archived_at' => now()]);
        $broken = $this->sale([['qty' => 1, 'sub' => 1000]], paid: 1000, serial: true);
        $this->makeReturnFor($broken['lines'][0], 1, ['approval_status' => 'approved', 'status' => 'Awaiting Settlement'], [$broken['lines'][0]['serials'][0]->id], 1000);
        $draftReturn = $broken['lines'][0];
        $previewRow = new Settlement(['sale_return_id' => SaleReturn::query()->where('sale_id', $broken['sale']->id)->value('id'), 'sale_return_detail_id' => \Modules\SalesReturn\Entities\SaleReturnDetail::query()->where('sale_return_id', SaleReturn::query()->where('sale_id', $broken['sale']->id)->value('id'))->value('id'), 'product_serial_number_id' => $draftReturn['serials'][0]->id, 'method' => 'CASH_REFUND']);

        // The additive permission migration is a no-op once the permission is registered (as in any migrated
        // database); make that precondition explicit so test order cannot matter.
        \Spatie\Permission\Models\Permission::findOrCreate('saleReturns.inspect', 'web');

        $before = $this->snapshot();
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        // 1. the audit itself (text and JSON, all filters)
        $this->runAudit(['--setting' => 1]);
        $this->runAudit(['--setting' => 1, '--json' => true, '--sale' => $s['sale']->id]);
        $this->runAudit(['--setting' => 1, '--return' => $return->id]);

        // 2. displays: return page, inspection page
        $this->http()->get(route('sale-returns.show', $return))->assertSuccessful();
        $this->http()->get(route('sale-returns.inspect', $return))->assertSuccessful();

        // 3. the financial preview (resolution) and the coverage rules
        app(\App\Support\SalesReturn\StandardFinancialService::class)->resolve($previewRow);
        \App\Support\SalesReturn\StandardReturnCoverage::returnCompletion($return->fresh());
        app(\App\Support\SalesReturn\SaleReturnLifecycleSyncService::class)->calculateEffectiveStandardReturnCoverage($s['sale']->id);

        // 4. re-running the additive migrations on a populated database
        foreach ([
            'Modules/SalesReturn/Database/Migrations/2026_11_08_000100_create_sale_return_custody_tables.php',
            'Modules/SalesReturn/Database/Migrations/2026_11_08_000200_register_sale_return_inspect_permission.php',
            'Modules/SalesReturn/Database/Migrations/2026_11_09_000100_add_dispatch_identity_to_sale_return_item_settlements.php',
            'Modules/SalesReturn/Database/Migrations/2026_11_10_000100_create_sale_return_adjustments.php',
        ] as $path) {
            (require base_path($path))->up();
        }

        $writes = array_values(array_filter($statements, fn ($sql) => preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $sql)));
        $this->assertSame([], $writes, 'no write statement may run');
        $this->assertSame([], array_values(array_filter($statements, fn ($sql) => stripos($sql, 'for update') !== false)), 'the audit takes no locks');
        $this->assertSame($before, $this->snapshot(), 'every table is byte-identical afterwards');
    }
}
