<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Sale\Entities\Sale;

class SaleReportValidator
{
    public function validate(array $data): array
    {
        $allowedDocumentStatuses = [
            Sale::STATUS_DRAFTED,
            Sale::STATUS_WAITING_APPROVAL,
            Sale::STATUS_APPROVED,
            Sale::STATUS_REJECTED,
            Sale::STATUS_DISPATCHED_PARTIALLY,
            Sale::STATUS_DISPATCHED,
            Sale::STATUS_RETURNED,
            Sale::STATUS_RETURNED_PARTIALLY,
        ];

        $validator = Validator::make($data, [
            'startDate' => 'required|date',
            'endDate' => 'required|date|after_or_equal:startDate',
            'customerIds' => 'nullable|array',
            'customerIds.*' => 'exists:customers,id',
            'tagIds' => 'nullable|array',
            'tagIds.*' => 'exists:tags,id',
            'documentStatuses' => 'nullable|array',
            'documentStatuses.*' => 'in:' . implode(',', $allowedDocumentStatuses),
            'paymentStatuses' => 'nullable|array',
            'paymentStatuses.*' => 'in:PAID,PARTIAL,UNPAID,paid,partial,unpaid',
            'isGlobal' => 'boolean',
            'scopeSettingId' => 'nullable|integer',
            'dateBasis' => 'nullable|in:date',
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
