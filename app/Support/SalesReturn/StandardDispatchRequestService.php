<?php

namespace App\Support\SalesReturn;

use Illuminate\Support\Facades\Auth;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement;

/**
 * Single set of prerequisites for asking to dispatch goods back to the customer, shared by the
 * bulk form and the single-item modal so they can never drift apart.
 *
 * Callers pass a settlement row already locked through StandardReturnGuard::lockSettlement().
 */
class StandardDispatchRequestService
{
    public function __construct(private readonly StandardReturnGuard $guard)
    {
    }

    public function request(SaleReturnItemSettlement $locked, ?string $serial, ?int $sourceLocationId, ?string $note = null, ?string $mode = null): void
    {
        $locked->loadMissing(['detail.product', 'serialNumber', 'saleReturn']);

        $this->guard->assertCanSettle($locked->saleReturn);

        if ($locked->status !== SaleReturnItemSettlement::STATUS_APPROVED_AWAITING_DISPATCH) {
            throw new SalesReturnStateException('Item tidak dalam status menunggu pengiriman.');
        }

        if (! in_array(strtoupper((string) $locked->method), [SaleReturnDetail::METHOD_PRODUCT_REPAIR, SaleReturnDetail::METHOD_UNPROCESSED], true)) {
            throw new SalesReturnStateException('Metode penyelesaian item ini tidak memerlukan pengiriman.');
        }

        $product = $locked->detail?->product;
        $identity = [];
        if ($locked->saleReturn->usesHeldCustody()) {
            // Held custody: persist WHAT is being dispatched (original vs different-unit replacement).
            $identity = app(StandardDispatchCustodyService::class)->resolveIdentity(
                $locked, $product, $serial, $mode, $sourceLocationId, app(StandardReturnCustodyService::class)
            );
        } elseif ($product && $product->serial_number_required) {
            $this->assertSerialDispatchable($locked, $product->id, $serial);
        }

        $locked->update([
            'status' => SaleReturnItemSettlement::STATUS_DISPATCH_REQUESTED,
            'dispatched_serial_number' => $serial,
            'dispatch_requested_at' => now(),
            'dispatch_requested_by' => Auth::id(),
            'dispatch_note' => $note,
            // chosen source location for non-serial items
            'location_id' => $identity['location_id'] ?? $sourceLocationId ?? $locked->location_id,
            'dispatch_kind' => $identity['dispatch_kind'] ?? null,
            'dispatch_serial_number_id' => $identity['dispatch_serial_number_id'] ?? null,
        ]);
    }

    private function assertSerialDispatchable(SaleReturnItemSettlement $locked, int $productId, ?string $serial): void
    {
        if (! $serial) {
            throw new SalesReturnStateException('Serial Number wajib diisi untuk produk ini.');
        }

        $sn = ProductSerialNumber::query()
            ->where('product_id', $productId)
            ->where('serial_number', $serial)
            ->lockForUpdate()
            ->first();

        if (! $sn) {
            throw new SalesReturnStateException("Serial Number {$serial} tidak ditemukan untuk produk ini.");
        }

        // Same serial as the one received => repair: the unit goes back, nothing new is consumed.
        if ($serial === ($locked->serialNumber?->serial_number ?? null)) {
            return;
        }

        if (strtolower($sn->status ?? 'active') !== 'active' || $sn->dispatch_detail_id !== null) {
            throw new SalesReturnStateException("Serial Number {$serial} sudah digunakan atau tidak aktif.");
        }

        if (! $sn->location_id) {
            throw new SalesReturnStateException("Serial Number {$serial} sedang tidak tersedia dalam stok.");
        }
    }
}
