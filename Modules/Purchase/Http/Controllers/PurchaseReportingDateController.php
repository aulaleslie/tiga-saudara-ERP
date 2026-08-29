<?php

namespace Modules\Purchase\Http\Controllers;

use App\Services\ReportingDateOverrideService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Purchase\Entities\Purchase;

class PurchaseReportingDateController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private ReportingDateOverrideService $service)
    {
    }

    public function store(Request $request, Purchase $purchase): JsonResponse
    {
        $this->authorize('overrideReportingDate', $purchase);

        $validated = $request->validate([
            'reporting_date' => 'required|date',
            'reason' => 'required|string|min:1|max:255',
        ]);

        try {
            $audit = $this->service->setOverride(
                $purchase,
                $validated['reporting_date'],
                $validated['reason'],
                auth()->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'Tanggal pelaporan berhasil diubah',
                'audit' => $audit,
                'effective_date' => $purchase->refresh()->effective_date,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function destroy(Request $request, Purchase $purchase): JsonResponse
    {
        $this->authorize('overrideReportingDate', $purchase);

        $validated = $request->validate([
            'reason' => 'required|string|min:1|max:255',
        ]);

        try {
            $audit = $this->service->clearOverride(
                $purchase,
                $validated['reason'],
                auth()->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'Tanggal pelaporan berhasil dihapus',
                'audit' => $audit,
                'effective_date' => $purchase->refresh()->effective_date,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
