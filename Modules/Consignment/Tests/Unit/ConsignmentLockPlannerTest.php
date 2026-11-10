<?php

namespace Modules\Consignment\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutAuditEvent;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Services\ConsignmentLockPlanner;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Consignment\Tests\Support\BuildsFinalizedBuyout;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Tests\TestCase;

/**
 * Lock-retry protocol: a changed resource set rolls the WHOLE transaction back and retries with the complete, sorted
 * lock set - no earlier-sorted key is ever acquired late. Deterministic SQLite; real-lock races need MySQL.
 */
class ConsignmentLockPlannerTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;
    use BuildsFinalizedBuyout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinalizationFixtures();
    }

    protected function tearDown(): void
    {
        ConsignmentLockPlanner::$afterDiscovery = null;
        ConsignmentLockPlanner::$afterLock = null;
        parent::tearDown();
    }

    private function secondProduct(): Product
    {
        return Product::create(['setting_id' => $this->setting->id, 'product_name' => 'W2', 'product_code' => 'W-2-' . uniqid(),
            'product_unit' => $this->product->product_unit, 'product_price' => 1, 'product_cost' => 1, 'product_quantity' => 5]);
    }

    /** Records the (setting, product) of every coordination SELECT, in execution order. */
    private function trackLocks(): \Closure
    {
        $log = [];
        DB::listen(function ($q) use (&$log) {
            if (str_starts_with(strtolower($q->sql), 'select') && str_contains($q->sql, 'consignment_inventory_coordinations')) {
                $log[] = array_map('intval', array_slice($q->bindings, 0, 2));
            }
        });

        return function () use (&$log) {
            $out = $log;
            $log = [];

            return $out;
        };
    }

    /** @test */
    public function a_grown_resource_set_rolls_back_every_partial_write_and_retries_with_the_full_sorted_set(): void
    {
        $b = $this->buyout($this->supplier);
        $p2 = $this->secondProduct();
        $drain = $this->trackLocks();
        $attempts = [];
        $discovered = [[$this->setting->id, $this->product->id]];
        $actual = [[$this->setting->id, $p2->id], [$this->setting->id, $this->product->id]];   // unsorted on purpose

        $result = ConsignmentLockPlanner::run(
            fn () => $discovered,
            function ($cover, $attempt) use ($b, $actual, &$attempts) {
                $attempts[] = $attempt;
                // A partial write happens BEFORE the set is found to be incomplete.
                ConsignmentBuyoutAuditEvent::create(['consignment_buyout_id' => $b->id, 'action' => "PROBE-{$attempt}"]);
                $cover($actual);

                return 'done';
            }
        );

        $this->assertSame('done', $result);
        $this->assertSame([1, 2], $attempts);
        $this->assertSame(['PROBE-2'], ConsignmentBuyoutAuditEvent::pluck('action')->map(fn ($a) => strtoupper($a))->all(), 'attempt 1 wrote nothing that survived');

        $selects = $drain();
        // attempt 1 locked only the discovered key; attempt 2 locked the complete set in ascending order.
        $this->assertSame([$this->setting->id, $this->product->id], $selects[0]);
        $second = array_slice($selects, 1);
        $sorted = $second;
        usort($sorted, fn ($a, $c) => $a <=> $c);
        $this->assertSame($sorted, $second, 'full set acquired in sorted order');
        $this->assertEqualsCanonicalizing([[$this->setting->id, $this->product->id], [$this->setting->id, $p2->id]], $second);
    }

    /** @test */
    public function a_set_that_never_settles_fails_cleanly_without_leaving_writes(): void
    {
        $b = $this->buyout($this->supplier);
        $products = [$this->product->id];
        for ($i = 0; $i < 6; $i++) {
            $products[] = $this->secondProduct()->id;
        }
        $n = 0;

        try {
            ConsignmentLockPlanner::run(
                fn () => [[$this->setting->id, $this->product->id]],
                function ($cover) use ($b, $products, &$n) {
                    $n++;
                    ConsignmentBuyoutAuditEvent::create(['consignment_buyout_id' => $b->id, 'action' => 'PROBE']);
                    // every attempt discovers one more product
                    $cover(array_map(fn ($id) => [$this->setting->id, $id], array_slice($products, 0, $n + 1)));
                }
            );
            $this->fail('should give up');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('kept changing', $e->getMessage());
        }
        $this->assertSame(ConsignmentLockPlanner::MAX_ATTEMPTS, $n);
        $this->assertSame(0, ConsignmentBuyoutAuditEvent::count());
    }

    /** @test */
    public function a_stable_set_runs_once_and_ordinary_exceptions_are_not_retried(): void
    {
        $runs = 0;
        $pair = [$this->setting->id, $this->product->id];
        $out = ConsignmentLockPlanner::run(fn () => [$pair], function ($cover) use (&$runs, $pair) {
            $runs++;
            $cover([$pair]);

            return 'ok';
        });
        $this->assertSame(['ok', 1], [$out, $runs]);

        $runs = 0;
        try {
            ConsignmentLockPlanner::run(fn () => [[$this->setting->id, $this->product->id]], function () use (&$runs) {
                $runs++;
                throw new \DomainException('business rule');
            });
        } catch (\DomainException $e) {
            $this->assertSame('business rule', $e->getMessage());
        }
        $this->assertSame(1, $runs);
    }

    /** @test */
    public function buyout_approval_retries_when_a_line_for_another_product_appears_after_discovery(): void
    {
        $p2 = $this->secondProduct();
        $r1 = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $r2 = $this->makeReceiptFor($p2, '10');
        ProductStock::create(['product_id' => $p2->id, 'location_id' => $this->location->id, 'quantity' => 10, 'quantity_tax' => 0,
            'quantity_non_tax' => 10, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0]);

        $unsold = fn (ConsignmentReceivingDetail $r, string $q) => ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id,
            'destination_location_id' => $this->dest1->id, 'base_quantity' => $q, 'buckets' => ['NON_TAXED' => $q]];
        $b = $this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [$unsold($r1, '2'), $unsold($r2, '3')], $this->user, $this->meta());
        $b = $this->life->submit($b, $this->user);

        // Make discovery see only product 1 for the first attempt (as if the second line arrived concurrently).
        $full = $this->life->pairsOf($b);
        $partial = array_values(array_filter($full, fn ($p) => $p[1] === $this->product->id));
        $this->assertCount(2, $full);
        $drain = $this->trackLocks();
        $attempts = 0;
        ConsignmentLockPlanner::$afterDiscovery = function ($attempt, $pairs) use (&$attempts) {
            $attempts = $attempt;
        };
        // Discovery override: first attempt sees the partial set. Re-run the real approve through a subclass seam.
        $svc = new class($partial) extends \Modules\Consignment\Services\ConsignmentBuyoutLifecycleService {
            public function __construct(private array $firstSet)
            {
                parent::__construct();
            }

            public function pairsOf(ConsignmentBuyout $buyout): array
            {
                static $calls = 0;

                return $calls++ === 0 ? $this->firstSet : parent::pairsOf($buyout);
            }
        };

        $approved = $svc->approve($b, $this->user);

        $this->assertSame('APPROVED', $approved->status);
        $this->assertSame(2, $attempts, 'first attempt rolled back, second attempt completed');
        $this->assertSame(4, $approved->reservations()->where('status', 'ACTIVE')->count());   // 2 lines x (receipt + bucket)
        $selects = $drain();
        $this->assertSame([$this->setting->id, $this->product->id], $selects[0], 'attempt 1 locked only the discovered key');
        $retry = array_slice($selects, 1, 2);
        $this->assertSame($retry, [[$this->setting->id, min($this->product->id, $p2->id)], [$this->setting->id, max($this->product->id, $p2->id)]], 'full set, ascending');
    }

    private function makeReceiptFor(Product $product, string $qty): ConsignmentReceivingDetail
    {
        $d = $this->receipt($this->supplier, $qty);
        DB::table('consignment_receiving_details')->where('id', $d->id)->update(['product_id' => $product->id]);
        DB::table('consignment_receival_lines')->where('id', $d->consignment_receival_line_id)->update(['product_id' => $product->id]);

        return $d->fresh();
    }

    /** @test */
    public function finalize_and_reverse_use_the_same_complete_lock_protocol(): void
    {
        [$b] = $this->mixed(null, 120.0);
        $seen = [];
        ConsignmentLockPlanner::$afterLock = function ($attempt, $pairs) use (&$seen) {
            $seen[] = $pairs;
        };
        $this->fin->finalize($b->fresh(), $this->user);
        (new \Modules\Consignment\Services\ConsignmentBuyoutReversalService())->reverse($b->fresh(), $this->user, 'x');

        $this->assertCount(2, $seen, 'one locked attempt for finalize, one for reverse');
        foreach ($seen as $pairs) {
            $this->assertSame([[$this->setting->id, $this->product->id]], $pairs);
        }
    }

    /** @test */
    public function a_retried_approval_delivers_notifications_once_and_only_after_commit_and_a_rolled_back_one_delivers_none(): void
    {
        $p2 = $this->secondProduct();
        $r1 = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $r2 = $this->makeReceiptFor($p2, '10');
        ProductStock::create(['product_id' => $p2->id, 'location_id' => $this->location->id, 'quantity' => 10, 'quantity_tax' => 0,
            'quantity_non_tax' => 10, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0]);
        $unsold = fn (ConsignmentReceivingDetail $r, string $q) => ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id,
            'destination_location_id' => $this->dest1->id, 'base_quantity' => $q, 'buckets' => ['NON_TAXED' => $q]];
        $b = $this->life->submit($this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10',
            [$unsold($r1, '2'), $unsold($r2, '3')], $this->user, $this->meta()), $this->user);

        $partial = array_values(array_filter($this->life->pairsOf($b), fn ($p) => $p[1] === $this->product->id));
        $deliveries = [];
        $baseline = DB::transactionLevel();
        $svc = new class($partial, $deliveries) extends \Modules\Consignment\Services\ConsignmentBuyoutLifecycleService {
            public function __construct(private array $firstSet, public array &$log)
            {
                parent::__construct();
            }

            public function pairsOf(ConsignmentBuyout $buyout): array
            {
                static $calls = 0;

                return $calls++ === 0 ? $this->firstSet : parent::pairsOf($buyout);
            }

            protected function deliverNotification(string $what, ConsignmentBuyout $buyout, string $reason = ''): void
            {
                $this->log[] = [$what, DB::transactionLevel()];
            }
        };

        $attempts = 0;
        ConsignmentLockPlanner::$afterDiscovery = function ($attempt) use (&$attempts) {
            $attempts = $attempt;
        };
        $this->assertSame('APPROVED', $svc->approve($b, $this->user)->status);

        $this->assertSame(2, $attempts, 'the first attempt was rolled back and retried');
        $this->assertSame([['resolve', $baseline]], $deliveries, 'one delivery, at the outermost (post-commit) transaction level, not inside the retried attempt');
    }

    /** @test */
    public function a_failed_approval_delivers_no_notification(): void
    {
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $b = $this->life->submit($this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id, 'destination_location_id' => $this->dest1->id,
            'base_quantity' => '2', 'buckets' => ['NON_TAXED' => '2']]], $this->user, $this->meta()), $this->user);
        DB::table('product_stocks')->update(['quantity_non_tax' => 0, 'quantity' => 0]);   // validation will refuse

        $deliveries = [];
        $svc = new class($deliveries) extends \Modules\Consignment\Services\ConsignmentBuyoutLifecycleService {
            public function __construct(public array &$log)
            {
                parent::__construct();
            }

            protected function deliverNotification(string $what, ConsignmentBuyout $buyout, string $reason = ''): void
            {
                $this->log[] = $what;
            }
        };
        try {
            $svc->approve($b, $this->user);
            $this->fail('approval should have been refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot be approved', $e->getMessage());
        }
        $this->assertSame([], $deliveries);
        $this->assertSame('WAITING_APPROVAL', $b->fresh()->status);
    }

    /** @test */
    public function a_retried_reversal_locks_the_purchase_before_the_coordination_rows_on_every_attempt(): void
    {
        [$b] = $this->mixed(null, 120.0);
        $purchase = $this->fin->finalize($b->fresh(), $this->user);
        $p2 = $this->secondProduct();

        $log = [];
        $thrown = null;
        DB::listen(function ($q) use (&$log, $purchase) {
            $sql = strtolower($q->sql);
            if (! str_starts_with($sql, 'select')) {
                return;
            }
            if (str_contains($sql, 'from "purchases"') && str_contains($sql, '"id" = ?') && in_array($purchase->id, $q->bindings)) {
                $log[] = 'purchase';
            } elseif (str_contains($sql, 'consignment_inventory_coordinations')) {
                $log[] = 'coord:' . $q->bindings[1];
            }
        });
        ConsignmentLockPlanner::$afterDiscovery = function ($attempt) use (&$log, $b, $p2) {
            $log[] = "attempt:{$attempt}";
            if ($attempt === 1) {
                // A concurrent change adds a line for another product after discovery: the set must grow and retry.
                $row = (array) DB::table('consignment_buyout_lines')->where('consignment_buyout_id', $b->id)->orderBy('id')->first();
                unset($row['id']);
                unset($row['sold_source_key'], $row['destination_key']);   // generated columns
                $row['line_type'] = 'UNSOLD_BUYOUT';
                $row['consignment_sold_source_id'] = null;
                $row['destination_location_id'] = $this->dest2->id;
                $row['product_id'] = $p2->id;
                DB::table('consignment_buyout_lines')->insert($row);
            }
        };

        try {
            (new \Modules\Consignment\Services\ConsignmentBuyoutReversalService())->reverse($b->fresh(), $this->user, 'retry order');
        } catch (\Throwable $e) {
            $thrown = get_class($e) . ': ' . $e->getMessage();
            // The injected extra line has no postings, so the attempt may legitimately refuse; only the lock order matters here.
        }

        $segments = [];
        $current = null;
        foreach ($log as $entry) {
            if (str_starts_with($entry, 'attempt:')) {
                $current = $entry;
                $segments[$current] = [];
            } elseif ($current) {
                $segments[$current][] = $entry;
            }
        }
        $this->assertGreaterThanOrEqual(2, count($segments), 'the grown resource set caused a retry: ' . json_encode($log) . ' / ' . ($thrown ?? 'no exception'));
        foreach ($segments as $name => $entries) {
            $this->assertSame('purchase', $entries[0] ?? null, "{$name}: the Purchase is locked before any coordination row: " . json_encode($entries));
            $coords = array_values(array_filter($entries, fn ($e) => str_starts_with($e, 'coord:')));
            $sorted = $coords;
            sort($sorted, SORT_NATURAL);
            $this->assertSame($sorted, $coords, "{$name}: coordination rows ascend");
        }
        $retry = array_values(array_filter($segments['attempt:2'], fn ($e) => str_starts_with($e, 'coord:')));
        $this->assertContains('coord:' . $p2->id, $retry, 'the retry locks the full, grown set');
    }
}
