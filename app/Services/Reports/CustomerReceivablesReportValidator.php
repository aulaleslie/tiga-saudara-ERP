<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class CustomerReceivablesReportValidator
{
    /**
     * @throws ValidationException
     */
    public function validate(array $data): array
    {
        $rules = [
            'endDate'       => 'required|date',
            'dueDateUntil'  => 'nullable|date',
            'customerIds'   => 'nullable|array',
            'customerIds.*' => 'integer|exists:customers,id',
            'tagIds'        => 'nullable|array',
            'tagIds.*'      => 'integer|exists:tags,id',
            'tagLogic'      => ['required', Rule::in(['Salah satu', 'Mencakup semua'])],
            'sortField'     => ['required', Rule::in(['customer_name', 'total_balance'])],
            'sortDirection' => ['required', Rule::in(['asc', 'desc'])],
        ];

        $messages = [
            'endDate.required'             => 'Tanggal per (as-of) wajib diisi.',
            'endDate.date'                 => 'Tanggal per (as-of) harus berupa tanggal yang valid.',
            'dueDateUntil.date'            => 'Jatuh tempo maksimal harus berupa tanggal yang valid.',
            'customerIds.array'            => 'Format pelanggan tidak valid.',
            'customerIds.*.exists'         => 'Pelanggan yang dipilih tidak valid.',
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
