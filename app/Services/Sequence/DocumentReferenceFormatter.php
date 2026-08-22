<?php

namespace App\Services\Sequence;

use InvalidArgumentException;

class DocumentReferenceFormatter
{
    /**
     * Formats a reference string from prefix, year, month, and numeric sequence number.
     * Numeric suffix is padded to minimum 5 digits (e.g. 00001), but supports larger values beyond 5 digits (e.g. 100001).
     */
    public function format(string $prefix, int $year, int $month, int $number): string
    {
        $trimmedPrefix = trim($prefix);
        if ($trimmedPrefix === '') {
            throw new InvalidArgumentException("Prefix cannot be empty.");
        }
        if ($year < 2000 || $year > 2100) {
            throw new InvalidArgumentException("Year must be between 2000 and 2100, got: {$year}");
        }
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("Month must be between 1 and 12, got: {$month}");
        }
        if ($number <= 0) {
            throw new InvalidArgumentException("Sequence number must be positive, got: {$number}");
        }

        $paddedNumber = $number < 100000
            ? str_pad((string) $number, 5, '0', STR_PAD_LEFT)
            : (string) $number;

        return sprintf(
            '%s-%04d-%02d-%s',
            $trimmedPrefix,
            $year,
            $month,
            $paddedNumber
        );
    }

    /**
     * Parses a reference string into its components: prefix, year, month, number.
     * Pattern matches `<prefix>-<YYYY>-<MM>-<number>` where `<prefix>` can contain hyphens.
     *
     * @param string $reference
     * @return array{prefix: string, year: int, month: int, number: int}|null Returns null if malformed or ambiguous
     */
    public function parse(string $reference): ?array
    {
        $trimmed = trim($reference);
        if ($trimmed === '') {
            return null;
        }

        // Must end with -YYYY-MM-NNNNN (or more digits)
        // Regex matches: prefix is anything preceding -(YYYY)-(MM)-(digits)
        if (!preg_match('/^(?<prefix>.+)-(?<year>\d{4})-(?<month>\d{2})-(?<number>\d{1,10})$/', $trimmed, $matches)) {
            return null;
        }

        $prefix = trim($matches['prefix']);
        if ($prefix === '') {
            return null;
        }

        $year = (int) $matches['year'];
        $month = (int) $matches['month'];
        $number = (int) $matches['number'];

        if ($year < 2000 || $year > 2100) {
            return null;
        }

        if ($month < 1 || $month > 12) {
            return null;
        }

        if ($number <= 0) {
            return null;
        }

        return [
            'prefix' => $prefix,
            'year' => $year,
            'month' => $month,
            'number' => $number,
        ];
    }
}
