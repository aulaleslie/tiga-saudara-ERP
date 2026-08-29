<?php

namespace Modules\Sale\Http\Controllers;

use App\DTOs\DateAdjustmentCommand;
use App\Services\DocumentDateAdjustmentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Sale\Entities\Sale;

class SaleDateAdjustmentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private DocumentDateAdjustmentService $service)
    {
    }

    public function update(Request $request, Sale $sale): JsonResponse
    {
        $validated = $request->validate([
            'reporting_action' => 'sometimes|string|in:keep,set,clear',
            'reporting_date' => 'nullable|date',
            'due_date_action' => 'sometimes|string|in:keep,set',
            'due_date' => 'nullable|date',
            'reason' => 'required|string|min:1|max:255',
        ]);

        $command = DateAdjustmentCommand::fromArray($validated);

        try {
            $result = $this->service->adjustDates($sale, $command, auth()->user(), authorize: true);

            return response()->json([
                'success' => true,
                'message' => 'Penyesuaian tanggal berhasil disimpan',
                'effective_date' => $result->document->effective_date,
                'due_date' => $result->document->due_date,
                'reporting_audit' => $result->reportingAudit,
                'due_date_audit' => $result->dueDateAudit,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
