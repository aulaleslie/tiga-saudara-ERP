<?php

namespace Tests\Feature\SalesReturn\Concurrency;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\SalesReturn\Entities\ReturnSerialRegistration;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnCustody;
use Tests\Feature\SalesReturn\BuildsStandardFlow;
use Tests\TestCase;

/**
 * Task 6.2: competing claims and competing registration against REAL MySQL row locks / unique indexes,
 * with real OS processes calling the production entry points (see registration_worker.php).
 *
 * @group mysql
 */
class RegistrationConcurrencyTest extends TestCase
{
    use DatabaseTruncation, BuildsStandardFlow;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql_test') {
            $this->markTestSkipped('Requires the isolated MySQL test database (phpunit.mysql.xml / scripts/run-mysql-sequence-tests.sh).');
        }
        $this->seedBase();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        try {
            $this->truncateDatabaseTables();
        } finally {
            parent::tearDown();
        }
    }

    /** @param array<int, array<int, string>> $jobs argument lists (mode first), one process each */
    private function race(array $jobs): array
    {
        $barrier = sys_get_temp_dir() . '/sr_registration_barrier_' . uniqid() . '.lock';
        $procs = $pipes = [];
        foreach ($jobs as $i => $args) {
            $cmd = 'php ' . escapeshellarg(__DIR__ . '/registration_worker.php') . ' '
                . implode(' ', array_map('escapeshellarg', array_merge([$args[0], (string) $this->user->id], array_slice($args, 1), [$barrier])));
            $procs[$i] = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
            $this->assertIsResource($procs[$i]);
        }
        usleep(600000);
        touch($barrier);

        $results = [];
        foreach ($jobs as $i => $_) {
            $out = stream_get_contents($pipes[$i][1]);
            $err = stream_get_contents($pipes[$i][2]);
            array_map('fclose', $pipes[$i]);
            $code = proc_close($procs[$i]);
            $this->assertSame(0, $code, "worker {$i} failed: {$err} {$out}");
            preg_match('/RESULT:(.+)/', $out, $m);
            $results[] = trim($m[1] ?? 'none');
        }
        @unlink($barrier);

        return $results;
    }

    private function untracked(int $quantity): array
    {
        $flow = $this->makeDispatchedSale($quantity, false);
        $flow['product']->update(['serial_number_required' => true]);

        return $flow;
    }

    private function draftWithPending(array $flow, array $texts): SaleReturn
    {
        $return = $this->makeReturnFor($flow, count($texts), ['approval_status' => 'approved', 'status' => 'Awaiting Receiving']);
        $detail = $return->saleReturnDetails()->first();
        app(\App\Support\SalesReturn\SerialRegistration\ReturnSerialRegistrationService::class)->saveStandardProposals($detail, [
            'pending_serials' => $texts, 'claim_uuids' => [],
        ]);

        return $return;
    }

    /** @test */
    public function two_returns_registering_the_same_serial_commit_exactly_one_registration(): void
    {
        $flow = $this->untracked(4);
        $a = $this->draftWithPending($flow, ['DUP-1', 'A-ONLY']);
        $b = $this->draftWithPending($flow, ['DUP-1', 'B-ONLY']);

        $results = $this->race([['receive', (string) $a->id], ['receive', (string) $b->id]]);

        $this->assertSame(1, ProductSerialNumber::query()->where('serial_number', 'DUP-1')->count(), implode(',', $results));
        $this->assertSame(1, SaleReturn::query()->whereNotNull('received_at')->count(), 'the loser rolled back completely: ' . implode(',', $results));
        $this->assertSame(2, ProductSerialNumber::query()->count(), 'only the winner\'s two serials exist (no half registration)');
        $this->assertSame(2, SaleReturnCustody::query()->count());
        $this->assertSame(2, ReturnSerialRegistration::query()->whereNotNull('registered_serial_id')->count());
        $loser = SaleReturn::query()->whereNull('received_at')->sole();
        $this->assertSame(2, ReturnSerialRegistration::query()->where('sale_return_id', $loser->id)->whereNull('registered_serial_id')->count(), 'loser keeps its proposed text');
    }

    /** @test */
    public function receiving_racing_a_purchase_style_creator_of_the_same_serial_never_duplicates_or_half_commits(): void
    {
        $flow = $this->untracked(2);
        $return = $this->draftWithPending($flow, ['RACE-1', 'RACE-2']);

        $results = $this->race([['receive', (string) $return->id], ['serial', (string) $flow['product']->id, 'RACE-1']]);

        $this->assertSame(1, ProductSerialNumber::query()->where('serial_number', 'RACE-1')->count(), implode(',', $results));
        $received = (bool) $return->fresh()->received_at;
        if ($received) {
            $this->assertContains('duplicate', $results, 'the purchase side lost the unique index');
            $this->assertSame(2, SaleReturnCustody::query()->count());
            $this->assertSame(2, ReturnSerialRegistration::query()->whereNotNull('registered_serial_id')->count());
        } else {
            $this->assertSame(1, ProductSerialNumber::query()->count(), 'receiving rolled back; only the competing creator exists');
            $this->assertSame(0, SaleReturnCustody::query()->count());
            $this->assertSame(0, ReturnSerialRegistration::query()->whereNotNull('registered_serial_id')->count());
        }
    }

    /** @test */
    public function competing_returns_cannot_claim_more_units_than_the_source_has_even_with_different_serials(): void
    {
        $flow = $this->untracked(3);

        $results = $this->race([
            ['create', (string) $flow['sale']->id, (string) $flow['dispatchDetail']->id, (string) $flow['product']->id, 'X-1,X-2'],
            ['create', (string) $flow['sale']->id, (string) $flow['dispatchDetail']->id, (string) $flow['product']->id, 'Y-1,Y-2'],
        ]);

        $claimed = (int) \Modules\SalesReturn\Entities\SaleReturnDetail::query()->sum('quantity');
        $this->assertLessThanOrEqual(3, $claimed, 'committed claims never exceed the source quantity: ' . implode(',', $results));
        $this->assertSame(1, SaleReturn::query()->count(), implode(',', $results));
        $this->assertSame(2, ReturnSerialRegistration::query()->count());
        $this->assertSame(0, ProductSerialNumber::query()->count(), 'drafts never create serial inventory');
    }
}
