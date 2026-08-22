<?php

namespace App\Services\Sequence;

use InvalidArgumentException;

final class SequenceNamespace
{
    public readonly DocumentType $documentType;
    public readonly int $settingId;
    public readonly string $prefix;
    public readonly int $year;
    public readonly int $month;

    public function __construct(
        DocumentType|string $documentType,
        int $settingId,
        string $prefix,
        int $year,
        int $month
    ) {
        $this->documentType = is_string($documentType) ? DocumentType::from($documentType) : $documentType;

        if ($settingId <= 0) {
            throw new InvalidArgumentException("settingId must be a positive integer, got: {$settingId}");
        }
        $this->settingId = $settingId;

        $trimmedPrefix = trim($prefix);
        if ($trimmedPrefix === '') {
            throw new InvalidArgumentException("prefix cannot be empty");
        }
        $this->prefix = $trimmedPrefix;

        if ($year < 2000 || $year > 2100) {
            throw new InvalidArgumentException("year must be between 2000 and 2100, got: {$year}");
        }
        $this->year = $year;

        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("month must be between 1 and 12, got: {$month}");
        }
        $this->month = $month;
    }

    public function canonicalKey(): string
    {
        return sprintf(
            '%s:%d:%s:%04d:%02d',
            $this->documentType->value,
            $this->settingId,
            $this->prefix,
            $this->year,
            $this->month
        );
    }

    /**
     * Compare this namespace to another for deterministic lock ordering.
     * Order by: document_type (string), setting_id (asc), prefix (string), year (asc), month (asc).
     */
    public function compareTo(SequenceNamespace $other): int
    {
        $cmp = strcmp($this->documentType->value, $other->documentType->value);
        if ($cmp !== 0) {
            return $cmp;
        }

        $cmp = $this->settingId <=> $other->settingId;
        if ($cmp !== 0) {
            return $cmp;
        }

        $cmp = strcmp($this->prefix, $other->prefix);
        if ($cmp !== 0) {
            return $cmp;
        }

        $cmp = $this->year <=> $other->year;
        if ($cmp !== 0) {
            return $cmp;
        }

        return $this->month <=> $other->month;
    }

    public function toArray(): array
    {
        return [
            'document_type' => $this->documentType->value,
            'setting_id' => $this->settingId,
            'prefix' => $this->prefix,
            'period_year' => $this->year,
            'period_month' => $this->month,
        ];
    }
}
