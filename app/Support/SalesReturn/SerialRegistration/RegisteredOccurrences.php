<?php

namespace App\Support\SalesReturn\SerialRegistration;

use Modules\Product\Entities\ProductSerialNumber;
use Modules\SalesReturn\Entities\ReturnSerialRegistration;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement;

/**
 * Customer-held occurrences that originate from return registration.
 *
 * A serial registered by a return stands for ONE attributed unit of its original (quantity-based) source.
 * When repair / replacement puts that unit back with the customer, a later return of it re-uses that unit:
 * it must not consume a second unit of the source's entitlement, and it must not be blocked merely because
 * the serial already appears in an earlier (completed) return.
 */
class RegisteredOccurrences
{
    /** @return array<int,int> serial ids registered by returns (any workflow), optionally restricted */
    public static function registeredIds(?array $candidateIds = null): array
    {
        $query = ReturnSerialRegistration::query()->whereNotNull('registered_serial_id');
        if ($candidateIds !== null) {
            if ($candidateIds === []) {
                return [];
            }
            $query->whereIn('registered_serial_id', array_map('intval', $candidateIds));
        }

        return $query->pluck('registered_serial_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Standard replacement chain: the serial that stands in for a return-registered unit after a DISPATCHED
     * REPLACEMENT settlement (registered R -> customer holds S). Returns the registered ROOT (R) or null.
     */
    public static function replacementRoot(int $serialId): ?int
    {
        $seen = [];
        $current = $serialId;
        while (true) {
            if (isset($seen[$current])) {
                return null;   // defensive: cyclic evidence
            }
            $seen[$current] = true;

            if (self::registeredIds([$current]) !== []) {
                return $current === $serialId ? null : $current;
            }

            $previous = SaleReturnItemSettlement::query()
                ->where('dispatch_serial_number_id', $current)
                ->where('dispatch_kind', 'REPLACEMENT')
                ->where('status', SaleReturnItemSettlement::STATUS_DISPATCHED)
                ->whereNotNull('product_serial_number_id')
                ->orderByDesc('id')
                ->value('product_serial_number_id');
            if (! $previous) {
                return null;
            }
            $current = (int) $previous;
        }
    }

    /** Dispatch line (standard source) a return-registered unit - or its replacement - belongs to. */
    public static function sourceDispatchDetailId(int $serialId): ?int
    {
        $root = self::registeredIds([$serialId]) !== [] ? $serialId : self::replacementRoot($serialId);
        if (! $root) {
            return null;
        }

        $direct = ProductSerialNumber::query()->whereKey($serialId)->value('dispatch_detail_id');
        if ($direct) {
            return (int) $direct;
        }

        $claim = ReturnSerialRegistration::query()->where('registered_serial_id', $root)->where('workflow', 'standard')->first();

        return $claim?->saleReturnDetail?->dispatch_detail_id ? (int) $claim->saleReturnDetail->dispatch_detail_id : null;
    }

    /** The registered attributed unit (root serial) an id stands for: itself, its replacement root, or null. */
    public static function attributedRoot(int $serialId): ?int
    {
        if (self::registeredIds([$serialId]) !== []) {
            return $serialId;
        }

        return self::replacementRoot($serialId);
    }

    /**
     * Standard workflow: may $serialId be claimed again? True only for a return-registered unit (or the replacement
     * standing in for it) that is back with the customer (SOLD) and is not part of another in-flight (not yet
     * received) non-rejected return.
     */
    public static function isReReturnable(int $serialId, ?int $excludeSaleReturnId = null): bool
    {
        if (self::attributedRoot($serialId) === null) {
            return false;
        }

        $status = ProductSerialNumber::query()->whereKey($serialId)->value('status');
        if (strtoupper((string) $status) !== ProductSerialNumber::STATUS_SOLD) {
            return false;
        }

        return ! SaleReturnDetail::query()
            ->when($excludeSaleReturnId, fn ($q) => $q->where('sale_return_id', '!=', $excludeSaleReturnId))
            ->whereHas('saleReturn', fn ($q) => $q->whereRaw('LOWER(approval_status) != ?', ['rejected'])->whereNull('received_at'))
            ->get(['serial_number_ids'])
            ->contains(fn ($detail) => in_array($serialId, array_map('intval', (array) ($detail->serial_number_ids ?? [])), true));
    }

    /**
     * Does a dispatch line still have a return-registered unit (or the replacement standing in for it) back with
     * the customer that may be returned again? Such a line stays offered even when its untouched quantity is zero.
     *
     * @param  \Illuminate\Support\Collection<int,SaleReturnDetail>  $details valid (non-rejected) details of ONE dispatch line
     */
    public static function hasReReturnableUnit($details, ?int $excludeSaleReturnId = null): bool
    {
        $ids = $details->flatMap(fn ($d) => array_map('intval', (array) ($d->serial_number_ids ?? [])))->filter()->unique();
        foreach ($ids as $id) {
            if (self::registeredIds([(int) $id]) === []) {
                continue;
            }
            $current = (int) $id;
            $seen = [];
            while ($current && ! isset($seen[$current])) {
                $seen[$current] = true;
                if (self::isReReturnable($current, $excludeSaleReturnId)) {
                    return true;
                }
                $current = (int) SaleReturnItemSettlement::query()
                    ->where('product_serial_number_id', $current)
                    ->where('dispatch_kind', 'REPLACEMENT')
                    ->where('status', SaleReturnItemSettlement::STATUS_DISPATCHED)
                    ->whereNotNull('dispatch_serial_number_id')
                    ->orderByDesc('id')
                    ->value('dispatch_serial_number_id');
            }
        }

        return false;
    }

    /**
     * Units of a standard dispatch line already consumed by other non-rejected returns, counting each
     * return-registered unit once no matter how many returns it (or its replacement) appears in.
     */
    public static function consumedUnits(int $dispatchDetailId, ?int $excludeSaleReturnId = null): int
    {
        $details = SaleReturnDetail::query()
            ->where('dispatch_detail_id', $dispatchDetailId)
            ->when($excludeSaleReturnId, fn ($q) => $q->where('sale_return_id', '!=', $excludeSaleReturnId))
            ->whereHas('saleReturn', fn ($q) => $q->whereRaw('LOWER(approval_status) != ?', ['rejected']))
            ->get(['quantity', 'serial_number_ids']);

        return self::consumedFromDetails($details);
    }

    /** @param \Illuminate\Support\Collection<int,SaleReturnDetail> $details valid (non-rejected) details of ONE dispatch line */
    public static function consumedFromDetails($details): int
    {
        $sum = (int) $details->sum('quantity');
        $ids = $details->flatMap(fn ($d) => array_map('intval', (array) ($d->serial_number_ids ?? [])))->filter();
        if ($ids->isEmpty()) {
            return $sum;
        }

        // Every id of one attributed unit (root + replacements) collapses to its registered root.
        $roots = $ids->map(fn ($id) => self::attributedRoot((int) $id))->filter();
        $repeats = $roots->countBy()->filter(fn ($count) => $count > 1)->map(fn ($count) => $count - 1)->sum();

        return max(0, $sum - (int) $repeats);
    }
}
