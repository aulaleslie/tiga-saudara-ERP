<?php

namespace Modules\Consignment\Services;

use Modules\Consignment\Entities\ConsignmentBillingConfirmationLine;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Consignment\Entities\ConsignmentSerializedAllocation;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;

class ConsignmentLocationDependencyChecker
{
    /**
     * Check all consignment dependencies for a given location.
     */
    public function checkDependencies(Location $location): array
    {
        $categories = [];
        $messages = [];

        // 1. Non-zero stock buckets
        $stockCount = ProductStock::where('location_id', $location->id)
            ->where(function ($q) {
                $q->where('quantity', '!=', 0)
                    ->orWhere('quantity_tax', '!=', 0)
                    ->orWhere('quantity_non_tax', '!=', 0)
                    ->orWhere('broken_quantity', '!=', 0)
                    ->orWhere('broken_quantity_tax', '!=', 0)
                    ->orWhere('broken_quantity_non_tax', '!=', 0);
            })
            ->count();

        if ($stockCount > 0) {
            $categories['stock'] = [
                'count' => $stockCount,
                'message' => "Lokasi masih memiliki {$stockCount} catatan stok produk aktif",
            ];
            $messages[] = $categories['stock']['message'];
        }

        // 2. Pending or approved consignment receiving documents
        $activeReceivingsCount = ConsignmentReceiving::where('setting_id', $location->setting_id)
            ->where('location_id', $location->id)
            ->whereIn('status', [ConsignmentReceiving::STATUS_PENDING, ConsignmentReceiving::STATUS_APPROVED])
            ->count();

        if ($activeReceivingsCount > 0) {
            $categories['active_receivings'] = [
                'count' => $activeReceivingsCount,
                'message' => "Lokasi memiliki {$activeReceivingsCount} dokumen penerimaan fisik konsinyasi aktif (PENDING/APPROVED)",
            ];
            $messages[] = $categories['active_receivings']['message'];
        }

        // 3. Active consignment serials
        $activeSerialsCount = ProductSerialNumber::where('location_id', $location->id)
            ->where('status', ProductSerialNumber::STATUS_ACTIVE)
            ->count();

        if ($activeSerialsCount > 0) {
            $categories['active_serials'] = [
                'count' => $activeSerialsCount,
                'message' => "Lokasi memiliki {$activeSerialsCount} nomor seri produk konsinyasi yang masih aktif",
            ];
            $messages[] = $categories['active_serials']['message'];
        }

        // 4. Immutable receiving provenance (historical receiving documents)
        $receivingProvenanceCount = ConsignmentReceiving::where('setting_id', $location->setting_id)
            ->where('location_id', $location->id)
            ->count();

        if ($receivingProvenanceCount > 0 && !isset($categories['active_receivings'])) {
            $categories['receiving_provenance'] = [
                'count' => $receivingProvenanceCount,
                'message' => "Lokasi memiliki {$receivingProvenanceCount} riwayat dokumen penerimaan fisik konsinyasi",
            ];
            $messages[] = $categories['receiving_provenance']['message'];
        }

        // 5. Sold sources
        $soldSourcesCount = ConsignmentSoldSource::where('setting_id', $location->setting_id)
            ->where('location_id', $location->id)
            ->count();

        if ($soldSourcesCount > 0) {
            $categories['sold_sources'] = [
                'count' => $soldSourcesCount,
                'message' => "Lokasi memiliki {$soldSourcesCount} riwayat bukti penjualan konsinyasi (sold source)",
            ];
            $messages[] = $categories['sold_sources']['message'];
        }

        // 6. Phase 2 allocation dependencies
        $soldSourceIds = ConsignmentSoldSource::where('setting_id', $location->setting_id)
            ->where('location_id', $location->id)
            ->pluck('id');

        $allocationsCount = 0;
        if ($soldSourceIds->isNotEmpty()) {
            $billingLinesCount = ConsignmentBillingConfirmationLine::whereIn('consignment_sold_source_id', $soldSourceIds)->count();
            $serializedAllocCount = ConsignmentSerializedAllocation::whereIn('consignment_sold_source_id', $soldSourceIds)->count();
            $allocationsCount = $billingLinesCount + $serializedAllocCount;
        }

        if ($allocationsCount > 0) {
            $categories['allocations'] = [
                'count' => $allocationsCount,
                'message' => "Lokasi memiliki {$allocationsCount} riwayat alokasi tagihan konsinyasi",
            ];
            $messages[] = $categories['allocations']['message'];
        }

        return [
            'has_dependencies' => count($messages) > 0,
            'categories' => $categories,
            'messages' => $messages,
        ];
    }

    /**
     * Get actionable blocker messages for location reclassification.
     */
    public function getReclassificationBlockers(Location $location): array
    {
        $check = $this->checkDependencies($location);
        return $check['messages'];
    }
}
