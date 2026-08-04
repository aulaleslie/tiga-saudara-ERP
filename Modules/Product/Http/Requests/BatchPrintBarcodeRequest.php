<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use Modules\Product\Services\BarcodeBatchService;

class BatchPrintBarcodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('barcodes.print');
    }

    public function rules(): array
    {
        return [
            'setting_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:' . BarcodeBatchService::MAX_PER_PRODUCT],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $items = $this->input('items');

            if (! is_array($items)) {
                return;
            }

            $total = 0;

            foreach ($items as $item) {
                if (is_array($item) && isset($item['quantity']) && is_numeric($item['quantity'])) {
                    $total += (int) $item['quantity'];
                }
            }

            if ($total > BarcodeBatchService::MAX_TOTAL_LABELS) {
                $validator->errors()->add(
                    'items',
                    'Total label melebihi batas ' . BarcodeBatchService::MAX_TOTAL_LABELS . ' label per batch.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Pilih minimal satu produk untuk dicetak.',
            'items.*.product_id.exists' => 'Produk yang dipilih tidak ditemukan.',
            'items.*.product_id.distinct' => 'Setiap produk hanya boleh muncul satu kali dalam batch.',
            'items.*.quantity.min' => 'Jumlah label harus minimal 1.',
            'items.*.quantity.max' => 'Jumlah label maksimal ' . BarcodeBatchService::MAX_PER_PRODUCT . ' per produk.',
            'items.*.quantity.integer' => 'Jumlah label harus berupa bilangan bulat.',
        ];
    }
}
