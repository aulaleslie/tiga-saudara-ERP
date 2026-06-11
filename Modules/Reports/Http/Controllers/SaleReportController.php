<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Customer;

class SaleReportController extends Controller
{
    public function index()
    {
        abort_if(Gate::denies('saleReports.access'), 403);

        return view('reports::sale-report.index', [
            'isGlobal' => false,
        ]);
    }

    public function indexGlobal()
    {
        abort_if(Gate::denies('saleReports.global.access'), 403);

        return view('reports::sale-report.index', [
            'isGlobal' => true,
        ]);
    }
}
