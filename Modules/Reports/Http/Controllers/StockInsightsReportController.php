<?php

namespace Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StockInsightsReportController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('stockInsights.access');

        return view('reports::stock-insights.index');
    }
}
