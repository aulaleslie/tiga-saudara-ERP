<?php

namespace App\Livewire\PurchaseReturn\Concerns;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;

trait ValidatesPurchaseReturnForm
{
    protected function purchaseReturnRules(): array
    {
        return [
            'supplier_id' => 'required|exists:suppliers,id',
            'date' => 'required|date',
            'rows' => 'required|array|min:1',
            'rows.*.product_id' => 'required|exists:products,id',
            'rows.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/'],
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
            'rows.*.quantity.numeric' => 'Jumlah produk harus berupa angka.',
            'rows.*.quantity.gt' => 'Jumlah produk harus lebih besar dari 0.',
            'rows.*.quantity.regex' => 'Jumlah produk tidak boleh melebihi 3 angka di belakang koma.',
            'rows.*.location_id.required' => 'Lokasi wajib dipilih.',
            'rows.*.location_id.exists' => 'Lokasi yang dipilih tidak valid.',
            'rows.*.purchase_order_id.exists' => 'Nota pembelian yang dipilih tidak valid.',
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
        $purchaseCache = [];
        $purchaseProductCache = [];

        foreach ($this->rows as $index => $row) {
            $productId = $row['product_id'] ?? null;
            $locationId = $row['location_id'] ?? null;
            $purchaseOrderId = $row['purchase_order_id'] ?? null;
            $serialRequired = ! empty($row['serial_number_required']);

            // Validate: serial entry on non-serial-tracked product
            if (empty($row['serial_number_required']) && !empty($row['serial_numbers'])) {
                $validator->errors()->add("rows.$index.serial_numbers", 'Produk ini tidak memerlukan nomor seri.');
            }

            if ($serialRequired) {
                if (empty($row['serial_numbers'])) {
                    $validator->errors()->add("rows.$index.serial_numbers", 'Produk memerlukan nomor seri.');
                }
                if (empty($row['purchase_order_id'])) {
                    $validator->errors()->add("rows.$index.purchase_order_id", 'Nomor seri harus memiliki referensi pembelian. Pilih ulang nomor seri.');
                }
            }

            if ($purchaseOrderId !== null) {
                if (! array_key_exists($purchaseOrderId, $purchaseCache)) {
                    $purchaseCache[$purchaseOrderId] = Purchase::query()
                        ->select(['id', 'supplier_id'])
                        ->find($purchaseOrderId);
                }

                $purchase = $purchaseCache[$purchaseOrderId];

                if (! $purchase) {
                    $validator->errors()->add("rows.$index.purchase_order_id", 'Nota pembelian yang dipilih tidak ditemukan.');
                } elseif ((int) $purchase->supplier_id !== (int) $this->supplier_id) {
                    $validator->errors()->add("rows.$index.purchase_order_id", 'Nota pembelian harus berasal dari pemasok yang sama.');
                }

                if ($productId !== null) {
                    $purchaseProductKey = $purchaseOrderId . '|' . $productId;
                    if (! array_key_exists($purchaseProductKey, $purchaseProductCache)) {
                        $purchaseProductCache[$purchaseProductKey] = PurchaseDetail::query()
                            ->where('purchase_id', $purchaseOrderId)
                            ->where('product_id', $productId)
                            ->exists();
                    }

                    if (! $purchaseProductCache[$purchaseProductKey]) {
                        $validator->errors()->add("rows.$index.product_id", 'Produk tidak ditemukan pada nota pembelian yang dipilih.');
                    }
                }
            }

            if ($productId !== null && $locationId !== null) {
                $combination = $serialRequired
                    ? ($productId . '-' . $locationId . '-' . ($purchaseOrderId ?? 'null'))
                    : ($productId . '-' . $locationId);

                $duplicateMessage = $serialRequired
                    ? 'Kombinasi produk, lokasi, dan nota pembelian ini sudah ada.'
                    : 'Kombinasi produk dan lokasi ini sudah ada.';

                if (in_array($combination, $lineCombinations, true)) {
                    $validator->errors()->add("rows.$index.product_id", $duplicateMessage);
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
                    ->map(fn ($sn) => ProductSerialNumber::normalize((string) $sn))
                    ->unique()
                    ->values()
                    ->all();

                // Check for duplicate serials across all rows (case-insensitive)
                foreach ($serialNumbers as $serial) {
                    $normalized = ProductSerialNumber::normalize($serial);
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
                        ->with([
                            'histories',
                            'receivedNoteDetails.purchaseDetail',
                            'receivedNoteDetail.purchaseDetail',
                        ])
                        ->get();

                    foreach ($serialsWithMismatches as $psn) {
                        if ($psn->location_id != $locationId) {
                            $validator->errors()->add(
                                "rows.$index.serial_numbers",
                                "Nomor seri '{$psn->serial_number}' berada di lokasi yang berbeda."
                            );
                        }
                        if ($purchaseOrderId !== null && $psn->resolveCurrentPurchaseId() != $purchaseOrderId) {
                            $validator->errors()->add(
                                "rows.$index.serial_numbers",
                                "Nomor seri '{$psn->serial_number}' berasal dari pembelian yang berbeda."
                            );
                        }
                        if (strtoupper($psn->status) !== ProductSerialNumber::STATUS_ACTIVE) {
                            $validator->errors()->add(
                                "rows.$index.serial_numbers",
                                "Nomor seri '{$psn->serial_number}' tidak aktif ({$psn->status})."
                            );
                        }
                        if ($psn->is_in_return_process) {
                            // Skip if this serial belongs to the current purchase return being edited
                            if (property_exists($this, 'purchaseReturn') && 
                                $psn->purchase_return_id === $this->purchaseReturn->id) {
                                continue;
                            }
                            $validator->errors()->add(
                                "rows.$index.serial_numbers",
                                "Nomor seri '{$psn->serial_number}' sedang dalam proses retur."
                            );
                        }
                    }
                }

                $existing = ProductSerialNumber::query()
                    ->whereIn('serial_number', $serialNumbers)
                    ->where('product_id', $productId)
                    ->pluck('serial_number')
                    ->map(fn ($sn) => ProductSerialNumber::normalize((string) $sn))
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
