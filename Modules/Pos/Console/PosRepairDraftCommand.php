<?php

namespace Modules\Pos\Console;

use App\Models\User;
use App\Support\RowTotalRoundingCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Services\PosCartTotalsCalculator;
use Modules\Pos\Services\PosTransactionLineAmountResolver;
use Modules\Pos\Services\PosTransactionSnapshotMapper;
use Modules\Setting\Entities\Setting;

class PosRepairDraftCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pos:repair-draft
        {id : The explicit ID of the draft POS transaction to preview or repair}
        {--setting= : The setting ID scope for validation}
        {--apply : Persist the repair instead of previewing}
        {--preview-hash= : Verify the expected preview hash before applying}
        {--actor= : User ID attributing the repair action}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Explicit ID-selected repair tool for drafts affected by unpersisted automatic row rounding';

    public function handle(PosTransactionSnapshotMapper $mapper): int
    {
        $id = (int) $this->argument('id');
        $settingOption = $this->option('setting');
        $apply = (bool) $this->option('apply');
        $expectedPreviewHash = $this->option('preview-hash');
        $actorInput = $this->option('actor');

        if ($id <= 0) {
            $this->error('Valid transaction ID is required.');
            return 1;
        }

        $query = PosTransaction::query()->whereKey($id);
        if ($settingOption !== null) {
            $query->where('setting_id', (int) $settingOption);
        }

        /** @var PosTransaction|null $transaction */
        $transaction = $query->first();

        if (! $transaction) {
            $this->error("Transaction ID {$id} not found" . ($settingOption ? " for setting {$settingOption}." : "."));
            return 1;
        }

        // Guard: Only DRAFT transactions are eligible
        if ($transaction->status !== PosTransaction::STATUS_DRAFT) {
            $this->error("Transaction is not in DRAFT status (current status: {$transaction->status}). Refusing repair.");
            return 1;
        }

        // Guard: Refuse transactions loaded into active carts (status LOADED)
        if ($transaction->status === PosTransaction::STATUS_LOADED) {
            $this->error("Transaction is currently LOADED in an active cart session. Refusing repair.");
            return 1;
        }

        $setting = Setting::query()->find($transaction->setting_id);
        $increment = (float) ($setting?->row_total_rounding_increment ?? 0.0);

        $this->line(sprintf(
            "Transaction: #%d (%s) | Setting: #%d | Current Increment: %.2f | Mode: %s",
            $transaction->id,
            $transaction->code,
            $transaction->setting_id,
            $increment,
            $apply ? 'APPLY' : 'PREVIEW'
        ));

        // Evaluate candidate lines and preview hash
        $evaluation = $this->evaluateTransactionLines($transaction, $increment);
        if ($evaluation['status'] === 'error') {
            $this->error($evaluation['message']);
            return 1;
        }

        $lineRepairs = $evaluation['lines'];
        $hasMissingAuthoritative = $evaluation['has_missing_authoritative'];
        $generatedPreviewHash = $evaluation['preview_hash'];

        $this->table(
            ['Line #', 'Product', 'Qty', 'Unit Price', 'Source', 'Current Minor', 'Target Minor', 'Target Rupiah'],
            array_map(function ($r) {
                return [
                    $r['line_no'],
                    $r['product'],
                    $r['qty'],
                    number_format($r['unit_price'], 2),
                    $r['price_source'],
                    $r['current_total_minor'] !== null ? $r['current_total_minor'] : '(none)',
                    $r['target_total_minor'],
                    number_format($r['target_total_minor'] / 100, 2),
                ];
            }, $lineRepairs)
        );

        $this->info("Preview Hash: {$generatedPreviewHash}");

        if (! $hasMissingAuthoritative) {
            $this->info("Transaction lines already carry authoritative amounts. No changes needed.");
            return 0;
        }

        // Guard: Actor validation when in apply mode
        $actorId = null;
        if ($apply) {
            if (empty($actorInput) || ! ctype_digit((string) $actorInput)) {
                $this->error("A valid numeric User ID must be provided via --actor when applying a repair.");
                return 1;
            }
            $actorId = (int) $actorInput;
            $actorUser = User::query()->find($actorId);
            if (! $actorUser) {
                $this->error("Actor user ID {$actorId} does not exist in database.");
                return 1;
            }

            // Preview hash is strictly required for apply
            if (empty($expectedPreviewHash)) {
                $this->error("The --preview-hash option is required when applying a repair.");
                return 1;
            }
        }

        if (! $apply) {
            $this->comment("Dry-run preview complete. Pass --apply --preview-hash={$generatedPreviewHash} --actor=<user_id> to execute.");
            return 0;
        }

        // Validate preview hash match
        if (! hash_equals($generatedPreviewHash, (string) $expectedPreviewHash)) {
            $this->error("Preview hash mismatch! Expected: {$expectedPreviewHash}, Generated: {$generatedPreviewHash}. Refusing to apply.");
            return 1;
        }

        // Atomic update under row lock with complete re-read & re-evaluation
        try {
            DB::transaction(function () use ($transaction, $mapper, $actorId, $expectedPreviewHash) {
                /** @var PosTransaction $lockedTx */
                $lockedTx = PosTransaction::query()
                    ->whereKey($transaction->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedTx->status !== PosTransaction::STATUS_DRAFT) {
                    throw new \RuntimeException("Transaction status changed during execution (current: {$lockedTx->status}). Aborted.");
                }

                // Re-read rounding configuration fresh under lock -- reusing the
                // pre-lock value would miss a concurrent setting change, and the
                // preview hash covers the increment precisely to catch that.
                $lockedSetting = Setting::query()->find($lockedTx->setting_id);
                $lockedIncrement = (float) ($lockedSetting?->row_total_rounding_increment ?? 0.0);

                // Re-evaluate complete snapshot fresh under lock
                $lockedEvaluation = $this->evaluateTransactionLines($lockedTx, $lockedIncrement);
                if ($lockedEvaluation['status'] === 'error') {
                    throw new \RuntimeException($lockedEvaluation['message']);
                }

                if (! hash_equals($lockedEvaluation['preview_hash'], (string) $expectedPreviewHash)) {
                    throw new \RuntimeException("Preview hash mismatch under lock! Draft was modified concurrently. Aborted.");
                }

                $lockedLineRepairs = $lockedEvaluation['lines'];

                // Capture before-snapshot for audit trail
                $beforeSnapshot = [
                    'transaction_id' => $lockedTx->id,
                    'snapshot_hash' => $lockedTx->snapshot_hash,
                    'snapshot_totals' => $lockedTx->snapshot_totals,
                    'lines' => array_map(function ($r) {
                        return [
                            'line_id' => $r['line_id'],
                            'line_no' => $r['line_no'],
                            'line_meta' => $r['line_meta'],
                        ];
                    }, $lockedLineRepairs),
                ];

                $afterLines = [];

                foreach ($lockedLineRepairs as $repair) {
                    /** @var PosTransactionLine $dbLine */
                    $dbLine = $repair['line_obj'];
                    $meta = $repair['line_meta'];

                    // Only update lines that were missing authoritative metadata
                    if ($repair['was_missing_authoritative']) {
                        $meta['line_total_minor'] = $repair['target_total_minor'];
                        $meta['line_gross_minor'] = $repair['raw_gross_minor'];
                        $meta['line_discount_minor'] = $repair['discount_minor'];
                        $meta[PosCartTotalsCalculator::LINE_PRICING_FINGERPRINT] = PosCartTotalsCalculator::pricingFingerprint([
                            'product_id' => $dbLine->product_id,
                            'qty' => $dbLine->qty,
                            'unit_price' => $dbLine->unit_price,
                            'line_discount_type' => $dbLine->line_discount_type,
                            'line_discount_value' => $dbLine->line_discount_value,
                            'tax_id' => $dbLine->tax_id,
                            'tax_rate' => $dbLine->tax_rate_snapshot,
                            'price_source' => $meta['price_source'] ?? 'BASE',
                            'conversion_id' => $dbLine->conversion_id,
                            'bundle_id' => $meta['bundle_id'] ?? null,
                        ]);

                        $dbLine->update(['line_meta' => $meta]);
                    }

                    $afterLines[] = [
                        'line_id' => $dbLine->id,
                        'line_no' => $dbLine->line_no,
                        'line_meta' => $meta,
                    ];
                }

                $lockedTx->refresh();
                $newHash = $mapper->buildSnapshotHash($lockedTx);
                $lockedTx->update([
                    'snapshot_hash' => $newHash,
                    'last_saved_by' => $actorId,
                ]);

                $afterSnapshot = [
                    'transaction_id' => $lockedTx->id,
                    'snapshot_hash' => $newHash,
                    'snapshot_totals' => $lockedTx->snapshot_totals,
                    'lines' => $afterLines,
                ];

                // Comprehensive audit logging
                Log::info("POS Draft Repaired", [
                    'transaction_id' => $lockedTx->id,
                    'actor_user_id' => $actorId,
                    'increment' => $lockedIncrement,
                    'preview_hash' => $expectedPreviewHash,
                    'new_snapshot_hash' => $newHash,
                    'before_snapshot' => $beforeSnapshot,
                    'after_snapshot' => $afterSnapshot,
                ]);
            });
        } catch (\Throwable $e) {
            $this->error("Failed to repair draft: " . $e->getMessage());
            return 1;
        }

        $this->info("Draft #{$transaction->id} successfully repaired and audited.");
        return 0;
    }

    /**
     * Evaluate lines of transaction to determine candidate totals and preview hash.
     *
     * @return array{
     *     status: 'ok'|'error',
     *     message?: string,
     *     lines?: array,
     *     has_missing_authoritative?: bool,
     *     preview_hash?: string
     * }
     */
    public function evaluateTransactionLines(PosTransaction $transaction, float $increment): array
    {
        $transaction->load(['lines']);
        if ($transaction->lines->isEmpty()) {
            return [
                'status' => 'error',
                'message' => "Transaction has no lines. Refusing repair.",
            ];
        }

        $lineRepairs = [];
        $calculatedGrandTotalCents = 0;
        $hasMissingAuthoritative = false;

        foreach ($transaction->lines as $line) {
            $lineMeta = $line->line_meta ?? [];
            $qty = (float) $line->qty;
            $unitPrice = (float) $line->unit_price;
            $discountType = (string) ($line->line_discount_type ?? 'fixed');
            $discountValue = (float) ($line->line_discount_value ?? 0);
            $rawGross = $qty * $unitPrice;
            $rawGrossCents = (int) round($rawGross * 100);

            $discountCents = 0;
            if ($discountValue > 0) {
                if ($discountType === 'percentage') {
                    $discountCents = min($rawGrossCents, (int) round(($rawGrossCents * $discountValue) / 100));
                } else {
                    $discountCents = min($rawGrossCents, (int) round($discountValue * 100));
                }
            }

            $rawNetCents = max(0, $rawGrossCents - $discountCents);
            $priceSource = (string) ($lineMeta['price_source'] ?? 'BASE');
            $hasCanonicalOverride = in_array($priceSource, ['LINE_TOTAL_OVERRIDE', 'LINE_UNIT_PRICE_OVERRIDE'], true)
                && isset($lineMeta['line_gross_minor'], $lineMeta['line_discount_minor'], $lineMeta['line_net_minor']);

            $isManualOverride = in_array($priceSource, ['LINE_TOTAL_OVERRIDE', 'LINE_UNIT_PRICE_OVERRIDE', 'MANUAL'], true)
                || ! empty($lineMeta['is_manual'])
                || ! empty($lineMeta['manual_price']);

            $currentLineTotalMinor = $lineMeta['line_total_minor'] ?? null;
            $wasMissingAuthoritative = false;

            if ($hasCanonicalOverride) {
                // Canonical override row: preserve canonical net minor as authoritative
                $targetLineTotalMinor = (int) $lineMeta['line_net_minor'];
            } elseif ($isManualOverride) {
                // Manual override without rounding: if line_total_minor is set, keep it.
                if ($currentLineTotalMinor !== null) {
                    $targetLineTotalMinor = (int) $currentLineTotalMinor;
                } elseif (isset($lineMeta['line_total'])) {
                    // A legacy authoritative line_total exists but line_total_minor does not.
                    // PosTransactionLineAmountResolver treats this value as the persisted total
                    // for manual rows; recomputing qty * unit_price here would silently discard
                    // it and could produce a different amount. Refuse rather than guess.
                    return [
                        'status' => 'error',
                        'message' => "Line #{$line->line_no} is a manual override with legacy 'line_total' metadata but no line_total_minor. Refusing ambiguous recovery.",
                    ];
                } else {
                    $wasMissingAuthoritative = true;
                    $hasMissingAuthoritative = true;
                    $targetLineTotalMinor = $rawNetCents; // Manual rows bypass increment rounding!
                }
            } elseif ($currentLineTotalMinor !== null) {
                // Already has authoritative line_total_minor
                $targetLineTotalMinor = (int) $currentLineTotalMinor;
            } elseif (in_array($priceSource, ['BASE', 'TIER'], true)) {
                // Standard automatic row missing line_total_minor (BASE or customer-TIER pricing;
                // both are qty * unit_price - discount, only the price lookup differs)
                $wasMissingAuthoritative = true;
                $hasMissingAuthoritative = true;
                if ($increment > 0) {
                    $rawNet = $rawNetCents / 100;
                    $roundedNet = RowTotalRoundingCalculator::round($rawNet, $increment);
                    $targetLineTotalMinor = (int) round($roundedNet * 100);
                } else {
                    $targetLineTotalMinor = $rawNetCents;
                }
            } else {
                // Unknown or complex price source without authoritative metadata
                return [
                    'status' => 'error',
                    'message' => "Line #{$line->line_no} has non-automatic price source '{$priceSource}' missing authoritative metadata. Refusing ambiguous recovery.",
                ];
            }

            $calculatedGrandTotalCents += $targetLineTotalMinor;

            $lineRepairs[] = [
                'line_id' => $line->id,
                'line_no' => $line->line_no,
                'product' => $line->product_name_snapshot,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'price_source' => $priceSource,
                'line_discount_type' => $discountType,
                'line_discount_value' => $discountValue,
                'tax_id' => $line->tax_id,
                'tax_rate_snapshot' => $line->tax_rate_snapshot,
                'raw_gross_minor' => $rawGrossCents,
                'discount_minor' => $discountCents,
                'current_total_minor' => $currentLineTotalMinor,
                'target_total_minor' => $targetLineTotalMinor,
                'was_missing_authoritative' => $wasMissingAuthoritative,
                'line_meta' => $lineMeta,
                'line_obj' => $line,
            ];
        }

        $headerGrandTotal = (float) ($transaction->snapshot_totals['grand_total'] ?? 0.0);
        $headerGrandTotalCents = (int) round($headerGrandTotal * 100);

        // Header reconciliation check
        if ($calculatedGrandTotalCents !== $headerGrandTotalCents) {
            return [
                'status' => 'error',
                'message' => sprintf(
                    "Header mismatch: calculated line sum (%.2f) does not match snapshot header total (%.2f). Ambiguous data - refusing repair.",
                    $calculatedGrandTotalCents / 100,
                    $headerGrandTotal
                ),
            ];
        }

        // Compute preview hash over the complete relevant snapshot: identity, rounding
        // configuration, header, and every pricing input/metadata field a repair could
        // depend on -- not just target totals, so changes that leave totals unchanged
        // (e.g. a different price_source or discount that nets to the same result)
        // still invalidate a stale preview.
        $previewPayload = json_encode([
            'transaction_id' => $transaction->id,
            'setting_id' => $transaction->setting_id,
            'increment' => $increment,
            'snapshot_hash' => $transaction->snapshot_hash,
            'snapshot_totals' => $transaction->snapshot_totals,
            'header_cents' => $headerGrandTotalCents,
            'lines' => array_map(fn ($r) => [
                'line_id' => $r['line_id'],
                'qty' => $r['qty'],
                'unit_price' => $r['unit_price'],
                'price_source' => $r['price_source'],
                'line_discount_type' => $r['line_discount_type'],
                'line_discount_value' => $r['line_discount_value'],
                'tax_id' => $r['tax_id'],
                'tax_rate_snapshot' => $r['tax_rate_snapshot'],
                'current_total_minor' => $r['current_total_minor'],
                'target_total_minor' => $r['target_total_minor'],
                'line_meta' => $r['line_meta'],
            ], $lineRepairs),
        ]);
        $generatedPreviewHash = hash('sha256', (string) $previewPayload);

        return [
            'status' => 'ok',
            'lines' => $lineRepairs,
            'has_missing_authoritative' => $hasMissingAuthoritative,
            'preview_hash' => $generatedPreviewHash,
            'calculated_grand_total_cents' => $calculatedGrandTotalCents,
        ];
    }
}
