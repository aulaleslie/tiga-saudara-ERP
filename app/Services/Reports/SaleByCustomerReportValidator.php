<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class SaleByCustomerReportValidator
{
    /**
     * @throws ValidationException
     */
    public function validate(array $data): array
    {
        $rules = [
            'startDate'     => 'required|date',
            'endDate'       => 'required|date|after_or_equal:startDate',
            'customerIds'   => 'nullable|array',
            'customerIds.*' => 'integer|exists:customers,id',
            'tagIds'        => 'nullable|array',
            'tagIds.*'      => 'integer|exists:tags,id',
            'tagLogic'      => ['required', Rule::in(['Salah satu', 'Mencakup semua'])],
            'categoryIds'   => 'nullable|array',
            'categoryIds.*' => 'integer|exists:categories,id',
            'categoryLogic' => ['required', Rule::in(['Salah satu', 'Mencakup semua'])],
            'sortField'     => ['required', Rule::in(['customer_name', 'customer_total', 'date'])],
            'sortDirection' => ['required', Rule::in(['asc', 'desc'])],
        ];

        $messages = [
            'startDate.required'           => 'Tanggal awal wajib diisi.',
            'startDate.date'               => 'Tanggal awal harus berupa tanggal yang valid.',
            'endDate.required'             => 'Tanggal akhir wajib diisi.',
            'endDate.date'                 => 'Tanggal akhir harus berupa tanggal yang valid.',
            'endDate.after_or_equal'       => 'Tanggal akhir harus sama atau setelah tanggal awal.',
            'customerIds.array'            => 'Format customer tidak valid.',
            'customerIds.*.exists'         => 'Customer yang dipilih tidak valid.',
            'tagIds.array'                 => 'Format tag tidak valid.',
            'tagIds.*.exists'              => 'Tag yang dipilih tidak valid.',
            'tagLogic.in'                  => 'Logika tag tidak valid.',
            'categoryIds.array'            => 'Format kategori tidak valid.',
            'categoryIds.*.exists'         => 'Kategori yang dipilih tidak valid.',
            'categoryLogic.in'             => 'Logika kategori tidak valid.',
            'sortField.in'                 => 'Pilihan pengurutan tidak valid.',
            'sortDirection.in'             => 'Arah pengurutan tidak valid.',
        ];

        $validator = Validator::make($data, $rules, $messages);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }
}
