<?php

namespace App\Support\SalesReturn;

use Illuminate\Support\Facades\DB;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement;

/**
 * Authoritative, lock-time checks for every standard Sales Return mutation.
 *
 * Lock order (always): source sale -> return header -> settlement row. State is re-read under
 * the locks so a hydrated model, a stale form or a replayed request can never decide an effect.
 */
class StandardReturnGuard
{
    public function __construct(private readonly SalesReturnWorkflowResolver $resolver)
    {
    }

    /** Must be called inside a transaction. */
    public function lockForAction(SaleReturn $saleReturn): SaleReturn
    {
        if ($saleReturn->sale_id) {
            Sale::withArchived()->whereKey($saleReturn->sale_id)->lockForUpdate()->first();
        }

        $locked = SaleReturn::withArchived()->whereKey($saleReturn->getKey())->lockForUpdate()->firstOrFail();

        $this->assertBusinessAccess($locked);
        $this->resolver->assertStandardMutable($locked);

        if (! is_null($locked->archived_at)) {
            throw new SalesReturnStateException('Retur penjualan sudah diarsipkan dan tidak dapat diproses.');
        }

        return $locked;
    }

    /** Settlement row resolved through its parent, under the source-sale -> header -> row locks. */
    public function lockSettlement(int $settlementId): SaleReturnItemSettlement
    {
        $settlement = SaleReturnItemSettlement::query()->findOrFail($settlementId);
        $saleReturn = SaleReturn::withArchived()->findOrFail($settlement->sale_return_id);

        $this->lockForAction($saleReturn);

        return SaleReturnItemSettlement::query()
            ->whereKey($settlementId)
            ->where('sale_return_id', $saleReturn->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function assertBusinessAccess(SaleReturn $saleReturn): void
    {
        $this->assertSettingScope((int) $saleReturn->setting_id);
    }

    /**
     * Fails CLOSED: a request without an active business, or for another business's document,
     * is refused. (`role.setting` always establishes the business on real requests.)
     */
    public function assertSettingScope(?int $documentSettingId): void
    {
        $active = session('setting_id');

        if ($active === null || $active === '' || ! $documentSettingId || (int) $documentSettingId !== (int) $active) {
            throw new SalesReturnAccessException();
        }
    }

    public function assertCanApprove(SaleReturn $locked): void
    {
        $approval = strtolower((string) $locked->approval_status);

        if ($approval !== 'pending') {
            throw new SalesReturnStateException('Hanya retur penjualan yang menunggu persetujuan yang dapat disetujui.');
        }
    }

    /** Header edits are only allowed before approval, and never after goods were received. */
    public function assertCanEdit(SaleReturn $locked): void
    {
        if (! in_array(strtolower((string) $locked->approval_status), ['pending', 'rejected'], true)
            || ! is_null($locked->received_at)) {
            throw new SalesReturnStateException('Retur penjualan yang sudah disetujui atau diterima tidak dapat diubah.');
        }
    }

    public function assertCanReject(SaleReturn $locked): void
    {
        if (strtolower((string) $locked->approval_status) !== 'pending') {
            throw new SalesReturnStateException('Hanya retur penjualan yang menunggu persetujuan yang dapat ditolak.');
        }
    }

    public function assertCanReceive(SaleReturn $locked): void
    {
        if (strtolower((string) $locked->approval_status) !== 'approved') {
            throw new SalesReturnStateException('Retur penjualan harus disetujui sebelum diterima.');
        }

        if (! is_null($locked->received_at) || strtolower((string) $locked->status) !== 'awaiting receiving') {
            throw new SalesReturnStateException('Retur penjualan tidak berada pada status menunggu penerimaan.');
        }
    }

    /** Settlement requires an approved AND received return still awaiting settlement. */
    public function assertCanSettle(SaleReturn $locked): void
    {
        if (strtolower((string) $locked->approval_status) !== 'approved') {
            throw new SalesReturnStateException('Penyelesaian hanya dapat diproses untuk retur yang sudah disetujui.');
        }

        if (is_null($locked->received_at) || strtolower((string) $locked->status) !== 'awaiting settlement') {
            throw new SalesReturnStateException('Penyelesaian hanya dapat diproses setelah barang retur diterima (Awaiting Settlement).');
        }
    }

    public function assertSettlementEditable(SaleReturnItemSettlement $locked): void
    {
        if (! $locked->isEditable()) {
            throw new SalesReturnStateException('Baris penyelesaian ini sudah diproses dan tidak dapat diubah atau dikirim ulang.');
        }
    }
}
