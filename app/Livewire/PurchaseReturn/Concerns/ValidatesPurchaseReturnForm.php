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
            'location_id' => 'required|exists:locations,id',
            'rows' => 'required|array|min:1',
            'rows.*.product_id' => 'required|exists:products,id',
            'rows.*.quantity' => 'required|integer|min:1',
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
            'location_id.required' => 'Lokasi wajib dipilih.',
            'location_id.exists' => 'Lokasi yang dipilih tidak valid.',
            'rows.required' => 'Setidaknya satu produk harus ditambahkan.',
            'rows.array' => 'Format produk tidak valid.',
            'rows.min' => 'Setidaknya satu produk harus ditambahkan.',
            'rows.*.product_id.required' => 'Silakan pilih produk.',
            'rows.*.product_id.exists' => 'Produk yang dipilih tidak valid.',
            'rows.*.quantity.required' => 'Jumlah produk harus diisi.',
            'rows.*.quantity.integer' => 'Jumlah produk harus berupa angka.',
            'rows.*.quantity.min' => 'Jumlah produk minimal 1.',
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
        $productIds = [];

        foreach ($this->rows as $index => $row) {
            $productId = $row['product_id'] ?? null;
            $qty = (int) ($row['quantity'] ?? 0);
            $availableTax = (int) ($row['available_quantity_tax'] ?? 0);
            $availableNonTax = (int) ($row['available_quantity_non_tax'] ?? 0);
            $totalAvailable = $availableTax + $availableNonTax;

            if ($qty > $totalAvailable) {
                $validator->errors()->add(
                    "rows.$index.quantity",
                    "Jumlah retur tidak boleh melebihi stok tersedia ({$totalAvailable})."
                );
            }

            if (! empty($row['serial_number_required']) && empty($row['serial_numbers'])) {
                $validator->errors()->add("rows.$index.serial_numbers", 'Produk memerlukan nomor seri.');
            }

            if ($productId !== null) {
                if (in_array($productId, $productIds)) {
                    $validator->errors()->add("rows.$index.product_id", 'Produk ini sudah dipilih sebelumnya.');
                } else {
                    $productIds[] = $productId;
                }
            }

            if (! empty($row['serial_numbers'])) {
                $serialNumbers = collect($row['serial_numbers'])
                    ->map(fn ($item) => is_array($item) ? ($item['serial_number'] ?? null) : $item)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $existing = ProductSerialNumber::query()
                    ->whereIn('serial_number', $serialNumbers)
                    ->where('is_broken', true)
                    ->pluck('serial_number')
                    ->unique()
                    ->values()
                    ->all();

                $missing = array_diff($serialNumbers, $existing);
                $extra = array_diff($existing, $serialNumbers);

                if (! empty($missing) || ! empty($extra)) {
                    $validator->errors()->add(
                        "rows.$index.serial_numbers",
                        'Nomor seri tidak valid atau tidak rusak: ' . implode(', ', array_merge($missing, $extra))
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
