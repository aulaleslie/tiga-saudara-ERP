<?php

namespace Modules\Pos\Services;

use Illuminate\Validation\ValidationException;
use Modules\Pos\Entities\PosReturnLine;
use Modules\Product\Entities\ProductSerialNumber;

class PosReturnReplacementGuard
{
    public function __construct(
        private readonly PosReturnReplacementOwnerResolver $replacementOwnerResolver,
    ) {
    }

    /**
     * Validate that the replacement serial is valid for the given product and location.
     *
     * @param int $productId
     * @param int $replacementSerialId
     * @param int|null $returnedSerialId
     * @param int|null $ignorePosReturnId
     * @return ProductSerialNumber
     * @throws ValidationException
     */
    public function validateReplacementSerial(int $productId, int $replacementSerialId, ?int $returnedSerialId = null, ?int $ignorePosReturnId = null): ProductSerialNumber
    {
        if ($returnedSerialId !== null && $returnedSerialId === $replacementSerialId) {
            throw ValidationException::withMessages([
                'replacement_serial' => 'Serial pengganti tidak boleh sama dengan serial yang diretur.'
            ]);
        }

        $resolvedContext = $this->resolveReplacementSerialContext($replacementSerialId);
        /** @var ProductSerialNumber|null $serial */
        $serial = $resolvedContext['serial'];

        if (!$serial) {
            throw ValidationException::withMessages([
                'replacement_serial' => 'Serial pengganti tidak ditemukan.'
            ]);
        }

        if ($serial->product_id !== $productId) {
            throw ValidationException::withMessages([
                'replacement_serial' => 'Serial pengganti harus memiliki product_id yang sama dengan produk yang diretur. Penggantian lintas owner dengan kode atau SKU serupa tetapi produk berbeda tidak didukung.'
            ]);
        }

        if ($serial->status !== ProductSerialNumber::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'replacement_serial' => 'Serial pengganti tidak berstatus tersedia (ACTIVE).'
            ]);
        }

        if (($resolvedContext['location_id'] ?? null) === null) {
            throw ValidationException::withMessages([
                'replacement_serial' => 'Serial pengganti belum memiliki lokasi aktif yang valid.'
            ]);
        }

        if (($resolvedContext['owner_setting_id'] ?? null) === null) {
            throw ValidationException::withMessages([
                'replacement_serial' => 'Lokasi serial pengganti belum terhubung ke owner bisnis, sehingga owner pengganti tidak dapat ditentukan.'
            ]);
        }

        $draftLockQuery = PosReturnLine::where('replacement_serial_id', $replacementSerialId)
            ->whereHas('posReturn', function ($q) {
                $q->active()->whereIn('status', [
                    \Modules\Pos\Entities\PosReturn::STATUS_DRAFT,
                    \Modules\Pos\Entities\PosReturn::STATUS_PENDING_APPROVAL,
                    \Modules\Pos\Entities\PosReturn::STATUS_APPROVED,
                    \Modules\Pos\Entities\PosReturn::STATUS_AWAITING_DISPATCH,
                    \Modules\Pos\Entities\PosReturn::STATUS_MANUAL_CORRECTION_REQUIRED,
                ]);
            });

        if ($ignorePosReturnId) {
            $draftLockQuery->where('pos_return_id', '!=', $ignorePosReturnId);
        }

        if ($draftLockQuery->exists()) {
            throw ValidationException::withMessages([
                'replacement_serial' => 'Serial pengganti sedang digunakan oleh retur draft lain.'
            ]);
        }

        return $serial;
    }

    public function resolveReplacementSerialContext(int $replacementSerialId): array
    {
        return $this->replacementOwnerResolver->resolveById($replacementSerialId);
    }
}
