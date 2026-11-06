<?php

namespace App\Support\SalesReturn;

use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosCheckoutSale;
use Modules\Sale\Entities\Sale;
use Modules\SalesReturn\Entities\SaleReturn;

/**
 * Single source of truth for who owns a return workflow.
 *
 * - STANDARD: ordinary sale, only standard returns.
 * - POS: sale produced by POS checkout (direct legacy link or split checkout-sale
 *   link) or already carrying a POS-linked return; must use POS Return.
 * - MIXED: a POS-origin sale that ALSO has standard (non-POS) returns. Historical
 *   evidence is never rewritten; new entitlement/financial/archive actions are blocked.
 *
 * A null pos_return_id alone never proves an ordinary sale.
 */
class SalesReturnWorkflowResolver
{
    public const STANDARD = 'standard';
    public const POS = 'pos';
    public const MIXED = 'mixed';

    public function isPosOriginSale(int $saleId): bool
    {
        return PosCheckout::query()->where('sale_id', $saleId)->exists()
            || PosCheckoutSale::query()->where('sale_id', $saleId)->exists()
            || SaleReturn::withArchived()->where('sale_id', $saleId)->whereNotNull('pos_return_id')->exists();
    }

    public function classifySale(int $saleId): string
    {
        if (! $this->isPosOriginSale($saleId)) {
            return self::STANDARD;
        }

        $hasStandardReturns = SaleReturn::withArchived()
            ->where('sale_id', $saleId)
            ->whereNull('pos_return_id')
            ->exists();

        return $hasStandardReturns ? self::MIXED : self::POS;
    }

    public function classifyReturn(SaleReturn $saleReturn): string
    {
        if (! empty($saleReturn->pos_return_id)) {
            return self::POS;
        }

        return $saleReturn->sale_id ? $this->classifySale((int) $saleReturn->sale_id) : self::STANDARD;
    }

    public function isPosLinkedReturn(SaleReturn $saleReturn): bool
    {
        return $this->classifyReturn($saleReturn) !== self::STANDARD;
    }

    /** Standard create/lookup: only STANDARD sales may receive new standard claims. */
    public function assertSaleAllowsStandardReturn(int $saleId): void
    {
        $class = $this->classifySale($saleId);

        if ($class === self::POS) {
            throw new SalesReturnWorkflowException(
                'Penjualan ini berasal dari POS. Gunakan menu Retur POS (/pos/returns) untuk membuat retur.',
                $saleId
            );
        }

        if ($class === self::MIXED) {
            throw new SalesReturnWorkflowException(
                'Penjualan POS ini memiliki riwayat retur campuran (POS dan standar) sehingga tidak dapat menerima klaim baru. Hubungi administrator untuk audit riwayat; data lama tidak diubah.',
                $saleId
            );
        }
    }

    /** Every standard mutation (header, settlement, inspection, dispatch, payments). */
    public function assertStandardMutable(SaleReturn $saleReturn): void
    {
        $class = $this->classifyReturn($saleReturn);

        if ($class === self::STANDARD) {
            return;
        }

        $message = $class === self::MIXED
            ? 'Retur ini bagian dari riwayat campuran POS dan standar sehingga tindakan baru diblokir. Data lama tidak diubah; hubungi administrator untuk audit.'
            : 'Retur ini dikelola oleh Retur POS dan hanya dapat dilihat di modul Retur Penjualan. Gunakan menu Retur POS (/pos/returns) untuk mengubahnya.';

        throw new SalesReturnWorkflowException($message, (int) $saleReturn->sale_id);
    }
}
