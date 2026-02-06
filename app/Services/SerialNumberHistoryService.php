<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Product\Entities\SerialNumberHistory;

class SerialNumberHistoryService
{
    /**
     * Record a history event for a serial number.
     * 
     * @param int $serialNumberId
     * @param string $eventType
     * @param int|null $locationId
     * @param Model|null $reference
     * @param string|null $note
     * @return SerialNumberHistory
     */
    public static function record(
        int $serialNumberId,
        string $eventType,
        ?int $locationId = null,
        ?Model $reference = null,
        ?string $note = null
    ): SerialNumberHistory {
        return SerialNumberHistory::create([
            'product_serial_number_id' => $serialNumberId,
            'event_type' => $eventType,
            'location_id' => $locationId,
            'reference_type' => $reference ? get_class($reference) : null,
            'reference_id' => $reference?->getKey(),
            'user_id' => auth()->id(),
            'note' => $note,
        ]);
    }
}
