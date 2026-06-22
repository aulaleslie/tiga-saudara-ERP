<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PurchaseOrderCompletionReportValidator
{
    public function validate(array $data): array
    {
        $validator = Validator::make($data, [
            'startDate' => 'required|date',
            'endDate' => 'required|date|after_or_equal:startDate',
            'sourceStage' => 'nullable|in:Penawaran,Pemesanan',
            'supplierIds' => 'nullable|array',
            'supplierIds.*' => 'exists:suppliers,id',
            'tagIds' => 'nullable|array',
            'tagIds.*' => 'exists:tags,id',
            'tagLogic' => 'nullable|in:any,all',
            'isGlobal' => 'boolean',
            'scopeSettingId' => 'nullable|integer',
        ], [
            'endDate.after_or_equal' => 'Tanggal akhir harus sama atau setelah tanggal awal.',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }
}
