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
        // First try to select with lock
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

        // Row missing: Insert with initial last_number = 0, catching unique constraint race
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
            // Another concurrent worker created the row; ignore and lock below
        }

        // Reload with FOR UPDATE lock
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
     * Scans existing documents in the database for a namespace and advances the counter if behind.
     */
    public function reconcileCounter(SequenceNamespace $namespace): int
    {
        return DB::transaction(function () use ($namespace) {
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
        });
    }

    /**
     * Executes an operation with automatic single retry and counter reconciliation on duplicate reference collision.
     *
     * @param Closure(): mixed $callback
     * @return mixed
     * @throws Throwable
     */
    public function executeWithConflictRetry(SequenceNamespace $namespace, Closure $callback): mixed
    {
        try {
            return DB::transaction(function () use ($callback) {
                return $callback();
            });
        } catch (QueryException $e) {
            // Check if this exception is a unique reference violation
            if (!$this->isUniqueReferenceConflict($e)) {
                throw $e;
            }

            Log::warning('document_sequence.conflict_detected_retrying', [
                'document_type' => $namespace->documentType->value,
                'setting_id' => $namespace->settingId,
                'prefix' => $namespace->prefix,
                'year' => $namespace->year,
                'month' => $namespace->month,
                'error_message' => $e->getMessage(),
            ]);

            // Reconcile counter forward
            $this->reconcileCounter($namespace);

            // Bounded single retry
            try {
                return DB::transaction(function () use ($callback) {
                    return $callback();
                });
            } catch (QueryException $retryException) {
                if ($this->isUniqueReferenceConflict($retryException)) {
                    Log::error('document_sequence.terminal_conflict_failure', [
                        'document_type' => $namespace->documentType->value,
                        'setting_id' => $namespace->settingId,
                        'prefix' => $namespace->prefix,
                        'year' => $namespace->year,
                        'month' => $namespace->month,
                        'error_message' => $retryException->getMessage(),
                    ]);
                }
                throw $retryException;
            }
        }
    }

    /**
     * Determines if a database query exception indicates a unique reference collision on purchases or sales.
     */
    public function isUniqueReferenceConflict(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'purchases_setting_reference_unique')
            || str_contains($message, 'sales_setting_reference_unique')
            || (str_contains($message, 'unique') && str_contains($message, 'reference'));
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
