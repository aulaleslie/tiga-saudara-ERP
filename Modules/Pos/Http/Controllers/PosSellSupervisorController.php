<?php

namespace Modules\Pos\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Pos\Services\PosSupervisorSearchService;

class PosSellSupervisorController extends Controller
{
    public function search(Request $request, PosSupervisorSearchService $searchService): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $settingId = (int) session('setting_id');
        abort_if($settingId <= 0, 403, 'Setting context is required.');

        $payload = $searchService->search(
            $settingId,
            (string) $validated['q'],
            (int) ($validated['limit'] ?? 10)
        );

        return response()->json($payload);
    }
}
