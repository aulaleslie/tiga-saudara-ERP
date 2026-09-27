<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Routing\Controller;

class SupplierPayablesReportController extends Controller
{
    public function index()
    {
        return view('reports::supplier-payables.index');
    }
}
