<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class StockMutationReportController extends Controller
{
    public function index()
    {
        abort_if(Gate::denies('stockMutationReports.access'), 403);
        return view('reports::stock-mutation-report.index');
    }
}
