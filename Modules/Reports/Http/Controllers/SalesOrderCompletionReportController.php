<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class SalesOrderCompletionReportController extends Controller
{
    public function index()
    {
        abort_if(Gate::denies('saleReports.access'), 403);

        return view('reports::sales-order-completion.index');
    }
}
