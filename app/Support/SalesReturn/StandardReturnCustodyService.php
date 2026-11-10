<?php

namespace App\Support\SalesReturn;

use App\Services\SerialNumberHistoryService;
use App\Support\SalesReturn\SerialRegistration\ReturnSerialRegistrationService;
use App\Support\SalesReturn\SerialRegistration\HistoricalFulfillmentClassifier;
use Modules\SalesReturn\Entities\ReturnSerialRegistration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\Transaction;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\SalesOrderSerialTracking;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnCustody;
use Modules\SalesReturn\Entities\SaleReturnCustodyMovement as Movement;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnInspection;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement;
use Modules\Setting\Entities\Location;

/**
 * Held custody of standard returned goods (policy v1).
 *
 * Conservation: for every custody row, held = received - released - dispatched >= 0, every change
 * is an append-only movement, and goods enter sellable stock ONLY through release().
 * Held goods are never in ProductStock / product_quantity; settlement never releases them.
 *
 * All methods must run inside a transaction. Lock order follows StandardReturnGuard:
 * source sale -> return header -> custody rows (by id) -> serials -> product / stock.
 */
class StandardReturnCustodyService
{
    public function __construct(private readonly StandardReturnGuard $guard)
    {
    }

    public static function policyVersion(): int
    {
        return (int) config('sales_return.custody_policy_version', 1);
    }

    public static function enabled(): bool
    {
        return (bool) config('sales_return.custody_enabled', true);
    }

    // ------------------------------------------------------------------ receiving

    /**
     * First receiving under the custody policy. Replays are safe: an occurrence that already has
     * custody is skipped, so custody and its RECEIVED movement exist exactly once.
     *
     * @return Collection<int, SaleReturnCustody> custody rows created by THIS call
     */
    public function receive(SaleReturn $locked, Carbon $receivedAt, int $actorId): Collection
    {
        $created = collect();

        $details = SaleReturnDetail::query()
            ->where('sale_return_id', $locked->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $dispatchDetails = DispatchDetail::query()
            ->whereIn('id', $details->pluck('dispatch_detail_id')->filter()->unique()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($details as $detail) {
            $quantity = round((float) ($detail->quantity ?? 0), 3);   // decimal(15,3): never truncated
            if ($quantity <= 0) {
                continue;
            }

            $dispatchDetail = $dispatchDetails->get($detail->dispatch_detail_id);
            if (! $dispatchDetail) {
                throw new \RuntimeException('Detail pengiriman tidak ditemukan untuk retur penjualan.');
            }

            $locationId = $detail->location_id ?? $locked->location_id ?? $dispatchDetail->location_id;
            if (! $locationId) {
                throw new \RuntimeException('Lokasi penerimaan retur tidak dapat ditentukan.');
            }

            $ownerSettingId = (int) (Location::query()->whereKey($locationId)->value('setting_id') ?: $locked->setting_id);
            $taxId = $dispatchDetail->tax_id;

            $serialIds = collect($detail->serial_number_ids ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();
            $serialIds = $this->registerPendingSerials($detail, $dispatchDetail, $serialIds, $quantity, (int) $locationId, $taxId, $receivedAt, $actorId, $locked);
            if ($serialIds->count() > $quantity) {
                throw new \RuntimeException('Jumlah nomor seri melebihi kuantitas retur.');
            }

            $serials = collect();
            if ($serialIds->isNotEmpty()) {
                $serials = ProductSerialNumber::query()->whereIn('id', $serialIds)->orderBy('id')->lockForUpdate()->get();
                if ($serials->count() !== $serialIds->count()) {
                    throw new \RuntimeException('Sebagian nomor seri tidak ditemukan.');
                }
                foreach ($serials as $serial) {
                    if ((int) $serial->dispatch_detail_id !== (int) $dispatchDetail->id
                        && \App\Support\SalesReturn\SerialRegistration\RegisteredOccurrences::sourceDispatchDetailId((int) $serial->id) !== (int) $dispatchDetail->id) {
                        throw new \RuntimeException('Nomor seri tidak berasal dari pengiriman penjualan ini.');
                    }
                }
            }

            // Ownership routing: units acquired through a finalized buyout come back to the company-owned standard
            // destination of their buyout line; everything else keeps the original route. Held until inspection either way.
            try {
                $ownership = app(\Modules\Consignment\Services\ConsignmentBuyoutReturnOwnershipService::class)
                    ->resolveForReceive($detail, $dispatchDetail, $serialIds, $quantity, $actorId);
            } catch (\DomainException $e) {
                throw new SalesReturnStateException($e->getMessage());
            }
            $acquiredSerialDestination = [];
            $acquiredParts = [];
            foreach ($ownership['allocations'] as $alloc) {
                if (! empty($alloc['serial_ids'])) {
                    foreach ($alloc['serial_ids'] as $sid) {
                        $acquiredSerialDestination[(int) $sid] = (int) $alloc['destination_location_id'];
                    }
                } else {
                    $acquiredParts[] = $alloc;
                }
            }

            $baseFor = function (int $locId) use ($locked, $detail, $dispatchDetail, $taxId): array {
                return [
                    'sale_return_id' => $locked->id,
                    'sale_return_detail_id' => $detail->id,
                    'sale_id' => $locked->sale_id,
                    'dispatch_detail_id' => $dispatchDetail->id,
                    'product_id' => $detail->product_id,
                    'owner_setting_id' => (int) (Location::query()->whereKey($locId)->value('setting_id') ?: $locked->setting_id),
                    'location_id' => $locId,
                    'tax_id' => $taxId,
                    'policy_version' => self::policyVersion(),
                ];
            };

            foreach ($serials as $serial) {
                $serialLocation = $acquiredSerialDestination[(int) $serial->id] ?? (int) $locationId;
                $row = $this->openCustody($baseFor($serialLocation), $serial->id, 1, $receivedAt, $actorId);
                if ($row) {
                    $created->push($row);
                    $this->holdSerial($serial, $serialLocation, $taxId, $detail, $locked, $receivedAt);
                }
            }

            $untracked = round($quantity - $serials->count(), 3);
            foreach ($acquiredParts as $part) {
                $partQty = round((float) $part['quantity'], 3);
                if ($partQty <= 0 || $untracked <= 0) {
                    continue;
                }
                $row = $this->openCustody($baseFor((int) $part['destination_location_id']), null, $partQty, $receivedAt, $actorId, 'ACQ' . $part['line_id']);
                if ($row) {
                    $created->push($row);
                }
                $untracked = round($untracked - $partQty, 3);
            }
            if ($untracked > 0) {
                $row = $this->openCustody($baseFor((int) $locationId), null, $untracked, $receivedAt, $actorId);
                if ($row) {
                    $created->push($row);
                }
            }
        }

        return $created;
    }

    /**
     * Registers this detail's proposed serials (if any) inside the receiving transaction and returns the
     * complete serial id set. Source eligibility and cardinality are re-validated NOW; a stale or
     * inconsistent source stops the whole receiving for review (nothing is dropped silently).
     *
     * @param  Collection<int,int>  $serialIds
     * @return Collection<int,int>
     */
    private function registerPendingSerials(SaleReturnDetail $detail, DispatchDetail $dispatchDetail, Collection $serialIds, float $quantity, int $locationId, ?int $taxId, Carbon $at, int $actorId, SaleReturn $return): Collection
    {
        $claims = ReturnSerialRegistration::query()
            ->where('workflow', ReturnSerialRegistration::WORKFLOW_STANDARD)
            ->where('sale_return_detail_id', $detail->id)
            ->orderBy('id')->lockForUpdate()->get();

        if ($claims->isEmpty()) {
            return $serialIds;
        }

        $pending = $claims->whereNull('registered_serial_id');
        if ($pending->isNotEmpty()) {
            $class = app(HistoricalFulfillmentClassifier::class)->forDispatchDetail($dispatchDetail);
            if (! $class->allowsUnknownSerialRegistration()
                || $pending->contains(fn ($c) => $c->source_fingerprint !== $class->fingerprint)) {
                throw new SalesReturnStateException('Sumber penjualan berubah atau tidak lagi mengizinkan pendaftaran nomor seri baru. Muat ulang dan tinjau retur.');
            }
            if (! (bool) Product::query()->whereKey($detail->product_id)->value('serial_number_required')) {
                throw new SalesReturnStateException('Produk tidak lagi memerlukan nomor seri. Muat ulang dan tinjau retur.');
            }
        }

        $registered = app(ReturnSerialRegistrationService::class)->register($claims, [
            'location_id' => $locationId,
            'tax_id' => $taxId,
            'dispatch_detail_id' => $dispatchDetail->id,   // operational anchor only; classification excludes registered serials
            'status' => ProductSerialNumber::STATUS_RETURN_IN_PROCESS,
            'is_in_return_process' => true,
            'actor_id' => $actorId,
            'at' => $at,
            'reference' => $return,
        ]);

        $all = $serialIds->merge($registered->pluck('id')->map(fn ($id) => (int) $id))->unique()->values();
        if (abs($all->count() - $quantity) > 0.0004) {
            throw new SalesReturnStateException('Jumlah nomor seri tidak sama dengan kuantitas retur. Tinjau retur sebelum diterima.');
        }

        // Received evidence lives on the RETURN detail (never on the original sale/dispatch serial lists).
        $detail->forceFill(['serial_number_ids' => $all->all()])->save();

        return $all;
    }

    /** @param array<string, mixed> $base */
    private function openCustody(array $base, ?int $serialId, float $quantity, Carbon $at, int $actorId, ?string $keySuffix = null): ?SaleReturnCustody
    {
        $occurrence = $base['sale_return_detail_id'] . ':' . ($serialId ?? 0) . ($keySuffix ? ':' . $keySuffix : '');

        if (SaleReturnCustody::query()->where('occurrence_key', $occurrence)->lockForUpdate()->exists()) {
            return null;   // replay: this physical occurrence was already received
        }

        $custody = SaleReturnCustody::create(array_merge($base, [
            'product_serial_number_id' => $serialId,
            'quantity_received' => $quantity,
            'quantity_released' => 0,
            'quantity_dispatched' => 0,
            'version' => 1,
            'occurrence_key' => $occurrence,
        ]));

        Movement::create([
            'sale_return_custody_id' => $custody->id,
            'sale_return_id' => $custody->sale_return_id,
            'type' => Movement::TYPE_RECEIVED,
            'quantity' => $quantity,
            'product_serial_number_id' => $serialId,
            'actor_id' => $actorId,
            'occurred_at' => $at,
            'notes' => null,
            'movement_key' => 'recv:' . $occurrence,
        ]);

        return $custody;
    }

    private function holdSerial(ProductSerialNumber $serial, int $locationId, ?int $taxId, SaleReturnDetail $detail, SaleReturn $return, Carbon $at): void
    {
        HeldSerialGuard::asCustodyService(function () use ($serial, $locationId, $taxId) {
            // dispatch_detail_id is deliberately KEPT: it is the lineage anchor and keeps the serial out of
            // every "not dispatched" availability rule. Status + flag hide it from the common scopes.
            $serial->forceFill([
                'status' => ProductSerialNumber::STATUS_RETURN_IN_PROCESS,
                'is_in_return_process' => true,
                'location_id' => $locationId,
                'tax_id' => $taxId,
            ])->save();
        });

        SalesOrderSerialTracking::query()
            ->where('sale_id', $return->sale_id)
            ->where('product_serial_number_id', $serial->id)
            ->update(['return_date' => $at, 'updated_at' => $at]);

        SerialNumberHistoryService::record(
            $serial->id,
            SerialNumberHistory::EVENT_SALE_RETURNED,
            $locationId,
            $detail,
            'Diterima ke custody retur penjualan (ditahan, belum dapat dijual)'
        );
    }

    // ------------------------------------------------------------------ eligibility

    /**
     * Why this custody cannot be released to the store (null = eligible).
     *
     * Store release is only for originals the customer does NOT get back: a final approved cash
     * return, or a different unit already dispatched as replacement. Goods committed to
     * original-goods dispatch (repair / cannot process) are customer-bound.
     */
    public function releaseBlocker(SaleReturnCustody $custody): ?string
    {
        if ($custody->held_quantity <= 0) {
            return 'Custody sudah habis (sudah dilepas atau dikirim).';
        }

        $settlement = $this->settlementForCustody($custody);

        if (! $settlement) {
            return 'Penyelesaian belum diputuskan: barang belum dapat dilepas ke stok toko.';
        }

        $method = strtoupper((string) $settlement->method);

        if ($method === SaleReturnDetail::METHOD_CASH_REFUND && $settlement->status === SaleReturnItemSettlement::STATUS_APPROVED) {
            return null;
        }

        // A different unit already went to the customer: the original is retained. Decided from the
        // PERSISTED dispatch identity (kind), never from comparing serial strings, so non-serial
        // replacements are covered too. ORIGINAL dispatch consumed the custody itself.
        // Only a repair can be answered by a replacement; cannot-process goods are ORIGINAL-only, so a
        // REPLACEMENT recorded against them is an invalid combination and never releases the original.
        if ($method === SaleReturnDetail::METHOD_PRODUCT_REPAIR
            && $settlement->status === SaleReturnItemSettlement::STATUS_DISPATCHED
            && strtoupper((string) $settlement->dispatch_kind) === StandardDispatchCustodyService::REPLACEMENT) {
            return null;
        }

        if (in_array($method, [SaleReturnDetail::METHOD_PRODUCT_REPAIR, SaleReturnDetail::METHOD_UNPROCESSED], true)) {
            return 'Barang ini terikat pengiriman kembali ke pelanggan (perbaikan / tidak dapat diproses) dan tidak boleh dilepas ke stok.';
        }

        return 'Status penyelesaian belum final: barang belum dapat dilepas ke stok toko.';
    }

    private function settlementForCustody(SaleReturnCustody $custody): ?SaleReturnItemSettlement
    {
        return SaleReturnItemSettlement::query()
            ->where('sale_return_id', $custody->sale_return_id)
            ->where('sale_return_detail_id', $custody->sale_return_detail_id)
            ->when($custody->product_serial_number_id,
                fn ($q) => $q->where('product_serial_number_id', $custody->product_serial_number_id),
                fn ($q) => $q->whereNull('product_serial_number_id'))
            ->first();
    }

    /** The custody row a settlement dispatches from (locked when $lock). */
    public function custodyForSettlement(SaleReturnItemSettlement $settlement, bool $lock = false): SaleReturnCustody
    {
        $query = SaleReturnCustody::query()
            ->where('sale_return_id', $settlement->sale_return_id)
            ->where('sale_return_detail_id', $settlement->sale_return_detail_id)
            ->when($settlement->product_serial_number_id,
                fn ($q) => $q->where('product_serial_number_id', $settlement->product_serial_number_id),
                fn ($q) => $q->whereNull('product_serial_number_id'));
        if ($lock) {
            $query->lockForUpdate();
        }

        $custody = $query->first();
        if (! $custody) {
            throw new SalesReturnStateException('Custody untuk item ini tidak ditemukan.');
        }

        return $custody;
    }

    // ------------------------------------------------------------------ inspection + release

    /**
     * @param  array<int, array{custody_id: int, quantity?: int|float}>  $selections
     */
    public function release(SaleReturn $saleReturn, array $selections, string $notes, ?string $requestKey = null): SaleReturnInspection
    {
        // The dedicated permission is enforced INSIDE the mutation (UI visibility is not authorization).
        abort_if(Gate::denies('saleReturns.inspect'), 403);

        $notes = trim($notes);
        // Stored through BaseModel, which upper-cases text: normalise the replay key the same way.
        $requestKey = $requestKey !== null ? strtoupper(trim($requestKey)) : null;
        if ($notes === '') {
            throw new SalesReturnStateException('Catatan inspeksi wajib diisi.');
        }
        if ($selections === []) {
            throw new SalesReturnStateException('Pilih barang yang akan dilepas.');
        }

        return DB::transaction(function () use ($saleReturn, $selections, $notes, $requestKey) {
            // Consignment buyout protocol: (setting, product) coordination locks precede the document locks below.
            \Modules\Consignment\Services\ConsignmentBuyoutReservationGuard::lockCoordination(
                (int) \Modules\SalesReturn\Entities\SaleReturn::query()->whereKey($saleReturn->id)->value('setting_id'),
                \Modules\SalesReturn\Entities\SaleReturnCustody::query()->where('sale_return_id', $saleReturn->id)->pluck('product_id')->all()
            );

            // Source sale (archived sales stay accessible) -> header; business scope + standard ownership.
            $locked = $this->guard->lockForAction($saleReturn);

            if (! $locked->usesHeldCustody()) {
                throw new SalesReturnStateException(
                    'Retur ini diterima sebelum kebijakan custody berlaku dan tidak memiliki custody. Tidak ada barang yang dapat dilepas; hubungi administrator untuk audit.'
                );
            }

            if ($requestKey !== null && SaleReturnInspection::query()
                ->where('sale_return_id', $locked->id)->where('request_key', $requestKey)->lockForUpdate()->exists()) {
                throw new SalesReturnStateException('Permintaan inspeksi ini sudah diproses.');
            }

            // Merge duplicate selections, then lock rows in id order.
            $wanted = [];
            foreach ($selections as $selection) {
                $id = (int) ($selection['custody_id'] ?? 0);
                $quantity = round((float) ($selection['quantity'] ?? 1), 3);
                if ($id <= 0 || $quantity <= 0) {
                    throw new SalesReturnStateException('Pemilihan barang tidak valid.');
                }
                $wanted[$id] = round(($wanted[$id] ?? 0) + $quantity, 3);
            }
            ksort($wanted);

            $custodies = SaleReturnCustody::query()
                ->where('sale_return_id', $locked->id)
                ->whereIn('id', array_keys($wanted))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($custodies->count() !== count($wanted)) {
                throw new SalesReturnStateException('Custody tidak termasuk dalam retur ini.');
            }

            foreach ($wanted as $id => $quantity) {
                $custody = $custodies[$id];
                if ($custody->product_serial_number_id && abs($quantity - 1.0) > 0.0004) {
                    throw new SalesReturnStateException('Barang bernomor seri dilepas satu per satu.');
                }
                if ($quantity - $custody->held_quantity > 0.0004) {
                    throw new SalesReturnStateException('Jumlah yang dilepas melebihi sisa custody.');
                }
                if ($reason = $this->releaseBlocker($custody)) {
                    throw new SalesReturnStateException($reason);
                }
            }

            $now = now();
            $inspection = SaleReturnInspection::create([
                'sale_return_id' => $locked->id,
                'inspected_by' => (int) Auth::id(),
                'inspected_at' => $now,
                'notes' => $notes,
                'released_quantity' => round(array_sum($wanted), 3),
                'request_key' => $requestKey,
            ]);

            foreach ($wanted as $id => $quantity) {
                $this->releaseOne($custodies[$id], $quantity, $inspection, $locked, $notes, $now);
            }

            return $inspection;
        });
    }

    private function releaseOne(SaleReturnCustody $custody, float $quantity, SaleReturnInspection $inspection, SaleReturn $return, string $notes, Carbon $at): void
    {
        // Balance first (guarded by the held check above, under the row lock).
        $updated = SaleReturnCustody::query()
            ->whereKey($custody->id)
            ->where('version', $custody->version)
            ->whereRaw('quantity_received - quantity_released - quantity_dispatched >= ? - 0.0004', [$quantity])
            ->update([
                'quantity_released' => DB::raw('quantity_released + ' . number_format($quantity, 3, '.', '')),
                'version' => DB::raw('version + 1'),
                'updated_at' => $at,
            ]);
        if ($updated !== 1) {
            throw new SalesReturnStateException('Custody berubah saat diproses; ulangi inspeksi.');
        }

        // Correct owner / tax bucket: exactly the anchors captured at receiving.
        $product = Product::query()->whereKey($custody->product_id)->lockForUpdate()->firstOrFail();
        $stock = ProductStock::query()
            ->where('product_id', $custody->product_id)
            ->where('location_id', $custody->location_id)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            $stock = ProductStock::create([
                'product_id' => $custody->product_id, 'location_id' => $custody->location_id,
                'quantity' => 0, 'quantity_tax' => 0, 'quantity_non_tax' => 0,
                'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0, 'broken_quantity' => 0,
                'tax_id' => $custody->tax_id,
            ]);
        }

        // Stock columns are decimal(15,3): keep every fractional digit (10.5 + 1 = 11.5).
        $previousProduct = (float) ($product->product_quantity ?? 0);
        $previousAtLocation = (float) ($stock->quantity ?? 0);
        $taxed = (bool) $custody->tax_id;

        if ($taxed) {
            $stock->quantity_tax = round((float) ($stock->quantity_tax ?? 0) + $quantity, 3);
        } else {
            $stock->quantity_non_tax = round((float) ($stock->quantity_non_tax ?? 0) + $quantity, 3);
        }
        $stock->quantity = round((float) $stock->quantity_non_tax + (float) $stock->quantity_tax
            + (float) ($stock->broken_quantity_non_tax ?? 0) + (float) ($stock->broken_quantity_tax ?? 0), 3);
        $stock->broken_quantity = round((float) ($stock->broken_quantity_non_tax ?? 0) + (float) ($stock->broken_quantity_tax ?? 0), 3);
        $stock->tax_id = $custody->tax_id;
        $stock->save();

        $product->product_quantity = round($previousProduct + $quantity, 3);
        $product->save();

        Transaction::create([
            'product_id' => $product->id,
            'setting_id' => (int) $custody->owner_setting_id,
            'type' => $taxed ? 'SALE_RETURN_RELEASE_TAX' : 'SALE_RETURN_RELEASE_NON_TAX',
            'quantity' => $quantity,
            'current_quantity' => (float) $product->product_quantity,
            'broken_quantity' => (float) ($stock->broken_quantity ?? 0),
            'location_id' => $custody->location_id,
            'user_id' => Auth::id(),
            'reason' => 'INSPEKSI RETUR PENJUALAN: ' . $return->reference,
            'previous_quantity' => $previousProduct,
            'after_quantity' => (float) $product->product_quantity,
            'previous_quantity_at_location' => $previousAtLocation,
            'after_quantity_at_location' => (float) $stock->quantity,
            'quantity_tax' => $taxed ? $quantity : 0,
            'quantity_non_tax' => $taxed ? 0 : $quantity,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        if ($custody->product_serial_number_id) {
            $serial = ProductSerialNumber::query()->whereKey($custody->product_serial_number_id)->lockForUpdate()->firstOrFail();
            HeldSerialGuard::asCustodyService(function () use ($serial, $custody) {
                $serial->forceFill([
                    'status' => ProductSerialNumber::STATUS_ACTIVE,
                    'is_in_return_process' => false,
                    'dispatch_detail_id' => null,
                    'location_id' => $custody->location_id,
                    'tax_id' => $custody->tax_id,
                ])->save();
            });

            SerialNumberHistoryService::record(
                $serial->id,
                SerialNumberHistory::EVENT_STATUS_CHANGED,
                $custody->location_id,
                $inspection,
                'Dilepas ke stok oleh inspeksi retur penjualan: ' . $notes
            );
        }

        Movement::create([
            'sale_return_custody_id' => $custody->id,
            'sale_return_id' => $custody->sale_return_id,
            'inspection_id' => $inspection->id,
            'type' => Movement::TYPE_RELEASED,
            'quantity' => $quantity,
            'product_serial_number_id' => $custody->product_serial_number_id,
            'actor_id' => Auth::id(),
            'occurred_at' => $at,
            'notes' => $notes,
            'destination_setting_id' => $custody->owner_setting_id,
            'destination_location_id' => $custody->location_id,
            'destination_tax_id' => $custody->tax_id,
            'movement_key' => 'rel:' . $inspection->id . ':' . $custody->id,
        ]);
    }

    // ------------------------------------------------------------------ display

    /**
     * Held / released / customer-dispatched quantities, independent of settlement or completion.
     *
     * @return array{held: float, released: float, dispatched: float, received: float, rows: Collection}
     */
    public function summary(SaleReturn $saleReturn): array
    {
        $rows = SaleReturnCustody::query()
            ->with(['product:id,product_name,product_code', 'serialNumber:id,serial_number'])
            ->where('sale_return_id', $saleReturn->id)
            ->orderBy('id')
            ->get();

        return [
            'received' => round((float) $rows->sum(fn ($row) => (float) $row->quantity_received), 3),
            'released' => round((float) $rows->sum(fn ($row) => (float) $row->quantity_released), 3),
            'dispatched' => round((float) $rows->sum(fn ($row) => (float) $row->quantity_dispatched), 3),
            'held' => round((float) $rows->sum(fn ($row) => $row->held_quantity), 3),
            'rows' => $rows->map(function (SaleReturnCustody $row) {
                $row->setAttribute('release_blocker', $this->releaseBlocker($row));

                return $row;
            }),
        ];
    }
}
