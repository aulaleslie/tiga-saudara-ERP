<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Purchase\Entities\Purchase;

class PurchaseReportValidator
{
    public function validate(array $data): array
    {
        $allowedStatuses = [
            Purchase::STATUS_DRAFTED,
            Purchase::STATUS_WAITING_APPROVAL,
            Purchase::STATUS_APPROVED,
            Purchase::STATUS_REJECTED,
            Purchase::STATUS_RECEIVED_PARTIALLY,
            Purchase::STATUS_RECEIVED,
            Purchase::STATUS_RETURNED,
            Purchase::STATUS_RETURNED_PARTIALLY,
        ];

        $validator = Validator::make($data, [
            'startDate' => 'required|date',
            'endDate' => 'required|date|after_or_equal:startDate',
            'supplierIds' => 'nullable|array',
            'supplierIds.*' => 'exists:suppliers,id',
            'withTax' => 'nullable|in:1,0',
            'tagIds' => 'nullable|array',
            'tagIds.*' => 'exists:tags,id',
            'status' => 'nullable|in:' . implode(',', $allowedStatuses),
            'paymentStatus' => 'nullable|in:PAID,PARTIAL,UNPAID,paid,partial,unpaid',
            'isGlobal' => 'boolean',
            'scopeSettingId' => 'nullable|integer',
        ], [
            'endDate.after_or_equal' => 'Tanggal akhir harus sama atau setelah tanggal awal.',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        // Normalization
        if (isset($validated['paymentStatus'])) {
            $validated['paymentStatus'] = strtoupper($validated['paymentStatus']);
        }

        return $validated;
    }
}
