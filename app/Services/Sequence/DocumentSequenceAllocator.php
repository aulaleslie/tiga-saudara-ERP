<?php

namespace App\Services\Sequence;

use Carbon\Carbon;
use Closure;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use RuntimeException;
use Throwable;

class DocumentSequenceAllocator
{
    public function __construct(
        protected DocumentReferenceFormatter $formatter = new DocumentReferenceFormatter()
    ) {
    }

    /**
     * Resolves the prefix for a given document type and setting.
     */
    public function resolvePrefix(DocumentType $documentType, Setting|int $setting): string
    {
        $settingModel = is_int($setting) ? Setting::findOrFail($setting) : $setting;

        $docPrefix = trim((string) ($settingModel->document_prefix ?? ''));
        $familyPrefix = match ($documentType) {
            DocumentType::PURCHASE => trim((string) ($settingModel->purchase_prefix_document ?: 'PR')),
            DocumentType::SALE => trim((string) ($settingModel->sale_prefix_document ?: 'SL')),
        };

        if ($docPrefix !== '') {
            return $docPrefix . '-' . $familyPrefix;
        }

        return $familyPrefix;
    }

    /**
     * Builds a SequenceNamespace object for a document type, setting ID, date, and optional prefix override.
     */
    public function buildNamespace(
        DocumentType $documentType,
        int $settingId,
        Carbon|string $date,
        ?string $prefix = null
    ): SequenceNamespace {
        $carbonDate = is_string($date) ? Carbon::parse($date) : $date;
        $effectivePrefix = $prefix ?? $this->resolvePrefix($documentType, $settingId);

        return new SequenceNamespace(
            $documentType,
            $settingId,
            $effectivePrefix,
            (int) $carbonDate->year,
            (int) $carbonDate->month
        );
    }

    /**
     * Locks and allocates a single sequence number within an active transaction.
     *
     * @throws RuntimeException if no transaction is active.
     */
    public function allocate(SequenceNamespace $namespace): SequenceAllocation
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException("Sequence allocation must occur within an active database transaction.");
        }

        $row = $this->lockOrCreateSequenceRow($namespace);

        $nextNumber = (int) $row->last_number + 1;

        // Atomically increment row
        DocumentSequence::query()
            ->whereKey($row->id)
            ->update([
                'last_number' => $nextNumber,
                'updated_at' => now(),
            ]);

        $reference = $this->formatter->format(
            $namespace->prefix,
            $namespace->year,
            $namespace->month,
            $nextNumber
        );

        Log::info('document_sequence.allocated', [
            'document_type' => $namespace->documentType->value,
            'setting_id' => $namespace->settingId,
            'prefix' => $namespace->prefix,
            'year' => $namespace->year,
            'month' => $namespace->month,
            'allocated_number' => $nextNumber,
            'reference' => $reference,
        ]);

        return new SequenceAllocation($namespace, $nextNumber, $reference);
    }

    /**
     * Locks multiple namespaces in canonical deterministic order.
     * Returns an associative array map of [canonicalKey => DocumentSequence].
     *
     * @param SequenceNamespace[] $namespaces
     * @return array<string, DocumentSequence>
     */
    public function lockNamespacesCanonically(array $namespaces): array
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException("Multi-namespace sequence locking must occur within an active database transaction.");
        }

        // Deduplicate namespaces by canonicalKey
        /** @var array<string, SequenceNamespace> $unique */
        $unique = [];
        foreach ($namespaces as $ns) {
            $unique[$ns->canonicalKey()] = $ns;
        }

        // Sort namespaces in canonical order
        $sorted = array_values($unique);
        usort($sorted, fn(SequenceNamespace $a, SequenceNamespace $b) => $a->compareTo($b));

        $lockedRows = [];
        foreach ($sorted as $ns) {
            $lockedRows[$ns->canonicalKey()] = $this->lockOrCreateSequenceRow($ns);
        }

        return $lockedRows;
    }

    /**
     * Acquires a row lock with FOR UPDATE, creating the counter row race-safely if it does not yet exist.
     */
    protected function lockOrCreateSequenceRow(SequenceNamespace $namespace): DocumentSequence
    {
        // In MySQL REPEATABLE-READ transactions, attempt locking first
        $row = DocumentSequence::query()
            ->where('document_type', $namespace->documentType->value)
            ->where('setting_id', $namespace->settingId)
            ->where('prefix', $namespace->prefix)
            ->where('period_year', $namespace->year)
            ->where('period_month', $namespace->month)
            ->lockForUpdate()
            ->first();

        if ($row !== null) {
            return $row;
        }

        // Row does not exist yet: create row
        try {
            DB::table('document_sequences')->insert([
                'document_type' => $namespace->documentType->value,
                'setting_id' => $namespace->settingId,
                'prefix' => $namespace->prefix,
                'period_year' => $namespace->year,
                'period_month' => $namespace->month,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('document_sequence.counter_row_created', [
                'document_type' => $namespace->documentType->value,
                'setting_id' => $namespace->settingId,
                'prefix' => $namespace->prefix,
                'year' => $namespace->year,
                'month' => $namespace->month,
            ]);
        } catch (QueryException $e) {
            if (!$this->isNamespaceUniqueConflict($e)) {
                throw $e;
            }
        }

        // Reload row with FOR UPDATE lock
        return DocumentSequence::query()
            ->where('document_type', $namespace->documentType->value)
            ->where('setting_id', $namespace->settingId)
            ->where('prefix', $namespace->prefix)
            ->where('period_year', $namespace->year)
            ->where('period_month', $namespace->month)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Determines if a QueryException is an exact unique key violation on the document_sequences namespace unique index.
     * Deadlocks (1213 / SQLSTATE 40001) must NOT be swallowed and must rethrow to transaction boundary.
     */
    public function isNamespaceUniqueConflict(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());
        $sqlState = (string) ($e->getCode() ?? '');
        $errorInfo = $e->errorInfo ?? [];
        $driverCode = (int) ($errorInfo[1] ?? 0);

        // Require MySQL error code 1062 or SQLSTATE 23000 AND exact index name
        $isUniqueViolation = ($sqlState === '23000' || $driverCode === 1062);
        $matchesExactConstraint = str_contains($message, 'doc_seq_type_setting_prefix_period_unique');

        return $isUniqueViolation && $matchesExactConstraint;
    }

    /**
     * Scans existing documents in the database for a namespace and advances the counter if behind.
     */
    public function reconcileCounter(SequenceNamespace $namespace): int
    {
        $reconcileCallback = function () use ($namespace) {
            $row = $this->lockOrCreateSequenceRow($namespace);
            $currentCounter = (int) $row->last_number;

            $maxHistorical = $this->findMaxHistoricalSuffix($namespace);

            if ($maxHistorical > $currentCounter) {
                DocumentSequence::query()
                    ->whereKey($row->id)
                    ->update([
                        'last_number' => $maxHistorical,
                        'updated_at' => now(),
                    ]);

                Log::warning('document_sequence.reconciled_stale_counter', [
                    'document_type' => $namespace->documentType->value,
                    'setting_id' => $namespace->settingId,
                    'prefix' => $namespace->prefix,
                    'year' => $namespace->year,
                    'month' => $namespace->month,
                    'previous_counter' => $currentCounter,
                    'reconciled_counter' => $maxHistorical,
                ]);

                return $maxHistorical;
            }

            return $currentCounter;
        };

        if (DB::transactionLevel() > 0) {
            return $reconcileCallback();
        }

        return DB::transaction($reconcileCallback);
    }

    /**
     * Executes an operation with automatic bounded retry and counter reconciliation on duplicate reference collisions or serialization deadlocks.
     *
     * Reference collisions and deadlocks are governed by independent, deterministic attempt
     * budgets: a reference collision gets one initial attempt plus at most one reconciled
     * retry (2 attempts total), while a deadlock/serialization failure gets up to
     * $maxDeadlockAttempts total attempts (default 3) with bounded jitter between them.
     *
     * @param Closure(): mixed $callback
     * @return mixed
     * @throws Throwable
     */
    public function executeWithConflictRetry(SequenceNamespace $namespace, Closure $callback, int $maxDeadlockAttempts = 3): mixed
    {
        // If caller already owns an active database transaction, execute directly without opening a nested transaction/savepoint.
        // This ensures that MySQL deadlocks and conflicts bubble cleanly to the outermost owner for a full transaction restart.
        if (DB::transactionLevel() > 0) {
            return $callback();
        }

        $maxCollisionAttempts = 2;
        $deadlockAttempt = 0;
        $collisionAttempt = 0;

        while (true) {
            try {
                return DB::transaction(function () use ($callback) {
                    return $callback();
                });
            } catch (Throwable $e) {
                $isDeadlock = $this->isDeadlockConflict($e);
                $isReferenceCollision = !$isDeadlock && $this->isUniqueReferenceConflict($e);

                if (!$isDeadlock && !$isReferenceCollision) {
                    throw $e;
                }

                if ($isDeadlock) {
                    $deadlockAttempt++;
                } else {
                    $collisionAttempt++;
                }

                $budgetExhausted = $isDeadlock
                    ? $deadlockAttempt >= $maxDeadlockAttempts
                    : $collisionAttempt >= $maxCollisionAttempts;

                if ($budgetExhausted) {
                    Log::error('document_sequence.terminal_conflict_failure', [
                        'document_type' => $namespace->documentType->value,
                        'setting_id' => $namespace->settingId,
                        'prefix' => $namespace->prefix,
                        'year' => $namespace->year,
                        'month' => $namespace->month,
                        'deadlock_attempt' => $deadlockAttempt,
                        'collision_attempt' => $collisionAttempt,
                        'is_deadlock' => $isDeadlock,
                        'is_collision' => $isReferenceCollision,
                        'error_message' => $e->getMessage(),
                    ]);
                    throw $e;
                }

                Log::warning('document_sequence.conflict_detected_retrying', [
                    'document_type' => $namespace->documentType->value,
                    'setting_id' => $namespace->settingId,
                    'prefix' => $namespace->prefix,
                    'year' => $namespace->year,
                    'month' => $namespace->month,
                    'deadlock_attempt' => $deadlockAttempt,
                    'collision_attempt' => $collisionAttempt,
                    'is_deadlock' => $isDeadlock,
                    'is_collision' => $isReferenceCollision,
                    'error_message' => $e->getMessage(),
                ]);

                // On duplicate reference collision, reconcile counter forward
                if ($isReferenceCollision) {
                    $this->reconcileCounter($namespace);
                }

                // If deadlock occurred, back off briefly with jitter before retrying
                if ($isDeadlock) {
                    usleep(random_int(10000, 50000)); // 10ms - 50ms jitter
                }
            }
        }
    }

    /**
     * Execute a complete document operation, retrying the whole operation on conflict.
     *
     * If no transaction is currently active, this method owns the outermost transaction
     * boundary and may perform bounded whole-operation retries: on a deadlock or reference
     * collision, the failed transaction is rolled back, counters are reconciled (for
     * collisions only), and the complete operation is restarted from scratch.
     *
     * If called while already inside an existing transaction (e.g. Purchase/Sale tests
     * under RefreshDatabase, or legitimate composition inside a caller-owned transaction),
     * this method cannot safely own retry: opening a nested transaction/savepoint here
     * would let a reference collision "succeed" locally only to be rolled back later by
     * the real outer owner, silently discarding the reconciliation this method would have
     * performed. Instead, the operation executes exactly once, within the existing
     * transaction, and any conflict is left to propagate to whichever caller actually owns
     * the outermost transaction. That owner must call this method (or
     * {@see executeOnceWithinExistingTransaction}) at its own outer boundary to get retry
     * behavior; production Purchase, Sale, and POS entry points do so.
     *
     * The namespace provider is evaluated only after a failed outermost transaction has
     * been rolled back. This allows stale counters to be reconciled safely before the
     * entire Purchase, Sale, or POS operation is attempted again.
     *
     * Reference collisions and deadlocks are governed by independent, deterministic
     * attempt budgets rather than a single shared counter:
     *   - Reference collision: one initial attempt plus at most one reconciled retry
     *     (2 attempts total for that conflict type).
     *   - Deadlock/serialization failure: up to $maxDeadlockAttempts total attempts
     *     with bounded jitter between them (default 3).
     * If retries alternate between conflict types, each type's budget is tracked and
     * enforced independently; either budget being exhausted ends the operation.
     *
     * @param Closure(): mixed $operation
     * @param Closure(): array<int, SequenceNamespace> $namespaceProvider
     */
    public function executeWholeOperationWithConflictRetry(
        Closure $operation,
        Closure $namespaceProvider,
        int $maxDeadlockAttempts = 3
    ): mixed {
        if (DB::transactionLevel() > 0) {
            return $this->executeOnceWithinExistingTransaction($operation);
        }

        if ($maxDeadlockAttempts < 1) {
            throw new InvalidArgumentException('maxDeadlockAttempts must be at least 1.');
        }

        $maxCollisionAttempts = 2;
        $deadlockAttempt = 0;
        $collisionAttempt = 0;

        while (true) {
            try {
                return DB::transaction($operation);
            } catch (Throwable $e) {
                $isDeadlock = $this->isDeadlockConflict($e);
                $isReferenceCollision = !$isDeadlock && $this->isUniqueReferenceConflict($e);

                if (!$isDeadlock && !$isReferenceCollision) {
                    throw $e;
                }

                if ($isDeadlock) {
                    $deadlockAttempt++;
                } else {
                    $collisionAttempt++;
                }

                $budgetExhausted = $isDeadlock
                    ? $deadlockAttempt >= $maxDeadlockAttempts
                    : $collisionAttempt >= $maxCollisionAttempts;

                if ($budgetExhausted) {
                    Log::error('document_sequence.terminal_conflict_failure', [
                        'deadlock_attempt' => $deadlockAttempt,
                        'collision_attempt' => $collisionAttempt,
                        'is_deadlock' => $isDeadlock,
                        'is_collision' => $isReferenceCollision,
                        'error_message' => $e->getMessage(),
                    ]);
                    throw $e;
                }

                $namespaces = $this->canonicalizeNamespaces($namespaceProvider());

                Log::warning('document_sequence.whole_operation_retrying', [
                    'deadlock_attempt' => $deadlockAttempt,
                    'collision_attempt' => $collisionAttempt,
                    'is_deadlock' => $isDeadlock,
                    'is_collision' => $isReferenceCollision,
                    'namespaces' => array_map(
                        static fn (SequenceNamespace $namespace): string => $namespace->canonicalKey(),
                        $namespaces
                    ),
                    'error_message' => $e->getMessage(),
                ]);

                if ($isReferenceCollision) {
                    if ($namespaces === []) {
                        throw new RuntimeException(
                            'Cannot reconcile a reference collision without its sequence namespace.',
                            0,
                            $e
                        );
                    }

                    // The failed transaction is fully rolled back by this point (DB::transaction()
                    // rethrows only after rollback), so reconciliation is safe here.
                    foreach ($namespaces as $namespace) {
                        Log::info('document_sequence.reconciling_after_collision', [
                            'collision_attempt' => $collisionAttempt,
                            'namespace' => $namespace->canonicalKey(),
                        ]);
                        $this->reconcileCounter($namespace);
                    }
                }

                if ($isDeadlock) {
                    usleep(random_int(10000, 50000));
                }
            }
        }
    }

    /**
     * Execute an operation exactly once, within the caller's existing transaction.
     *
     * Use this (or rely on {@see executeWholeOperationWithConflictRetry} detecting the
     * nested case automatically) when composing sequence-allocating work inside a
     * transaction some other caller already owns — e.g. tests wrapped in
     * RefreshDatabase, or nested service calls. No savepoint is opened and no retry is
     * attempted: any deadlock or reference collision propagates unchanged to the actual
     * outer transaction owner, which is the only place that can safely roll back and
     * reconcile.
     *
     * @param Closure(): mixed $operation
     * @throws RuntimeException if no transaction is currently active.
     */
    public function executeOnceWithinExistingTransaction(Closure $operation): mixed
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('executeOnceWithinExistingTransaction() requires an already-active database transaction.');
        }

        return $operation();
    }

    /**
     * @param array<int, SequenceNamespace> $namespaces
     * @return array<int, SequenceNamespace>
     */
    private function canonicalizeNamespaces(array $namespaces): array
    {
        $unique = [];
        foreach ($namespaces as $namespace) {
            if (!$namespace instanceof SequenceNamespace) {
                throw new InvalidArgumentException('All retry namespaces must be SequenceNamespace instances.');
            }

            $unique[$namespace->canonicalKey()] = $namespace;
        }

        $canonical = array_values($unique);
        usort($canonical, static fn (SequenceNamespace $a, SequenceNamespace $b): int => $a->compareTo($b));

        return $canonical;
    }

    /**
     * Determines if a database query exception indicates a deadlock (1213 / SQLSTATE 40001).
     */
    public function isDeadlockConflict(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        $sqlState = (string) ($e->getCode() ?? '');
        $errorInfo = property_exists($e, 'errorInfo') ? ($e->errorInfo ?? []) : [];
        $driverCode = (int) ($errorInfo[1] ?? 0);

        return $sqlState === '40001'
            || $driverCode === 1213
            || str_contains($message, 'deadlock')
            || str_contains($message, '1213');
    }

    /**
     * Determines if a database query exception indicates a unique reference collision on
     * exactly the Purchase or Sale `(setting_id, reference)` unique constraints.
     *
     * Deliberately narrow: does not classify supplier invoice numbers, tax references,
     * idempotency keys, or any other unique constraint on purchases/sales as a sequence
     * collision, so unrelated unique violations are never mistaken for a reference clash
     * and never trigger reconciliation/retry.
     */
    public function isUniqueReferenceConflict(Throwable $e): bool
    {
        if (!$e instanceof QueryException) {
            return false;
        }

        $message = strtolower($e->getMessage());
        $sqlState = (string) ($e->getCode() ?? '');
        $errorInfo = $e->errorInfo ?? [];
        $driverCode = (int) ($errorInfo[1] ?? 0);

        // MySQL: exact SQLSTATE/error code plus exact reference-index name.
        if ($sqlState === '23000' || $driverCode === 1062) {
            if (str_contains($message, 'purchases_setting_reference_unique')
                || str_contains($message, 'sales_setting_reference_unique')) {
                return true;
            }
        }

        // SQLite: exact column-combination match on the reference unique index.
        if ($driverCode === 19 && str_contains($message, 'unique constraint failed')) {
            if (str_contains($message, 'purchases.setting_id, purchases.reference')
                || str_contains($message, 'sales.setting_id, sales.reference')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Finds the maximum numeric suffix from historical references in this namespace.
     */
    public function findMaxHistoricalSuffix(SequenceNamespace $namespace): int
    {
        $query = match ($namespace->documentType) {
            DocumentType::PURCHASE => Purchase::withArchived()->where('setting_id', $namespace->settingId),
            DocumentType::SALE => Sale::withArchived()->where('setting_id', $namespace->settingId),
        };

        // Pattern prefix for SQL LIKE search: "<prefix>-<YYYY>-<MM>-%"
        $likePattern = sprintf('%s-%04d-%02d-%%', $namespace->prefix, $namespace->year, $namespace->month);

        $references = $query->where('reference', 'LIKE', $likePattern)->pluck('reference');

        $max = 0;
        foreach ($references as $ref) {
            $parsed = $this->formatter->parse((string) $ref);
            if ($parsed !== null
                && $parsed['prefix'] === $namespace->prefix
                && $parsed['year'] === $namespace->year
                && $parsed['month'] === $namespace->month
            ) {
                if ($parsed['number'] > $max) {
                    $max = $parsed['number'];
                }
            }
        }

        return $max;
    }
}
