<?php

namespace App\Support\SalesReturn;

use App\Services\SerialNumberHistoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\Transaction;
use Modules\SalesReturn\Entities\SaleReturnCustody;
use Modules\SalesReturn\Entities\SaleReturnCustodyMovement as Movement;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement;
use Modules\Setting\Entities\Location;

/**
 * Customer dispatch for returns held in custody (policy v1).
 *
 *  ORIGINAL    the held goods themselves go back (repair / cannot process): custody
 *              quantity_dispatched is consumed once; sellable stock is NOT touched.
 *  REPLACEMENT a different unit from available stock goes out: that stock bucket is consumed and
 *              the original's custody is preserved (it may later be released by inspection).
 *
 * The decision is persisted on the settlement (dispatch_kind, dispatch_serial_number_id) at
 * request time and re-validated under locks at approval. Callers hold the locks taken by
 * StandardReturnGuard (source sale -> header -> settlement); custody rows are locked here.
 */
class StandardDispatchCustodyService
{
    public const ORIGINAL = 'ORIGINAL';
    public const REPLACEMENT = 'REPLACEMENT';

    /**
     * Request time: decide and validate the dispatch identity.
     *
     * @return array{dispatch_kind: string, dispatch_serial_number_id: ?int, location_id: ?int}
     */
    public function resolveIdentity(SaleReturnItemSettlement $locked, Product $product, ?string $serial, ?string $mode, ?int $sourceLocationId, StandardReturnCustodyService $custodyService): array
    {
        $custody = $custodyService->custodyForSettlement($locked, true);
        $quantity = $this->quantity($locked, $custody);
        $originalOnly = $this->isOriginalOnly($locked);

        if ($product->serial_number_required) {
            if (! $serial || trim($serial) === '') {
                throw new SalesReturnStateException('Serial Number wajib diisi untuk produk ini.');
            }
            $serial = ProductSerialNumber::normalize($serial);
            $sn = ProductSerialNumber::query()->where('product_id', $product->id)->where('serial_number', $serial)->lockForUpdate()->first();
            if (! $sn) {
                throw new SalesReturnStateException("Serial Number {$serial} tidak ditemukan untuk produk ini.");
            }

            if ($custody->product_serial_number_id && (int) $sn->id === (int) $custody->product_serial_number_id) {
                $this->assertOriginalAvailable($custody, $quantity);

                return ['dispatch_kind' => self::ORIGINAL, 'dispatch_serial_number_id' => (int) $sn->id, 'location_id' => $locked->location_id];
            }

            $this->assertReplaceable($originalOnly);
            $this->assertReplacementSerial($sn, $custody, $locked);

            return ['dispatch_kind' => self::REPLACEMENT, 'dispatch_serial_number_id' => (int) $sn->id, 'location_id' => $sn->location_id];
        }

        // Non-serial: the mode is explicit and persisted; the default keeps the custody-bound original.
        $mode = strtoupper(trim((string) ($mode ?: self::ORIGINAL)));
        if (! in_array($mode, [self::ORIGINAL, self::REPLACEMENT], true)) {
            throw new SalesReturnStateException('Jenis pengiriman tidak valid.');
        }

        if ($mode === self::ORIGINAL) {
            $this->assertOriginalAvailable($custody, $quantity);

            return ['dispatch_kind' => self::ORIGINAL, 'dispatch_serial_number_id' => null, 'location_id' => $locked->location_id];
        }

        $this->assertReplaceable($originalOnly);
        $locationId = $sourceLocationId ?? $locked->location_id ?? $custody->location_id;
        $this->assertReplacementBucket($custody, (int) $locationId, $quantity, false);

        return ['dispatch_kind' => self::REPLACEMENT, 'dispatch_serial_number_id' => null, 'location_id' => (int) $locationId];
    }

    /** Approval time: apply the persisted identity exactly once. */
    public function execute(SaleReturnItemSettlement $locked, StandardReturnCustodyService $custodyService): void
    {
        $kind = strtoupper((string) $locked->dispatch_kind);
        if (! in_array($kind, [self::ORIGINAL, self::REPLACEMENT], true)) {
            throw new SalesReturnStateException('Identitas pengiriman belum tercatat; ajukan ulang pengiriman.');
        }

        if ($kind === self::REPLACEMENT) {
            $this->assertReplaceable($this->isOriginalOnly($locked));
        }

        $custody = $custodyService->custodyForSettlement($locked, true);
        $quantity = $this->quantity($locked, $custody);
        $now = now();

        if ($kind === self::ORIGINAL) {
            $this->dispatchOriginal($locked, $custody, $quantity, $now);
        } else {
            $this->dispatchReplacement($locked, $custody, $quantity, $now);
        }
    }

    // ------------------------------------------------------------------ original

    private function dispatchOriginal(SaleReturnItemSettlement $locked, SaleReturnCustody $custody, float $quantity, Carbon $at): void
    {
        $this->assertOriginalAvailable($custody, $quantity);

        $serialId = $custody->product_serial_number_id;
        if ($serialId && (int) $locked->dispatch_serial_number_id !== (int) $serialId) {
            throw new SalesReturnStateException('Serial yang dikirim tidak sama dengan serial yang ditahan.');
        }

        $updated = SaleReturnCustody::query()
            ->whereKey($custody->id)
            ->where('version', $custody->version)
            ->whereRaw('quantity_received - quantity_released - quantity_dispatched >= ? - 0.0004', [$quantity])
            ->update([
                'quantity_dispatched' => DB::raw('quantity_dispatched + ' . number_format($quantity, 3, '.', '')),
                'version' => DB::raw('version + 1'),
                'updated_at' => $at,
            ]);
        if ($updated !== 1) {
            throw new SalesReturnStateException('Custody berubah saat diproses; ulangi pengiriman.');
        }

        if ($serialId) {
            $serial = ProductSerialNumber::query()->whereKey($serialId)->lockForUpdate()->firstOrFail();
            HeldSerialGuard::asCustodyService(function () use ($serial) {
                // Back to the customer: SOLD again; the dispatch link (lineage) is kept.
                $serial->forceFill([
                    'status' => ProductSerialNumber::STATUS_SOLD,
                    'is_in_return_process' => false,
                ])->save();
            });
            SerialNumberHistoryService::record($serial->id, SerialNumberHistory::EVENT_STATUS_CHANGED, $serial->location_id, $locked,
                'Dikirim kembali ke pelanggan dari custody retur penjualan (barang asli)');
        }

        Movement::create([
            'sale_return_custody_id' => $custody->id,
            'sale_return_id' => $custody->sale_return_id,
            'type' => Movement::TYPE_DISPATCHED,
            'quantity' => $quantity,
            'product_serial_number_id' => $serialId,
            'actor_id' => Auth::id(),
            'occurred_at' => $at,
            'notes' => 'Pengiriman barang asli ke pelanggan (penyelesaian #' . $locked->id . ')',
            'movement_key' => 'disp:' . $locked->id . ':' . $custody->id,
        ]);
    }

    private function assertOriginalAvailable(SaleReturnCustody $custody, float $quantity): void
    {
        if ($quantity - $custody->held_quantity > 0.0004) {
            throw new SalesReturnStateException('Custody tidak mencukupi untuk dikirim (sudah dilepas atau dikirim).');
        }
    }

    // ------------------------------------------------------------------ replacement

    private function dispatchReplacement(SaleReturnItemSettlement $locked, SaleReturnCustody $custody, float $quantity, Carbon $at): void
    {
        $product = Product::query()->whereKey($custody->product_id)->lockForUpdate()->firstOrFail();

        if ($product->serial_number_required) {
            $sn = ProductSerialNumber::query()->whereKey((int) $locked->dispatch_serial_number_id)->lockForUpdate()->first();
            if (! $sn) {
                throw new SalesReturnStateException('Serial pengganti tidak ditemukan.');
            }
            $this->assertReplacementSerial($sn, $custody, $locked);
            $locationId = (int) $sn->location_id;
            $taxed = (bool) $sn->tax_id;
            $quantity = 1.0;
        } else {
            $locationId = (int) ($locked->location_id ?: $custody->location_id);
            $taxed = (bool) $custody->tax_id;   // required bucket = the original's tax bucket
            $sn = null;
        }

        $stock = $this->assertReplacementBucket($custody, $locationId, $quantity, $taxed, $sn !== null);

        $previousProduct = (float) $product->product_quantity;
        $previousAt = (float) $stock->quantity;

        if ($taxed) {
            $stock->quantity_tax = round((float) $stock->quantity_tax - $quantity, 3);
        } else {
            $stock->quantity_non_tax = round((float) $stock->quantity_non_tax - $quantity, 3);
        }
        $stock->quantity = round((float) $stock->quantity_non_tax + (float) $stock->quantity_tax
            + (float) ($stock->broken_quantity_non_tax ?? 0) + (float) ($stock->broken_quantity_tax ?? 0), 3);
        $stock->save();

        $product->product_quantity = round($previousProduct - $quantity, 3);
        $product->save();

        Transaction::create([
            'product_id' => $product->id,
            'setting_id' => (int) $custody->owner_setting_id,
            'type' => 'DISPATCH_RETURN',
            'quantity' => -$quantity,
            'current_quantity' => (float) $product->product_quantity,
            'broken_quantity' => (float) ($stock->broken_quantity ?? 0),
            'location_id' => $locationId,
            'user_id' => Auth::id(),
            'reason' => 'Dispatch replacement for Sale Return #' . $locked->saleReturn->reference,
            'previous_quantity' => $previousProduct,
            'after_quantity' => (float) $product->product_quantity,
            'previous_quantity_at_location' => $previousAt,
            'after_quantity_at_location' => (float) $stock->quantity,
            'quantity_non_tax' => $taxed ? 0 : $quantity,
            'quantity_tax' => $taxed ? $quantity : 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        if ($sn) {
            $sn->forceFill(['status' => ProductSerialNumber::STATUS_SOLD])->save();
            SerialNumberHistoryService::record($sn->id, SerialNumberHistory::EVENT_STATUS_CHANGED, $locationId, $locked,
                'Dikirim ke pelanggan sebagai pengganti retur penjualan; unit asli tetap di custody');
        }
        // The original's custody is deliberately untouched.
    }

    /** Cannot-process goods are customer-bound: they can only go back as the ORIGINAL. */
    private function isOriginalOnly(SaleReturnItemSettlement $settlement): bool
    {
        return strtoupper((string) $settlement->method) === \Modules\SalesReturn\Entities\SaleReturnDetail::METHOD_UNPROCESSED;
    }

    private function assertReplaceable(bool $originalOnly): void
    {
        if ($originalOnly) {
            throw new SalesReturnStateException('Barang yang tidak dapat diproses hanya dapat dikirim kembali sebagai barang asli, bukan pengganti.');
        }
    }

    private function assertReplacementSerial(ProductSerialNumber $sn, SaleReturnCustody $custody, SaleReturnItemSettlement $settlement): void
    {
        if ((int) $sn->product_id !== (int) $custody->product_id) {
            throw new SalesReturnStateException('Serial pengganti bukan untuk produk yang sama.');
        }
        if ($custody->product_serial_number_id && (int) $sn->id === (int) $custody->product_serial_number_id) {
            throw new SalesReturnStateException('Serial pengganti sama dengan barang asli.');
        }
        // Operational availability: the model's own rule covers status, dispatch link, broken, return
        // process AND active transfer custody (the same rule every other consumer uses).
        if (! $sn->isSellable() || HeldSerialGuard::isHeld($sn)) {
            throw new SalesReturnStateException("Serial Number {$sn->serial_number} sudah digunakan, ditahan, dalam transfer atau tidak aktif.");
        }

        // Competing claims: a POS replacement draft/approval, or another return's pending dispatch.
        try {
            app(\Modules\Pos\Services\PosReturnReplacementGuard::class)
                ->validateReplacementSerial((int) $custody->product_id, (int) $sn->id, $custody->product_serial_number_id ? (int) $custody->product_serial_number_id : null);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw new SalesReturnStateException('Serial pengganti tidak dapat dipakai: ' . collect($e->errors())->flatten()->first());
        }

        $competing = SaleReturnItemSettlement::query()
            ->where('dispatch_serial_number_id', $sn->id)
            ->where('dispatch_kind', self::REPLACEMENT)
            ->where('status', SaleReturnItemSettlement::STATUS_DISPATCH_REQUESTED)
            ->where('id', '!=', $settlement->id)
            ->lockForUpdate()
            ->exists();
        if ($competing) {
            throw new SalesReturnStateException("Serial Number {$sn->serial_number} sudah diajukan sebagai pengganti pada pengiriman lain.");
        }
        if (! $sn->location_id) {
            throw new SalesReturnStateException("Serial Number {$sn->serial_number} sedang tidak tersedia dalam stok.");
        }
        try {
            \Modules\Consignment\Services\ConsignmentBuyoutReservationGuard::assertSerialsUnclaimed([(int) $sn->id], (string) $sn->serial_number);
        } catch (\DomainException $e) {
            throw new SalesReturnStateException($e->getMessage());
        }
        $owner = Location::query()->whereKey($sn->location_id)->value('setting_id');
        if ((int) $owner !== (int) $custody->owner_setting_id) {
            throw new SalesReturnStateException('Serial pengganti bukan milik bisnis yang sama dengan retur.');
        }
        $stockTaxed = (bool) $sn->tax_id;
        $this->assertReplacementBucket($custody, (int) $sn->location_id, 1.0, $stockTaxed, true);
    }

    /** Locks and returns the stock row after proving owner match and bucket sufficiency. */
    private function assertReplacementBucket(SaleReturnCustody $custody, int $locationId, float $quantity, bool $taxed, bool $serial = false): ProductStock
    {
        $taxed = $serial ? $taxed : (bool) $custody->tax_id;

        $owner = Location::query()->whereKey($locationId)->value('setting_id');
        if (! $locationId || (int) $owner !== (int) $custody->owner_setting_id) {
            throw new SalesReturnStateException('Lokasi sumber pengganti bukan milik bisnis yang sama dengan retur.');
        }

        $stock = ProductStock::query()->where('product_id', $custody->product_id)->where('location_id', $locationId)->lockForUpdate()->first();
        $available = $stock ? (float) ($taxed ? $stock->quantity_tax : $stock->quantity_non_tax) : 0.0;
        if (! $stock || $quantity - $available > 0.0004) {
            throw new SalesReturnStateException('Stok pengganti tidak cukup pada lokasi dan kelompok pajak yang diperlukan.');
        }

        // Goods reserved by an approved consignment buyout cannot be used as the replacement.
        try {
            \Modules\Consignment\Services\ConsignmentBuyoutReservationGuard::assertQuantityAvailable((int) $custody->product_id, $locationId, $taxed, $available, $quantity);
        } catch (\DomainException $e) {
            throw new SalesReturnStateException($e->getMessage());
        }

        return $stock;
    }

    private function quantity(SaleReturnItemSettlement $locked, SaleReturnCustody $custody): float
    {
        return $custody->product_serial_number_id ? 1.0 : round((float) ($locked->detail?->quantity ?? 1), 3);
    }
}
