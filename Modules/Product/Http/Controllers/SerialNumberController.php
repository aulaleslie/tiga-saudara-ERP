<?php

namespace Modules\Product\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;

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
        $existsCommitted = ProductSerialNumber::where('product_id', $validated['product_id'])
            ->where('serial_number', $validated['serial_number'])
            ->exists();

        if ($existsCommitted) {
            return response()->json([
                'valid' => false,
                'message' => 'Serial number sudah ada untuk produk ini.',
            ], 200);
        }

        // Check if serial number is pending in a PENDING receiving
        $existsPending = ReceivedNoteDetail::whereHas('receivedNote', function ($q) {
            $q->where('status', ReceivedNote::STATUS_PENDING);
        })
            ->whereHas('purchaseDetail', function ($q) use ($validated) {
                $q->where('product_id', $validated['product_id']);
            })
            ->whereNotNull('pending_serial_numbers')
            ->get()
            ->contains(function ($detail) use ($validated) {
                $pendingSerials = $detail->pending_serial_numbers ?? [];
                return in_array($validated['serial_number'], $pendingSerials);
            });

        if ($existsPending) {
            return response()->json([
                'valid' => false,
                'message' => 'Serial number sedang dalam proses penerimaan yang menunggu persetujuan.',
            ], 200);
        }

        return response()->json(['valid' => true], 200);
    }

    /**
     * Validate a serial number for dispatch.
     * Checks if the serial number exists, is at the correct location, and is not already dispatched.
     */
    public function validateDispatchSerial(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'serial_number' => 'required|string|max:255',
            'location_id' => 'required|integer|exists:locations,id',
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

        if ((int) $serial->location_id !== (int) $validated['location_id']) {
            return response()->json([
                'valid' => false,
                'message' => 'Serial number berada di lokasi yang berbeda.',
            ], 200);
        }

        // Check if already dispatched (assuming dispatch_detail_id is not null implies dispatched)
        if ($serial->dispatch_detail_id) {
            return response()->json([
                'valid' => false,
                'message' => 'Serial number sudah dikirim/terpakai.',
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

        return response()->json(['valid' => true], 200);
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
        
        return response()->json([
            'valid' => true,
            'serial_number_object' => $serial,
            'location_id' => $serial->location_id,
            'location_name' => $serial->location->name ?? null,
            'location_label' => ($serial->location->setting->company_name ?? 'N/A') . ' - ' . ($serial->location->name ?? 'N/A'),
        ], 200);
    }
}
