<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class PurchaseOrderCompletionReportController extends Controller
{
    public function index()
    {
        abort_if(Gate::denies('purchaseReports.access'), 403);

        return view('reports::purchase-order-completion.index');
    }
}
