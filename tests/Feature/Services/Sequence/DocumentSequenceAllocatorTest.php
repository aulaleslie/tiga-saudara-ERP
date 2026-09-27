<?php

namespace Tests\Feature\Services\Sequence;

use App\Services\Sequence\DocumentReferenceFormatter;
use App\Services\Sequence\DocumentSequence;
use App\Services\Sequence\DocumentSequenceAllocator;
use App\Services\Sequence\DocumentType;
use App\Services\Sequence\SequenceNamespace;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class DocumentSequenceAllocatorTest extends TestCase
{
    use RefreshDatabase;

    private Setting $settingA;
    private Setting $settingB;
    private DocumentSequenceAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->settingA = Setting::create([
            'company_name' => 'Business A',
            'company_email' => 'a@example.com',
            'company_phone' => '111',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'a@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Addr A',
            'document_prefix' => 'PD-BL',
            'purchase_prefix_document' => 'PR',
            'sale_prefix_document' => 'SL',
        ]);

        $this->settingB = Setting::create([
            'company_name' => 'Business B',
            'company_email' => 'b@example.com',
            'company_phone' => '222',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'b@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Addr B',
            'document_prefix' => 'PD-JK',
            'purchase_prefix_document' => 'PR',
            'sale_prefix_document' => 'SL',
        ]);

        $this->allocator = new DocumentSequenceAllocator();
    }

    public function test_allocation_increments_and_formats_monotonically(): void
    {
        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        $alloc1 = DB::transaction(fn() => $this->allocator->allocate($ns));
        $this->assertEquals(1, $alloc1->number);
        $this->assertEquals('PD-BL-PR-2026-08-00001', $alloc1->reference);

        $alloc2 = DB::transaction(fn() => $this->allocator->allocate($ns));
        $this->assertEquals(2, $alloc2->number);
        $this->assertEquals('PD-BL-PR-2026-08-00002', $alloc2->reference);

        // Sequence row check
        $seqRow = DocumentSequence::where('setting_id', $this->settingA->id)
            ->where('document_type', 'purchase')
            ->first();
        $this->assertNotNull($seqRow);
        $this->assertEquals(2, $seqRow->last_number);
    }

    public function test_independent_namespaces_have_isolated_counters(): void
    {
        $nsPurchase = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');
        $nsSale = $this->allocator->buildNamespace(DocumentType::SALE, $this->settingA->id, '2026-08-15');
        $nsBusinessB = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingB->id, '2026-08-15');

        $p1 = DB::transaction(fn() => $this->allocator->allocate($nsPurchase));
        $s1 = DB::transaction(fn() => $this->allocator->allocate($nsSale));
        $b1 = DB::transaction(fn() => $this->allocator->allocate($nsBusinessB));

        $this->assertEquals('PD-BL-PR-2026-08-00001', $p1->reference);
        $this->assertEquals('PD-BL-SL-2026-08-00001', $s1->reference);
        $this->assertEquals('PD-JK-PR-2026-08-00001', $b1->reference);
    }

    public function test_rollback_does_not_advance_counter_permanently(): void
    {
        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        // First successful transaction
        DB::transaction(fn() => $this->allocator->allocate($ns));

        // Second transaction fails and rolls back
        try {
            DB::transaction(function () use ($ns) {
                $this->allocator->allocate($ns);
                throw new \Exception("Rollback simulation");
            });
        } catch (\Exception $e) {
            // expected
        }

        // Third transaction should get number 2 (not 3)
        $alloc3 = DB::transaction(fn() => $this->allocator->allocate($ns));
        $this->assertEquals(2, $alloc3->number);
        $this->assertEquals('PD-BL-PR-2026-08-00002', $alloc3->reference);
    }

    public function test_stale_counter_reconciliation_advances_forward(): void
    {
        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        // Create historical purchase with reference 00181
        Supplier::create([
            'supplier_name' => 'Supplier',
            'supplier_email' => 's@test.com',
            'supplier_phone' => '123',
            'address' => 'Addr',
            'city' => 'City',
            'country' => 'Country',
            'setting_id' => $this->settingA->id,
        ]);
        PaymentTerm::create([
            'name' => 'COD',
            'longevity' => 0,
            'setting_id' => $this->settingA->id,
        ]);

        DB::table('purchases')->insert([
            'date' => '2026-08-01',
            'due_date' => '2026-08-01',
            'reference' => 'PD-BL-PR-2026-08-00181',
            'supplier_id' => Supplier::first()->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
            'setting_id' => $this->settingA->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sequence row starts at 0 or empty
        $reconciled = $this->allocator->reconcileCounter($ns);
        $this->assertEquals(181, $reconciled);

        // Next allocation gives 182
        $alloc = DB::transaction(fn() => $this->allocator->allocate($ns));
        $this->assertEquals(182, $alloc->number);
        $this->assertEquals('PD-BL-PR-2026-08-00182', $alloc->reference);
    }

    public function test_whole_operation_retry_recovers_from_stale_counter_inside_document_transaction(): void
    {
        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        Supplier::create([
            'supplier_name' => 'Supplier',
            'supplier_email' => 's@test.com',
            'supplier_phone' => '123',
            'address' => 'Addr',
            'city' => 'City',
            'country' => 'Country',
            'setting_id' => $this->settingA->id,
        ]);
        PaymentTerm::create([
            'name' => 'COD',
            'longevity' => 0,
            'setting_id' => $this->settingA->id,
        ]);

        // Existing row in database with reference 00001
        DB::table('purchases')->insert([
            'date' => '2026-08-01',
            'due_date' => '2026-08-01',
            'reference' => 'PD-BL-PR-2026-08-00001',
            'supplier_id' => Supplier::first()->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_term_id' => PaymentTerm::first()->id,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'payment_method' => 'Cash',
            'setting_id' => $this->settingA->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Temporarily commit the test-level transaction so executeWholeOperationWithConflictRetry runs as the outermost boundary
        $initialLevel = $this->breakOuterTransactionForOutermostTest();

        try {
            // The whole-operation owner opens the transaction. DocumentReferenceService
            // therefore allocates without a nested savepoint. The first insert collides,
            // the owner rolls back, reconciles to 1, and restarts the complete operation.
            $purchase = $this->allocator->executeWholeOperationWithConflictRetry(
                fn () => \App\Services\DocumentReferenceService::createPurchaseWithReference([
                    'date' => '2026-08-15',
                    'due_date' => '2026-08-15',
                    'supplier_id' => Supplier::first()->id,
                    'status' => Purchase::STATUS_DRAFTED,
                    'payment_status' => 'Unpaid',
                    'payment_term_id' => PaymentTerm::first()->id,
                    'setting_id' => $this->settingA->id,
                    'is_tax_included' => false,
                    'total_amount' => 100,
                    'paid_amount' => 0,
                    'due_amount' => 100,
                    'payment_method' => 'Cash',
                ]),
                static fn (): array => [$ns]
            );

            $this->assertEquals('PD-BL-PR-2026-08-00002', $purchase->reference);
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_whole_operation_retry_without_active_transaction_owns_outermost_boundary(): void
    {
        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        $initialLevel = $this->breakOuterTransactionForOutermostTest();

        try {
            $result = $this->allocator->executeWholeOperationWithConflictRetry(
                fn () => DB::transaction(fn () => $this->allocator->allocate($ns)),
                static fn (): array => [$ns]
            );

            $this->assertEquals(1, $result->number);
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_whole_operation_retry_inside_existing_transaction_executes_once_without_savepoint(): void
    {
        // We are already inside RefreshDatabase's outer transaction here.
        $this->assertGreaterThan(0, DB::transactionLevel());
        $levelBefore = DB::transactionLevel();

        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        $result = $this->allocator->executeWholeOperationWithConflictRetry(
            fn () => $this->allocator->allocate($ns),
            static fn (): array => [$ns]
        );

        $this->assertEquals(1, $result->number);
        // No nested transaction/savepoint was opened or closed.
        $this->assertEquals($levelBefore, DB::transactionLevel());
    }

    public function test_nested_conflicts_bubble_to_outer_owner_without_reconciliation_or_savepoint_retry(): void
    {
        $this->assertGreaterThan(0, DB::transactionLevel());

        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        $callCount = 0;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom-inside-nested');

        try {
            $this->allocator->executeWholeOperationWithConflictRetry(
                function () use (&$callCount) {
                    $callCount++;
                    throw new \RuntimeException('boom-inside-nested');
                },
                static fn (): array => []
            );
        } finally {
            // Operation must have executed exactly once: no whole-operation retry loop
            // runs while nested inside an existing transaction.
            $this->assertEquals(1, $callCount);
        }
    }

    public function test_non_conflict_exceptions_are_never_retried(): void
    {
        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        $initialLevel = $this->breakOuterTransactionForOutermostTest();

        $attempts = 0;

        try {
            try {
                $this->allocator->executeWholeOperationWithConflictRetry(
                    function () use (&$attempts) {
                        $attempts++;
                        throw new \RuntimeException('unrelated failure');
                    },
                    static fn (): array => [$ns]
                );
                $this->fail('Expected exception was not thrown.');
            } catch (\RuntimeException $e) {
                $this->assertEquals('unrelated failure', $e->getMessage());
            }

            $this->assertEquals(1, $attempts, 'Non-conflict exceptions must not be retried.');
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_reference_collision_succeeds_on_single_retry(): void
    {
        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        $initialLevel = $this->breakOuterTransactionForOutermostTest();

        $attempts = 0;

        try {
            $result = $this->allocator->executeWholeOperationWithConflictRetry(
                function () use (&$attempts) {
                    $attempts++;
                    if ($attempts === 1) {
                        throw $this->makeQueryException(
                            "SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: purchases.setting_id, purchases.reference"
                        );
                    }

                    return 'ok';
                },
                static fn (): array => [$ns]
            );

            $this->assertEquals('ok', $result);
            $this->assertEquals(2, $attempts);
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_second_reference_collision_is_terminal(): void
    {
        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        $initialLevel = $this->breakOuterTransactionForOutermostTest();

        $attempts = 0;

        try {
            $this->expectException(QueryException::class);

            try {
                $this->allocator->executeWholeOperationWithConflictRetry(
                    function () use (&$attempts) {
                        $attempts++;
                        throw $this->makeQueryException(
                            "SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: purchases.setting_id, purchases.reference"
                        );
                    },
                    static fn (): array => [$ns]
                );
            } finally {
                $this->assertEquals(2, $attempts, 'Reference collision must receive exactly one retry (2 attempts total).');
            }
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_deadlock_succeeds_within_three_attempts(): void
    {
        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        $initialLevel = $this->breakOuterTransactionForOutermostTest();

        $attempts = 0;

        try {
            $result = $this->allocator->executeWholeOperationWithConflictRetry(
                function () use (&$attempts) {
                    $attempts++;
                    if ($attempts < 3) {
                        throw $this->makeQueryException(
                            "SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock"
                        );
                    }

                    return 'ok';
                },
                static fn (): array => [$ns],
                3
            );

            $this->assertEquals('ok', $result);
            $this->assertEquals(3, $attempts);
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_fourth_deadlock_is_never_attempted(): void
    {
        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        $initialLevel = $this->breakOuterTransactionForOutermostTest();

        $attempts = 0;

        try {
            $this->expectException(QueryException::class);

            try {
                $this->allocator->executeWholeOperationWithConflictRetry(
                    function () use (&$attempts) {
                        $attempts++;
                        throw $this->makeQueryException(
                            "SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock"
                        );
                    },
                    static fn (): array => [$ns],
                    3
                );
            } finally {
                $this->assertEquals(3, $attempts, 'Deadlock must receive at most 3 total attempts.');
            }
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_reconciliation_is_not_performed_for_deadlocks(): void
    {
        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        $initialLevel = $this->breakOuterTransactionForOutermostTest();

        $attempts = 0;

        try {
            // Sequence row absent beforehand; if reconciliation were mistakenly triggered
            // for a deadlock, findMaxHistoricalSuffix() would run a purchases scan and
            // lockOrCreateSequenceRow() would create a row before the real allocation does.
            $this->assertNull(
                DocumentSequence::where('setting_id', $this->settingA->id)->where('document_type', 'purchase')->first()
            );

            $result = $this->allocator->executeWholeOperationWithConflictRetry(
                function () use (&$attempts) {
                    $attempts++;
                    if ($attempts === 1) {
                        throw $this->makeQueryException(
                            "SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock"
                        );
                    }

                    return 'ok';
                },
                static fn (): array => [$ns],
                3
            );

            $this->assertEquals('ok', $result);
            $this->assertNull(
                DocumentSequence::where('setting_id', $this->settingA->id)->where('document_type', 'purchase')->first(),
                'A deadlock retry must not create/reconcile a sequence row; only the real allocation should.'
            );
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_execute_with_conflict_retry_reference_collision_succeeds_on_single_retry(): void
    {
        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        $initialLevel = $this->breakOuterTransactionForOutermostTest();

        $attempts = 0;

        try {
            $result = $this->allocator->executeWithConflictRetry($ns, function () use (&$attempts) {
                $attempts++;
                if ($attempts === 1) {
                    throw $this->makeQueryException(
                        "SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: purchases.setting_id, purchases.reference"
                    );
                }

                return 'ok';
            });

            $this->assertEquals('ok', $result);
            $this->assertEquals(2, $attempts, 'Reference collision must receive exactly one retry (2 attempts total).');
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_execute_with_conflict_retry_second_reference_collision_is_terminal(): void
    {
        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        $initialLevel = $this->breakOuterTransactionForOutermostTest();

        $attempts = 0;

        try {
            $this->expectException(QueryException::class);

            try {
                $this->allocator->executeWithConflictRetry($ns, function () use (&$attempts) {
                    $attempts++;
                    throw $this->makeQueryException(
                        "SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: purchases.setting_id, purchases.reference"
                    );
                });
            } finally {
                $this->assertEquals(2, $attempts, 'Reference collision must receive at most 2 total attempts.');
            }
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_execute_with_conflict_retry_deadlock_succeeds_within_three_attempts(): void
    {
        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        $initialLevel = $this->breakOuterTransactionForOutermostTest();

        $attempts = 0;

        try {
            $result = $this->allocator->executeWithConflictRetry($ns, function () use (&$attempts) {
                $attempts++;
                if ($attempts < 3) {
                    throw $this->makeQueryException(
                        "SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock"
                    );
                }

                return 'ok';
            }, 3);

            $this->assertEquals('ok', $result);
            $this->assertEquals(3, $attempts);
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    public function test_execute_with_conflict_retry_fourth_deadlock_is_never_attempted(): void
    {
        $ns = $this->allocator->buildNamespace(DocumentType::PURCHASE, $this->settingA->id, '2026-08-15');

        $initialLevel = $this->breakOuterTransactionForOutermostTest();

        $attempts = 0;

        try {
            $this->expectException(QueryException::class);

            try {
                $this->allocator->executeWithConflictRetry($ns, function () use (&$attempts) {
                    $attempts++;
                    throw $this->makeQueryException(
                        "SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock"
                    );
                }, 3);
            } finally {
                $this->assertEquals(3, $attempts, 'Deadlock must receive at most 3 total attempts.');
            }
        } finally {
            $this->restoreOuterTransaction($initialLevel);
        }
    }

    private function makeQueryException(string $message): QueryException
    {
        $sqlState = '23000';
        if (preg_match('/SQLSTATE\[(\w+)\]/', $message, $m)) {
            $sqlState = $m[1];
        }

        $previous = new \PDOException($message);
        $previous->errorInfo = [$sqlState, str_contains($message, '1213') ? 1213 : 19];

        return new QueryException('mysql_test', 'select 1', [], $previous);
    }

    /**
     * Commits the RefreshDatabase outer transaction so a method under test can act as the
     * true outermost boundary, returning the level to restore to in the matching restore call.
     */
    private function breakOuterTransactionForOutermostTest(): int
    {
        $initialLevel = DB::transactionLevel();
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }

        return $initialLevel;
    }

    /**
     * Cleans up rows committed to the real sqlite database by a test that broke the outer
     * transaction, then restores the transaction level for RefreshDatabase teardown.
     */
    private function restoreOuterTransaction(int $initialLevel): void
    {
        DB::table('document_sequences')->where('setting_id', $this->settingA->id)->delete();
        DB::table('document_sequences')->where('setting_id', $this->settingB->id)->delete();
        DB::table('purchases')->whereIn('setting_id', [$this->settingA->id, $this->settingB->id])->delete();
        DB::table('payment_terms')->whereIn('setting_id', [$this->settingA->id, $this->settingB->id])->delete();
        DB::table('suppliers')->whereIn('setting_id', [$this->settingA->id, $this->settingB->id])->delete();
        DB::table('settings')->whereIn('id', [$this->settingA->id, $this->settingB->id])->delete();
        DB::table('currencies')->truncate();

        while (DB::transactionLevel() < $initialLevel) {
            DB::beginTransaction();
        }
    }

    public function test_canonical_multi_namespace_locking(): void
    {
        $nsA = $this->allocator->buildNamespace(DocumentType::SALE, $this->settingB->id, '2026-08-15');
        $nsB = $this->allocator->buildNamespace(DocumentType::SALE, $this->settingA->id, '2026-08-15');

        DB::transaction(function () use ($nsA, $nsB) {
            $locked = $this->allocator->lockNamespacesCanonically([$nsA, $nsB]);
            $this->assertCount(2, $locked);
            $this->assertArrayHasKey($nsA->canonicalKey(), $locked);
            $this->assertArrayHasKey($nsB->canonicalKey(), $locked);
        });
    }
}
