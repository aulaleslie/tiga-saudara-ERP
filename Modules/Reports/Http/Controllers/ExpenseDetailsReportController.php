<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Routing\Controller;

class ExpenseDetailsReportController extends Controller
{
    public function index()
    {
        return view('reports::expense-details.index');
    }
}
