<?php

namespace Modules\Product\Services;

use Modules\Product\Entities\ProductSerialNumber;

class ReceivingSerialNumberValidationService
{
    /**
     * Validate a serial number for purchase or consignment receiving.
     * Reconciles policy across AJAX endpoints and backend services.
     */
    public function validateForReceiving(int $productId, string $rawSerialNumber): array
    {
        $serialNumber = trim($rawSerialNumber);

        if ($serialNumber === '') {
            return [
                'valid' => false,
                'message' => 'Nomor seri tidak boleh kosong.',
            ];
        }

        $serial = ProductSerialNumber::where('product_id', $productId)
            ->where('serial_number', $serialNumber)
            ->first();

        if ($serial) {
            if ($serial->is_in_return_process || $serial->status === ProductSerialNumber::STATUS_RETURN_IN_PROCESS) {
                return [
                    'valid' => false,
                    'message' => 'Serial number sedang dalam proses retur.',
                ];
            }

            if ($serial->is_broken) {
                return [
                    'valid' => false,
                    'message' => 'Serial number rusak (broken).',
                ];
            }

            if (in_array($serial->status, [ProductSerialNumber::STATUS_RETURNED, ProductSerialNumber::STATUS_SOLD], true)) {
                return [
                    'valid' => true,
                    'serial_number' => $serialNumber,
                    'info_message' => 'Serial number ini adalah hasil retur/terjual dan akan digunakan kembali.',
                ];
            }

            return [
                'valid' => false,
                'message' => "Serial number sudah ada untuk produk ini (Status: {$serial->status}).",
            ];
        }

        return [
            'valid' => true,
            'serial_number' => $serialNumber,
        ];
    }
}
