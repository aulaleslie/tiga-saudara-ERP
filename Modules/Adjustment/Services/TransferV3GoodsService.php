<?php

namespace Modules\Adjustment\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferActionHistory;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Adjustment\Entities\TransferRequestRevision;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Support\PendingDispatchSerialGuard;
use RuntimeException;

/**
 * Version 3 goods manifest: location-free draft entry, atomic create-submit,
 * submission freezing into immutable request revisions, material-edit
 * invalidation of approval context, rejection and acknowledgement.
 *
 * Lines are operator intent only: [{product_id, quantity, serial_ids[]}].
 * No source/destination is accepted or stored; source sufficiency is an
 * approval concern.
 */
class TransferV3GoodsService
{
    /**
     * Create a new v3 DRAFT. Incomplete serial selection is allowed.
     */
    public function createDraft(string $condition, array $lines, User $actor, int $activeSettingId, ?string $idempotencyKey = null): Transfer
    {
        return DB::transaction(function () use ($condition, $lines, $actor, $activeSettingId, $idempotencyKey) {
            if ($existing = $this->replayedCreation($idempotencyKey)) {
                return $existing;
            }

            $normalized = $this->normalizeLines($condition, $lines, null, false);

            $transfer = Transfer::create([
                'origin_location_id'      => null,
                'destination_location_id' => null,
                'stock_condition'         => $condition,
                'created_by'              => $actor->id,
                'created_in_setting_id'   => $activeSettingId,
                'status'                  => Transfer::STATUS_DRAFT,
                'revision'                => 1,
                'workflow_version'        => Transfer::WORKFLOW_V3,
            ]);

            $this->syncProducts($transfer, $normalized);

            TransferV3EventRecorder::record($transfer, TransferActionHistory::ACTION_CREATED, null, Transfer::STATUS_DRAFT, $actor->id, $activeSettingId, 'Draf transfer dibuat', [], $idempotencyKey);

            return $transfer;
        });
    }

    /**
     * Create and submit as one atomic operation: either exactly one
     * numbered PENDING transfer with creation and submission history is
     * committed, or nothing is.
     */
    public function createAndSubmit(string $condition, array $lines, User $actor, int $activeSettingId, ?string $idempotencyKey = null): Transfer
    {
        return DB::transaction(function () use ($condition, $lines, $actor, $activeSettingId, $idempotencyKey) {
            if ($existing = $this->replayedCreation($idempotencyKey)) {
                return $existing;
            }

            // Validate the complete submission before creating anything.
            $this->normalizeLines($condition, $lines, null, true);

            $transfer = $this->createDraft($condition, $lines, $actor, $activeSettingId, $idempotencyKey);

            return $this->submit($transfer, $lines, $actor, $activeSettingId);
        });
    }

    /**
     * Save goods on an existing DRAFT or PENDING v3 transfer. A material
     * change to a PENDING transfer returns it to DRAFT and invalidates its
     * approval context (current request revision), so a saved allocation
     * plan can never authorize the changed goods.
     */
    public function saveDraft(Transfer $transfer, string $condition, array $lines, User $actor, int $activeSettingId, ?int $expectedRevision = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $condition, $lines, $actor, $activeSettingId, $expectedRevision) {
            $locked = $this->lock($transfer, $expectedRevision);

            if (! in_array($locked->status, [Transfer::STATUS_DRAFT, Transfer::STATUS_PENDING], true)) {
                throw new InvalidArgumentException('Transfer hanya dapat diubah saat berstatus Draf atau Menunggu Persetujuan.');
            }

            $this->assertConditionUnchanged($locked, $condition);

            $normalized = $this->normalizeLines($condition, $lines, $locked, false);

            if ($this->canonical($normalized) === $this->persistedCanonical($locked)) {
                return $locked;
            }

            $fromStatus = $locked->status;

            $this->syncProducts($locked, $normalized);

            $locked->update([
                'status'                      => Transfer::STATUS_DRAFT,
                'revision'                    => $locked->revision + 1,
                'current_request_revision_id' => null,
            ]);

            TransferV3EventRecorder::record($locked, TransferActionHistory::ACTION_EDITED, $fromStatus, Transfer::STATUS_DRAFT, $actor->id, $activeSettingId, 'Barang transfer diperbarui', [
                'returned_to_draft'                 => $fromStatus === Transfer::STATUS_PENDING,
                'invalidated_approval_configuration' => $fromStatus === Transfer::STATUS_PENDING ? $locked->approval_configuration_revision : null,
            ]);

            return $locked;
        });
    }

    /**
     * Submit a DRAFT: persists the given goods, validates exact serialized
     * agreement and current eligibility, freezes an immutable request
     * revision and transitions to PENDING atomically.
     */
    public function submit(Transfer $transfer, array $lines, User $actor, int $activeSettingId, ?int $expectedRevision = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $lines, $actor, $activeSettingId, $expectedRevision) {
            $locked = $this->lock($transfer, $expectedRevision);

            if ($locked->status !== Transfer::STATUS_DRAFT) {
                throw new InvalidArgumentException('Hanya transfer berstatus Draf yang dapat diajukan untuk persetujuan.');
            }

            $normalized = $this->normalizeLines($locked->stock_condition, $lines, $locked, true);

            $this->syncProducts($locked, $normalized);

            $revisionNumber = (int) TransferRequestRevision::where('transfer_id', $locked->id)->max('revision_number') + 1;

            $requestRevision = TransferRequestRevision::create([
                'transfer_id'             => $locked->id,
                'revision_number'         => $revisionNumber,
                'stock_condition'         => $locked->stock_condition,
                'lines'                   => array_values(array_map(fn (array $line) => [
                    'product_id' => $line['product_id'],
                    'quantity'   => $line['quantity'],
                    'serials'    => $line['serials'],
                ], $normalized)),
                'submitted_by'            => $actor->id,
                'submitted_in_setting_id' => $activeSettingId,
                'submitted_at'            => now(),
            ]);

            $locked->update([
                'status'                      => Transfer::STATUS_PENDING,
                'revision'                    => $locked->revision + 1,
                'current_request_revision_id' => $requestRevision->id,
            ]);

            TransferV3EventRecorder::record($locked, TransferActionHistory::ACTION_SUBMITTED, Transfer::STATUS_DRAFT, Transfer::STATUS_PENDING, $actor->id, $activeSettingId, 'Diajukan untuk persetujuan', [
                'request_revision_id'     => $requestRevision->id,
                'request_revision_number' => $revisionNumber,
            ]);

            return $locked;
        });
    }

    /**
     * Submit an existing DRAFT using its persisted goods (list submission).
     */
    public function submitPersisted(Transfer $transfer, User $actor, int $activeSettingId): Transfer
    {
        $transfer->loadMissing('products');

        $lines = $transfer->products->map(fn (TransferProduct $product) => [
            'product_id' => (int) $product->product_id,
            'quantity'   => (int) $product->quantity,
            'serial_ids' => collect($product->serial_numbers ?? [])->map(fn ($serial) => (int) ($serial['id'] ?? $serial))->all(),
        ])->all();

        return $this->submit($transfer, $lines, $actor, $activeSettingId, (int) $transfer->revision);
    }

    public function reject(Transfer $transfer, string $reason, User $actor, int $activeSettingId): Transfer
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Alasan penolakan harus diisi.');
        }

        return DB::transaction(function () use ($transfer, $reason, $actor, $activeSettingId) {
            $locked = $this->lock($transfer);

            if ($locked->status !== Transfer::STATUS_PENDING) {
                throw new InvalidArgumentException('Hanya transfer berstatus Menunggu Persetujuan yang dapat ditolak.');
            }

            $locked->update([
                'status'      => Transfer::STATUS_REJECTED,
                'revision'    => $locked->revision + 1,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
            ]);

            TransferV3EventRecorder::record($locked, TransferActionHistory::ACTION_REJECTED, Transfer::STATUS_PENDING, Transfer::STATUS_REJECTED, $actor->id, $activeSettingId, $reason, [
                'request_revision_id' => $locked->current_request_revision_id,
            ]);

            return $locked;
        });
    }

    /**
     * Explicitly return a REJECTED document to DRAFT for revision. The
     * rejected request revision stays as immutable evidence but no longer
     * authorizes approval.
     */
    public function acknowledgeRejection(Transfer $transfer, User $actor, int $activeSettingId): Transfer
    {
        return DB::transaction(function () use ($transfer, $actor, $activeSettingId) {
            $locked = $this->lock($transfer);

            if ($locked->status !== Transfer::STATUS_REJECTED) {
                throw new InvalidArgumentException('Hanya transfer yang ditolak yang dapat dikembalikan ke Draf.');
            }

            $locked->update([
                'status'                      => Transfer::STATUS_DRAFT,
                'revision'                    => $locked->revision + 1,
                'current_request_revision_id' => null,
            ]);

            TransferV3EventRecorder::record($locked, TransferActionHistory::ACTION_ACKNOWLEDGED, Transfer::STATUS_REJECTED, Transfer::STATUS_DRAFT, $actor->id, $activeSettingId, 'Penolakan diakui');

            return $locked;
        });
    }

    private function lock(Transfer $transfer, ?int $expectedRevision = null): Transfer
    {
        $locked = Transfer::whereKey($transfer->id)->lockForUpdate()->first();

        if (! $locked) {
            throw new RuntimeException('Transfer tidak ditemukan.');
        }

        TransferV3Access::assertV3($locked);

        if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
            throw new RuntimeException('Transfer telah diubah oleh proses lain. Muat ulang halaman dan coba lagi.');
        }

        return $locked;
    }

    private function replayedCreation(?string $idempotencyKey): ?Transfer
    {
        if (! $idempotencyKey) {
            return null;
        }

        $history = TransferActionHistory::where('idempotency_key', mb_strtoupper(trim($idempotencyKey), 'UTF-8'))
            ->where('action', TransferActionHistory::ACTION_CREATED)
            ->lockForUpdate()
            ->first();

        return $history ? Transfer::find($history->transfer_id) : null;
    }

    private function assertConditionUnchanged(Transfer $transfer, string $condition): void
    {
        if ($transfer->stock_condition !== $condition) {
            throw new InvalidArgumentException('Kondisi stok tidak dapat diubah pada transfer yang sudah disimpan.');
        }
    }

    /**
     * Validate and canonicalize operator lines. Draft validation checks
     * identity and structure; submission additionally requires positive
     * whole quantities equal to the distinct selected serial count and
     * current canonical serial eligibility for the document condition.
     *
     * @return array<int, array{product_id: int, quantity: int, serials: array<int, array{id: int, serial_number: string}>}>
     */
    public function normalizeLines(string $condition, array $lines, ?Transfer $transfer, bool $forSubmission): array
    {
        if (! in_array($condition, Transfer::CONDITIONS, true)) {
            throw new InvalidArgumentException('Pilih kondisi stok: Barang Baik atau Barang Rusak.');
        }

        $merged = [];
        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $rawQuantity = $line['quantity'] ?? 0;

            if ($productId <= 0) {
                throw new InvalidArgumentException('Baris produk tidak valid.');
            }

            if (! is_numeric($rawQuantity) || (float) $rawQuantity != (int) $rawQuantity || (int) $rawQuantity <= 0) {
                throw new InvalidArgumentException('Jumlah setiap produk harus berupa bilangan bulat lebih dari 0.');
            }

            $merged[$productId] ??= ['product_id' => $productId, 'quantity' => 0, 'serial_ids' => []];
            $merged[$productId]['quantity'] += (int) $rawQuantity;
            foreach ((array) ($line['serial_ids'] ?? []) as $serialId) {
                $merged[$productId]['serial_ids'][] = (int) $serialId;
            }
        }

        if ($merged === []) {
            throw new InvalidArgumentException('Silakan pilih minimal satu produk.');
        }

        ksort($merged);

        $existingProductIds = $transfer
            ? $transfer->products()->pluck('product_id')->map(fn ($id) => (int) $id)->all()
            : [];

        $products = Product::whereIn('id', array_keys($merged))->get()->keyBy('id');
        $isBroken = $condition === Transfer::CONDITION_BREAKAGE;

        $normalized = [];
        foreach ($merged as $productId => $line) {
            $product = $products->get($productId);

            if (! $product) {
                throw new InvalidArgumentException('Produk tidak ditemukan.');
            }

            if (! $product->stock_managed) {
                throw new InvalidArgumentException("Produk {$product->product_name} tidak dikelola stoknya.");
            }

            $alreadyOnTransfer = in_array($productId, $existingProductIds, true);
            if ((! $product->is_active && ! $alreadyOnTransfer) || ($forSubmission && ! $product->is_active)) {
                throw new InvalidArgumentException("Produk {$product->product_name} tidak aktif.");
            }

            $serialIds = array_values(array_unique(array_filter($line['serial_ids'], fn ($id) => $id > 0)));
            $serials = [];

            if (! $product->serial_number_required) {
                if ($serialIds !== []) {
                    throw new InvalidArgumentException("Produk {$product->product_name} tidak menggunakan nomor seri.");
                }
            } else {
                if (count($serialIds) !== count($line['serial_ids'])) {
                    throw new InvalidArgumentException("Nomor seri untuk {$product->product_name} tidak boleh duplikat.");
                }

                if (count($serialIds) > $line['quantity']) {
                    throw new InvalidArgumentException("Jumlah nomor seri untuk {$product->product_name} melebihi jumlah yang diminta.");
                }

                if ($forSubmission && count($serialIds) !== $line['quantity']) {
                    throw new InvalidArgumentException(sprintf(
                        'Jumlah %s (%d) harus sama dengan jumlah nomor seri yang dipilih (%d) sebelum diajukan.',
                        $product->product_name,
                        $line['quantity'],
                        count($serialIds)
                    ));
                }

                $records = ProductSerialNumber::whereIn('id', $serialIds)->get()->keyBy('id');

                foreach ($serialIds as $serialId) {
                    $serial = $records->get($serialId);

                    if (! $serial || (int) $serial->product_id !== $productId) {
                        throw new InvalidArgumentException("Nomor seri yang dipilih tidak sesuai dengan produk {$product->product_name}.");
                    }

                    if ($forSubmission) {
                        $this->assertSerialEligible($serial, $isBroken);
                    }

                    $serials[] = ['id' => (int) $serial->id, 'serial_number' => (string) $serial->serial_number];
                }
            }

            $normalized[$productId] = [
                'product_id' => $productId,
                'quantity'   => $line['quantity'],
                'serials'    => $serials,
            ];
        }

        return $normalized;
    }

    public function assertSerialEligible(ProductSerialNumber $serial, bool $isBroken): void
    {
        $eligible = $isBroken ? $serial->isAvailableBroken() : $serial->isSellable();

        if (! $eligible || $serial->location_id === null || PendingDispatchSerialGuard::isReserved((string) $serial->serial_number)) {
            throw new InvalidArgumentException("Nomor seri {$serial->serial_number} tidak tersedia untuk kondisi transfer ini.");
        }
    }

    private function syncProducts(Transfer $transfer, array $normalized): void
    {
        $transfer->products()->delete();

        foreach ($normalized as $line) {
            TransferProduct::create([
                'transfer_id'             => $transfer->id,
                'product_id'              => $line['product_id'],
                'quantity'                => $line['quantity'],
                'quantity_tax'            => 0,
                'quantity_non_tax'        => 0,
                'quantity_broken_tax'     => 0,
                'quantity_broken_non_tax' => 0,
                'serial_numbers'          => $line['serials'] !== [] ? $line['serials'] : null,
            ]);
        }
    }

    private function canonical(array $normalized): array
    {
        $canonical = [];
        foreach ($normalized as $line) {
            $ids = array_map(fn ($serial) => (int) $serial['id'], $line['serials']);
            sort($ids);
            $canonical[(int) $line['product_id']] = [(int) $line['quantity'], $ids];
        }
        ksort($canonical);

        return $canonical;
    }

    private function persistedCanonical(Transfer $transfer): array
    {
        $canonical = [];
        foreach ($transfer->products()->get() as $product) {
            $ids = collect($product->serial_numbers ?? [])->map(fn ($serial) => (int) ($serial['id'] ?? $serial))->sort()->values()->all();
            $canonical[(int) $product->product_id] = [(int) $product->quantity, $ids];
        }
        ksort($canonical);

        return $canonical;
    }
}
