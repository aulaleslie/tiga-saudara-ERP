<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SoldSerialNumberReportValidator
{
    public function validate(array $data): array
    {
        $validator = Validator::make($data, [
            'startDate' => 'required|date',
            'endDate' => 'required|date|after_or_equal:startDate',
            'productIds' => 'required|array|min:1',
            'productIds.*' => 'integer|min:1',
            'scopeSettingIds' => 'nullable|array',
            'scopeSettingIds.*' => 'integer|min:1',
        ], [
            'startDate.required' => 'Tanggal awal wajib diisi.',
            'startDate.date' => 'Tanggal awal tidak valid.',
            'endDate.required' => 'Tanggal akhir wajib diisi.',
            'endDate.date' => 'Tanggal akhir tidak valid.',
            'endDate.after_or_equal' => 'Tanggal akhir harus sama atau setelah tanggal awal.',
            'productIds.required' => 'Pilih minimal satu produk atau paket.',
            'productIds.array' => 'Pilihan produk tidak valid.',
            'productIds.min' => 'Pilih minimal satu produk atau paket.',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * Sanitize submitted business IDs against authorized IDs, falling back to
     * default authorized IDs when the sanitized selection is empty.
     *
     * @param  array<int|string>  $submittedSettingIds
     * @param  array<int>  $allowedSettingIds
     * @param  array<int>  $defaultSettingIds
     * @return array<int>
     */
    public function sanitizeSettingIds(
        array $submittedSettingIds,
        array $allowedSettingIds,
        array $defaultSettingIds = [],
    ): array {
        $allowed = array_values(array_unique(array_filter(
            array_map('intval', $allowedSettingIds),
            fn (int $id): bool => $id > 0
        )));

        if ($allowed === []) {
            return [];
        }

        $sanitized = array_values(array_unique(array_filter(
            array_map('intval', $submittedSettingIds),
            fn (int $id): bool => in_array($id, $allowed, true)
        )));

        if ($sanitized === []) {
            $sanitized = array_values(array_unique(array_filter(
                array_map('intval', $defaultSettingIds),
                fn (int $id): bool => in_array($id, $allowed, true)
            )));
        }

        sort($sanitized, SORT_NUMERIC);

        return $sanitized;
    }
}
