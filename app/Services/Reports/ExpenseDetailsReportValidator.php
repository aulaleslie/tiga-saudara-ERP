<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class ExpenseDetailsReportValidator
{
    /**
     * @throws ValidationException
     */
    public function validate(array $data): array
    {
        $rules = [
            'startDate'     => 'required|date',
            'endDate'       => 'required|date|after_or_equal:startDate',
            'categoryIds'   => 'nullable|array',
            'categoryIds.*' => 'integer|exists:expense_categories,id',
            'tagIds'        => 'nullable|array',
            'tagIds.*'      => 'integer|exists:tags,id',
            'tagLogic'      => ['required', Rule::in(['Salah satu', 'Mencakup semua'])],
            'sortDirection' => ['required', Rule::in(['asc', 'desc'])],
        ];

        $messages = [
            'startDate.required'           => 'Tanggal Mulai wajib diisi.',
            'startDate.date'               => 'Tanggal Mulai harus berupa tanggal yang valid.',
            'endDate.required'             => 'Tanggal Selesai wajib diisi.',
            'endDate.date'                 => 'Tanggal Selesai harus berupa tanggal yang valid.',
            'endDate.after_or_equal'       => 'Tanggal Selesai harus sama atau setelah Tanggal Mulai.',
            'categoryIds.array'            => 'Format kategori tidak valid.',
            'categoryIds.*.exists'         => 'Kategori yang dipilih tidak valid.',
            'tagIds.array'                 => 'Format tag tidak valid.',
            'tagIds.*.exists'              => 'Tag yang dipilih tidak valid.',
            'tagLogic.in'                  => 'Logika tag tidak valid.',
            'sortDirection.in'             => 'Arah pengurutan tidak valid.',
        ];

        $validator = Validator::make($data, $rules, $messages);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }
}
