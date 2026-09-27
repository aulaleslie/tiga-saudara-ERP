<?php

namespace Modules\Sale\Support;

use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;

class PendingDispatchSerialGuard
{
    public static function isReserved(string $serialNumber): bool
    {
        $serialNumber = trim($serialNumber);

        if ($serialNumber === '') {
            return false;
        }

        return DispatchDetail::query()
            ->whereHas('dispatch', fn ($query) => $query->where('status', Dispatch::STATUS_PENDING))
            ->whereNotNull('serial_numbers')
            ->where('serial_numbers', 'LIKE', '%"' . $serialNumber . '"%')
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    public static function getReservedSerialsForProduct(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        return DispatchDetail::query()
            ->where('product_id', $productId)
            ->whereHas('dispatch', fn ($query) => $query->where('status', Dispatch::STATUS_PENDING))
            ->whereNotNull('serial_numbers')
            ->pluck('serial_numbers')
            ->flatMap(function ($serialNumbers): array {
                $decoded = is_array($serialNumbers)
                    ? $serialNumbers
                    : json_decode((string) $serialNumbers, true);

                if (! is_array($decoded)) {
                    return [];
                }

                return array_values(array_filter(
                    $decoded,
                    static fn ($serial): bool => is_string($serial) && trim($serial) !== ''
                ));
            })
            ->map(static fn (string $serial): string => trim($serial))
            ->unique()
            ->values()
            ->all();
    }
}
