<?php

namespace Modules\Consignment\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsignmentInventoryCoordination extends BaseModel
{
    protected $table = 'consignment_inventory_coordinations';

    protected $guarded = ['id'];

    protected $casts = [

    ];

    /**
     * Ensure the row exists (race-safe via the unique key) and lock it for the open transaction.
     */
    public static function lockFor(int $settingId, int $productId): ?self
    {
        if (\Illuminate\Support\Facades\DB::transactionLevel() < 1) {
            throw new \LogicException('Coordination locks require an active database transaction.');
        }
        try {
            $now = now();
            if (\Illuminate\Support\Facades\DB::getDriverName() === 'mysql') {
                // MySQL/InnoDB: INSERT IGNORE on a duplicate takes a SHARED lock that the following SELECT ... FOR UPDATE
                // must upgrade, and two sessions creating the same new row deadlock on that upgrade (verified on MySQL
                // 8.0.44). ON DUPLICATE KEY UPDATE requests the EXCLUSIVE lock directly, so concurrent creators queue.
                \Illuminate\Support\Facades\DB::insert(
                    'INSERT INTO consignment_inventory_coordinations (setting_id, product_id, created_at, updated_at) VALUES (?, ?, ?, ?) '
                    . 'ON DUPLICATE KEY UPDATE id = id',
                    [$settingId, $productId, $now, $now]
                );
            } else {
                static::query()->insertOrIgnore([
                    'setting_id' => $settingId,
                    'product_id' => $productId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // A product/business that does not exist has no stock to protect and cannot satisfy the FK; the
            // caller's own validation reports the bad identifier. (MySQL downgrades this to a warning.)
            if (! str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }
            $row = static::query()->where('setting_id', $settingId)->where('product_id', $productId)->lockForUpdate()->first();
            if (! $row) {
                return null;
            }
        }

        return static::query()->where('setting_id', $settingId)->where('product_id', $productId)->lockForUpdate()->first();
    }

    /**
     * Lock many (setting, product) pairs in deterministic order and report any that cannot be locked
     * because the product or business does not exist. The OWNING OPERATION must reject missing pairs before
     * it mutates anything: a missing pair is never silently treated as safe.
     *
     * @param array<int, array{0:int,1:int}> $pairs
     * @return array{locked: array, missing: array<int, array{0:int,1:int}>}
     */
    public static function lockManyReport(array $pairs): array
    {
        if (\Illuminate\Support\Facades\DB::transactionLevel() < 1) {
            throw new \LogicException('Coordination locks require an active database transaction.');
        }
        $unique = [];
        foreach ($pairs as [$s, $p]) {
            $unique[(int) $s . ':' . (int) $p] = [(int) $s, (int) $p];
        }
        usort($unique, fn ($a, $b) => $a <=> $b);

        $locked = [];
        $missing = [];
        foreach ($unique as $k) {
            $row = static::lockFor($k[0], $k[1]);
            $row ? $locked[] = $row : $missing[] = $k;
        }

        return ['locked' => $locked, 'missing' => $missing];
    }

    /**
     * Strict variant: every pair must exist and be locked, otherwise nothing proceeds.
     *
     * @param array<int, array{0:int,1:int}> $pairs
     */
    public static function lockMany(array $pairs): array
    {
        $result = static::lockManyReport($pairs);
        if ($result['missing']) {
            throw new \DomainException('Unknown business/product for coordination lock: '
                . implode(', ', array_map(fn ($m) => "setting {$m[0]} / product {$m[1]}", $result['missing'])));
        }

        return $result['locked'];
    }
}
