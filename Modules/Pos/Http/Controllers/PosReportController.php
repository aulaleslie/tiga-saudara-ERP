<?php

namespace Modules\Pos\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Pos\Services\PosReportingService;
use Modules\Pos\Support\PosExpectedCashVisibility;

class PosReportController extends Controller
{
    public function index(): Renderable
    {
        $settingId = $this->currentSettingId();
        $canViewExpectedCash = PosExpectedCashVisibility::canView(auth()->user());

        return view('pos::reports.index', compact('settingId', 'canViewExpectedCash'));
    }

    public function dailySales(Request $request, PosReportingService $reportingService): JsonResponse
    {
        $this->validateDateParams($request);
        
        $settingId = $this->currentSettingId();
        $rows = $reportingService->getDailySalesSummary(
            $settingId,
            $request->input('date_from'),
            $request->input('date_to')
        );
        
        return response()->json(
            PosExpectedCashVisibility::projectSalesReportRows($rows, auth()->user())
        );
    }

    public function cashierSummary(Request $request, PosReportingService $reportingService): JsonResponse
    {
        $this->validateDateParams($request);
        
        $settingId = $this->currentSettingId();
        $rows = $reportingService->getCashierSummary(
            $settingId,
            $request->input('date_from'),
            $request->input('date_to')
        );
        
        return response()->json(
            PosExpectedCashVisibility::projectSalesReportRows($rows, auth()->user())
        );
    }

    public function paymentMethodSummary(Request $request, PosReportingService $reportingService): JsonResponse
    {
        $this->validateDateParams($request);
        
        $settingId = $this->currentSettingId();
        $rows = $reportingService->getPaymentMethodSummary(
            $settingId,
            $request->input('date_from'),
            $request->input('date_to')
        );
        
        return response()->json(
            PosExpectedCashVisibility::projectPaymentMethodReportRows($rows, auth()->user())
        );
    }

    public function itemSales(Request $request, PosReportingService $reportingService): JsonResponse
    {
        $this->validateDateParams($request);
        
        $settingId = $this->currentSettingId();
        
        return response()->json(
            $reportingService->getItemSalesSummary(
                $settingId,
                $request->input('date_from'),
                $request->input('date_to')
            )
        );
    }

    public function supervisorApprovals(Request $request, PosReportingService $reportingService): JsonResponse
    {
        $this->validateDateParams($request);
        
        $settingId = $this->currentSettingId();
        
        return response()->json(
            $reportingService->getSupervisorApprovalLog(
                $settingId,
                $request->input('date_from'),
                $request->input('date_to')
            )
        );
    }

    private function validateDateParams(Request $request): void
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);
    }

    private function currentSettingId(): int
    {
        $settingId = (int) session('setting_id');

        abort_if($settingId <= 0, 403, 'Setting context is required.');

        return $settingId;
    }
}
