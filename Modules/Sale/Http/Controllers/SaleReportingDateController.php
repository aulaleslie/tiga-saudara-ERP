<?php

namespace Modules\Sale\Http\Controllers;

use App\Services\ReportingDateOverrideService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Routing\Controller;
use Modules\Sale\Entities\Sale;

class SaleReportingDateController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private ReportingDateOverrideService $service)
    {
    }

    public function store(Request $request, Sale $sale): JsonResponse
    {
        $this->authorize('overrideReportingDate', $sale);

        $validated = $request->validate([
            'reporting_date' => 'required|date',
            'reason' => 'required|string|min:1',
        ]);

        try {
            $audit = $this->service->setOverride(
                $sale,
                $validated['reporting_date'],
                $validated['reason'],
                auth()->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'Tanggal pelaporan berhasil diubah',
                'audit' => $audit,
                'effective_date' => $sale->refresh()->effective_date,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function destroy(Request $request, Sale $sale): JsonResponse
    {
        $this->authorize('overrideReportingDate', $sale);

        $validated = $request->validate([
            'reason' => 'required|string|min:1',
        ]);

        try {
            $audit = $this->service->clearOverride(
                $sale,
                $validated['reason'],
                auth()->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'Tanggal pelaporan berhasil dihapus',
                'audit' => $audit,
                'effective_date' => $sale->refresh()->effective_date,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
