<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class SalesTaxReportController extends Controller
{
    public function index()
    {
        abort_if(Gate::denies('reports.access'), 403);

        return view('reports::sales-tax-report.index');
    }
}
