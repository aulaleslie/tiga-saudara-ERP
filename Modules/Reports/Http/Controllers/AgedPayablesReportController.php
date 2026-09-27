<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Routing\Controller;

class AgedPayablesReportController extends Controller
{
    public function index()
    {
        return view('reports::aged-payables.index');
    }
}
