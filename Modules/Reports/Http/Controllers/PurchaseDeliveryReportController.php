<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class PurchaseDeliveryReportController extends Controller
{
    public function index()
    {
        abort_if(Gate::denies('purchaseReports.access'), 403);
        return view('reports::purchase-delivery.index');
    }
}
