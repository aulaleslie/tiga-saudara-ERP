<?php

declare(strict_types=1);

namespace Modules\Setting\Entities;

use App\Models\BaseModel;

class CashDenomination extends BaseModel
{
    protected $fillable = [
        'value',
        'type',
        'is_enabled',
        'position',
    ];

    protected $casts = [
        'value' => 'integer',
        'is_enabled' => 'boolean',
        'position' => 'integer',
    ];

    public function label(): string
    {
        return 'Rp' . number_format($this->value, 0, ',', '.');
    }

    public function typeLabel(): string
    {
        return $this->type === 'coin' ? 'Uang Logam' : 'Uang Kertas';
    }

    public function unitLabel(): string
    {
        return $this->type === 'coin' ? 'keping' : 'lembar';
    }
}
