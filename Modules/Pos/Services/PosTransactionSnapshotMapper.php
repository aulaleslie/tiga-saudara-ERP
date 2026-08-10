<?php

namespace Modules\Pos\Services;

use App\Support\SalesLocationResolver;
use Illuminate\Support\Facades\DB;
use Modules\Pos\Entities\PosTransaction;
use Modules\Pos\Entities\PosTransactionLine;
use Modules\Pos\Entities\PosTransactionLineSerial;
use Modules\Product\Entities\Product;

class PosTransactionSnapshotMapper
{
    /**
     * Persist cart lines into pos_transaction_lines and pos_transaction_line_serials tables.
     * Deletes existing lines before re-inserting (inside transaction).
     *
     * @param  array<int, array<string, mixed>>  $cartLines  keyed by session line_id
     */
    public function persistLines(PosTransaction $transaction, array $cartLines): void
    {
        DB::transaction(function () use ($transaction, $cartLines) {
            // Delete existing lines and serials (cascade)
            PosTransactionLine::where('pos_transaction_id', $transaction->id)->delete();

            if (empty($cartLines)) {
                return;
            }

            $lineNo = 1;
            foreach ($cartLines as $sessionLineId => $line) {
                if (!is_array($line)) {
                    continue;
                }

                // Extract fields for snapshot storage
                // Explicit minor-unit contract: if price_source is PACKED, line_total is minor units and must be stored as line_total_minor
                $lineMeta = [
                    'barcode' => $line['barcode'] ?? null,
                    'price_source' => $line['price_source'] ?? 'BASE',
                    'stock_managed' => isset($line['stock_managed']) ? (bool) $line['stock_managed'] : true,
                    'merge_key' => $line['merge_key'] ?? null,
                    'conversion_unit_name' => $line['conversion_unit_name'] ?? null,
                    'bundle_id' => $line['bundle_id'] ?? null,
                    'bundle_name' => $line['bundle_name'] ?? null,
                    'bundle_price' => $line['bundle_price'] ?? null,
                    'bundle_items' => $line['bundle_items'] ?? null,
                    'breakdown' => $line['breakdown'] ?? null,
                    'pricing_basis' => $line['pricing_basis'] ?? null,
                ];

                // Store line_total with explicit unit designation
                if (($line['price_source'] ?? 'BASE') === 'PACKED') {
                    // PackedLinePricingService returns breakdown.line_total_minor (minor units)
                    // line_total is already Rupiah; keep it, and extract minor units from breakdown
                    $lineMeta['line_total'] = $line['line_total'] ?? null;
                    if (isset($line['breakdown']['line_total_minor'])) {
                        $lineMeta['line_total_minor'] = $line['breakdown']['line_total_minor'];
                    }
                } else {
                    // Base pricing stores line_total in Rupiah
                    $lineMeta['line_total'] = $line['line_total'] ?? null;
                }

                $dbLine = PosTransactionLine::create([
                    'pos_transaction_id' => $transaction->id,
                    'line_no' => $lineNo,
                    'product_id' => $line['product_id'],
                    'product_name_snapshot' => $line['product_name'] ?? 'Unknown',
                    'product_code_snapshot' => $line['product_code'] ?? null,
                    'conversion_id' => $line['conversion_id'] ?? null,
                    'qty' => $line['qty'] ?? 0,
                    'unit_price' => $line['unit_price'] ?? 0,
                    'tax_id' => $line['tax_id'] ?? null,
                    'tax_name_snapshot' => $line['tax_name'] ?? null,
                    'tax_rate_snapshot' => $line['tax_rate'] ?? 0,
                    'line_discount_type' => $line['line_discount_type'] ?? 'fixed',
                    'line_discount_value' => $line['line_discount_value'] ?? 0,
                    'line_meta' => $lineMeta,
                ]);

                // Persist assigned serials
                if (!empty($line['assigned_serials']) && is_array($line['assigned_serials'])) {
                    foreach ($line['assigned_serials'] as $serialNumber) {
                        PosTransactionLineSerial::create([
                            'pos_transaction_line_id' => $dbLine->id,
                            'serial_number' => $serialNumber,
                        ]);
                    }
                }

                $lineNo++;
            }
        });
    }

    /**
     * Hydrate a session cart array from a persisted PosTransaction.
     * Returns cart array format compatible with PosCartSessionStore.
     *
     * @return array{
     *     next_line_id: int,
     *     lines: array<int, array<string, mixed>>,
     *     bill_discount_type: string,
     *     bill_discount_value: float,
     *     selected_customer_id: int|null,
     *     selected_customer_tier: string|null,
     *     active_transaction_id: int,
     *     note: string|null
     * }
     */
    public function hydrateCart(PosTransaction $transaction): array
    {
        // Eager load lines with serials
        $transaction->load(['lines.serials', 'customer']);

        $productIds = $transaction->lines
            ->pluck('product_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        // Batch-load products for stock_managed fallback resolution
        $productsById = [];
        if ($productIds !== []) {
            $productsById = Product::query()
                ->whereIn('id', $productIds)
                ->get(['id', 'stock_managed'])
                ->keyBy('id')
                ->all();
        }

        $availableQtyByProduct = [];
        $allowedLocationIds = SalesLocationResolver::resolveLocationIds((int) $transaction->setting_id)->all();
        if ($productIds !== [] && $allowedLocationIds !== []) {
            $availableQtyByProduct = DB::table('product_stocks')
                ->selectRaw('product_id, SUM(quantity) as total_qty')
                ->whereIn('product_id', $productIds)
                ->whereIn('location_id', $allowedLocationIds)
                ->groupBy('product_id')
                ->pluck('total_qty', 'product_id')
                ->map(fn ($qty) => (int) $qty)
                ->all();
        }

        $tier = null;
        if ($transaction->customer) {
            $tier = (string) $transaction->customer->tier;
        }

        $lines = [];
        $nextLineId = 1;

        foreach ($transaction->lines as $dbLine) {
            $lineMeta = $dbLine->line_meta ?? [];
            $serials = $dbLine->serials->pluck('serial_number')->toArray();

            // Restore price_source from metadata, or default to 'BASE'
            $priceSource = $lineMeta['price_source'] ?? 'BASE';

            // Restore stock_managed classification with safe legacy fallback
            $stockManaged = $this->resolveStockManaged($lineMeta, $dbLine->product_id, $productsById);

            // For PACKED lines, restore line_total from minor units
            // Prioritize line_total_minor, fall back to legacy line_total as minor units for backward compat
            $lineTotal = null;
            if ($priceSource === 'PACKED') {
                if (isset($lineMeta['line_total_minor'])) {
                    $lineTotal = (int) $lineMeta['line_total_minor'];
                } elseif (isset($lineMeta['line_total'])) {
                    // Legacy packed snapshots may have line_total as minor units only
                    $lineTotal = (int) $lineMeta['line_total'];
                }
            } else {
                // Non-packed lines store line_total in Rupiah
                $lineTotal = $lineMeta['line_total'] ?? null;
            }

            $lines[$nextLineId] = [
                'line_id' => $nextLineId,
                'product_id' => $dbLine->product_id,
                'product_name' => $dbLine->product_name_snapshot,
                'product_code' => $dbLine->product_code_snapshot,
                'barcode' => $lineMeta['barcode'] ?? null,
                'stock_managed' => $stockManaged,
                'serial_number_required' => $this->isSerialRequired($dbLine->product_id),
                'assigned_serials' => $serials,
                'qty' => $dbLine->qty,
                'available_qty' => (int) ($availableQtyByProduct[(int) $dbLine->product_id] ?? 0),
                'unit_price' => (float) $dbLine->unit_price,
                'line_discount_type' => $dbLine->line_discount_type,
                'line_discount_value' => (float) $dbLine->line_discount_value,
                'tax_id' => $dbLine->tax_id,
                'tax_name' => $dbLine->tax_name_snapshot,
                'tax_rate' => (float) $dbLine->tax_rate_snapshot,
                'merge_key' => $lineMeta['merge_key'] ?? $this->computeMergeKey($dbLine, $tier),
                'price_source' => $priceSource,
                'price_valid' => true,
                'price_error' => null,
                'conversion_id' => $dbLine->conversion_id,
                'conversion_unit_name' => $lineMeta['conversion_unit_name'] ?? null,
                'bundle_id' => $lineMeta['bundle_id'] ?? null,
                'bundle_name' => $lineMeta['bundle_name'] ?? null,
                'bundle_price' => $lineMeta['bundle_price'] ?? null,
                'bundle_items' => $lineMeta['bundle_items'] ?? [],
                'breakdown' => $lineMeta['breakdown'] ?? null,
                'pricing_basis' => $lineMeta['pricing_basis'] ?? null,
                'line_total' => $lineTotal,
            ];

            $nextLineId++;
        }

        return [
            'next_line_id' => $nextLineId,
            'lines' => $lines,
            'bill_discount_type' => 'fixed',
            'bill_discount_value' => 0.0,
            'selected_customer_id' => $transaction->customer_id,
            'selected_customer_tier' => $tier,
            'active_transaction_id' => $transaction->id,
            'note' => $transaction->note,
        ];
    }

    /**
     * Build snapshot_totals from the cart totals structure.
     *
     * @param  array<string, mixed>  $totals  from PosCartService::buildSnapshot()['totals']
     * @return array<string, mixed>
     */
    public function buildSnapshotTotals(array $totals): array
    {
        return [
            'line_count' => $totals['line_count'] ?? 0,
            'subtotal' => $totals['subtotal'] ?? 0,
            'discount_total' => $totals['discount_total'] ?? 0,
            'tax_total' => $totals['tax_total'] ?? 0,
            'grand_total' => $totals['grand_total'] ?? 0,
        ];
    }

    /**
     * Build deterministic snapshot hash from persisted transaction header + lines + serials.
     */
    public function buildSnapshotHash(PosTransaction $transaction): string
    {
        $transaction->loadMissing(['lines.serials']);

        $totals = is_array($transaction->snapshot_totals) ? $transaction->snapshot_totals : [];

        $lines = $transaction->lines
            ->sortBy(fn (PosTransactionLine $line) => (int) $line->line_no)
            ->values()
            ->map(function (PosTransactionLine $line): array {
                return [
                    'line_no' => (int) $line->line_no,
                    'product_id' => (int) $line->product_id,
                    'conversion_id' => $line->conversion_id !== null ? (int) $line->conversion_id : null,
                    'qty' => round((float) $line->qty, 4),
                    'unit_price' => round((float) $line->unit_price, 2),
                    'tax_id' => $line->tax_id !== null ? (int) $line->tax_id : null,
                    'tax_name' => $line->tax_name_snapshot !== null ? (string) $line->tax_name_snapshot : null,
                    'tax_rate' => round((float) $line->tax_rate_snapshot, 4),
                    'line_discount_type' => (string) $line->line_discount_type,
                    'line_discount_value' => round((float) $line->line_discount_value, 2),
                    'bundle_id' => $line->line_meta['bundle_id'] ?? null,
                    'serials' => $line->serials
                        ->pluck('serial_number')
                        ->map(fn ($serial): string => (string) $serial)
                        ->sort()
                        ->values()
                        ->all(),
                ];
            })
            ->all();

        $payload = [
            'setting_id' => (int) $transaction->setting_id,
            'owner_user_id' => (int) $transaction->owner_user_id,
            'customer_id' => $transaction->customer_id !== null ? (int) $transaction->customer_id : null,
            'note' => $transaction->note !== null ? (string) $transaction->note : null,
            'lines' => $lines,
            'snapshot_totals' => [
                'subtotal' => round((float) ($totals['subtotal'] ?? 0), 2),
                'discount_total' => round((float) ($totals['discount_total'] ?? 0), 2),
                'tax_total' => round((float) ($totals['tax_total'] ?? 0), 2),
                'grand_total' => round((float) ($totals['grand_total'] ?? 0), 2),
            ],
        ];

        $canonical = $this->canonicalizeForHash($payload);
        $json = json_encode(
            $canonical,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );

        return hash('sha256', $json ?: '{}');
    }

    /**
     * Resolve stock_managed classification for a hydrated line.
     * Uses persisted metadata if available; falls back to current product record.
     * Defaults to conservative stock-managed behavior if product cannot be resolved.
     *
     * @param  array<string, mixed>  $lineMeta  Persisted line metadata
     * @param  int|null  $productId  Product ID from persisted line
     * @param  array<int, Product>  $productsById  Batch-loaded products
     * @return bool
     */
    private function resolveStockManaged(array $lineMeta, ?int $productId, array $productsById): bool
    {
        // If metadata has stock_managed, use it (applies to newly saved or previously saved drafts)
        if (isset($lineMeta['stock_managed'])) {
            return (bool) $lineMeta['stock_managed'];
        }

        // Legacy fallback: resolve from current product record if available
        if ($productId !== null && isset($productsById[$productId])) {
            return (bool) $productsById[$productId]->stock_managed;
        }

        // Conservative default: if product cannot be resolved, treat as stock-managed
        // This prevents silently bypassing inventory validation
        return true;
    }

    /**
     * Check if a product requires serials.
     */
    private function isSerialRequired(int $productId): bool
    {
        $product = Product::find($productId);

        return (bool) ($product?->serial_number_required ?? false);
    }

    /**
     * @return mixed
     */
    private function canonicalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->canonicalizeForHash($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeForHash($item);
        }

        return $value;
    }

    /**
     * Compute merge_key from transaction line fields.
     * Uses PosMergeKeyGenerator for parity with PosCartService.
     */
    private function computeMergeKey(PosTransactionLine $line, ?string $tier = null): string
    {
        $lineMeta = $line->line_meta ?? [];
        $priceSource = $lineMeta['price_source'] ?? 'BASE';
        $bundleId = $lineMeta['bundle_id'] ?? null;

        return \Modules\Pos\Support\PosMergeKeyGenerator::build(
            (int) $line->product_id,
            (float) $line->unit_price,
            $line->tax_id !== null ? (int) $line->tax_id : null,
            $line->conversion_id !== null ? (int) $line->conversion_id : null,
            $bundleId !== null ? (int) $bundleId : null,
            $tier,
            $priceSource
        );
    }
}
