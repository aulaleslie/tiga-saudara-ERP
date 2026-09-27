<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Routing\Controller;

class ExpenseListReportController extends Controller
{
    public function index()
    {
        return view('reports::expense-list.index');
    }
}
