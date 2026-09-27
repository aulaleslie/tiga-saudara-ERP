<?php

namespace App\Services\Sequence;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;

class SequenceReconciliationService
{
    public function __construct(
        protected DocumentReferenceFormatter $formatter = new DocumentReferenceFormatter()
    ) {
    }

    /**
     * Reconciles and analyzes existing references for the specified document types.
     *
     * @param DocumentType[] $documentTypes
     * @return array{
     *   namespaces: array<string, array{
     *     document_type: string,
     *     setting_id: int,
     *     prefix: string,
     *     year: int,
     *     month: int,
     *     historical_max: int,
     *     current_counter: int,
     *     target_counter: int,
     *     count: int
     *   }>,
     *   malformed_references: array<int, array{
     *     document_type: string,
     *     id: int,
     *     setting_id: int|null,
     *     reference: string,
     *     reason: string
     *   }>,
     *   date_drift_references: array<int, array{
     *     document_type: string,
     *     id: int,
     *     setting_id: int|null,
     *     reference: string,
     *     document_date: string|null,
     *     embedded_year: int,
     *     embedded_month: int
     *   }>,
     *   unexpected_prefixes: array<int, array{
     *     document_type: string,
     *     setting_id: int,
     *     prefix: string,
     *     expected_prefix: string,
     *     count: int
     *   }>
     * }
     */
    public function analyze(array $documentTypes): array
    {
        $namespaces = [];
        $malformed = [];
        $dateDrifts = [];
        $prefixMap = []; // [type:setting_id:prefix => count]

        // Preload settings for expected prefix lookup
        $settings = Setting::all()->keyBy('id');

        foreach ($documentTypes as $docType) {
            $query = match ($docType) {
                DocumentType::PURCHASE => Purchase::withArchived()->select(['id', 'setting_id', 'reference', 'date']),
                DocumentType::SALE => Sale::withArchived()->select(['id', 'setting_id', 'reference', 'date']),
            };

            $query->chunk(500, function ($records) use ($docType, &$namespaces, &$malformed, &$dateDrifts, &$prefixMap) {
                foreach ($records as $record) {
                    $ref = (string) $record->reference;
                    $settingId = (int) $record->setting_id;

                    if ($settingId <= 0) {
                        $malformed[] = [
                            'document_type' => $docType->value,
                            'id' => (int) $record->id,
                            'setting_id' => null,
                            'reference' => $ref,
                            'reason' => 'Missing or invalid setting_id',
                        ];
                        continue;
                    }

                    $parsed = $this->formatter->parse($ref);
                    if ($parsed === null) {
                        $malformed[] = [
                            'document_type' => $docType->value,
                            'id' => (int) $record->id,
                            'setting_id' => $settingId,
                            'reference' => $ref,
                            'reason' => 'Malformed or unparseable reference structure',
                        ];
                        continue;
                    }

                    $prefixKey = "{$docType->value}:{$settingId}:{$parsed['prefix']}";
                    $prefixMap[$prefixKey] = ($prefixMap[$prefixKey] ?? 0) + 1;

                    // Check for embedded-period vs document date drift
                    if ($record->date !== null) {
                        $docDate = Carbon::parse($record->date);
                        if ((int) $docDate->year !== $parsed['year'] || (int) $docDate->month !== $parsed['month']) {
                            $dateDrifts[] = [
                                'document_type' => $docType->value,
                                'id' => (int) $record->id,
                                'setting_id' => $settingId,
                                'reference' => $ref,
                                'document_date' => $docDate->format('Y-m-d'),
                                'embedded_year' => $parsed['year'],
                                'embedded_month' => $parsed['month'],
                            ];
                        }
                    }

                    // Key namespace by embedded identity
                    $nsKey = sprintf(
                        '%s:%d:%s:%04d:%02d',
                        $docType->value,
                        $settingId,
                        $parsed['prefix'],
                        $parsed['year'],
                        $parsed['month']
                    );

                    if (!isset($namespaces[$nsKey])) {
                        $namespaces[$nsKey] = [
                            'document_type' => $docType->value,
                            'setting_id' => $settingId,
                            'prefix' => $parsed['prefix'],
                            'year' => $parsed['year'],
                            'month' => $parsed['month'],
                            'historical_max' => $parsed['number'],
                            'current_counter' => 0,
                            'target_counter' => $parsed['number'],
                            'count' => 1,
                        ];
                    } else {
                        $namespaces[$nsKey]['count']++;
                        if ($parsed['number'] > $namespaces[$nsKey]['historical_max']) {
                            $namespaces[$nsKey]['historical_max'] = $parsed['number'];
                        }
                    }
                }
            });
        }

        // Fetch current counters from document_sequences table
        $existingRows = DocumentSequence::all();
        $counterMap = [];
        foreach ($existingRows as $row) {
            $key = sprintf(
                '%s:%d:%s:%04d:%02d',
                $row->document_type,
                $row->setting_id,
                $row->prefix,
                $row->period_year,
                $row->period_month
            );
            $counterMap[$key] = (int) $row->last_number;
        }

        foreach ($namespaces as $key => &$nsData) {
            $curr = $counterMap[$key] ?? 0;
            $nsData['current_counter'] = $curr;
            $nsData['target_counter'] = max($curr, $nsData['historical_max']);
        }
        unset($nsData);

        // Analyze unexpected prefixes against currently configured setting prefixes
        $unexpectedPrefixes = [];
        foreach ($prefixMap as $pKey => $count) {
            [$docTypeValue, $settingId, $prefix] = explode(':', $pKey, 3);
            $docType = DocumentType::from($docTypeValue);
            $setting = $settings->get((int) $settingId);

            $expectedPrefix = '';
            if ($setting) {
                $docPrefix = trim((string) ($setting->document_prefix ?? ''));
                $famPrefix = match ($docType) {
                    DocumentType::PURCHASE => trim((string) ($setting->purchase_prefix_document ?: 'PR')),
                    DocumentType::SALE => trim((string) ($setting->sale_prefix_document ?: 'SL')),
                };
                $expectedPrefix = $docPrefix !== '' ? "{$docPrefix}-{$famPrefix}" : $famPrefix;
            }

            if ($expectedPrefix !== '' && $prefix !== $expectedPrefix) {
                $unexpectedPrefixes[] = [
                    'document_type' => $docType->value,
                    'setting_id' => (int) $settingId,
                    'prefix' => $prefix,
                    'expected_prefix' => $expectedPrefix,
                    'count' => $count,
                ];
            }
        }

        return [
            'namespaces' => $namespaces,
            'malformed_references' => $malformed,
            'date_drift_references' => $dateDrifts,
            'unexpected_prefixes' => $unexpectedPrefixes,
        ];
    }

    /**
     * Bootstraps sequence counters from historical maximums monotonically.
     * Never decrements an existing counter and never modifies historical documents.
     *
     * @param DocumentType[] $documentTypes
     * @param bool $dryRun If true, does not write to database
     * @return array{advanced_count: int, created_count: int, unchanged_count: int, namespaces: array}
     */
    public function bootstrap(array $documentTypes, bool $dryRun = false): array
    {
        $analysis = $this->analyze($documentTypes);
        $namespaces = $analysis['namespaces'];

        $advancedCount = 0;
        $createdCount = 0;
        $unchangedCount = 0;

        if (!$dryRun) {
            DB::transaction(function () use ($namespaces, &$advancedCount, &$createdCount, &$unchangedCount) {
                foreach ($namespaces as $ns) {
                    $row = DocumentSequence::query()
                        ->where('document_type', $ns['document_type'])
                        ->where('setting_id', $ns['setting_id'])
                        ->where('prefix', $ns['prefix'])
                        ->where('period_year', $ns['year'])
                        ->where('period_month', $ns['month'])
                        ->lockForUpdate()
                        ->first();

                    if ($row === null) {
                        DocumentSequence::create([
                            'document_type' => $ns['document_type'],
                            'setting_id' => $ns['setting_id'],
                            'prefix' => $ns['prefix'],
                            'period_year' => $ns['year'],
                            'period_month' => $ns['month'],
                            'last_number' => $ns['target_counter'],
                        ]);
                        $createdCount++;

                        Log::info('document_sequence.bootstrapped_new_namespace', [
                            'document_type' => $ns['document_type'],
                            'setting_id' => $ns['setting_id'],
                            'prefix' => $ns['prefix'],
                            'year' => $ns['year'],
                            'month' => $ns['month'],
                            'last_number' => $ns['target_counter'],
                        ]);
                    } else {
                        $currentNumber = (int) $row->last_number;
                        if ($ns['target_counter'] > $currentNumber) {
                            $row->update([
                                'last_number' => $ns['target_counter'],
                            ]);
                            $advancedCount++;

                            Log::info('document_sequence.bootstrapped_advanced_counter', [
                                'document_type' => $ns['document_type'],
                                'setting_id' => $ns['setting_id'],
                                'prefix' => $ns['prefix'],
                                'year' => $ns['year'],
                                'month' => $ns['month'],
                                'previous_number' => $currentNumber,
                                'new_number' => $ns['target_counter'],
                            ]);
                        } else {
                            $unchangedCount++;
                        }
                    }
                }
            });
        } else {
            foreach ($namespaces as $ns) {
                if ($ns['current_counter'] === 0) {
                    $createdCount++;
                } elseif ($ns['target_counter'] > $ns['current_counter']) {
                    $advancedCount++;
                } else {
                    $unchangedCount++;
                }
            }
        }

        return [
            'advanced_count' => $advancedCount,
            'created_count' => $createdCount,
            'unchanged_count' => $unchangedCount,
            'namespaces' => $namespaces,
            'malformed_count' => count($analysis['malformed_references']),
            'date_drift_count' => count($analysis['date_drift_references']),
            'unexpected_prefix_count' => count($analysis['unexpected_prefixes']),
        ];
    }
}
