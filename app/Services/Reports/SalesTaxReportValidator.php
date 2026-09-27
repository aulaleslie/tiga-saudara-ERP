<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SalesTaxReportValidator
{
    /**
     * @throws ValidationException
     */
    public function validate(array $data): array
    {
        $rules = [
            'startDate'     => 'required|date',
            'endDate'       => 'required|date|after_or_equal:startDate',
            'periodPreset'  => 'nullable|string',
        ];

        $messages = [
            'startDate.required'           => 'Tanggal Mulai wajib diisi.',
            'startDate.date'               => 'Tanggal Mulai harus berupa tanggal yang valid.',
            'endDate.required'             => 'Tanggal Selesai wajib diisi.',
            'endDate.date'                 => 'Tanggal Selesai harus berupa tanggal yang valid.',
            'endDate.after_or_equal'       => 'Tanggal Selesai harus sama atau setelah Tanggal Mulai.',
        ];

        $validator = Validator::make($data, $rules, $messages);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }
}
