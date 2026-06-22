<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class AgedPayablesReportValidator
{
    /**
     * @throws ValidationException
     */
    public function validate(array $data): array
    {
        $rules = [
            'asOfDate'      => 'required|date',
            'agingBasis'    => ['required', Rule::in(['Tanggal Transaksi', 'Tanggal Jatuh Tempo'])],
            'supplierIds'   => 'nullable|array',
            'supplierIds.*' => 'integer|exists:suppliers,id',
            'tagIds'        => 'nullable|array',
            'tagIds.*'      => 'integer|exists:tags,id',
            'tagLogic'      => ['required', Rule::in(['Salah satu', 'Mencakup semua'])],
            'sortField'     => ['required', Rule::in(['supplier_name', 'total_balance'])],
            'sortDirection' => ['required', Rule::in(['asc', 'desc'])],
        ];

        $messages = [
            'asOfDate.required'            => 'Tanggal per (as-of) wajib diisi.',
            'asOfDate.date'                => 'Tanggal per (as-of) harus berupa tanggal yang valid.',
            'agingBasis.in'                => 'Dasar usia utang tidak valid.',
            'supplierIds.array'            => 'Format supplier tidak valid.',
            'supplierIds.*.exists'         => 'Supplier yang dipilih tidak valid.',
            'tagIds.array'                 => 'Format tag tidak valid.',
            'tagIds.*.exists'              => 'Tag yang dipilih tidak valid.',
            'tagLogic.in'                  => 'Logika tag tidak valid.',
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
