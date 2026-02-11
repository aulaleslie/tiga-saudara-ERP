<?php

namespace App\Services;

use Modules\Sale\Entities\PosAuditLog;
use Modules\Sale\Entities\PosDraft;

class PosAuditLogger
{
    public static function record(
        string $action,
        ?PosDraft $draft = null,
        ?int $settingId = null,
        ?int $userId = null,
        ?array $payload = null
    ): PosAuditLog {
        $settingId ??= $draft?->setting_id ?? (int) session('setting_id');
        $userId ??= auth()->id();

        return PosAuditLog::query()->create([
            'setting_id' => $settingId,
            'pos_draft_id' => $draft?->id,
            'user_id' => $userId,
            'pos_code' => $draft?->document_number,
            'action' => $action,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
