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
        // Also check if it's broken? existing logic in SerialNumberLoader checked if is_broken
        // let's check is_broken too.
        if ($serial->dispatch_detail_id) {
            return response()->json([
                'valid' => false,
                'message' => 'Serial number sudah dikirim/terpakai.',
            ], 200);
        }
        
        // Also check if broken? existing loader had ->where('is_broken', false) unless specified
         if ($serial->is_broken) {
            return response()->json([
                'valid' => false,
                'message' => 'Serial number rusak (broken).',
            ], 200);
        }

        return response()->json(['valid' => true], 200);
    }
}
