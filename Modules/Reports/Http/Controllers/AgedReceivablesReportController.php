<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Routing\Controller;

class AgedReceivablesReportController extends Controller
{
    public function index()
    {
        return view('reports::aged-receivables.index');
    }
}
