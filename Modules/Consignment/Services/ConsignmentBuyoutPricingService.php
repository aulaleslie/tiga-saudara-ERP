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
 * Two intent formats are read:
 *
 * - Product intent (version 2, written by the tab-3 wizard): products[<product id>] => {unit_price, discount_type,
 *   discount_value, tax_id, row_total_override}, global_discount_type, global_discount_value, is_tax_included. One
 *   commercial row per product across receipts, sold/unsold kinds, locations and owners. The row is calculated once
 *   with the billing calculator and its exact amounts are allocated back to the source slices (buyout lines) by
 *   canonical quantity with deterministic residual-cent assignment, so every slice carries the same invoice price.
 * - Legacy intent (no version): rows[<line id>] => {...}. Kept unchanged so already-approved documents keep their
 *   approved hash and accepted amounts.
 */
class ConsignmentBuyoutPricingService
{
    public const INTENT_VERSION = 2;

    /** Explicit "no tax" choice for a PKP product row whose receipts carry differing tax evidence. */
    public const NO_TAX = '0';

    public function __construct(
        protected ?ConsignmentBillingPricingCalculator $calculator = null,
        protected ?ImportDocumentAdjustmentAllocator $allocator = null
    ) {
        $this->calculator ??= new ConsignmentBillingPricingCalculator();
        $this->allocator ??= new ImportDocumentAdjustmentAllocator();
    }

    public static function isProductIntent(?array $intent): bool
    {
        return (int) ($intent['version'] ?? 0) >= self::INTENT_VERSION;
    }

    /**
     * @return array{
     *   valid: bool, blockers: string[], is_pkp: bool, is_tax_included: bool, neutralized_tax_inputs: bool,
     *   product_intent: bool, products: array<int, array<string, mixed>>,
     *   slices: array<int, array<string, mixed>>,
     *   totals: array{original_total: float, sub_total: float, tax_amount: float, gross_total: float,
     *                 global_discount_type: string, global_discount_amount: float, total_amount: float,
     *                 inventory_cost_total: float}
     * }
     */
    public function calculate(ConsignmentBuyout $buyout): array
    {
        return $this->calculateLines((int) $buyout->setting_id, $buyout->lines()->orderBy('id')->get(), $buyout->pricing_intent ?? []);
    }

    /**
     * Same calculation over (possibly unsaved) lines: the tab-3 preview passes transient lines with stable ids, so
     * preview, draft save, approval and finalization all share this one server calculation.
     *
     * @param iterable<ConsignmentBuyoutLine> $lines
     */
    public function calculateLines(int $settingId, iterable $lines, array $intent): array
    {
        $setting = Setting::find($settingId);
        $isPkp = (bool) ($setting->is_pkp ?? false);
        $isTaxIncluded = $isPkp ? (bool) ($intent['is_tax_included'] ?? true) : false;
        $productMode = self::isProductIntent($intent);
        $blockers = [];

        $lines = collect($lines)->sortBy('id')->values();
        $details = ConsignmentReceivingDetail::with('product:id,product_name,product_code')
            ->whereIn('id', $lines->pluck('consignment_receiving_detail_id')->filter()->unique()->all())->get()->keyBy('id');

        // Original receipt evidence per slice (never rewritten).
        $evidence = [];
        foreach ($lines as $line) {
            $detail = $details->get($line->consignment_receiving_detail_id);
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
            $evidence[$line->id] = [
                'line' => $line, 'detail' => $detail, 'qty' => $qty, 'unit_price' => $unitPrice,
                'sub_total' => $subTotal, 'tax_amount' => $taxAmount, 'total' => round($subTotal + $taxAmount, 2),
            ];
        }

        [$slices, $products, $neutralized] = $productMode
            ? $this->productSlices($evidence, $intent, $isPkp, $isTaxIncluded, $blockers)
            : $this->legacySlices($evidence, $intent, $isPkp, $isTaxIncluded, $blockers);

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
        foreach ($products as $pid => &$p) {
            $p['payable'] = round(array_sum(array_map(fn ($id) => $slices[$id]['payable'] ?? 0, $p['line_ids'])), 2);
        }
        unset($p);

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
            'product_intent' => $productMode,
            'products' => array_values($products),
            'slices' => array_values($slices),
            'totals' => $totals,
        ];
    }

    /** Legacy per-line intent: unchanged behaviour for documents prepared before product-level terms. */
    private function legacySlices(array $evidence, array $intent, bool $isPkp, bool $isTaxIncluded, array &$blockers): array
    {
        $rowIntents = is_array($intent['rows'] ?? null) ? $intent['rows'] : [];
        // A non-PKP business never carries tax: submitted tax inputs are ignored (neutralized), not trusted.
        $neutralized = false;
        if (! $isPkp) {
            foreach ($rowIntents as $i) {
                if (($i['tax_id'] ?? null) !== null && $i['tax_id'] !== '') {
                    $neutralized = true;
                }
            }
        }

        $slices = [];
        foreach ($evidence as $lineId => $ev) {
            $line = $ev['line'];
            $detail = $ev['detail'];
            $rowIntent = is_array($rowIntents[$lineId] ?? null) ? $rowIntents[$lineId] : [];
            if (! $isPkp) {
                unset($rowIntent['tax_id']);
            }
            $unchanged = $this->intentIsEmpty($rowIntent, $detail->tax_id);

            $resolvedRate = null;
            if ($isPkp && ! $unchanged && ($rowIntent['tax_id'] ?? null) !== null && $rowIntent['tax_id'] !== '' && (int) $rowIntent['tax_id'] !== (int) $detail->tax_id) {
                $resolvedRate = $this->activeRate($rowIntent['tax_id']);
                if ($resolvedRate === null) {
                    $blockers[] = "Line #{$lineId}: selected tax [{$rowIntent['tax_id']}] is not a valid active tax.";
                }
            }

            $calc = $this->calculator->calculateRow([
                'quantity' => $ev['qty'],
                'original_unit_price' => $ev['unit_price'],
                'original_sub_total' => $ev['sub_total'],
                'original_tax_amount' => $ev['tax_amount'],
                'original_total' => $ev['total'],
                'allocation_tax_id' => $detail->tax_id,
                'allocation_tax_rate' => (float) ($detail->tax_rate ?? 0),
            ], $rowIntent, $isPkp, $isTaxIncluded, $unchanged, $resolvedRate);

            foreach ($calc['errors'] as $e) {
                $blockers[] = "Line #{$lineId}: {$e}";
            }
            if (! is_finite($calc['total']) || $calc['total'] < 0) {
                $blockers[] = "Line #{$lineId}: calculated total [{$calc['total']}] is invalid.";
            }

            $slices[$lineId] = $this->slice($ev, [
                'unit_price' => $calc['unit_price'], 'discount_type' => $calc['discount_type'], 'discount_amount' => $calc['discount_amount'],
                'tax_id' => $calc['tax_id'], 'tax_rate' => $calc['tax_rate'], 'tax_amount' => $calc['tax_amount'],
                'sub_total' => $calc['sub_total'], 'total' => $calc['total'],
            ]);
        }

        return [$slices, [], $neutralized];
    }

    /**
     * Product intent: one commercial row per product, calculated once, allocated exactly to its source slices.
     */
    private function productSlices(array $evidence, array $intent, bool $isPkp, bool $isTaxIncluded, array &$blockers): array
    {
        $productIntents = is_array($intent['products'] ?? null) ? $intent['products'] : [];
        if (! empty($intent['rows'])) {
            $blockers[] = 'Source-level price overrides are not accepted with product-level invoice terms.';
        }

        $groups = [];
        foreach ($evidence as $lineId => $ev) {
            $groups[(int) $ev['line']->product_id][$lineId] = $ev;
        }
        ksort($groups);

        foreach (array_keys($productIntents) as $pid) {
            if (! is_numeric($pid) || ! isset($groups[(int) $pid])) {
                $blockers[] = "Product [{$pid}] has invoice terms but is not part of the selected goods.";
            } elseif (! is_array($productIntents[$pid])) {
                $blockers[] = "Product [{$pid}] invoice terms are malformed.";
            }
        }

        $neutralized = false;
        $slices = [];
        $products = [];
        foreach ($groups as $pid => $group) {
            ksort($group);
            $first = reset($group);
            $name = $first['detail']->product->product_name ?? ('#' . $pid);
            $in = is_array($productIntents[$pid] ?? null) ? $productIntents[$pid] : [];
            $in = array_intersect_key($in, array_flip(['unit_price', 'discount_type', 'discount_value', 'tax_id', 'row_total_override']));
            if (! $isPkp && self::filled($in['tax_id'] ?? null)) {
                $neutralized = true;
            }
            if (! $isPkp) {
                unset($in['tax_id']);
            }

            $qtyMilli = array_sum(array_map(fn ($ev) => ConsignmentBuyoutCapacityService::milli($ev['qty']), $group));
            $qty = $qtyMilli / 1000;
            $sub = round(array_sum(array_column($group, 'sub_total')), 2);
            $tax = round(array_sum(array_column($group, 'tax_amount')), 2);
            $tot = round(array_sum(array_column($group, 'total')), 2);
            $prices = array_values(array_unique(array_map(fn ($ev) => number_format($ev['unit_price'], 6, '.', ''), $group)));
            $taxIds = array_values(array_unique(array_map(fn ($ev) => $ev['detail']->tax_id !== null ? (int) $ev['detail']->tax_id : null, $group), SORT_REGULAR));
            $pricesAgree = count($prices) === 1;
            $taxAgree = count($taxIds) === 1;
            $commonTaxId = $taxAgree ? $taxIds[0] : null;
            $commonRate = $taxAgree && $commonTaxId !== null ? (float) ($first['detail']->tax_rate ?? 0) : 0.0;

            $hasPrice = self::filled($in['unit_price'] ?? null);
            $hasOverride = self::filled($in['row_total_override'] ?? null);
            $hasTax = $isPkp && self::filled($in['tax_id'] ?? null);
            $explicitNoTax = $hasTax && (string) $in['tax_id'] === self::NO_TAX;
            $hasDiscount = self::filled($in['discount_value'] ?? null) && (float) $in['discount_value'] !== 0.0;

            $needsPrice = ! $pricesAgree && ! $hasPrice && ! $hasOverride;
            $needsTax = $isPkp && ! $taxAgree && ! $hasTax;
            if ($needsPrice) {
                $blockers[] = "Produk {$name}: harga penerimaan berbeda (" . implode(' / ', array_map(fn ($p) => rtrim(rtrim($p, '0'), '.'), $prices))
                    . '); isi satu harga satuan atau total baris faktur.';
            }
            if ($needsTax) {
                $blockers[] = "Produk {$name}: bukti pajak penerimaan berbeda; pilih satu pajak faktur.";
            }
            foreach (['unit_price', 'row_total_override', 'discount_value'] as $k) {
                if (self::filled($in[$k] ?? null) && (! is_numeric($in[$k]) || (float) $in[$k] < 0)) {
                    $blockers[] = "Produk {$name}: nilai {$k} [{$in[$k]}] tidak valid.";
                }
            }

            // Evidence tax identity for the calculator: an explicit "no tax" clears it, a disagreement leaves none.
            $evTaxId = $explicitNoTax ? null : $commonTaxId;
            $evRate = $explicitNoTax ? 0.0 : $commonRate;
            if ($explicitNoTax) {
                unset($in['tax_id']);
            }
            $resolvedRate = null;
            if ($hasTax && ! $explicitNoTax && (int) $in['tax_id'] !== (int) $commonTaxId) {
                $resolvedRate = $this->activeRate($in['tax_id']);
                if ($resolvedRate === null) {
                    $blockers[] = "Produk {$name}: pajak [{$in['tax_id']}] bukan pajak aktif yang valid.";
                }
            }

            $unchanged = $pricesAgree && $taxAgree && ! $hasPrice && ! $hasOverride && ! $hasDiscount
                && (! $hasTax || (! $explicitNoTax && (int) $in['tax_id'] === (int) $commonTaxId));
            $calc = $this->calculator->calculateRow([
                'quantity' => $qty,
                'original_unit_price' => $qty > 0 ? round($sub / $qty, 6) : 0.0,
                'original_sub_total' => $sub,
                'original_tax_amount' => $explicitNoTax ? 0.0 : $tax,
                'original_total' => $explicitNoTax ? $sub : $tot,
                'allocation_tax_id' => $evTaxId,
                'allocation_tax_rate' => $evRate,
            ], $in, $isPkp, $isTaxIncluded, $unchanged, $resolvedRate);
            foreach ($calc['errors'] as $e) {
                $blockers[] = "Produk {$name}: {$e}";
            }
            if (! is_finite($calc['total']) || $calc['total'] < 0) {
                $blockers[] = "Produk {$name}: total [{$calc['total']}] tidak valid.";
            }

            // Exact allocation of the row's amounts back to its slices by canonical quantity.
            $weights = array_map(fn ($ev) => ConsignmentBuyoutCapacityService::milli($ev['qty']), $group);
            $totalShares = self::splitCents((int) round($calc['total'] * 100), $weights);
            $taxShares = self::splitCents((int) round($calc['tax_amount'] * 100), $weights);
            foreach ($group as $lineId => $ev) {
                $slices[$lineId] = $this->slice($ev, [
                    'unit_price' => $calc['unit_price'], 'discount_type' => $calc['discount_type'], 'discount_amount' => $calc['discount_amount'],
                    'tax_id' => $calc['tax_id'], 'tax_rate' => $calc['tax_rate'], 'tax_amount' => $taxShares[$lineId] / 100,
                    'sub_total' => ($totalShares[$lineId] - $taxShares[$lineId]) / 100, 'total' => $totalShares[$lineId] / 100,
                ]) + ['product_row' => $pid];
            }

            $displayDefault = $pricesAgree
                ? ($isPkp && $isTaxIncluded && $commonRate > 0 && $qty > 0 ? round($tot / $qty, 6) : (float) $prices[0])
                : null;
            $products[$pid] = [
                'product_id' => $pid,
                'product_name' => $name,
                'product_code' => $first['detail']->product->product_code ?? null,
                'quantity' => $qty,
                'line_ids' => array_keys($group),
                'original' => ['unit_prices' => array_map('floatval', $prices), 'sub_total' => $sub, 'tax_amount' => $tax, 'total' => $tot, 'tax_ids' => $taxIds],
                'prices_agree' => $pricesAgree,
                'tax_agree' => $taxAgree,
                'default_unit_price' => $displayDefault,
                'default_tax_id' => $commonTaxId,
                // Receipt tax rate behind "follow receipt" (0 for untaxed or disagreeing evidence), independent of invoice choices.
                'default_tax_rate' => $taxAgree ? $commonRate : 0.0,
                'needs_price' => $needsPrice,
                'needs_tax' => $needsTax,
                'intent' => $in,
                'accepted' => array_diff_key($calc, ['errors' => true]),
            ];
        }

        return [$slices, $products, $neutralized];
    }

    private function slice(array $ev, array $accepted): array
    {
        $line = $ev['line'];

        return [
            'line_id' => $line->id,
            'line_type' => $line->line_type,
            'receiving_detail_id' => $line->consignment_receiving_detail_id,
            'product_id' => $line->product_id,
            'quantity' => $ev['qty'],
            'original' => ['unit_price' => $ev['unit_price'], 'sub_total' => $ev['sub_total'], 'tax_amount' => $ev['tax_amount'], 'total' => $ev['total']],
            'accepted' => $accepted,
            'document_discount' => 0.0,
            'payable' => $accepted['total'],
            'inventory_cost' => null,
        ];
    }

    /**
     * Largest-remainder split of an integer cent amount by integer weights; ties go to the earliest key, so the
     * result is deterministic and always sums exactly to $cents.
     *
     * @param array<int|string, int> $weights
     * @return array<int|string, int>
     */
    public static function splitCents(int $cents, array $weights): array
    {
        // Arbitrary-precision arithmetic: cents x milli-quantity can exceed PHP_INT_MAX for supported amounts.
        $sum = (string) array_sum($weights);
        $out = array_fill_keys(array_keys($weights), 0);
        if (bccomp($sum, '0') <= 0) {
            if ($out) {
                $out[array_key_first($out)] = $cents;
            }

            return $out;
        }
        $rema = [];
        $position = array_flip(array_keys($weights));
        foreach ($weights as $k => $w) {
            $exact = bcmul((string) $cents, (string) $w, 0);
            $out[$k] = (int) bcdiv($exact, $sum, 0);
            $rema[$k] = bcmod($exact, $sum, 0);
        }
        $left = $cents - array_sum($out);
        $order = array_keys($weights);
        usort($order, fn ($a, $b) => bccomp($rema[$b], $rema[$a]) ?: $position[$a] <=> $position[$b]);
        for ($i = 0; $i < $left; $i++) {
            $out[$order[$i % count($order)]]++;
        }

        return $out;
    }

    /**
     * Product view of a legacy (per-line) intent, for reopening an older draft in the product editor. Saved line terms
     * that agree across a product's lines become its product terms; any disagreement (including some lines with saved
     * terms and some without, or a per-line total override over several lines) is a conflict that needs one explicit
     * product price or total. Nothing is invented for conflicts.
     *
     * @return array{products: array<int, array<string, string>>, conflicts: int[], global_discount_type: string,
     *               global_discount_value: float, is_tax_included: bool}
     */
    public function projectLegacyIntent(ConsignmentBuyout $buyout): array
    {
        $intent = $buyout->pricing_intent ?? [];
        $rows = is_array($intent['rows'] ?? null) ? $intent['rows'] : [];
        $products = [];
        $conflicts = [];
        foreach ($buyout->lines()->orderBy('id')->get()->groupBy(fn ($l) => (int) $l->product_id) as $pid => $lines) {
            $terms = $lines->map(function ($l) use ($rows) {
                $r = is_array($rows[$l->id] ?? null) ? $rows[$l->id] : [];
                $t = [];
                foreach (['unit_price', 'tax_id', 'row_total_override'] as $k) {
                    if (self::filled($r[$k] ?? null)) {
                        $t[$k] = (string) $r[$k];
                    }
                }
                if (self::filled($r['discount_value'] ?? null) && (float) $r['discount_value'] !== 0.0) {
                    $t['discount_value'] = (string) $r['discount_value'];
                    $t['discount_type'] = (string) ($r['discount_type'] ?? 'fixed');
                }
                ksort($t);

                return $t;
            });
            $distinct = $terms->map(fn ($t) => json_encode($t))->unique();
            $first = $terms->first();
            if ($distinct->count() > 1 || (isset($first['row_total_override']) && $lines->count() > 1)) {
                $conflicts[] = $pid;
            } elseif ($first) {
                $products[$pid] = $first;
            }
        }

        return [
            'products' => $products,
            'conflicts' => $conflicts,
            'global_discount_type' => (string) ($intent['global_discount_type'] ?? 'fixed'),
            'global_discount_value' => (float) ($intent['global_discount_value'] ?? 0),
            'is_tax_included' => (bool) ($intent['is_tax_included'] ?? true),
        ];
    }

    private static function filled(mixed $v): bool
    {
        return $v !== null && $v !== '';
    }

    private function activeRate(mixed $taxId): ?float
    {
        $rate = Tax::active()->whereKey((int) $taxId)->value('value');

        return $rate !== null ? (float) $rate : null;
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
