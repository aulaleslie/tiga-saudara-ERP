<?php

namespace Modules\Sale\Services;

use Illuminate\Support\Collection;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalesOrderSerialTracking;
use Modules\SalesReturn\Entities\SaleReturnDetail;

class SaleSerialDisplayResolver
{
    /**
     * Annotate dispatch detail rows with view-only serial badge metadata.
     */
    public function annotateDispatchesForSale(Sale $sale): void
    {
        $dispatches = $sale->relationLoaded('saleDispatches')
            ? $sale->saleDispatches
            : $sale->saleDispatches()->with('details')->get();

        if ($dispatches->isEmpty()) {
            return;
        }

        $detailSerials = [];
        $lookupPairs = collect();

        foreach ($dispatches as $dispatch) {
            foreach ($dispatch->details as $detail) {
                $serials = $this->normalizeSerials($detail->serial_numbers);
                $detailSerials[$detail->id] = $serials;
                $detail->serialNumberBadges = [];

                foreach ($serials as $serialNumber) {
                    $lookupPairs->push([
                        'product_id' => (int) $detail->product_id,
                        'serial_number' => $serialNumber,
                    ]);
                }
            }
        }

        if ($lookupPairs->isEmpty()) {
            return;
        }

        $productIds = $lookupPairs->pluck('product_id')->unique()->values();
        $serialNumbers = $lookupPairs->pluck('serial_number')->map(fn ($value) => $this->normalizeSerialValue($value))->unique()->values();

        $serialRecords = ProductSerialNumber::query()
            ->whereIn('product_id', $productIds)
            ->whereIn('serial_number', $serialNumbers)
            ->get(['id', 'product_id', 'serial_number'])
            ->keyBy(fn (ProductSerialNumber $serial) => $this->serialKey((int) $serial->product_id, (string) $serial->serial_number));

        $serialIds = $serialRecords->pluck('id')->map(fn ($id) => (int) $id)->unique()->values();

        $trackingsBySerialId = $serialIds->isEmpty()
            ? collect()
            : SalesOrderSerialTracking::query()
                ->where('sale_id', $sale->id)
                ->whereIn('product_serial_number_id', $serialIds)
                ->get(['id', 'sale_id', 'product_serial_number_id', 'dispatch_date', 'return_date'])
                ->keyBy('product_serial_number_id');

        $legacyReturnedSerialIdSet = $this->resolveLegacyReturnedSerialIdSet((int) $sale->id, $serialIds);

        foreach ($dispatches as $dispatch) {
            $dispatchStatus = strtoupper((string) ($dispatch->status ?? ''));

            foreach ($dispatch->details as $detail) {
                $rawSerials = $detailSerials[$detail->id] ?? [];
                $badges = [];

                foreach ($rawSerials as $serialNumber) {
                    $normalized = $this->normalizeSerialValue($serialNumber);

                    if ($dispatchStatus === Dispatch::STATUS_PENDING) {
                        $badges[] = $this->makeBadge($serialNumber, 'bg-secondary', 'Menunggu persetujuan pengiriman', 'pending_dispatch');
                        continue;
                    }

                    if ($dispatchStatus === Dispatch::STATUS_REJECTED) {
                        $badges[] = $this->makeBadge($serialNumber, 'bg-secondary', 'Pengiriman ditolak', 'rejected_dispatch');
                        continue;
                    }

                    $serialRecord = $serialRecords->get($this->serialKey((int) $detail->product_id, $normalized));

                    if (! $serialRecord) {
                        $badges[] = $this->makeBadge($serialNumber, 'bg-secondary', 'Serial tidak ditemukan', 'unknown');
                        continue;
                    }

                    $tracking = $trackingsBySerialId->get($serialRecord->id);
                    $isReturned = $tracking
                        ? ! is_null($tracking->return_date)
                        : isset($legacyReturnedSerialIdSet[(int) $serialRecord->id]);

                    if ($isReturned) {
                        $badges[] = $this->makeBadge($serialNumber, 'bg-danger', 'Sudah diretur dari penjualan ini', 'returned');
                        continue;
                    }

                    $badges[] = $this->makeBadge($serialNumber, 'bg-info', 'Masih aktif pada penjualan ini', 'active_sale');
                }

                $detail->serialNumberBadges = $badges;
            }
        }
    }

    /**
     * @return array<int, true>
     */
    protected function resolveLegacyReturnedSerialIdSet(int $saleId, Collection $candidateSerialIds): array
    {
        if ($saleId <= 0 || $candidateSerialIds->isEmpty()) {
            return [];
        }

        $saleReturnDetailIds = SaleReturnDetail::query()
            ->whereHas('saleReturn', function ($query) use ($saleId) {
                $query->where('sale_id', $saleId);
            })
            ->pluck('id');

        $returnedByHistory = collect();
        if ($saleReturnDetailIds->isNotEmpty()) {
            $returnedByHistory = SerialNumberHistory::query()
                ->whereIn('product_serial_number_id', $candidateSerialIds)
                ->where('event_type', SerialNumberHistory::EVENT_SALE_RETURNED)
                ->where('reference_type', SaleReturnDetail::class)
                ->whereIn('reference_id', $saleReturnDetailIds)
                ->pluck('product_serial_number_id')
                ->map(fn ($id) => (int) $id);
        }

        $returnedByState = SaleReturnDetail::query()
            ->whereHas('saleReturn', function ($query) use ($saleId) {
                $query->where('sale_id', $saleId)
                    ->where(function ($statusQuery) {
                        $statusQuery->whereRaw('LOWER(status) = ?', ['awaiting settlement'])
                            ->orWhereRaw('LOWER(status) = ?', ['completed']);
                    });
            })
            ->whereNotNull('serial_number_ids')
            ->get(['serial_number_ids'])
            ->flatMap(function (SaleReturnDetail $detail) {
                return collect($detail->serial_number_ids ?? []);
            })
            ->map(fn ($id) => (int) $id)
            ->filter();

        return $returnedByHistory
            ->concat($returnedByState)
            ->filter(fn ($id) => $candidateSerialIds->contains((int) $id))
            ->unique()
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * @param mixed $serialPayload
     * @return array<int, string>
     */
    protected function normalizeSerials($serialPayload): array
    {
        $decoded = $serialPayload;

        if (is_string($serialPayload)) {
            $decoded = json_decode($serialPayload, true);
        }

        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->filter(fn ($value) => is_string($value) || is_numeric($value))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->values()
            ->all();
    }

    protected function normalizeSerialValue(string $value): string
    {
        return mb_strtoupper(trim($value), 'UTF-8');
    }

    protected function serialKey(int $productId, string $serialNumber): string
    {
        return $productId.'|'.$this->normalizeSerialValue($serialNumber);
    }

    /**
     * @return array{serial_number:string,badge_class:string,title:string,state:string}
     */
    protected function makeBadge(string $serialNumber, string $badgeClass, string $title, string $state): array
    {
        return [
            'serial_number' => $serialNumber,
            'badge_class' => $badgeClass,
            'title' => $title,
            'state' => $state,
        ];
    }
}
