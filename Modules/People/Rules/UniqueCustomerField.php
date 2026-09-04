<?php

namespace Modules\People\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\DB;

class UniqueCustomerField implements Rule
{
    protected string $table;
    protected string $column;
    protected string $value;
    protected ?int $excludeId;
    protected string $message = 'Nilai sudah digunakan.';

    public function __construct(string $column, ?int $excludeId = null, ?string $table = null)
    {
        $this->table = $table ?? 'customers';
        $this->column = $column;
        $this->excludeId = $excludeId;
    }

    public function passes($attribute, $value): bool
    {
        $this->value = $value;

        if (empty($value) || (is_string($value) && trim($value) === '')) {
            return true;
        }

        $trimmedValue = strtolower(trim($value));

        $query = DB::table($this->table)
            ->whereRaw("LOWER(TRIM(`{$this->column}`)) = ?", [$trimmedValue]);

        if ($this->excludeId !== null) {
            $query->where('id', '!=', $this->excludeId);
        }

        if ($query->exists()) {
            return false;
        }

        return true;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;
        return $this;
    }
}
