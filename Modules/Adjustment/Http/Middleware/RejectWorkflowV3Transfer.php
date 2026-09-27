<?php

namespace Modules\Adjustment\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Adjustment\Entities\Transfer;

/**
 * Legacy (v1/v2) transfer endpoints -- single-route dispatch preparation,
 * blind receipt, return movements, legacy approval/edit -- reject workflow
 * version 3 documents before any service runs, so no legacy effect can be
 * applied to a v3 document.
 */
class RejectWorkflowV3Transfer
{
    public function handle(Request $request, Closure $next)
    {
        $transfer = $request->route('transfer');

        $version = $transfer instanceof Transfer
            ? (int) $transfer->workflow_version
            : (is_numeric($transfer) ? (int) Transfer::whereKey((int) $transfer)->value('workflow_version') : 0);

        if ($version === Transfer::WORKFLOW_V3) {
            abort(404);
        }

        return $next($request);
    }
}
