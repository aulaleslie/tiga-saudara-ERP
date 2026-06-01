<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Purchase\Entities\Purchase;

class PurchaseReportValidator
{
    public function validate(array $data): array
    {
        $allowedDocumentStatuses = [
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
            'tagIds' => 'nullable|array',
            'tagIds.*' => 'exists:tags,id',
            'documentStatuses' => 'nullable|array',
            'documentStatuses.*' => 'in:' . implode(',', $allowedDocumentStatuses),
            'paymentStatuses' => 'nullable|array',
            'paymentStatuses.*' => 'in:PAID,PARTIAL,UNPAID,paid,partial,unpaid',
            'isGlobal' => 'boolean',
            'scopeSettingId' => 'nullable|integer',
            'dateBasis' => 'nullable|in:transaction_date,due_date',
            'reportMode' => 'nullable|in:detail,header',
        ], [
            'endDate.after_or_equal' => 'Tanggal akhir harus sama atau setelah tanggal awal.',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        if (!empty($validated['paymentStatuses'])) {
            $validated['paymentStatuses'] = array_map('strtoupper', $validated['paymentStatuses']);
        }

        return $validated;
    }
}
