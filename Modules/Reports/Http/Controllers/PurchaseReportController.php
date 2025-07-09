<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Routing\Controller;

class PurchaseReportController extends Controller
{
    public function index()
    {
        return view('reports::purchase-report.index');
    }
}
