<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class InventorySummaryReportController extends Controller
{
    public function index()
    {
        abort_if(Gate::denies('inventoryValuationReports.access'), 403);

        return view('reports::inventory-summary-report.index');
    }
}
