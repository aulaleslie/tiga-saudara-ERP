<?php

namespace Modules\Adjustment\Entities;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Setting\Entities\Location;
use RuntimeException;

class Transfer extends BaseModel
{
    public const STATUS_PENDING           = 'PENDING';
    public const STATUS_APPROVED          = 'APPROVED';
    public const STATUS_REJECTED          = 'REJECTED';
    public const STATUS_DISPATCHED        = 'DISPATCHED';
    public const STATUS_RECEIVED          = 'RECEIVED';
    public const STATUS_RETURN_DISPATCHED = 'RETURN_DISPATCHED';
    public const STATUS_RETURN_RECEIVED   = 'RETURN_RECEIVED';

    protected $fillable = [
        'document_number',
        'origin_location_id',
        'destination_location_id',
        'created_by',
        'approved_by',
        'rejected_by',
        'dispatched_by',
        'received_by',
        'return_dispatched_by',
        'return_received_by',
        'status',
        'transfer_date',
        'approved_at',
        'rejected_at',
        'dispatched_at',
        'received_at',
        'return_dispatched_at',
        'return_received_at',
    ];

    protected $casts = [
        'document_number'       => 'string',
        'approved_at'          => 'datetime',
        'rejected_at'          => 'datetime',
        'dispatched_at'        => 'datetime',
        'received_at'          => 'datetime',
        'return_dispatched_at' => 'datetime',
        'return_received_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Transfer $transfer): void {
            if ($transfer->document_number) {
                return;
            }

            $transfer->document_number = static::nextDocumentNumber($transfer);
        });
    }

    protected static function nextDocumentNumber(Transfer $transfer): string
    {
        $originLocationId = $transfer->origin_location_id;

        if (! $originLocationId) {
            throw new RuntimeException('Origin location is required to generate a document number.');
        }

        $settingId = null;

        if ($transfer->relationLoaded('originLocation')) {
            $settingId = $transfer->originLocation?->setting?->getKey();
        }

        if ($settingId === null) {
            $settingId = Location::query()
                ->whereKey($originLocationId)
                ->value('setting_id');
        }

        $sequenceDate = static::resolveSequenceDate($transfer);

        $year   = $sequenceDate->format('Y');
        $month  = $sequenceDate->format('m');
        $prefix = sprintf('TS-%s-%s-', $year, $month);

        $resolver = function () use ($settingId, $transfer, $prefix) {
            $query = DB::table('transfers')
                ->leftJoin('locations', 'locations.id', '=', 'transfers.origin_location_id')
                ->select('transfers.document_number')
                ->whereNotNull('transfers.document_number')
                ->where('transfers.document_number', 'like', $prefix . '%')
                ->orderByDesc('transfers.document_number')
                ->lockForUpdate();

            $query->where(function ($builder) use ($settingId, $transfer) {
                if ($settingId !== null) {
                    $builder->where('locations.setting_id', $settingId);
                } else {
                    $builder->whereNull('locations.setting_id')
                        ->where('transfers.origin_location_id', $transfer->origin_location_id);
                }
            });

            $latest = $query->first();

            $nextNumber = 1;

            if ($latest && preg_match('/(\d{4})$/', $latest->document_number, $matches)) {
                $nextNumber = (int) $matches[1] + 1;
            }

            return sprintf('%s%04d', $prefix, $nextNumber);
        };

        return DB::transactionLevel() > 0
            ? $resolver()
            : DB::transaction($resolver);
    }

    protected static function resolveSequenceDate(Transfer $transfer): Carbon
    {
        $value = $transfer->transfer_date ?? null;

        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value) {
            try {
                return Carbon::parse($value);
            } catch (\Throwable $throwable) {
                // fall through to now()
            }
        }

        return Carbon::now();
    }

    /**
     * Relationships
     */

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function returnDispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'return_dispatched_by');
    }

    public function returnReceivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'return_received_by');
    }

    public function originLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'origin_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(TransferProduct::class);
    }

    public function requiresReturn(): bool
    {
        $origin      = $this->relationLoaded('originLocation') ? $this->originLocation : $this->originLocation()->first();
        $destination = $this->relationLoaded('destinationLocation') ? $this->destinationLocation : $this->destinationLocation()->first();

        if (! $origin || ! $destination) {
            return false;
        }

        return (int) $origin->setting_id !== (int) $destination->setting_id;
    }
}
