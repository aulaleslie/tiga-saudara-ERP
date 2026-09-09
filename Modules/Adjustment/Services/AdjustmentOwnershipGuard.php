<?php

namespace Modules\Adjustment\Services;

use Illuminate\Validation\ValidationException;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Setting\Entities\Location;

/**
 * Shared active-setting ownership guard for Adjustment (Stock Opname) documents.
 *
 * A document's destination location must belong to the currently active
 * setting and must never be a consignment location. Permission checks
 * remain necessary but are not sufficient on their own.
 */
class AdjustmentOwnershipGuard
{
    /**
     * Assert that the given adjustment's location belongs to the active
     * setting and is not a consignment location.
     *
     * @throws ValidationException
     */
    public function assertOwned(Adjustment $adjustment, ?int $activeSettingId = null): void
    {
        $activeSettingId ??= (int) session('setting_id');

        $location = $adjustment->location ?? ($adjustment->location_id ? Location::find($adjustment->location_id) : null);

        if (!$location) {
            throw ValidationException::withMessages([
                'location_id' => ['Lokasi dokumen penyesuaian tidak ditemukan.'],
            ]);
        }

        $this->assertLocationOwned($location, $activeSettingId);
    }

    /**
     * Assert that a candidate location belongs to the active setting and is
     * not a consignment location. Use before assigning/changing a document's
     * destination location.
     *
     * @throws ValidationException
     */
    public function assertLocationOwned(Location $location, ?int $activeSettingId = null): void
    {
        $activeSettingId ??= (int) session('setting_id');

        if ((int) $location->setting_id !== (int) $activeSettingId) {
            throw ValidationException::withMessages([
                'location_id' => ['Lokasi yang dipilih bukan milik pengaturan aktif Anda.'],
            ]);
        }

        if ($location->is_consignment) {
            throw ValidationException::withMessages([
                'location_id' => ['Penyesuaian stok tidak dapat dilakukan pada lokasi konsinyasi.'],
            ]);
        }
    }
}
