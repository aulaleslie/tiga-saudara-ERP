<?php

namespace Modules\Pos\Services;

use App\Scopes\ArchivingScope;
use Illuminate\Support\Collection;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Pos\Entities\PosTransaction;
use Modules\Sale\Entities\Sale;

class PosReachableSalesResolver
{
    /**
     * Resolve reachable sales for a POS transaction or checkout.
     *
     * @param PosTransaction|PosCheckout $record
     * @return Collection<int, Sale>
     */
    public function resolveSales(PosTransaction|PosCheckout $record): Collection
    {
        $diagnostics = $this->resolveWithDiagnostics($record);

        return $diagnostics['sales'];
    }

    /**
     * Resolve reachable sales along with diagnostics about mapping integrity.
     *
     * @param PosTransaction|PosCheckout $record
     * @return array{
     *     sales: Collection<int, Sale>,
     *     has_split_mapping: bool,
     *     is_inline_fallback: bool,
     *     is_valid: bool,
     *     anomalies: array<string>
     * }
     */
    public function resolveWithDiagnostics(PosTransaction|PosCheckout $record): array
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
            $allSaleRelationsLoaded = $checkoutSales->every(fn ($cs) => $cs->relationLoaded('sale'));

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

            return [
                'sales' => new \Illuminate\Database\Eloquent\Collection([$sale]),
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
}
