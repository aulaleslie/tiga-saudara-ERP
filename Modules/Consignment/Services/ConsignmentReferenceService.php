<?php

namespace Modules\Consignment\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentReceival;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Setting\Entities\Setting;

class ConsignmentReferenceService
{
    /**
     * Atomically allocate a reference and create a ConsignmentReceival document.
     */
    public static function createReceivalWithReference(array $data): ConsignmentReceival
    {
        return DB::transaction(function () use ($data) {
            $date = isset($data['date']) ? Carbon::parse($data['date']) : now();
            $year = $date->year;
            $month = $date->month;
            $settingId = (int) $data['setting_id'];

            // Lock Setting row to serialize reference allocation for this setting
            $setting = Setting::whereKey($settingId)->lockForUpdate()->firstOrFail();

            $latestReference = ConsignmentReceival::where('setting_id', $settingId)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->latest('id')
                ->value('reference');

            $nextNumber = 1;
            if ($latestReference) {
                $parts = explode('-', $latestReference);
                $lastNumber = (int) end($parts);
                $nextNumber = $lastNumber + 1;
            }

            $prefix = (optional($setting)->document_prefix ?: '') . '-CR';
            $prefix = ltrim($prefix, '-');

            $reference = make_reference_id($prefix, $year, $month, $nextNumber);
            $data['reference'] = $reference;

            return ConsignmentReceival::create($data);
        });
    }

    /**
     * Atomically allocate a receiving_number and create a ConsignmentReceiving document.
     */
    public static function createReceivingWithReference(array $data): ConsignmentReceiving
    {
        return DB::transaction(function () use ($data) {
            $date = isset($data['date']) ? Carbon::parse($data['date']) : now();
            $year = $date->year;
            $month = $date->month;
            $settingId = (int) $data['setting_id'];

            $setting = Setting::whereKey($settingId)->lockForUpdate()->firstOrFail();

            $latestReference = ConsignmentReceiving::where('setting_id', $settingId)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->latest('id')
                ->value('receiving_number');

            $nextNumber = 1;
            if ($latestReference) {
                $parts = explode('-', $latestReference);
                $lastNumber = (int) end($parts);
                $nextNumber = $lastNumber + 1;
            }

            $prefix = (optional($setting)->document_prefix ?: '') . '-CRN';
            $prefix = ltrim($prefix, '-');

            $receivingNumber = make_reference_id($prefix, $year, $month, $nextNumber);
            $data['receiving_number'] = $receivingNumber;

            return ConsignmentReceiving::create($data);
        });
    }
}
