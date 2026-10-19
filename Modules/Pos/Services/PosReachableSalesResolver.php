<?php

namespace Modules\Pos\Services;

use App\Scopes\ArchivingScope;
use Illuminate\Support\Collection;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Pos\Entities\PosTransaction;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;

class PosReachableSalesResolver
{
    /**
     * Resolve reachable sales for a POS transaction or checkout.
     *
     * @param PosTransaction|PosCheckout $record
     * @param array|null $preparedReplacementContext
     * @return Collection<int, Sale>
     */
    public function resolveSales(PosTransaction|PosCheckout $record, ?array $preparedReplacementContext = null): Collection
    {
        $diagnostics = $this->resolveWithDiagnostics($record, $preparedReplacementContext);

        return $diagnostics['sales'];
    }

    /**
     * Resolve reachable sales along with diagnostics about mapping integrity.
     *
     * @param PosTransaction|PosCheckout $record
     * @param array|null $preparedReplacementContext
     * @return array{
     *     sales: Collection<int, Sale>,
     *     has_split_mapping: bool,
     *     is_inline_fallback: bool,
     *     is_valid: bool,
     *     anomalies: array<string>
     * }
     */
    public function resolveWithDiagnostics(PosTransaction|PosCheckout $record, ?array $preparedReplacementContext = null): array
    {
        $checkout = $this->extractCheckout($record);

        if (!$checkout) {
            return [
                'sales' => collect(),
                'has_split_mapping' => false,
                'is_inline_fallback' => false,
                'is_valid' => false,
                'anomalies' => ['Transaction has no associated checkout record.'],
            ];
        }

        $anomalies = [];
        // Reuse the eager-loaded checkoutSales relation when the caller already loaded it
        // (e.g. via 'completedCheckout.checkoutSales.sale...') to avoid an extra query per
        // POS transaction when this resolver runs in a loop over a list of transactions.
        $checkoutSales = $checkout->relationLoaded('checkoutSales')
            ? $checkout->checkoutSales
            : PosCheckoutSale::where('pos_checkout_id', $checkout->id)->get();

        if ($checkoutSales->isNotEmpty()) {
            // Split mapping path
            $hasSplitMapping = true;
            $isInlineFallback = false;

            $saleIds = [];
            $seenKeys = [];

            foreach ($checkoutSales as $cs) {
                if (in_array($cs->split_key, $seenKeys, true)) {
                    $anomalies[] = "Duplicate split_key '{$cs->split_key}' in checkout sales.";
                }
                $seenKeys[] = $cs->split_key;

                if (empty($cs->sale_id)) {
                    $anomalies[] = "Checkout sale record {$cs->id} has null or empty sale_id.";
                } else {
                    if (in_array($cs->sale_id, $saleIds, true)) {
                        $anomalies[] = "Duplicate sale_id '{$cs->sale_id}' in split mappings.";
                    }
                    $saleIds[] = $cs->sale_id;
                }
            }

            if (empty($saleIds)) {
                return [
                    'sales' => collect(),
                    'has_split_mapping' => true,
                    'is_inline_fallback' => false,
                    'is_valid' => false,
                    'anomalies' => array_merge($anomalies, ['No sale IDs could be extracted from checkout sales.']),
                ];
            }

            // Reuse each PosCheckoutSale's eager-loaded 'sale' relation when every one of
            // them is loaded (e.g. via PosSettlementProjectionService::reachableSalesEagerLoad(),
            // which constrains the relation with withArchived() so an archived Sale is still
            // present rather than silently dropped) to avoid an additional query per POS
            // transaction when this resolver runs in a loop over a list of transactions.
            // tenantSetting is loaded lazily below only when the fast path is not available,
            // to keep behavior identical to the query-based path's eager loading.
            $allSaleRelationsLoaded = $checkoutSales->every(fn ($cs) => $cs->relationLoaded('sale') && $cs->sale !== null);

            if ($allSaleRelationsLoaded) {
                $sales = $checkoutSales->pluck('sale')->filter()->keyBy('id');
                $sales->each(function (Sale $sale) {
                    $sale->loadMissing('tenantSetting');
                });
            } else {
                // Load sales bypassing ArchivingScope with tenantSetting eager-loaded
                $sales = Sale::withoutGlobalScope(ArchivingScope::class)
                    ->with('tenantSetting')
                    ->whereIn('id', $saleIds)
                    ->get()
                    ->keyBy('id');
            }

            // Check if all sale IDs exist in database
            $missingSaleIds = array_diff($saleIds, $sales->keys()->all());
            if (!empty($missingSaleIds)) {
                $anomalies[] = 'Referenced sales missing in database: ' . implode(', ', $missingSaleIds) . '.';
            }

            // Check customer consistency if customer is set on checkout
            if ($checkout->customer_id) {
                foreach ($sales as $sale) {
                    if ((int) $sale->customer_id !== (int) $checkout->customer_id) {
                        $anomalies[] = "Sale {$sale->id} customer ({$sale->customer_id}) does not match checkout customer ({$checkout->customer_id}).";
                    }
                }
            }

            $orderedSales = new \Illuminate\Database\Eloquent\Collection();
            foreach ($checkoutSales as $cs) {
                if ($cs->sale_id && $sales->has($cs->sale_id)) {
                    $orderedSales->push($sales->get($cs->sale_id));
                }
            }

            // Include cross-owner replacement sales linked to completed returns
            $replacementSales = $this->resolveReplacementSales($checkout, $saleIds, $anomalies, $preparedReplacementContext);
            if ($replacementSales->isNotEmpty()) {
                $orderedSales = $orderedSales->concat($replacementSales);
                $sales = $sales->union($replacementSales->keyBy('id'));
            }

            return [
                'sales' => $orderedSales,
                'has_split_mapping' => true,
                'is_inline_fallback' => false,
                'is_valid' => empty($anomalies),
                'anomalies' => $anomalies,
            ];
        }

        // Inline fallback path
        if ($checkout->sale_id) {
            // Reuse the eager-loaded 'sale' relation when present (see split-mapping path
            // above for why withArchived() must have been applied by the caller).
            if ($checkout->relationLoaded('sale')) {
                $sale = $checkout->sale;
                $sale?->loadMissing('tenantSetting');
            } else {
                $sale = Sale::withoutGlobalScope(ArchivingScope::class)
                    ->with('tenantSetting')
                    ->find($checkout->sale_id);
            }

            if (!$sale) {
                return [
                    'sales' => new \Illuminate\Database\Eloquent\Collection(),
                    'has_split_mapping' => false,
                    'is_inline_fallback' => true,
                    'is_valid' => false,
                    'anomalies' => ["Referenced inline sale {$checkout->sale_id} missing in database."],
                ];
            }

            if ($checkout->customer_id && (int) $sale->customer_id !== (int) $checkout->customer_id) {
                $anomalies[] = "Inline sale {$sale->id} customer ({$sale->customer_id}) does not match checkout customer ({$checkout->customer_id}).";
            }

            $orderedSales = new \Illuminate\Database\Eloquent\Collection([$sale]);
            $replacementSales = $this->resolveReplacementSales($checkout, [(int) $sale->id], $anomalies, $preparedReplacementContext);
            if ($replacementSales->isNotEmpty()) {
                $orderedSales = $orderedSales->concat($replacementSales);
            }

            return [
                'sales' => $orderedSales,
                'has_split_mapping' => false,
                'is_inline_fallback' => true,
                'is_valid' => empty($anomalies),
                'anomalies' => $anomalies,
            ];
        }

        return [
            'sales' => collect(),
            'has_split_mapping' => false,
            'is_inline_fallback' => false,
            'is_valid' => false,
            'anomalies' => ['Checkout has neither split checkout_sales mappings nor an inline sale_id.'],
        ];
    }

    /**
     * Extract the checkout instance from a PosTransaction or PosCheckout.
     */
    protected function extractCheckout(PosTransaction|PosCheckout $record): ?PosCheckout
    {
        if ($record instanceof PosCheckout) {
            return $record;
        }

        if ($record->completedCheckout) {
            return $record->completedCheckout;
        }

        if ($record->completed_checkout_id) {
            return PosCheckout::find($record->completed_checkout_id);
        }

        return PosCheckout::where('pos_transaction_id', $record->id)->first();
    }

    /**
     * Batch prepare replacement sales for a collection/array of PosTransaction or PosCheckout records.
     *
     * @param iterable<PosTransaction|PosCheckout> $records
     * @return array{
     *     by_checkout_id: array<int, array{candidate_sale_ids: array<int>}>,
     *     by_transaction_id: array<int, array{candidate_sale_ids: array<int>}>,
     *     sales_by_id: \Illuminate\Support\Collection<int, Sale>
     * }
     */
    public function prepareReplacementSales(iterable $records): array
    {
        $checkoutMap = [];
        $trxToCheckoutMap = [];
        $unloadedCheckoutIds = [];

        foreach ($records as $record) {
            if ($record instanceof PosCheckout) {
                if ($record->id) {
                    $checkoutId = (int) $record->id;
                    $checkoutMap[$checkoutId] = $record;
                    if ($record->pos_transaction_id) {
                        $trxToCheckoutMap[(int) $record->pos_transaction_id] = $checkoutId;
                    }
                }
            } elseif ($record instanceof PosTransaction) {
                if ($record->relationLoaded('completedCheckout') && $record->completedCheckout) {
                    $checkout = $record->completedCheckout;
                    $checkoutId = (int) $checkout->id;
                    $checkoutMap[$checkoutId] = $checkout;
                    $trxToCheckoutMap[(int) $record->id] = $checkoutId;
                    if ($checkout->pos_transaction_id) {
                        $trxToCheckoutMap[(int) $checkout->pos_transaction_id] = $checkoutId;
                    }
                } elseif ($record->completed_checkout_id) {
                    $unloadedCheckoutIds[(int) $record->id] = (int) $record->completed_checkout_id;
                }
            }
        }

        if (! empty($unloadedCheckoutIds)) {
            $loadedCheckouts = PosCheckout::whereIn('id', array_values($unloadedCheckoutIds))->get()->keyBy('id');
            foreach ($unloadedCheckoutIds as $trxId => $checkoutId) {
                if ($loadedCheckouts->has($checkoutId)) {
                    $checkout = $loadedCheckouts->get($checkoutId);
                    $checkoutMap[$checkoutId] = $checkout;
                    $trxToCheckoutMap[$trxId] = $checkoutId;
                    if ($checkout->pos_transaction_id) {
                        $trxToCheckoutMap[(int) $checkout->pos_transaction_id] = $checkoutId;
                    }
                }
            }
        }

        $context = [
            'by_checkout_id' => [],
            'by_transaction_id' => [],
            'sales_by_id' => collect(),
        ];

        if (empty($checkoutMap)) {
            return $context;
        }

        // Initialize explicit known-empty candidate entries for all checkouts in the batch
        foreach ($checkoutMap as $checkoutId => $checkout) {
            $context['by_checkout_id'][$checkoutId] = [
                'candidate_sale_ids' => [],
            ];
        }
        foreach ($trxToCheckoutMap as $trxId => $checkoutId) {
            $context['by_transaction_id'][$trxId] = &$context['by_checkout_id'][$checkoutId];
        }

        $checkoutIds = array_keys($checkoutMap);
        $transactionIds = array_keys($trxToCheckoutMap);

        $completedReturns = PosReturn::query()
            ->where('status', PosReturn::STATUS_COMPLETED)
            ->where('is_reversed', false)
            ->where(function ($q) use ($checkoutIds, $transactionIds) {
                $q->whereIn('pos_checkout_id', $checkoutIds);
                if (! empty($transactionIds)) {
                    $q->orWhereIn('pos_transaction_id', $transactionIds);
                }
            })
            ->get(['id', 'pos_checkout_id', 'pos_transaction_id']);

        if ($completedReturns->isEmpty()) {
            return $context;
        }

        $returnIds = $completedReturns->pluck('id')->all();

        $completedReturnLines = PosReturnLine::query()
            ->whereIn('pos_return_id', $returnIds)
            ->where('resolution', PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT)
            ->get(['id', 'pos_return_id']);

        if ($completedReturnLines->isEmpty()) {
            return $context;
        }

        $returnLineIds = $completedReturnLines->pluck('id')->all();

        $dispatchDetails = DispatchDetail::query()
            ->whereIn('pos_return_line_id', $returnLineIds)
            ->whereNotNull('sale_id')
            ->get(['id', 'pos_return_line_id', 'sale_id']);

        if ($dispatchDetails->isEmpty()) {
            return $context;
        }

        $allReplacementSaleIds = $dispatchDetails->pluck('sale_id')->unique()->values()->all();

        $salesById = Sale::withoutGlobalScope(ArchivingScope::class)
            ->with(['tenantSetting', 'salePayments.creditApplications', 'salePayments.paymentMethod'])
            ->whereIn('id', $allReplacementSaleIds)
            ->get()
            ->keyBy('id');

        $context['sales_by_id'] = $salesById;

        $dispatchByLine = $dispatchDetails->groupBy('pos_return_line_id');
        $linesByReturn = $completedReturnLines->groupBy('pos_return_id');

        // Group returns by checkout and transaction IDs once in O(returns) to eliminate quadratic O(checkouts × returns) scanning
        $returnsByCheckoutId = [];
        $returnsByTransactionId = [];

        foreach ($completedReturns as $ret) {
            if ($ret->pos_checkout_id) {
                $returnsByCheckoutId[(int) $ret->pos_checkout_id][] = $ret;
            }
            if ($ret->pos_transaction_id) {
                $returnsByTransactionId[(int) $ret->pos_transaction_id][] = $ret;
            }
        }

        foreach ($checkoutMap as $checkoutId => $checkout) {
            $trxId = $checkout->pos_transaction_id ? (int) $checkout->pos_transaction_id : null;

            $checkoutReturns = $returnsByCheckoutId[$checkoutId] ?? [];
            $trxReturns = ($trxId && isset($returnsByTransactionId[$trxId])) ? $returnsByTransactionId[$trxId] : [];

            // Merge and deduplicate each checkout's matching returns
            $matchingReturns = [];
            foreach ($checkoutReturns as $ret) {
                $matchingReturns[$ret->id] = $ret;
            }
            foreach ($trxReturns as $ret) {
                $matchingReturns[$ret->id] = $ret;
            }

            $candidateSaleIds = [];
            foreach ($matchingReturns as $ret) {
                $lines = $linesByReturn->get($ret->id) ?? [];
                foreach ($lines as $line) {
                    $details = $dispatchByLine->get($line->id) ?? [];
                    foreach ($details as $detail) {
                        if ($detail->sale_id !== null) {
                            $sid = (int) $detail->sale_id;
                            if (! in_array($sid, $candidateSaleIds, true)) {
                                $candidateSaleIds[] = $sid;
                            }
                        }
                    }
                }
            }

            $context['by_checkout_id'][$checkoutId] = [
                'candidate_sale_ids' => $candidateSaleIds,
            ];
        }

        foreach ($trxToCheckoutMap as $trxId => $checkoutId) {
            $context['by_transaction_id'][$trxId] = &$context['by_checkout_id'][$checkoutId];
        }

        return $context;
    }

    /**
     * Resolve cross-owner replacement sales linked via completed returns for this checkout.
     *
     * @param PosCheckout $checkout
     * @param array<int> $existingSaleIds
     * @param array<string> $anomalies
     * @param array|null $preparedReplacementContext
     * @return Collection<int, Sale>
     */
    protected function resolveReplacementSales(
        PosCheckout $checkout,
        array $existingSaleIds,
        array &$anomalies,
        ?array $preparedReplacementContext = null
    ): Collection {
        if ($preparedReplacementContext !== null) {
            $checkoutId = (int) $checkout->id;
            $trxId = $checkout->pos_transaction_id ? (int) $checkout->pos_transaction_id : null;

            $entry = $preparedReplacementContext['by_checkout_id'][$checkoutId]
                ?? ($trxId && isset($preparedReplacementContext['by_transaction_id'][$trxId]) ? $preparedReplacementContext['by_transaction_id'][$trxId] : null);

            if ($entry !== null) {
                $candidateSaleIds = $entry['candidate_sale_ids'];
                $newReplacementSaleIds = array_values(array_diff($candidateSaleIds, $existingSaleIds));

                if (empty($newReplacementSaleIds)) {
                    return new \Illuminate\Database\Eloquent\Collection();
                }

                $salesById = $preparedReplacementContext['sales_by_id'] ?? collect();
                $replacementSales = new \Illuminate\Database\Eloquent\Collection();

                foreach ($newReplacementSaleIds as $saleId) {
                    if ($salesById->has($saleId)) {
                        $replacementSales->push($salesById->get($saleId));
                    }
                }

                $missingSaleIds = array_diff($newReplacementSaleIds, $replacementSales->pluck('id')->all());
                if (! empty($missingSaleIds)) {
                    $anomalies[] = 'Referenced replacement sales missing in database: ' . implode(', ', $missingSaleIds) . '.';
                }

                return $replacementSales;
            }
        }

        return $this->resolveReplacementSalesFallback($checkout, $existingSaleIds, $anomalies);
    }

    /**
     * Fallback single-record replacement sales resolution when no prepared batch context is provided.
     *
     * @param PosCheckout $checkout
     * @param array<int> $existingSaleIds
     * @param array<string> $anomalies
     * @return Collection<int, Sale>
     */
    protected function resolveReplacementSalesFallback(
        PosCheckout $checkout,
        array $existingSaleIds,
        array &$anomalies
    ): Collection {
        $completedReturnIds = PosReturn::query()
            ->where('status', PosReturn::STATUS_COMPLETED)
            ->where('is_reversed', false)
            ->where(function ($q) use ($checkout) {
                $q->where('pos_checkout_id', $checkout->id);
                if (! empty($checkout->pos_transaction_id)) {
                    $q->orWhere('pos_transaction_id', $checkout->pos_transaction_id);
                }
            })
            ->pluck('id');

        if ($completedReturnIds->isEmpty()) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        $completedReturnLineIds = PosReturnLine::query()
            ->whereIn('pos_return_id', $completedReturnIds)
            ->where('resolution', PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT)
            ->pluck('id');

        if ($completedReturnLineIds->isEmpty()) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        $replacementSaleIds = DispatchDetail::query()
            ->whereIn('pos_return_line_id', $completedReturnLineIds)
            ->whereNotNull('sale_id')
            ->pluck('sale_id')
            ->unique()
            ->values()
            ->all();

        $newReplacementSaleIds = array_values(array_diff($replacementSaleIds, $existingSaleIds));
        if (empty($newReplacementSaleIds)) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        $replacementSales = Sale::withoutGlobalScope(ArchivingScope::class)
            ->with(['tenantSetting', 'salePayments.creditApplications', 'salePayments.paymentMethod'])
            ->whereIn('id', $newReplacementSaleIds)
            ->get();

        $missingSaleIds = array_diff($newReplacementSaleIds, $replacementSales->pluck('id')->all());
        if (! empty($missingSaleIds)) {
            $anomalies[] = 'Referenced replacement sales missing in database: ' . implode(', ', $missingSaleIds) . '.';
        }

        return $replacementSales;
    }
}
