<?php

namespace Modules\Sale\Services;

use Modules\Setting\Entities\Setting;
use Modules\Sale\Entities\PosDraft;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PosCodeAllocator
{
    public function allocate(Setting $setting): string
    {
        $prefix = $setting->pos_document_prefix ?: 'POS';
        $date = now();
        $yearInfo = $date->format('Y');
        $monthInfo = $date->format('m');
        $pattern = "{$prefix}-{$yearInfo}-{$monthInfo}-%";

        // We need to find the max number for this pattern
        // We lock for update to prevent race conditions if this were a high concurrency scenario,
        // though strictly 'allocate' just calculates it. Ideally the caller uses a transaction lock.
        // For simple unique monotonic generation without a separate sequence table, we query the latest.
        
        $lastDraft = PosDraft::where('setting_id', $setting->id)
            ->where('document_number', 'like', $pattern)
            ->orderBy('id', 'desc') // Assuming ID order roughly correlates, but we should parse the number ideally
            // Better: Order by length desc, then string desc to handle '10' vs '2' if not padded (but we pad)
            // Since we pad to 5 digits, string sort works for up to 99999.
            ->orderBy('document_number', 'desc')
            ->first();

        $nextSequence = 1;

        if ($lastDraft) {
            $parts = explode('-', $lastDraft->document_number);
            $lastSequence = (int) end($parts);
            $nextSequence = $lastSequence + 1;
        }

        return sprintf('%s-%s-%s-%05d', $prefix, $yearInfo, $monthInfo, $nextSequence);
    }
}
