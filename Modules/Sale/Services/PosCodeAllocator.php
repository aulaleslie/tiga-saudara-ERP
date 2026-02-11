<?php

namespace Modules\Sale\Services;

use Illuminate\Support\Facades\DB;
use Modules\Setting\Entities\Setting;
use Modules\Sale\Entities\PosDraft;

class PosCodeAllocator
{
    public function allocate(Setting $setting): string
    {
        $prefix = trim((string) ($setting->pos_document_prefix ?: 'POS'));
        $prefix = strtoupper($prefix);
        $date = now();
        $yearInfo = $date->format('Y');
        $monthInfo = $date->format('m');
        $pattern = sprintf('%s-%s-%s-%%', $prefix, $yearInfo, $monthInfo);

        return DB::transaction(function () use ($setting, $pattern, $prefix, $yearInfo, $monthInfo) {
            // Serialize allocation per setting to keep monotonic ordering.
            Setting::query()
                ->whereKey($setting->id)
                ->lockForUpdate()
                ->first();

            $lastDraft = PosDraft::query()
                ->where('setting_id', $setting->id)
                ->where('document_number', 'like', $pattern)
                ->orderByDesc('document_number')
                ->lockForUpdate()
                ->first();

            $nextSequence = 1;
            if ($lastDraft && preg_match('/-(\d{5})$/', (string) $lastDraft->document_number, $matches)) {
                $nextSequence = ((int) $matches[1]) + 1;
            }

            if ($nextSequence > 99999) {
                throw new \RuntimeException('POS document sequence overflow for the month.');
            }

            return sprintf('%s-%s-%s-%05d', $prefix, $yearInfo, $monthInfo, $nextSequence);
        }, 3);
    }
}
