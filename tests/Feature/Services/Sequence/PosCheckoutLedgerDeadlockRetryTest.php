<?php

namespace Tests\Feature\Services\Sequence;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Services\FinalizePosCheckoutService;
use Modules\Setting\Entities\Setting;
use PDOException;
use Tests\TestCase;

/**
 * Focused coverage for the FinalizePosCheckoutService::resolveCheckoutLedger()
 * deadlock/idempotency-race retry gap: MySQL deadlocks (1213 / SQLSTATE 40001)
 * previously propagated uncaught because the ledger-resolution transaction only
 * retried the exact idempotency-key unique race. See
 * Modules/Pos/Services/FinalizePosCheckoutService.php:
 * executeLedgerResolutionWithConflictRetry(), isDeadlockConflict(),
 * isCheckoutIdempotencyUniqueConflict().
 */
class PosCheckoutLedgerDeadlockRetryTest extends TestCase
{
    use RefreshDatabase;

    private FinalizePosCheckoutService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(FinalizePosCheckoutService::class);

        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);
    }

    public function test_deadlock_on_first_attempt_succeeds_on_retry(): void
    {
        $initialLevel = $this->breakOuterTransactionForOutermostTest();
        $attempts = 0;

        try {
            $result = $this->service->executeLedgerResolutionWithConflictRetry(function () use (&$attempts) {
                $attempts++;
                if ($attempts === 1) {
                    throw $this->makeDeadlockException();
                }

                return ['checkout' => null, 'replay_payload' => ['ok' => true]];
            });

            $this->assertEquals(2, $attempts);
            $this->assertEquals(['ok' => true], $result['replay_payload']);
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_deadlock_succeeds_within_three_total_attempts(): void
    {
        $initialLevel = $this->breakOuterTransactionForOutermostTest();
        $attempts = 0;

        try {
            $result = $this->service->executeLedgerResolutionWithConflictRetry(function () use (&$attempts) {
                $attempts++;
                if ($attempts < 3) {
                    throw $this->makeDeadlockException();
                }

                return ['checkout' => null, 'replay_payload' => ['ok' => true]];
            });

            $this->assertEquals(3, $attempts);
            $this->assertEquals(['ok' => true], $result['replay_payload']);
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_third_deadlock_is_terminal_and_a_fourth_attempt_never_runs(): void
    {
        $initialLevel = $this->breakOuterTransactionForOutermostTest();
        $attempts = 0;

        try {
            try {
                $this->service->executeLedgerResolutionWithConflictRetry(function () use (&$attempts) {
                    $attempts++;
                    throw $this->makeDeadlockException();
                });
                $this->fail('Expected QueryException to propagate after budget exhaustion.');
            } catch (QueryException $e) {
                $this->assertEquals(3, $attempts, 'Deadlock must receive at most 3 total attempts.');
            }
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_unique_idempotency_race_resolves_via_one_retry(): void
    {
        $initialLevel = $this->breakOuterTransactionForOutermostTest();
        $attempts = 0;

        try {
            $result = $this->service->executeLedgerResolutionWithConflictRetry(function () use (&$attempts) {
                $attempts++;
                if ($attempts === 1) {
                    throw $this->makeCheckoutIdempotencyUniqueException();
                }

                return ['checkout' => null, 'replay_payload' => ['winner' => true]];
            });

            $this->assertEquals(2, $attempts);
            $this->assertEquals(['winner' => true], $result['replay_payload']);
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_unrelated_unique_violation_is_not_retried(): void
    {
        $initialLevel = $this->breakOuterTransactionForOutermostTest();
        $attempts = 0;

        try {
            try {
                $this->service->executeLedgerResolutionWithConflictRetry(function () use (&$attempts) {
                    $attempts++;
                    throw $this->makeUnrelatedUniqueException();
                });
                $this->fail('Expected QueryException to propagate immediately.');
            } catch (QueryException $e) {
                $this->assertEquals(1, $attempts, 'An unrelated unique violation must never be retried.');
            }
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_non_conflict_query_exception_is_not_retried(): void
    {
        $initialLevel = $this->breakOuterTransactionForOutermostTest();
        $attempts = 0;

        try {
            try {
                $this->service->executeLedgerResolutionWithConflictRetry(function () use (&$attempts) {
                    $attempts++;
                    throw $this->makeGenericQueryException();
                });
                $this->fail('Expected QueryException to propagate immediately.');
            } catch (QueryException $e) {
                $this->assertEquals(1, $attempts);
            }
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_validation_exception_is_not_retried(): void
    {
        $initialLevel = $this->breakOuterTransactionForOutermostTest();
        $attempts = 0;

        try {
            try {
                $this->service->executeLedgerResolutionWithConflictRetry(function () use (&$attempts) {
                    $attempts++;
                    throw new \RuntimeException('domain failure, not a QueryException');
                });
                $this->fail('Expected RuntimeException to propagate immediately.');
            } catch (\RuntimeException $e) {
                $this->assertEquals(1, $attempts);
            }
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_nested_existing_transaction_executes_once_and_bubbles_deadlock(): void
    {
        $attempts = 0;

        // When a caller already owns an active transaction, executeLedgerResolutionWithConflictRetry()
        // must invoke the callback directly (no DB::transaction() wrapper) — opening a nested
        // transaction here would create a SAVEPOINT, which contradicts the "no local retry, no
        // savepoint" contract documented on the method and would let a deadlock be silently
        // absorbed at the savepoint boundary before the real outer owner ever saw it.
        $executedSql = [];
        DB::listen(function ($query) use (&$executedSql) {
            $executedSql[] = $query->sql;
        });

        DB::beginTransaction();
        try {
            try {
                $this->service->executeLedgerResolutionWithConflictRetry(function () use (&$attempts) {
                    $attempts++;
                    throw $this->makeDeadlockException();
                });
                $this->fail('Expected the deadlock to bubble to the transaction owner.');
            } catch (QueryException $e) {
                $this->assertEquals(1, $attempts, 'Inside an existing transaction, the callback must execute exactly once.');
                $this->assertSame($this->makeDeadlockException()::class, $e::class);
                $this->assertStringContainsString('1213', $e->getMessage(), 'The original deadlock QueryException must propagate unwrapped.');
            }
        } finally {
            DB::rollBack();
        }

        foreach ($executedSql as $sql) {
            $this->assertStringNotContainsStringIgnoringCase(
                'savepoint',
                $sql,
                'No SAVEPOINT must be issued when resolution executes inside an existing caller-owned transaction.'
            );
        }
    }

    public function test_rolled_back_attempts_leave_no_partial_checkout_row(): void
    {
        $setting = $this->makeSetting();
        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'PT-RETRY',
            'name' => 'Retry Test Terminal',
            'is_active' => true,
        ]);
        $cashier = User::factory()->create();
        $session = PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'status' => PosSession::STATUS_OPEN,
            'opening_float_total' => 0,
            'opened_at' => now(),
        ]);
        $initialLevel = $this->breakOuterTransactionForOutermostTest();
        $attempts = 0;

        try {
            $this->service->executeLedgerResolutionWithConflictRetry(function () use (&$attempts, $setting, $terminal, $cashier, $session) {
                $attempts++;
                if ($attempts === 1) {
                    // Simulate a partial write inside the failed attempt's transaction —
                    // it must never survive the rollback triggered by the deadlock.
                    PosCheckout::query()->create([
                        'setting_id' => $setting->id,
                        'pos_session_id' => $session->id,
                        'terminal_id' => $terminal->id,
                        'cashier_user_id' => $cashier->id,
                        'customer_id' => null,
                        'status' => PosCheckout::STATUS_FINALIZING,
                        'idempotency_key' => 'partial-attempt-key',
                        'payload_hash' => str_repeat('a', 64),
                        'subtotal' => 0,
                        'discount_total' => 0,
                        'tax_total' => 0,
                        'grand_total' => 0,
                        'paid_total' => 0,
                        'change_total' => 0,
                    ]);

                    throw $this->makeDeadlockException();
                }

                return ['checkout' => null, 'replay_payload' => ['ok' => true]];
            });

            $this->assertEquals(
                0,
                PosCheckout::query()->where('idempotency_key', 'partial-attempt-key')->count(),
                'A rolled-back attempt must leave no partial PosCheckout row.'
            );
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_is_deadlock_conflict_matches_mysql_1213_and_sqlstate_40001(): void
    {
        $this->assertTrue($this->service->isDeadlockConflict($this->makeDeadlockException()));
    }

    public function test_is_deadlock_conflict_does_not_match_lock_wait_timeout_1205(): void
    {
        $previous = new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded');
        $previous->errorInfo = ['HY000', 1205];
        $exception = new QueryException('sqlite', 'insert into pos_checkouts ...', [], $previous);

        $this->assertFalse($this->service->isDeadlockConflict($exception));
    }

    public function test_is_checkout_idempotency_unique_conflict_matches_sqlite_exact_columns(): void
    {
        $this->assertTrue($this->service->isCheckoutIdempotencyUniqueConflict($this->makeCheckoutIdempotencyUniqueException()));
    }

    public function test_is_checkout_idempotency_unique_conflict_rejects_unrelated_unique_violation(): void
    {
        $this->assertFalse($this->service->isCheckoutIdempotencyUniqueConflict($this->makeUnrelatedUniqueException()));
    }

    public function test_is_checkout_idempotency_unique_conflict_matches_mysql_index_name(): void
    {
        $previous = new PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-abc' for key 'pos_checkouts_setting_idempotency_unique'"
        );
        $previous->errorInfo = ['23000', 1062];
        $exception = new QueryException('sqlite', 'insert into pos_checkouts ...', [], $previous);

        $this->assertTrue($this->service->isCheckoutIdempotencyUniqueConflict($exception));
    }

    private function makeDeadlockException(): QueryException
    {
        $previous = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
        $previous->errorInfo = ['40001', 1213];

        return new QueryException('sqlite', 'insert into pos_checkouts ...', [], $previous);
    }

    private function makeCheckoutIdempotencyUniqueException(): QueryException
    {
        $previous = new PDOException(
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: pos_checkouts.setting_id, pos_checkouts.idempotency_key'
        );
        $previous->errorInfo = ['23000', 19];

        return new QueryException('sqlite', 'insert into pos_checkouts ...', [], $previous);
    }

    private function makeUnrelatedUniqueException(): QueryException
    {
        $previous = new PDOException(
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: pos_checkouts.receipt_number'
        );
        $previous->errorInfo = ['23000', 19];

        return new QueryException('sqlite', 'insert into pos_checkouts ...', [], $previous);
    }

    private function makeGenericQueryException(): QueryException
    {
        $previous = new PDOException('SQLSTATE[HY000]: General error: 1 no such table: pos_checkouts');
        $previous->errorInfo = ['HY000', 1];

        return new QueryException('sqlite', 'insert into pos_checkouts ...', [], $previous);
    }

    private function makeSetting(): Setting
    {
        return Setting::create([
            'company_name' => 'Ledger Retry Test',
            'company_email' => 'ledger-retry@example.com',
            'company_phone' => '000',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'ledger-retry@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Addr',
            'document_prefix' => 'PD-LR',
            'purchase_prefix_document' => 'PR',
            'sale_prefix_document' => 'SL',
        ]);
    }

    /**
     * Commits the RefreshDatabase outer transaction so executeLedgerResolutionWithConflictRetry
     * can act as the true outermost transaction boundary — otherwise DB::transactionLevel() > 0
     * routes it into the single-execution "existing transaction" branch instead of the retry loop.
     */
    private function breakOuterTransactionForOutermostTest(): int
    {
        $initialLevel = DB::transactionLevel();
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }

        return $initialLevel;
    }

    private function restoreOuterTransaction(int $initialLevel): void
    {
        DB::table('pos_checkouts')->delete();
        DB::table('pos_sessions')->delete();
        DB::table('pos_terminals')->delete();
        DB::table('settings')->delete();
        DB::table('users')->delete();
        DB::table('currencies')->truncate();

        while (DB::transactionLevel() < $initialLevel) {
            DB::beginTransaction();
        }
    }
}
