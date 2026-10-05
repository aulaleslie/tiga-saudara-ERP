<?php

declare(strict_types=1);

namespace Modules\Setting\Entities;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Modules\Setting\Enums\CashDenomination;

class SettingCashDenomination extends BaseModel
{
    protected $fillable = [
        'setting_id',
        'denomination',
        'is_enabled',
        'position',
    ];

    protected $casts = [
        'denomination' => CashDenomination::class,
        'is_enabled' => 'boolean',
        'position' => 'integer',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public static function createDefaultsForSetting(int $settingId): void
    {
        if (! Schema::hasTable((new self())->getTable())) {
            return;
        }

        $now = now();
        $rows = [];

        foreach (CashDenomination::cases() as $position => $denomination) {
            $rows[] = [
                'setting_id' => $settingId,
                'denomination' => $denomination->value,
                'is_enabled' => true,
                'position' => $position + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        self::query()->insertOrIgnore($rows);
    }
}
