<?php

namespace Modules\Product\Services;

use Modules\Product\Entities\ProductSerialNumber;

class SerialConversionValidationService
{
    /**
     * Validate a scanned serial number for the conversion workflow.
     *
     * Rules:
     * 1. Cannot be blank after trimming.
     * 2. Cannot exist anywhere in product_serial_numbers database table regardless of product, setting, location, or status.
     * 3. Cannot exist in the array of serials already scanned in the current session.
     */
    public function validateSerial(string $rawSerial, array $sessionSerials = []): array
    {
        $serialNumber = trim($rawSerial);

        if ($serialNumber === '') {
            return [
                'valid' => false,
                'message' => 'Nomor seri tidak boleh kosong.',
            ];
        }

        if (mb_strlen($serialNumber) > 255) {
            return [
                'valid' => false,
                'message' => 'Nomor seri tidak boleh melebihi 255 karakter.',
            ];
        }

        // Check session duplicates
        $trimmedSessionSerials = array_map(fn ($s) => trim((string) $s), $sessionSerials);
        if (in_array($serialNumber, $trimmedSessionSerials, true)) {
            return [
                'valid' => false,
                'message' => 'Nomor seri ini sudah di-scan di dalam sesi konversi ini.',
            ];
        }

        // Global database check
        $existsInDb = ProductSerialNumber::where('serial_number', $serialNumber)->exists();
        if ($existsInDb) {
            return [
                'valid' => false,
                'message' => 'Nomor seri ini sudah terdaftar di sistem database.',
            ];
        }

        return [
            'valid' => true,
            'serial_number' => $serialNumber,
        ];
    }
}
