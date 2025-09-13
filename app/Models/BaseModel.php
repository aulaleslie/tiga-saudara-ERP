<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

abstract class BaseModel extends Model
{
    /** Toggle if this model should uppercase all string attributes */
    protected bool $uppercaseAllText = true;

    /** Fields to skip (supports wildcards via Str::is) */
    protected array $uppercaseExcept = [
        'password', 'remember_token',
        '*email*', '*phone*', '*mobile*', '*whatsapp*',
    ];

    protected function shouldUppercase(string $key): bool
    {
        foreach ($this->uppercaseExcept as $pattern) {
            if (Str::is($pattern, $key)) {
                return false;
            }
        }
        return true;
    }

    public function setAttribute($key, $value)
    {
        if ($this->uppercaseAllText && is_string($value) && $this->shouldUppercase($key)) {
            $value = mb_strtoupper(trim($value), 'UTF-8');
        }
        return parent::setAttribute($key, $value);
    }
}
