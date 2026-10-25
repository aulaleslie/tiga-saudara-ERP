<?php

namespace Modules\Pos\Services;

use App\Support\RowTotalRoundingCalculator;
use Illuminate\Support\Collection;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Setting\Entities\Setting;

/**
 * Service providing authoritative cumulative minor-unit cash-return valuation
 * and lifecycle rounding context management for POS returns.
 *
 * Persisted Sale `return_rounding_context` schema:
 * {
 *     "policy_version": 1,
 *     "pos_business_id": 1,
 *     "frozen_increment": 100.0,
 *     "created_at": "2026-10-24T14:30:00+08:00",
 *     "created_by": 1,
 *     "commercial_lines": {
 *         "<commercial_line_key>": {
 *             "commercial_line_id": 101,
 *             "baseline_quantity": 120.0,
 *             "baseline_returnable_amount": 790000.0,
 *             "historical_returned_quantity": 0.0,
 *             "historical_returned_amount": 0.0,
 *             "committed_quantity": 1.0,
 *             "committed_amount": 6600.0,
 *             "last_allocation_reference": "POSR-..."
 *         }
 *     }
 * }
 *
 * Valuation Rules:
 * Let A be original authoritative returnable charge, Q original quantity,
 * q cumulative eligible cash-returned quantity, H actual previously committed return value,
 * and I frozen increment:
 * - If q exhausts Q: target cumulative return is A.
 * - Otherwise: target = clamp(round_increment(A * q / Q, I), H, A).
 * - Current return is min(target - H, stored_residual).
 * - Exact minor-unit residual is consumed on final exhaustion without unit-price reconstruction.
 * - Zero increment retains two-decimal currency precision.
 * - Coarse increments permit zero monetary deltas with valid physical quantities.
 *
 * Baseline & Reversal Rules:
 * - Previews resolve provisional valuations without mutating database records.
 * - Increments are frozen at first successful cash return approval using the POS transaction business setting.
 * - Existing historical returns seed immutable baseline evidence without inventing a retroactive policy.
 * - Reversals atomically release recorded allocations based on audit evidence without recomputing historical refunds.
 */
class PosReturnRoundingValuationService
{
    public const POLICY_VERSION = 1;

    /**
     * Compute cumulative minor-unit valuation for a commercial line.
     *
     * @param float $baselineAmount Original authoritative charged amount (A)
     * @param float $baselineQuantity Original quantity (Q)
     * @param float $cumulativeReturnedQuantity Cumulative returned quantity including this request (q)
     * @param float $previouslyCommittedAmount Previously committed returned value (H)
     * @param float $increment Frozen increment (I), 0 for currency precision
     * @param float $storedResidual Stored returnable residual remaining on source line
     * @return array{
     *     target_cumulative_amount: float,
     *     current_return_amount: float,
     *     remaining_commercial_value: float,
     *     is_exhaustion: bool,
     *     raw_proportional_amount: float
     * }
     */
    public function calculateCumulativeValuation(
        float $baselineAmount,
        float $baselineQuantity,
        float $cumulativeReturnedQuantity,
        float $previouslyCommittedAmount,
        float $increment,
        float $storedResidual
    ): array {
        if ($baselineQuantity <= 0 || $baselineAmount <= 0) {
            throw new \InvalidArgumentException('Commercial line baseline evidence is ambiguous: quantity and returnable amount must be positive.');
        }

        if ($storedResidual < -0.01) {
            throw new \InvalidArgumentException('Commercial line stored residual cannot be negative.');
        }

        if ($cumulativeReturnedQuantity < 0) {
            throw new \InvalidArgumentException('Cumulative returned quantity cannot be negative.');
        }

        if ($cumulativeReturnedQuantity > $baselineQuantity + 0.0001) {
            throw new \InvalidArgumentException('Cumulative returned quantity exceeds original baseline quantity.');
        }

        if ($previouslyCommittedAmount < -0.01) {
            throw new \InvalidArgumentException('Previously committed return amount cannot be negative.');
        }

        if ($previouslyCommittedAmount > $baselineAmount + 0.01) {
            throw new \InvalidArgumentException('Previously committed return amount exceeds baseline returnable charge.');
        }

        $baselineAmount = round($baselineAmount, 2);
        $previouslyCommittedAmount = round($previouslyCommittedAmount, 2);
        $storedResidual = max(0.0, round($storedResidual, 2));

        $isExhaustion = $cumulativeReturnedQuantity >= ($baselineQuantity - 0.0001);

        $baselineMinor = (int) round($baselineAmount * 100);
        $prevMinor = (int) round($previouslyCommittedAmount * 100);
        $incrementMinor = (int) round(max(0.0, $increment) * 100);

        $rawProportionalAmount = round(($baselineAmount * $cumulativeReturnedQuantity) / $baselineQuantity, 4);

        if ($isExhaustion) {
            // Full exhaustion consumes the exact residual up to baseline amount
            $targetMinor = $baselineMinor;
            $currentReturnMinor = min(
                max(0, $targetMinor - $prevMinor),
                (int) round($storedResidual * 100)
            );
            $targetAmount = ($prevMinor + $currentReturnMinor) / 100.0;
            $currentReturnAmount = $currentReturnMinor / 100.0;
            $remainingCommercialValue = max(0.0, round($baselineAmount - $targetAmount, 2));

            return [
                'target_cumulative_amount' => round($targetAmount, 2),
                'current_return_amount' => round($currentReturnAmount, 2),
                'remaining_commercial_value' => round($remainingCommercialValue, 2),
                'is_exhaustion' => true,
                'raw_proportional_amount' => round($rawProportionalAmount, 2),
            ];
        }

        // Proportional valuation in minor units
        $rawMinor = ($baselineMinor * $cumulativeReturnedQuantity) / $baselineQuantity;

        if ($incrementMinor <= 0) {
            // Zero increment: round to minor units (currency precision) without increment rounding
            $roundedMinor = (int) round($rawMinor);
        } else {
            // Half-up increment rounding in minor units
            $remainder = fmod($rawMinor, $incrementMinor);
            if ($remainder < 0) {
                $remainder += $incrementMinor;
            }
            $quotient = floor($rawMinor / $incrementMinor);
            if (($remainder * 2) >= ($incrementMinor - 0.000001)) {
                $roundedMinor = (int) (($quotient + 1) * $incrementMinor);
            } else {
                $roundedMinor = (int) ($quotient * $incrementMinor);
            }
        }

        // Clamp target between H and A
        $targetMinor = max($prevMinor, min($baselineMinor, $roundedMinor));
        $deltaMinor = $targetMinor - $prevMinor;
        $deltaAmount = $deltaMinor / 100.0;

        // Cap current return by stored residual
        $currentReturnAmount = min(max(0.0, $deltaAmount), $storedResidual);
        $targetAmount = round($previouslyCommittedAmount + $currentReturnAmount, 2);
        $remainingCommercialValue = max(0.0, round($baselineAmount - $targetAmount, 2));

        return [
            'target_cumulative_amount' => round($targetAmount, 2),
            'current_return_amount' => round($currentReturnAmount, 2),
            'remaining_commercial_value' => round($remainingCommercialValue, 2),
            'is_exhaustion' => false,
            'raw_proportional_amount' => round($rawProportionalAmount, 2),
        ];
    }

    /**
     * Resolve the effective rounding increment for a source sale.
     * Uses frozen increment if already committed; otherwise resolves provisional
     * increment from the POS transaction business setting.
     */
    public function resolveEffectiveIncrement(Sale $sale, ?PosTransaction $posTransaction = null): float
    {
        $context = $sale->return_rounding_context;
        if (is_array($context) && isset($context['frozen_increment'])) {
            return (float) $context['frozen_increment'];
        }

        $posBusinessId = $this->resolvePosBusinessId($sale, $posTransaction);
        if ($posBusinessId <= 0) {
            return 0.0;
        }

        $setting = Setting::query()->whereKey($posBusinessId)->first();

        return (float) ($setting?->row_total_rounding_increment ?? 0.0);
    }

    /**
     * Resolve the POS transaction business setting ID.
     */
    public function resolvePosBusinessId(Sale $sale, ?PosTransaction $posTransaction = null): int
    {
        if ($posTransaction && (int) $posTransaction->setting_id > 0) {
            return (int) $posTransaction->setting_id;
        }

        $context = $sale->return_rounding_context;
        if (is_array($context) && ! empty($context['pos_business_id'])) {
            return (int) $context['pos_business_id'];
        }

        return (int) ($sale->setting_id ?? 0);
    }

    /**
     * Resolve the canonical commercial line key for a return line.
     * Guarantees identical commercial identity across preview planning, execution commit, and reversal.
     * For bundle returns, links component lines to their parent commercial key.
     */
    public function resolveCommercialLineKey(PosReturnLine $line, ?iterable $contextLines = null): string
    {
        if ($line->pos_transaction_line_id) {
            return 'ptl_' . $line->pos_transaction_line_id;
        }

        $posTxn = null;
        if ($line->relationLoaded('posReturn') && $line->posReturn?->posTransaction) {
            $posTxn = $line->posReturn->posTransaction;
        } elseif ($line->pos_return_id) {
            $pr = \Modules\Pos\Entities\PosReturn::with(['posTransaction.lines'])->find($line->pos_return_id);
            $posTxn = $pr?->posTransaction;
        }

        if ($line->bundle_parent_sale_detail_id) {
            if ($contextLines) {
                foreach ($contextLines as $candidate) {
                    if ((int) $candidate->sale_detail_id === (int) $line->bundle_parent_sale_detail_id) {
                        if (! empty($candidate->pos_transaction_line_id)) {
                            return 'ptl_' . $candidate->pos_transaction_line_id;
                        }
                        if ($posTxn) {
                            $candPtl = $posTxn->lines?->firstWhere('product_id', (int) $candidate->product_id);
                            if ($candPtl) {
                                return 'ptl_' . $candPtl->id;
                            }
                        }
                    }
                }
            } elseif ($line->relationLoaded('posReturn') && $line->posReturn && $line->posReturn->relationLoaded('lines')) {
                $parent = $line->posReturn->lines->first(fn ($l) => (int) $l->sale_detail_id === (int) $line->bundle_parent_sale_detail_id);
                if ($parent) {
                    if (! empty($parent->pos_transaction_line_id)) {
                        return 'ptl_' . $parent->pos_transaction_line_id;
                    }
                    if ($posTxn) {
                        $candPtl = $posTxn->lines?->firstWhere('product_id', (int) $parent->product_id);
                        if ($candPtl) {
                            return 'ptl_' . $candPtl->id;
                        }
                    }
                }
            }

            return 'detail_' . $line->bundle_parent_sale_detail_id;
        }

        if ($posTxn) {
            $candPtl = $posTxn->lines?->firstWhere('product_id', (int) $line->product_id);
            if ($candPtl) {
                return 'ptl_' . $candPtl->id;
            }
        }

        return 'detail_' . $line->sale_detail_id;
    }

    /**
     * Resolve authoritative commercial line baseline (original charged amount and quantity).
     *
     * @return array{
     *     commercial_line_key: string,
     *     commercial_line_id: int|null,
     *     baseline_quantity: float,
     *     baseline_returnable_amount: float,
     *     stored_residual: float
     * }
     */
    public function resolveCommercialBaseline(
        SaleDetails|PosReturnLine $source,
        ?PosTransactionLine $transactionLine = null
    ): array {
        $lineMeta = null;
        if ($source instanceof PosReturnLine) {
            $transactionLine = $transactionLine ?? ($source->relationLoaded('posTransactionLine')
                ? $source->posTransactionLine
                : ($source->pos_transaction_line_id ? \Modules\Pos\Entities\PosTransactionLine::find($source->pos_transaction_line_id) : null));
            if (! $transactionLine) {
                $posTxn = null;
                if ($source->relationLoaded('posReturn') && $source->posReturn?->posTransaction) {
                    $posTxn = $source->posReturn->posTransaction;
                } elseif ($source->pos_return_id) {
                    $pr = \Modules\Pos\Entities\PosReturn::with(['posTransaction.lines'])->find($source->pos_return_id);
                    $posTxn = $pr?->posTransaction;
                }
                if ($posTxn) {
                    $transactionLine = $posTxn->lines?->firstWhere('product_id', (int) $source->product_id);
                }
            }
            $saleDetail = $source->relationLoaded('saleDetail')
                ? $source->saleDetail
                : ($source->sale_detail_id ? \Modules\Sale\Entities\SaleDetails::find($source->sale_detail_id) : null);
            $lineMeta = $source->line_meta;
        } else {
            $saleDetail = $source;
        }

        if ($transactionLine) {
            $amounts = PosTransactionLineAmountResolver::resolve($transactionLine);
            $baselineAmount = (float) $amounts['charged_total'];
            $baselineQuantity = (float) $transactionLine->qty;
            $lineKey = 'ptl_' . $transactionLine->id;
            $lineId = (int) $transactionLine->id;
        } elseif ($saleDetail) {
            $detailId = ($source instanceof PosReturnLine && $source->bundle_parent_sale_detail_id)
                ? (int) $source->bundle_parent_sale_detail_id
                : (int) $saleDetail->id;
            $baselineQuantity = (float) ($saleDetail->quantity ?? 0);
            $baselineAmount = (float) ($saleDetail->sub_total ?? 0);

            $isBundle = $saleDetail->relationLoaded('bundleItems') ? $saleDetail->bundleItems->isNotEmpty() : $saleDetail->bundleItems()->exists();
            $isParentOnly = ($source instanceof PosReturnLine && $source->bundle_return_scope === PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY);
            if ($isBundle && ! $isParentOnly) {
                $bundleItems = $saleDetail->relationLoaded('bundleItems') ? $saleDetail->bundleItems : $saleDetail->bundleItems()->get();
                $componentAmount = (float) $bundleItems->sum(fn ($bi) => (float) ($bi->sub_total ?? ($bi->price * $bi->quantity)));
                $baselineAmount += $componentAmount;
            }

            if ($source instanceof PosReturnLine && ($baselineQuantity <= 0 || $baselineAmount <= 0)) {
                $candidateQty = (float) ($saleDetail->quantity ?? 0) + (float) $source->quantity;
                $candidateAmt = (float) ($saleDetail->sub_total ?? 0) + (float) ($source->expected_cash_amount ?? $source->line_total ?? 0);
                if ($candidateQty > 0 && $candidateAmt > 0) {
                    $baselineQuantity = $candidateQty;
                    $baselineAmount = $candidateAmt;
                }
            }

            $lineKey = 'detail_' . $detailId;
            $lineId = $detailId;
        } else {
            throw new \RuntimeException('Cannot resolve commercial line baseline: missing transaction line and sale detail.');
        }

        if (is_array($lineMeta) && isset($lineMeta['reviewed_unit_price'])) {
            $reviewedUnitPrice = (float) $lineMeta['reviewed_unit_price'];
            if ($reviewedUnitPrice > 0 && $baselineQuantity > 0) {
                $baselineAmount = round($reviewedUnitPrice * $baselineQuantity, 2);
            }
        }

        if ($baselineQuantity <= 0 || $baselineAmount <= 0) {
            throw new \RuntimeException("Commercial baseline for line {$lineKey} is ambiguous: quantity ({$baselineQuantity}) or amount ({$baselineAmount}) is not positive.");
        }

        $isBundle = false;
        if ($transactionLine) {
            $isBundle = !empty($transactionLine->line_meta['bundle_id'])
                || !empty($transactionLine->line_meta['is_bundle'])
                || !empty($transactionLine->line_meta['bundle_items']);
        }
        if (!$isBundle && $saleDetail) {
            $isBundle = $saleDetail->relationLoaded('bundleItems') ? $saleDetail->bundleItems->isNotEmpty() : $saleDetail->bundleItems()->exists();
        }

        if ($isBundle) {
            $isParentOnly = ($source instanceof PosReturnLine && $source->bundle_return_scope === PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY);
            if ($isParentOnly) {
                $storedResidual = $saleDetail ? (float) ($saleDetail->sub_total ?? 0) : $baselineAmount;
            } else {
                $parentResidual = $saleDetail ? (float) ($saleDetail->sub_total ?? 0) : 0.0;
                $componentResidual = 0.0;
                if ($saleDetail) {
                    $bundleItems = $saleDetail->relationLoaded('bundleItems') ? $saleDetail->bundleItems : $saleDetail->bundleItems()->get();
                    $componentResidual = (float) $bundleItems->sum(fn ($bi) => (float) ($bi->sub_total ?? ($bi->price * $bi->quantity)));
                }
                $storedResidual = round($parentResidual + $componentResidual, 2);
                if ($storedResidual <= 0) {
                    $storedResidual = $baselineAmount;
                }
            }
        } else {
            $storedResidual = $saleDetail ? (float) ($saleDetail->sub_total ?? 0) : $baselineAmount;
        }

        return [
            'commercial_line_key' => $lineKey,
            'commercial_line_id' => $lineId,
            'baseline_quantity' => round($baselineQuantity, 4),
            'baseline_returnable_amount' => round($baselineAmount, 2),
            'stored_residual' => round($storedResidual, 2),
        ];
    }

    /**
     * Seed or get the existing commercial line state from a Sale's return rounding context.
     * Seeds historical completed returns if no context exists yet.
     */
    public function getOrSeedCommercialLineState(
        Sale $sale,
        string $commercialLineKey,
        array $baselineInfo,
        ?int $ignorePosReturnId = null
    ): array {
        $context = $sale->return_rounding_context ?? [];
        $lines = $context['commercial_lines'] ?? [];

        if (isset($lines[$commercialLineKey])) {
            return $lines[$commercialLineKey];
        }

        // Seed historical returns for this line
        $historical = $this->queryHistoricalCommittedReturns($sale, $commercialLineKey, $baselineInfo, $ignorePosReturnId);

        return [
            'commercial_line_id' => $baselineInfo['commercial_line_id'],
            'baseline_quantity' => $baselineInfo['baseline_quantity'],
            'baseline_returnable_amount' => $baselineInfo['baseline_returnable_amount'],
            'historical_returned_quantity' => $historical['quantity'],
            'historical_returned_amount' => $historical['amount'],
            'committed_quantity' => $historical['quantity'],
            'committed_amount' => $historical['amount'],
            'last_allocation_reference' => $historical['last_reference'],
        ];
    }

    /**
     * Query completed historical return evidence for a line before this policy was initialized.
     *
     * @return array{quantity: float, amount: float, last_reference: string|null}
     */
    public function queryHistoricalCommittedReturns(
        Sale $sale,
        string $commercialLineKey,
        array $baselineInfo,
        ?int $ignorePosReturnId = null
    ): array {
        $ptlId = str_starts_with($commercialLineKey, 'ptl_')
            ? (int) substr($commercialLineKey, 4)
            : null;

        $saleDetailId = str_starts_with($commercialLineKey, 'detail_')
            ? (int) substr($commercialLineKey, 7)
            : null;

        $query = PosReturnLine::query()
            ->where('resolution', PosReturnLine::RESOLUTION_CASH_RETURN)
            ->whereHas('posReturn', function ($q) {
                $q->where('status', PosReturn::STATUS_COMPLETED)
                    ->where('is_reversed', false);
            });

        if ($ignorePosReturnId) {
            $query->where('pos_return_id', '!=', $ignorePosReturnId);
        }

        if ($ptlId) {
            $query->where('pos_transaction_line_id', $ptlId);
        } elseif ($saleDetailId) {
            $query->where(function ($q) use ($saleDetailId) {
                $q->where('sale_detail_id', $saleDetailId)
                    ->orWhere('bundle_parent_sale_detail_id', $saleDetailId);
            });
        } else {
            return ['quantity' => 0.0, 'amount' => 0.0, 'last_reference' => null];
        }

        $lines = $query->with('posReturn')->get();
        if ($lines->isEmpty()) {
            return ['quantity' => 0.0, 'amount' => 0.0, 'last_reference' => null];
        }

        $parentLines = $lines->filter(fn ($l) => empty($l->bundle_parent_sale_detail_id) || (int) $l->bundle_parent_sale_detail_id === (int) $l->sale_detail_id);
        $quantity = $parentLines->isNotEmpty()
            ? (float) $parentLines->sum('quantity')
            : (float) $lines->sum('quantity');

        $amount = (float) $lines->sum(fn ($l) => (float) ($l->expected_cash_amount ?? $l->line_total ?? 0));
        $lastReference = $lines->last()?->posReturn?->reference;

        return [
            'quantity' => round($quantity, 4),
            'amount' => round($amount, 2),
            'last_reference' => $lastReference,
        ];
    }

    /**
     * Initialize or update rounding context on a Sale upon first successful approval commit.
     *
     * @param Sale $sale
     * @param PosTransaction $posTransaction
     * @param string $commercialLineKey
     * @param array $baselineInfo
     * @param float $committedDeltaQty
     * @param float $committedDeltaAmount
     * @param string $allocationReference
     * @param int|null $actorId
     * @return array The updated return_rounding_context array
     */
    public function commitLineAllocation(
        Sale $sale,
        ?PosTransaction $posTransaction = null,
        string $commercialLineKey = '',
        array $baselineInfo = [],
        float $committedDeltaQty = 0.0,
        float $committedDeltaAmount = 0.0,
        string $allocationReference = '',
        ?int $actorId = null
    ): array {
        $context = $sale->return_rounding_context ?? [];

        if (empty($context['policy_version'])) {
            $context['policy_version'] = self::POLICY_VERSION;
            $context['pos_business_id'] = $posTransaction ? (int) $posTransaction->setting_id : (int) ($sale->setting_id ?? 0);
            $context['frozen_increment'] = $this->resolveEffectiveIncrement($sale, $posTransaction);
            $context['created_at'] = now()->toIso8601String();
            $context['created_by'] = $actorId;
            $context['commercial_lines'] = [];
        }

        $lineState = $this->getOrSeedCommercialLineState($sale, $commercialLineKey, $baselineInfo);

        $lineState['committed_quantity'] = round($lineState['committed_quantity'] + $committedDeltaQty, 4);
        $lineState['committed_amount'] = round($lineState['committed_amount'] + $committedDeltaAmount, 2);
        $lineState['last_allocation_reference'] = $allocationReference;

        $context['commercial_lines'][$commercialLineKey] = $lineState;

        $sale->return_rounding_context = $context;
        $sale->save();

        return $context;
    }

    /**
     * Release allocations for a reversed or cancelled POS return atomically from source Sale contexts.
     */
    public function releaseReturnAllocation(Sale $sale, PosReturn $posReturn): void
    {
        $context = $sale->return_rounding_context;
        if (! is_array($context) || empty($context['commercial_lines'])) {
            return;
        }

        $posReturn->loadMissing('lines');
        $lines = $posReturn->lines
            ->filter(fn ($l) => $l->resolution === PosReturnLine::RESOLUTION_CASH_RETURN);

        $grouped = $lines->groupBy(fn ($l) => $this->resolveCommercialLineKey($l, $lines));

        $modified = false;
        foreach ($grouped as $key => $groupLines) {
            if (! isset($context['commercial_lines'][$key])) {
                continue;
            }

            $currentCommittedQty = (float) ($context['commercial_lines'][$key]['committed_quantity'] ?? 0);
            $currentCommittedAmt = (float) ($context['commercial_lines'][$key]['committed_amount'] ?? 0);

            $parentLine = $groupLines->first(fn ($l) => empty($l->bundle_parent_sale_detail_id) || (int) $l->bundle_parent_sale_detail_id === (int) $l->sale_detail_id) ?? $groupLines->first();
            $releaseQty = (float) $parentLine->quantity;
            $releaseAmt = (float) $groupLines->sum(fn ($l) => (float) ($l->expected_cash_amount ?? $l->line_total ?? 0));

            $context['commercial_lines'][$key]['committed_quantity'] = max(
                (float) ($context['commercial_lines'][$key]['historical_returned_quantity'] ?? 0),
                round($currentCommittedQty - $releaseQty, 4)
            );

            $context['commercial_lines'][$key]['committed_amount'] = max(
                (float) ($context['commercial_lines'][$key]['historical_returned_amount'] ?? 0),
                round($currentCommittedAmt - $releaseAmt, 2)
            );

            $context['commercial_lines'][$key]['last_allocation_reference'] = 'reversed_' . $posReturn->reference;
            $modified = true;
        }

        if ($modified) {
            $sale->return_rounding_context = $context;
            $sale->save();
        }
    }

    /**
     * Deterministically allocate a single commercial return amount across owner/bundle fragments.
     * All fragments receive exact minor-unit portions summing to the total without independent increment rounding.
     *
     * @param float $totalCommercialAmount Total commercial return amount in Rupiah
     * @param Collection|array<int|string, array{id: mixed, basis: float}> $fragments
     * @return array<int|string, float> Map of fragment key to allocated amount in Rupiah
     */
    public function allocateCommercialAmountAcrossFragments(float $totalCommercialAmount, Collection|array $fragments): array
    {
        $fragmentsCollection = collect($fragments);
        if ($fragmentsCollection->isEmpty()) {
            return [];
        }

        if ($fragmentsCollection->count() === 1) {
            $key = $fragmentsCollection->keys()->first();
            return [$key => round($totalCommercialAmount, 2)];
        }

        $totalCents = (int) round($totalCommercialAmount * 100);
        $totalBasis = (float) $fragmentsCollection->sum(fn ($f) => (float) ($f['basis'] ?? 0));

        if ($totalBasis <= 0) {
            // Equal share if no basis
            $count = $fragmentsCollection->count();
            $eachCents = (int) floor($totalCents / $count);
            $remainderCents = $totalCents - ($eachCents * $count);

            $allocations = [];
            $i = 0;
            foreach ($fragmentsCollection as $key => $frag) {
                $allocations[$key] = ($eachCents + ($i < $remainderCents ? 1 : 0)) / 100.0;
                $i++;
            }
            return $allocations;
        }

        $allocatedCents = [];
        $remainders = [];
        $sumAllocatedCents = 0;

        foreach ($fragmentsCollection as $key => $frag) {
            $basis = (float) ($frag['basis'] ?? 0);
            $exactCents = ($totalCents * $basis) / $totalBasis;
            $floorCents = (int) floor($exactCents);
            $allocatedCents[$key] = $floorCents;
            $remainders[$key] = $exactCents - $floorCents;
            $sumAllocatedCents += $floorCents;
        }

        $leftoverCents = $totalCents - $sumAllocatedCents;
        if ($leftoverCents > 0) {
            // Sort keys by remainder descending
            arsort($remainders);
            foreach ($remainders as $key => $rem) {
                if ($leftoverCents <= 0) {
                    break;
                }
                $allocatedCents[$key]++;
                $leftoverCents--;
            }
        }

        $result = [];
        foreach ($allocatedCents as $key => $cents) {
            $result[$key] = $cents / 100.0;
        }

        return $result;
    }

    /**
     * Evaluate authoritative cumulative rounding valuation for return draft selections.
     * Used identically across live form display (create and edit) and persistence.
     *
     * @param array $snapshot Source POS transaction snapshot
     * @param array $lineSelections Selections keyed by line key
     * @param int|null $ignorePosReturnId Return ID to exclude when editing an existing draft
     * @return array{
     *     lines: array<string, array{
     *         line_key: string,
     *         resolution: string,
     *         quantity: float,
     *         refund_amount: float,
     *         retained_amount: float,
     *         is_actionable: bool
     *     }>,
     *     total_cash_return: float,
     *     total_retained_charges: float,
     *     total_actionable: int
     * }
     */
    public function evaluateDraftValuation(
        array $snapshot,
        array $lineSelections,
        ?int $ignorePosReturnId = null
    ): array {
        $posTransactionId = $snapshot['pos_transaction_id'] ?? $snapshot['header']['transaction_id'] ?? null;
        $posTransaction = $posTransactionId
            ? PosTransaction::with(['lines'])->find($posTransactionId)
            : null;

        $saleIds = collect($snapshot['lines'] ?? [])->pluck('sale_id')->filter()->unique();
        $sales = Sale::whereIn('id', $saleIds)->get()->keyBy('id');

        $saleDetailIds = collect($snapshot['lines'] ?? [])->pluck('sale_detail_id')->filter()->unique();
        $saleDetails = SaleDetails::with(['bundleItems'])->whereIn('id', $saleDetailIds)->get()->keyBy('id');

        $evaluatedLines = [];
        $totalCashReturn = 0.0;
        $totalRetainedCharges = 0.0;
        $totalActionable = 0;

        // Commercial line accumulated tracking within this draft
        $draftAccumulated = [];

        foreach ($snapshot['lines'] ?? [] as $line) {
            $key = \Modules\Pos\Livewire\PosReturn\PosReturnCreateForm::buildLineKey($line);
            $sel = $lineSelections[$key] ?? ['resolution' => PosReturnLine::RESOLUTION_NONE];
            $res = $sel['resolution'] ?? PosReturnLine::RESOLUTION_NONE;

            $isSerial = !empty($line['is_tracked']) && !empty($line['serial_number_ids']);
            $quantity = $isSerial ? 1.0 : max(0.0, (float) ($sel['quantity'] ?? 0));

            $isActionable = false;
            $refundAmount = 0.0;
            $retainedAmount = 0.0;

            if ($res === PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT) {
                $isActionable = true;
                $totalActionable++;
            } elseif ($res === PosReturnLine::RESOLUTION_CASH_RETURN && $quantity > 0) {
                $isActionable = true;
                $totalActionable++;

                $bundleScope = $sel['bundle_return_scope'] ?? PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE;
                $isBundle = !empty($line['is_bundle']);
                $componentRetainedPerUnit = $isBundle
                    ? (float) collect($line['bundle_items'] ?? [])->sum(fn ($bi) => (float) ($bi['allocated_price'] ?? $bi['price'] ?? 0))
                    : 0.0;
                $parentAllocatedPrice = (float) ($line['parent_allocated_price'] ?? ($line['unit_price'] - $componentRetainedPerUnit));
                if ($parentAllocatedPrice <= 0 && (float) $line['unit_price'] > 0) {
                    $parentAllocatedPrice = (float) $line['unit_price'];
                    $componentRetainedPerUnit = 0.0;
                }

                if ($isBundle && $bundleScope === PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY) {
                    $retainedAmount = round($quantity * $componentRetainedPerUnit, 2);
                }

                $saleDetail = $saleDetails->get((int) ($line['sale_detail_id'] ?? 0));
                $sale = $sales->get((int) ($line['sale_id'] ?? $saleDetail?->sale_id));
                $ptl = $posTransaction?->lines?->firstWhere('id', (int) ($line['pos_transaction_line_id'] ?? 0));

                try {
                    if ($isBundle && $bundleScope === PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY) {
                        $commKey = 'detail_' . $line['sale_detail_id'];
                        $baselineQty = (float) ($saleDetail?->quantity ?? $line['original_quantity'] ?? $line['quantity'] ?? 1);
                        $baselineAmt = round($parentAllocatedPrice * $baselineQty, 2);
                        $storedResidual = (float) ($saleDetail?->sub_total ?? 0);
                        if ($storedResidual <= 0) {
                            $storedResidual = $baselineAmt;
                        }
                        $baseline = [
                            'commercial_line_key' => $commKey,
                            'commercial_line_id' => (int) $line['sale_detail_id'],
                            'baseline_quantity' => $baselineQty,
                            'baseline_returnable_amount' => $baselineAmt,
                            'stored_residual' => $storedResidual,
                        ];
                    } else {
                        $baseline = $this->resolveCommercialBaseline($saleDetail ?? $line, $ptl);
                        $commKey = $baseline['commercial_line_key'];
                        if ($isBundle) {
                            $parentResidual = (float) ($saleDetail?->sub_total ?? ($parentAllocatedPrice * $baseline['baseline_quantity']));
                            $componentResidual = 0.0;
                            if ($saleDetail) {
                                $bundleItems = $saleDetail->relationLoaded('bundleItems') ? $saleDetail->bundleItems : $saleDetail->bundleItems()->get();
                                if ($bundleItems->isNotEmpty()) {
                                    $componentResidual = (float) $bundleItems->sum(fn ($bi) => (float) ($bi->sub_total ?? ($bi->price * $bi->quantity)));
                                }
                            }
                            if ($componentResidual <= 0 && !empty($line['bundle_items'])) {
                                $componentResidual = (float) collect($line['bundle_items'])->sum(function ($bi) use ($baseline) {
                                    $itemPrice = (float) ($bi['allocated_price'] ?? $bi['price'] ?? 0);
                                    $itemQty = (float) ($bi['quantity'] ?? 1);
                                    return round($itemPrice * $itemQty * $baseline['baseline_quantity'], 2);
                                });
                            }
                            $storedResidual = round($parentResidual + $componentResidual, 2);
                            if ($storedResidual <= 0) {
                                $storedResidual = (float) $baseline['stored_residual'];
                            }
                        } else {
                            $storedResidual = (float) ($saleDetail?->sub_total ?? $baseline['stored_residual']);
                        }
                    }

                    $increment = $sale
                        ? $this->resolveEffectiveIncrement($sale, $posTransaction)
                        : 0.0;

                    $lineState = $sale
                        ? $this->getOrSeedCommercialLineState($sale, $commKey, $baseline, $ignorePosReturnId)
                        : [];

                    $prevCommQty = (float) ($lineState['committed_quantity'] ?? 0);
                    $prevCommAmt = (float) ($lineState['committed_amount'] ?? 0);

                    if (!isset($draftAccumulated[$commKey])) {
                        $draftAccumulated[$commKey] = [
                            'qty' => 0.0,
                            'amt' => 0.0,
                        ];
                    }

                    $accumQty = $draftAccumulated[$commKey]['qty'];
                    $accumAmt = $draftAccumulated[$commKey]['amt'];

                    $cumQty = min($baseline['baseline_quantity'], $prevCommQty + $accumQty + $quantity);

                    $val = $this->calculateCumulativeValuation(
                        $baseline['baseline_returnable_amount'],
                        $baseline['baseline_quantity'],
                        $cumQty,
                        $prevCommAmt + $accumAmt,
                        $increment,
                        $storedResidual
                    );

                    $refundAmount = $val['current_return_amount'];

                    $draftAccumulated[$commKey]['qty'] += $quantity;
                    $draftAccumulated[$commKey]['amt'] += $refundAmount;
                } catch (\Throwable $e) {
                    $refundAmount = ($isBundle && $bundleScope === PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY)
                        ? round($quantity * $parentAllocatedPrice, 2)
                        : round($quantity * (float) $line['unit_price'], 2);
                }

                $totalCashReturn += $refundAmount;
                $totalRetainedCharges += $retainedAmount;
            }

            $evaluatedLines[$key] = [
                'line_key' => $key,
                'resolution' => $res,
                'quantity' => $quantity,
                'refund_amount' => $refundAmount,
                'retained_amount' => $retainedAmount,
                'is_actionable' => $isActionable,
            ];
        }

        return [
            'lines' => $evaluatedLines,
            'total_cash_return' => round($totalCashReturn, 2),
            'total_retained_charges' => round($totalRetainedCharges, 2),
            'total_actionable' => $totalActionable,
        ];
    }
}
