<?php

namespace Modules\Product\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Product\Entities\ProductSerialNumber;

class SerialNumberController extends Controller
{
    /**
     * Validate a serial number against the database.
     * Checks if the serial number already exists (committed) or is pending in a receiving.
     */
    public function validateSerial(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'serial_number' => 'required|string|max:255',
        ]);

        // Check if serial number already exists in committed product_serial_numbers
        $serial = ProductSerialNumber::where('product_id', $validated['product_id'])
            ->where('serial_number', $validated['serial_number'])
            ->first();

        if ($serial) {
            // BLOCK: Return in process
            if ($serial->is_in_return_process || $serial->status === ProductSerialNumber::STATUS_RETURN_IN_PROCESS) {
                return response()->json([
                    'valid' => false,
                    'message' => 'Serial number sedang dalam proses retur.',
                ], 200);
            }

            // ALLOW: Returned status (Reuse)
            if ($serial->status === ProductSerialNumber::STATUS_RETURNED) {
                return response()->json([
                    'valid' => true,
                    'info_message' => 'Serial number ini adalah hasil retur dan akan digunakan kembali.',
                ], 200);
            }

            // BLOCK: Active or other statuses
            return response()->json([
                'valid' => false,
                'message' => 'Serial number sudah ada untuk produk ini.',
            ], 200);
        }

        // Remove cross-pending checks completely as per requirement

        return response()->json(['valid' => true], 200);
    }

    /**
     * Validate a serial number for dispatch.
     * Checks if the serial number exists and is not already dispatched.
     * returns location info and tax_id for auto-loading.
     */
    public function validateDispatchSerial(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'serial_number' => 'required|string|max:255',
            'location_id' => 'nullable|integer|exists:locations,id',
            'expected_tax_id' => 'nullable|integer|exists:taxes,id',
        ]);

        $serial = ProductSerialNumber::where('product_id', $validated['product_id'])
            ->where('serial_number', $validated['serial_number'])
            ->first();

        if (!$serial) {
            return response()->json([
                'valid' => false,
                'message' => 'Serial number tidak ditemukan.',
            ], 200);
        }

        // Check tax mismatch if expected_tax_id is provided
        if (isset($validated['expected_tax_id'])) {
            $expectedTaxId = $validated['expected_tax_id'] ? (int) $validated['expected_tax_id'] : null;
            $serialTaxId = $serial->tax_id ? (int) $serial->tax_id : null;

            if ($expectedTaxId !== $serialTaxId) {
                $taxMismatchMsg = $expectedTaxId
                    ? 'Serial number ini Non-PPN, tidak bisa digunakan untuk produk PPN.'
                    : 'Serial number ini PPN, tidak bisa digunakan untuk produk Non-PPN.';
                return response()->json([
                    'valid' => false,
                    'message' => $taxMismatchMsg,
                ], 200);
            }
        }

        // Check if already dispatched (assuming dispatch_detail_id is not null implies dispatched)
        if ($serial->dispatch_detail_id) {
            return response()->json([
                'valid' => false,
                'message' => 'Serial number sudah dikirim/terpakai.',
            ], 200);
        }

        if ($serial->status !== ProductSerialNumber::STATUS_ACTIVE) {
            return response()->json([
                'valid' => false,
                'message' => "Serial number tidak aktif ({$serial->status}).",
            ], 200);
        }

        if ($serial->is_broken) {
            return response()->json([
                'valid' => false,
                'message' => 'Serial number rusak (broken).',
            ], 200);
        }

        if ($serial->is_in_return_process) {
            return response()->json([
                'valid' => false,
                'message' => 'Serial number sedang dalam proses retur.',
            ], 200);
        }

        return response()->json([
            'valid' => true,
            'location_id' => $serial->location_id,
            'location_name' => $serial->location->name ?? null,
            'tax_id' => $serial->tax_id,
        ], 200);
    }

    /**
     * Update a serial number.
     */
    public function updateSerial(Request $request, ProductSerialNumber $serialNumber): JsonResponse
    {
        $validated = $request->validate([
            'serial_number' => 'required|string|max:255',
        ]);

        // Check if the new serial number already exists (excluding current) scoped to product
        $exists = ProductSerialNumber::where('serial_number', $validated['serial_number'])
            ->where('product_id', $serialNumber->product_id)
            ->where('id', '!=', $serialNumber->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Serial number sudah digunakan untuk produk ini.',
            ], 422);
        }

        // Check if it's pending in a receiving
        $existsPending = ReceivedNoteDetail::whereHas('receivedNote', function ($q) {
            $q->where('status', ReceivedNote::STATUS_PENDING);
        })
            ->whereNotNull('pending_serial_numbers')
            ->get()
            ->contains(function ($detail) use ($validated) {
                $pendingSerials = $detail->pending_serial_numbers ?? [];
                return in_array($validated['serial_number'], $pendingSerials);
            });

        if ($existsPending) {
            return response()->json([
                'success' => false,
                'message' => 'Serial number sedang dalam proses penerimaan yang menunggu persetujuan.',
            ], 422);
        }

        $serialNumber->update([
            'serial_number' => $validated['serial_number'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Serial number berhasil diperbarui.',
            'serial_number' => $serialNumber->fresh(),
        ]);
    }

    /**
     * Validate a serial number for purchase return.
     * Checks if the serial number exists at the specified location and is available (not dispatched).
     */
    public function validatePurchaseReturnSerial(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'serial_number' => 'required|string|max:255',
        ]);

        $serial = ProductSerialNumber::where('product_id', $validated['product_id'])
            ->where('serial_number', $validated['serial_number'])
            ->with(['location.setting'])
            ->first();

        if (!$serial) {
            return response()->json([
                'valid' => false,
                'message' => 'Serial number tidak ditemukan.',
            ], 200);
        }

        if ($serial->dispatch_detail_id) {
            return response()->json([
                'valid' => false,
                'message' => 'Serial number sudah terjual/keluar.',
            ], 200);
        }

        if ($serial->status !== ProductSerialNumber::STATUS_ACTIVE) {
            return response()->json([
                'valid' => false,
                'message' => "Serial number tidak aktif ({$serial->status}).",
            ], 200);
        }

        if ($serial->is_in_return_process) {
            return response()->json([
                'valid' => false,
                'message' => 'Serial number sedang dalam proses retur.',
            ], 200);
        }
        
        return response()->json([
            'valid' => true,
            'serial_number_object' => $serial,
            'location_id' => $serial->location_id,
            'location_name' => $serial->location->name ?? null,
            'location_label' => ($serial->location->setting->company_name ?? 'N/A') . ' - ' . ($serial->location->name ?? 'N/A'),
        ], 200);
    }
}
