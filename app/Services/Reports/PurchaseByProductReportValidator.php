<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class PurchaseByProductReportValidator
{
    /**
     * @throws ValidationException
     */
    public function validate(array $data): array
    {
        $rules = [
            'startDate'     => 'required|date',
            'endDate'       => 'required|date|after_or_equal:startDate',
            'supplierIds'   => 'nullable|array',
            'supplierIds.*' => 'integer|exists:suppliers,id',
            'tagIds'        => 'nullable|array',
            'tagIds.*'      => 'integer|exists:tags,id',
            'tagLogic'      => ['required', Rule::in(['Salah satu', 'Mencakup semua'])],
            'categoryIds'   => 'nullable|array',
            'categoryIds.*' => 'integer|exists:categories,id',
            'categoryLogic' => ['required', Rule::in(['Salah satu', 'Mencakup semua'])],
            'productIds'    => 'nullable|array',
            'productIds.*'  => 'integer|exists:products,id',
            'sortField'     => ['required', Rule::in(['product_name', 'product_code', 'purchase_quantity', 'return_quantity', 'purchase_value', 'average_purchase_value'])],
            'sortDirection' => ['required', Rule::in(['asc', 'desc'])],
            'scopeSettingId'=> 'required|integer|exists:settings,id',
        ];

        $messages = [
            'startDate.required'           => 'Tanggal awal wajib diisi.',
            'startDate.date'               => 'Tanggal awal harus berupa tanggal yang valid.',
            'endDate.required'             => 'Tanggal akhir wajib diisi.',
            'endDate.date'                 => 'Tanggal akhir harus berupa tanggal yang valid.',
            'endDate.after_or_equal'       => 'Tanggal akhir harus sama atau setelah tanggal awal.',
            'supplierIds.array'            => 'Format supplier tidak valid.',
            'supplierIds.*.exists'         => 'Supplier yang dipilih tidak valid.',
            'tagIds.array'                 => 'Format tag tidak valid.',
            'tagIds.*.exists'              => 'Tag yang dipilih tidak valid.',
            'tagLogic.in'                  => 'Logika tag tidak valid.',
            'categoryIds.array'            => 'Format kategori tidak valid.',
            'categoryIds.*.exists'         => 'Kategori yang dipilih tidak valid.',
            'categoryLogic.in'             => 'Logika kategori tidak valid.',
            'productIds.array'             => 'Format produk tidak valid.',
            'productIds.*.exists'          => 'Produk yang dipilih tidak valid.',
            'sortField.in'                 => 'Pilihan pengurutan tidak valid.',
            'sortDirection.in'             => 'Arah pengurutan tidak valid.',
            'scopeSettingId.required'      => 'Cabang wajib dipilih.',
            'scopeSettingId.exists'        => 'Cabang yang dipilih tidak valid.',
        ];

        $validator = Validator::make($data, $rules, $messages);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }
}
