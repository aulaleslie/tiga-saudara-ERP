<?php

namespace Modules\Sale\Services;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
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
            : $sale->saleDispatches()->with('details.replacementReturnedSerial')->get();

        if ($dispatches->isEmpty()) {
            return;
        }

        $dispatches->loadMissing('details.replacementReturnedSerial');

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

        $legacyReturnedSerialMetadata = $this->resolveLegacyReturnedSerialMetadata((int) $sale->id, $serialIds);

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

                    if ($this->isReplacementLineageDetail($detail)) {
                        $badges[] = $this->makeBadge(
                            $serialNumber,
                            'bg-primary',
                            $this->buildReplacementBadgeTitle($detail, $dispatch),
                            'replacement'
                        );
                        continue;
                    }

                    $serialRecord = $serialRecords->get($this->serialKey((int) $detail->product_id, $normalized));

                    if (! $serialRecord) {
                        $badges[] = $this->makeBadge($serialNumber, 'bg-secondary', 'Serial tidak ditemukan', 'unknown');
                        continue;
                    }

                    $tracking = $trackingsBySerialId->get($serialRecord->id);
                    $returnedMetadata = $this->resolveReturnedMetadata(
                        $tracking,
                        $legacyReturnedSerialMetadata[(int) $serialRecord->id] ?? null
                    );

                    if ($returnedMetadata !== null) {
                        $badges[] = $this->makeBadge(
                            $serialNumber,
                            'bg-danger',
                            $this->buildReturnedBadgeTitle($returnedMetadata['returned_at'] ?? null),
                            'returned'
                        );
                        continue;
                    }

                    $badges[] = $this->makeBadge($serialNumber, 'bg-info', 'Masih aktif pada penjualan ini', 'active_sale');
                }

                $detail->serialNumberBadges = $badges;
            }
        }
    }

    /**
     * @return array<int, array{returned_at:mixed}>
     */
    protected function resolveLegacyReturnedSerialMetadata(int $saleId, Collection $candidateSerialIds): array
    {
        if ($saleId <= 0 || $candidateSerialIds->isEmpty()) {
            return [];
        }

        $saleReturnDetails = SaleReturnDetail::query()
            ->whereHas('saleReturn', function ($query) use ($saleId) {
                $query->where('sale_id', $saleId);
            })
            ->with(['saleReturn:id,sale_id,date,status'])
            ->get(['id', 'sale_return_id', 'serial_number_ids']);

        $saleReturnDetailIds = $saleReturnDetails->pluck('id');

        $returnedByHistory = collect();
        if ($saleReturnDetailIds->isNotEmpty()) {
            $returnedByHistory = SerialNumberHistory::query()
                ->whereIn('product_serial_number_id', $candidateSerialIds)
                ->where('event_type', SerialNumberHistory::EVENT_SALE_RETURNED)
                ->where('reference_type', SaleReturnDetail::class)
                ->whereIn('reference_id', $saleReturnDetailIds)
                ->get(['product_serial_number_id', 'reference_id', 'created_at'])
                ->mapWithKeys(function (SerialNumberHistory $history) use ($saleReturnDetails) {
                    $detail = $saleReturnDetails->firstWhere('id', (int) $history->reference_id);

                    return [
                        (int) $history->product_serial_number_id => [
                            'returned_at' => $history->created_at ?? $detail?->saleReturn?->date,
                        ],
                    ];
                });
        }

        $returnedByState = $saleReturnDetails
            ->filter(function (SaleReturnDetail $detail) {
                $status = strtolower((string) ($detail->saleReturn?->status ?? ''));

                return in_array($status, ['awaiting settlement', 'completed'], true);
            })
            ->flatMap(function (SaleReturnDetail $detail) {
                $returnedAt = $detail->saleReturn?->date;

                return collect($detail->serial_number_ids ?? [])->mapWithKeys(function ($id) use ($returnedAt) {
                    return [(int) $id => ['returned_at' => $returnedAt]];
                });
            })
            ->filter(fn ($metadata, $id) => $candidateSerialIds->contains((int) $id));

        return $returnedByHistory
            ->union($returnedByState)
            ->all();
    }

    protected function isReplacementLineageDetail(DispatchDetail $detail): bool
    {
        return ! is_null($detail->pos_return_line_id)
            || ! is_null($detail->replacement_of_dispatch_detail_id)
            || ! is_null($detail->replacement_returned_serial_id);
    }

    /**
     * @param array{returned_at:mixed}|null $legacyMetadata
     * @return array{returned_at:mixed}|null
     */
    protected function resolveReturnedMetadata(?SalesOrderSerialTracking $tracking, ?array $legacyMetadata): ?array
    {
        if ($tracking && ! is_null($tracking->return_date)) {
            return ['returned_at' => $tracking->return_date];
        }

        if ($legacyMetadata !== null) {
            return $legacyMetadata;
        }

        return null;
    }

    protected function buildReturnedBadgeTitle($returnedAt): string
    {
        $formatted = $this->formatBadgeDate($returnedAt);

        if ($formatted === null) {
            return 'Sudah diretur dari penjualan ini';
        }

        return 'Sudah diretur dari penjualan ini pada '.$formatted;
    }

    protected function buildReplacementBadgeTitle(DispatchDetail $detail, Dispatch $dispatch): string
    {
        $returnedSerial = trim((string) ($detail->replacementReturnedSerial?->serial_number ?? ''));
        $dispatchDate = $this->formatBadgeDate($dispatch->dispatch_date);

        $title = 'Serial pengganti POS retur';

        if ($returnedSerial !== '') {
            $title .= ' untuk serial retur '.$returnedSerial;
        }

        if ($dispatchDate !== null) {
            $title .= ' dikirim pada '.$dispatchDate;
        }

        return $title;
    }

    protected function formatBadgeDate($value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
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
        return ProductSerialNumber::normalize($value);
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
