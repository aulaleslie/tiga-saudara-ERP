<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Routing\Controller;

class CustomerReceivablesReportController extends Controller
{
    public function index()
    {
        return view('reports::customer-receivables.index');
    }
}
