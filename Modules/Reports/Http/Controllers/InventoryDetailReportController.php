<?php

namespace Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InventoryDetailReportController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('stockMutationReports.access');

        return view('reports::inventory-detail-report.index');
    }
}
