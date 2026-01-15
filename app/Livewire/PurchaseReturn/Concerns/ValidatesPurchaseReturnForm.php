<?php

namespace App\Livewire\PurchaseReturn\Concerns;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;
use Modules\Product\Entities\ProductSerialNumber;

trait ValidatesPurchaseReturnForm
{
    protected function purchaseReturnRules(): array
    {
        return [
            'supplier_id' => 'required|exists:suppliers,id',
            'date' => 'required|date',
            'rows' => 'required|array|min:1',
            'rows.*.product_id' => 'required|exists:products,id',
            'rows.*.quantity' => 'required|integer|min:1',
            'rows.*.location_id' => 'required|exists:locations,id',
            'rows.*.purchase_order_id' => 'nullable|exists:purchases,id',
        ];
    }

    protected function purchaseReturnMessages(): array
    {
        return [
            'supplier_id.required' => 'Pilih pemasok terlebih dahulu.',
            'supplier_id.exists' => 'Pemasok yang dipilih tidak valid.',
            'date.required' => 'Tanggal retur wajib diisi.',
            'date.date' => 'Format tanggal tidak valid.',
            'rows.required' => 'Setidaknya satu produk harus ditambahkan.',
            'rows.array' => 'Format produk tidak valid.',
            'rows.min' => 'Setidaknya satu produk harus ditambahkan.',
            'rows.*.product_id.required' => 'Silakan pilih produk.',
            'rows.*.product_id.exists' => 'Produk yang dipilih tidak valid.',
            'rows.*.quantity.required' => 'Jumlah produk harus diisi.',
            'rows.*.quantity.integer' => 'Jumlah produk harus berupa angka.',
            'rows.*.quantity.min' => 'Jumlah produk minimal 1.',
            'rows.*.location_id.required' => 'Lokasi wajib dipilih.',
            'rows.*.location_id.exists' => 'Lokasi yang dipilih tidak valid.',
            'rows.*.purchase_order_id.exists' => 'Nomor purchase order tidak valid.',
        ];
    }

    protected function makePurchaseReturnValidator(array $data): LaravelValidator
    {
        $validator = Validator::make($data, $this->purchaseReturnRules(), $this->purchaseReturnMessages());

        $validator->after(function (LaravelValidator $validator) {
            $this->applyPurchaseReturnAfterValidation($validator);
        });

        return $validator;
    }

    protected function applyPurchaseReturnAfterValidation(LaravelValidator $validator): void
    {
        $lineCombinations = [];
        $allSerials = []; // Track all serials across rows: normalized_serial => row_index

        foreach ($this->rows as $index => $row) {
            $productId = $row['product_id'] ?? null;
            $locationId = $row['location_id'] ?? null;
            $purchaseOrderId = $row['purchase_order_id'] ?? null;

            // Validate: serial entry on non-serial-tracked product
            if (empty($row['serial_number_required']) && !empty($row['serial_numbers'])) {
                $validator->errors()->add("rows.$index.serial_numbers", 'Produk ini tidak memerlukan nomor seri.');
            }

            if (! empty($row['serial_number_required']) && empty($row['serial_numbers'])) {
                $validator->errors()->add("rows.$index.serial_numbers", 'Produk memerlukan nomor seri.');
            }

            if ($productId !== null && $locationId !== null) {
                $combination = $productId . '-' . $locationId . '-' . ($purchaseOrderId ?: 'none');
                if (in_array($combination, $lineCombinations)) {
                    $validator->errors()->add("rows.$index.product_id", 'Kombinasi produk, lokasi, dan purchase order ini sudah ada.');
                } else {
                    $lineCombinations[] = $combination;
                }

                // Check stock availability
                $hasStock = \Modules\Product\Entities\ProductStock::where('product_id', $productId)
                    ->where('location_id', $locationId)
                    ->where('quantity', '>', 0)
                    ->exists();

                if (!$hasStock) {
                    $validator->errors()->add("rows.$index.location_id", 'Stok tidak tersedia di lokasi yang dipilih.');
                }
            }

            if (! empty($row['serial_numbers'])) {
                $serialNumbers = collect($row['serial_numbers'])
                    ->map(fn ($item) => is_array($item) ? ($item['serial_number'] ?? null) : $item)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                // Check for duplicate serials across all rows (case-insensitive)
                foreach ($serialNumbers as $serial) {
                    $normalized = strtolower(trim($serial));
                    if (isset($allSerials[$normalized])) {
                        $validator->errors()->add(
                            "rows.$index.serial_numbers",
                            "Nomor seri '$serial' sudah digunakan pada baris lain."
                        );
                    } else {
                        $allSerials[$normalized] = $index;
                    }
                }

                // Validate each serial's location and purchase match the row location and purchase
                if ($locationId !== null) {
                    $serialsWithMismatches = ProductSerialNumber::whereIn('serial_number', $serialNumbers)
                        ->where('product_id', $productId)
                        ->with(['receivedNoteDetail.receivedNote'])
                        ->get();

                    foreach ($serialsWithMismatches as $psn) {
                        if ($psn->location_id != $locationId) {
                            $validator->errors()->add(
                                "rows.$index.serial_numbers",
                                "Nomor seri '{$psn->serial_number}' berada di lokasi yang berbeda."
                            );
                        }
                        
                        if ($purchaseOrderId !== null) {
                            $serialPurchaseId = $psn->receivedNoteDetail->receivedNote->po_id ?? null;
                            if ($serialPurchaseId != $purchaseOrderId) {
                                $validator->errors()->add(
                                    "rows.$index.serial_numbers",
                                    "Nomor seri '{$psn->serial_number}' berasal dari pembelian yang berbeda."
                                );
                            }
                        }
                    }
                }

                $existing = ProductSerialNumber::query()
                    ->whereIn('serial_number', $serialNumbers)
                    ->where('product_id', $productId)
                    ->pluck('serial_number')
                    ->unique()
                    ->values()
                    ->all();

                $missing = array_diff($serialNumbers, $existing);

                if (! empty($missing)) {
                    $validator->errors()->add(
                        "rows.$index.serial_numbers",
                        'Nomor seri tidak valid atau tidak ditemukan: ' . implode(', ', $missing)
                    );
                }
            }
        }

        if ($this->calculateReturnTotal() <= 0) {
            $validator->errors()->add('rows', 'Nilai retur harus lebih dari 0.');
        }
    }

    public function rules(): array
    {
        return $this->purchaseReturnRules();
    }

    public function messages(): array
    {
        return $this->purchaseReturnMessages();
    }
}
