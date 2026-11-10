<?php

namespace Modules\Consignment\Services;

use App\Support\ImportDocumentAdjustmentAllocator;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutLine;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;

/**
 * Receipt-backed invoice pricing for a buyout. Reuses ConsignmentBillingPricingCalculator for every row and
 * the existing deterministic adjustment allocator for the document discount, so preview and accepted terms
 * share one calculation. Nothing here moves stock or writes cost: it produces three separate amounts per
 * slice (original evidence, accepted invoice, inventory cost) and header totals that reconcile exactly.
 *
 * Intent (buyout->pricing_intent): rows[<line id>] => {unit_price, discount_type, discount_value, tax_id,
 * row_total_override}, global_discount_type, global_discount_value, is_tax_included.
 * Slice identity is the buyout line id (receipt + type + sold source + location), so sold and unsold slices
 * of the same product and mixed receipts stay commercially separate and lossless.
 */
class ConsignmentBuyoutPricingService
{
    public function __construct(
        protected ?ConsignmentBillingPricingCalculator $calculator = null,
        protected ?ImportDocumentAdjustmentAllocator $allocator = null
    ) {
        $this->calculator ??= new ConsignmentBillingPricingCalculator();
        $this->allocator ??= new ImportDocumentAdjustmentAllocator();
    }

    /**
     * @return array{
     *   valid: bool, blockers: string[], is_pkp: bool, is_tax_included: bool, neutralized_tax_inputs: bool,
     *   slices: array<int, array<string, mixed>>,
     *   totals: array{original_total: float, sub_total: float, tax_amount: float, gross_total: float,
     *                 global_discount_type: string, global_discount_amount: float, total_amount: float,
     *                 inventory_cost_total: float}
     * }
     */
    public function calculate(ConsignmentBuyout $buyout): array
    {
        $intent = $buyout->pricing_intent ?? [];
        $setting = Setting::find($buyout->setting_id);
        $isPkp = (bool) ($setting->is_pkp ?? false);
        $isTaxIncluded = $isPkp ? (bool) ($intent['is_tax_included'] ?? true) : false;
        $rowIntents = is_array($intent['rows'] ?? null) ? $intent['rows'] : [];
        $blockers = [];

        // A non-PKP business never carries tax: submitted tax inputs are ignored (neutralized), not trusted.
        $neutralized = false;
        if (! $isPkp) {
            foreach ($rowIntents as $i) {
                if (($i['tax_id'] ?? null) !== null && $i['tax_id'] !== '') {
                    $neutralized = true;
                }
            }
        }

        $lines = $buyout->lines()->orderBy('id')->get();
        $slices = [];
        foreach ($lines as $line) {
            $detail = ConsignmentReceivingDetail::find($line->consignment_receiving_detail_id);
            if (! $detail) {
                $blockers[] = "Line #{$line->id}: receipt detail not found.";
                continue;
            }
            $qty = (float) $line->base_quantity;
            $received = (float) ($detail->received_base_quantity ?? $detail->quantity_received);
            $unitDpp = (float) $detail->unit_dpp;
            $unitCost = (float) $detail->unit_cost;
            $unitPrice = $unitDpp > 0 ? $unitDpp : $unitCost;
            $subTotal = round($qty * $unitPrice, 2);
            // Proportional original tax evidence: the receipt's stored tax scaled to the slice quantity.
            $taxAmount = $received > 0 ? round((float) ($detail->tax_amount ?? 0) * $qty / $received, 2) : 0.0;
            $originalTotal = round($subTotal + $taxAmount, 2);

            $rowIntent = is_array($rowIntents[$line->id] ?? null) ? $rowIntents[$line->id] : [];
            if (! $isPkp) {
                unset($rowIntent['tax_id']);
            }
            $unchanged = $this->intentIsEmpty($rowIntent, $detail->tax_id);

            $resolvedRate = null;
            if ($isPkp && ! $unchanged && ($rowIntent['tax_id'] ?? null) !== null && $rowIntent['tax_id'] !== '' && (int) $rowIntent['tax_id'] !== (int) $detail->tax_id) {
                $rate = Tax::active()->whereKey((int) $rowIntent['tax_id'])->value('value');
                $resolvedRate = $rate !== null ? (float) $rate : null;
                if ($resolvedRate === null) {
                    $blockers[] = "Line #{$line->id}: selected tax [{$rowIntent['tax_id']}] is not a valid active tax.";
                }
            }

            $calc = $this->calculator->calculateRow([
                'quantity' => $qty,
                'original_unit_price' => $unitPrice,
                'original_sub_total' => $subTotal,
                'original_tax_amount' => $taxAmount,
                'original_total' => $originalTotal,
                'allocation_tax_id' => $detail->tax_id,
                'allocation_tax_rate' => (float) ($detail->tax_rate ?? 0),
            ], $rowIntent, $isPkp, $isTaxIncluded, $unchanged, $resolvedRate);

            foreach ($calc['errors'] as $e) {
                $blockers[] = "Line #{$line->id}: {$e}";
            }
            if (! is_finite($calc['total']) || $calc['total'] < 0) {
                $blockers[] = "Line #{$line->id}: calculated total [{$calc['total']}] is invalid.";
            }

            $slices[$line->id] = [
                'line_id' => $line->id,
                'line_type' => $line->line_type,
                'receiving_detail_id' => $line->consignment_receiving_detail_id,
                'product_id' => $line->product_id,
                'quantity' => $qty,
                'original' => ['unit_price' => $unitPrice, 'sub_total' => $subTotal, 'tax_amount' => $taxAmount, 'total' => $originalTotal],
                'accepted' => [
                    'unit_price' => $calc['unit_price'], 'discount_type' => $calc['discount_type'], 'discount_amount' => $calc['discount_amount'],
                    'tax_id' => $calc['tax_id'], 'tax_rate' => $calc['tax_rate'], 'tax_amount' => $calc['tax_amount'],
                    'sub_total' => $calc['sub_total'], 'total' => $calc['total'],
                ],
                'document_discount' => 0.0,
                'payable' => $calc['total'],
                'inventory_cost' => null,
            ];
        }

        // Header: document discount over the sum of tax-inclusive row totals (Purchase create semantics).
        $sumRows = round(array_sum(array_map(fn ($s) => $s['accepted']['total'], $slices)), 2);
        $type = strtolower((string) ($intent['global_discount_type'] ?? 'fixed'));
        $discount = $this->calculator->applyGlobalDiscount($sumRows, $type, (float) ($intent['global_discount_value'] ?? 0));
        foreach ($discount['errors'] as $e) {
            $blockers[] = $e;
        }

        // Deterministic allocation over positive pre-global totals, stable slice (line id) ordering.
        ksort($slices);
        $allocations = $this->allocator->allocate(
            array_map(fn ($s) => (float) $s['accepted']['total'], $slices),
            (float) $discount['discount_amount']
        );
        foreach ($slices as $id => &$s) {
            $s['document_discount'] = round((float) ($allocations[$id] ?? 0.0), 2);
            $s['payable'] = round($s['accepted']['total'] - $s['document_discount'], 2);
            if ($s['payable'] < -0.005) {
                $blockers[] = "Line #{$id}: payable after the document discount is negative.";
            }

            if ($s['line_type'] === ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT) {
                // Inventory DPP: recoverable tax excluded, non-PKP tax zero, document discount counted once
                // (only its net-of-tax share reduces cost for a PKP row).
                $total = (float) $s['accepted']['total'];
                $sub = (float) $s['accepted']['sub_total'];
                $netDiscount = $total > 0 ? round($s['document_discount'] * ($sub / $total), 2) : 0.0;
                $cost = round($sub - $netDiscount, 2);
                if ($cost < 0) {
                    $blockers[] = "Line #{$id}: inventory cost would be negative ({$cost}) and is rejected rather than clamped.";
                }
                $s['inventory_cost'] = $cost;
            }
        }
        unset($s);

        $payableSum = round(array_sum(array_column($slices, 'payable')), 2);
        if (abs($payableSum - $discount['total_amount']) > 0.005) {
            $blockers[] = "Slice payables ({$payableSum}) do not reconcile with the header total ({$discount['total_amount']}).";
        }

        $totals = [
            'original_total' => round(array_sum(array_map(fn ($s) => $s['original']['total'], $slices)), 2),
            'sub_total' => round(array_sum(array_map(fn ($s) => $s['accepted']['sub_total'], $slices)), 2),
            'tax_amount' => round(array_sum(array_map(fn ($s) => $s['accepted']['tax_amount'], $slices)), 2),
            'gross_total' => $sumRows,
            'global_discount_type' => in_array($type, ['fixed', 'percentage'], true) ? $type : 'fixed',
            'global_discount_amount' => $discount['discount_amount'],
            'total_amount' => $discount['total_amount'],
            'inventory_cost_total' => round(array_sum(array_map(fn ($s) => (float) ($s['inventory_cost'] ?? 0), $slices)), 2),
        ];

        return [
            'valid' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'is_pkp' => $isPkp,
            'is_tax_included' => $isTaxIncluded,
            'neutralized_tax_inputs' => $neutralized,
            'slices' => array_values($slices),
            'totals' => $totals,
        ];
    }

    /**
     * Persist the accepted terms on each line (allowed while approved). Returns the same calculation, so
     * preview and acceptance cannot disagree.
     */
    public function accept(ConsignmentBuyout $buyout): array
    {
        $result = $this->calculate($buyout);
        if (! $result['valid']) {
            throw new \InvalidArgumentException('Pricing cannot be accepted: ' . implode(' ', $result['blockers']));
        }
        foreach ($result['slices'] as $slice) {
            $line = ConsignmentBuyoutLine::whereKey($slice['line_id'])->firstOrFail();
            $snapshot = [
                'is_pkp' => $result['is_pkp'], 'is_tax_included' => $result['is_tax_included'],
                'original' => $slice['original'], 'accepted' => $slice['accepted'],
                'document_discount' => $slice['document_discount'], 'payable' => $slice['payable'],
                'inventory_cost' => $slice['inventory_cost'],
            ];
            // Equivalent re-acceptance (finalization re-runs it) must not write: MySQL normalizes JSON numerics, so a
            // byte-level comparison would mark identical terms dirty and trip the immutability guard after approval.
            if ($line->accepted_snapshot !== null && $this->canonical($line->accepted_snapshot) === $this->canonical($snapshot)) {
                continue;
            }
            $line->update(['accepted_snapshot' => $snapshot]);
        }

        return $result;
    }

    /** Order- and numeric-format-insensitive form of a snapshot (numbers compared at 6 decimals). */
    private function canonical(mixed $value): string
    {
        $norm = function ($v) use (&$norm) {
            if (is_array($v)) {
                ksort($v);

                return array_map($norm, $v);
            }
            if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
                return number_format((float) $v, 6, '.', '');
            }

            return $v;
        };

        return json_encode($norm($value));
    }

    private function intentIsEmpty(array $intent, ?int $allocationTaxId): bool
    {
        foreach (['unit_price', 'discount_value', 'row_total_override'] as $k) {
            if (($intent[$k] ?? null) !== null && $intent[$k] !== '' && (float) $intent[$k] !== 0.0) {
                return false;
            }
        }
        if (($intent['tax_id'] ?? null) !== null && $intent['tax_id'] !== '' && (int) $intent['tax_id'] !== (int) $allocationTaxId) {
            return false;
        }

        return true;
    }
}
