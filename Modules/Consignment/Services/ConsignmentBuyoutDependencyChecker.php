<?php

namespace Modules\Consignment\Services;

use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutLine;
use Modules\Consignment\Entities\ConsignmentBuyoutReservation;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentSoldReceiptAttribution;

/**
 * Buyout-related dependencies that make a custody receiving non-reversible. Matching current stock is never enough
 * to bypass these: a sale attributed to the receipt, a live reservation or a finalized (non-reversed) acquisition
 * all rely on the receipt's quantities.
 */
class ConsignmentBuyoutDependencyChecker
{
    /** @return string[] */
    public function receivingBlockers(ConsignmentReceiving $receiving): array
    {
        $detailIds = $receiving->details()->pluck('id')->all();
        if (! $detailIds) {
            return [];
        }
        $blockers = [];

        $attributed = ConsignmentSoldReceiptAttribution::whereIn('consignment_receiving_detail_id', $detailIds)->count();
        if ($attributed > 0) {
            $blockers[] = "Terdapat {$attributed} atribusi penjualan terjual ke penerimaan ini; hapus atribusi sebelum membatalkan penerimaan.";
        }

        $reserved = ConsignmentBuyoutReservation::whereIn('consignment_receiving_detail_id', $detailIds)
            ->where('status', ConsignmentBuyoutReservation::STATUS_ACTIVE)->distinct()->pluck('consignment_buyout_id');
        if ($reserved->isNotEmpty()) {
            $refs = ConsignmentBuyout::whereIn('id', $reserved)->pluck('reference')->implode(', ');
            $blockers[] = "Penerimaan ini dicadangkan oleh pembelian putus konsinyasi yang disetujui ({$refs}); batalkan dokumen tersebut terlebih dahulu.";
        }

        $finalized = ConsignmentBuyoutLine::whereIn('consignment_receiving_detail_id', $detailIds)
            ->whereHas('buyout', fn ($q) => $q->where('status', ConsignmentBuyout::STATUS_FINALIZED))
            ->with('buyout:id,reference')->get();
        if ($finalized->isNotEmpty()) {
            $refs = $finalized->pluck('buyout.reference')->unique()->implode(', ');
            $blockers[] = "Penerimaan ini telah diambil alih oleh pembelian putus konsinyasi final ({$refs}); lakukan reversal dokumen tersebut terlebih dahulu.";
        }

        return $blockers;
    }
}
