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
     * Checks if the serial number already exists for the given product.
     */
    public function validateSerial(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'serial_number' => 'required|string|max:255',
        ]);

        $exists = ProductSerialNumber::where('product_id', $validated['product_id'])
            ->where('serial_number', $validated['serial_number'])
            ->exists();

        if ($exists) {
            return response()->json([
                'valid' => false,
                'message' => 'Serial number sudah ada untuk produk ini.',
            ], 200);
        }

        return response()->json(['valid' => true], 200);
    }
}
