<?php

namespace Modules\Product\Services;

class Ean13Service
{
    private const PREFIX_MIN = 200;
    private const PREFIX_MAX = 299;
    private ?\Closure $candidateGenerator = null;

    /**
     * Validates an EAN-13 barcode by format and checksum.
     * A valid EAN-13 must be exactly 13 decimal digits with a correct check digit.
     *
     * @param string|null $barcode
     * @return bool
     */
    public function isValidEan13(?string $barcode): bool
    {
        if (!$barcode || !is_string($barcode)) {
            return false;
        }

        if (strlen($barcode) !== 13) {
            return false;
        }

        if (!ctype_digit($barcode)) {
            return false;
        }

        return $this->calculateCheckDigit(substr($barcode, 0, 12)) === $barcode[12];
    }

    /**
     * Generates a random EAN-13 candidate with a prefix from 200-299.
     * The first three digits are the prefix, next nine are random digits,
     * and the thirteenth is the calculated check digit.
     * Can be overridden for testing via setCandidateGenerator().
     *
     * @return string
     */
    public function generateCandidate(): string
    {
        if ($this->candidateGenerator !== null) {
            return ($this->candidateGenerator)();
        }

        $prefix = random_int(self::PREFIX_MIN, self::PREFIX_MAX);
        $payload = str_pad($prefix, 3, '0', STR_PAD_LEFT);

        for ($i = 0; $i < 9; $i++) {
            $payload .= random_int(0, 9);
        }

        $checkDigit = $this->calculateCheckDigit($payload);
        return $payload . $checkDigit;
    }

    /**
     * Inject a fake candidate generator for testing.
     *
     * @param \Closure $generator
     */
    public function setCandidateGenerator(\Closure $generator): void
    {
        $this->candidateGenerator = $generator;
    }

    /**
     * Calculates the EAN-13 check digit (modulo-10) for the first 12 digits.
     * Formula: sum = (d1 + 3*d2 + d3 + 3*d4 + ... + d11 + 3*d12)
     *          check = (10 - (sum % 10)) % 10
     *
     * @param string $twelveDigits
     * @return string
     */
    private function calculateCheckDigit(string $twelveDigits): string
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $twelveDigits[$i];
            $weight = ($i % 2 === 0) ? 1 : 3;
            $sum += $digit * $weight;
        }

        $checkDigit = (10 - ($sum % 10)) % 10;
        return (string) $checkDigit;
    }
}
