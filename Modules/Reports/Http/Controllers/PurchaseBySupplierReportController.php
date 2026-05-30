<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Contracts\Support\Renderable;

class PurchaseBySupplierReportController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(): Renderable
    {
        abort_if(Gate::denies('purchaseReports.access'), 403);

        return view('reports::purchase-by-supplier.index');
    }
}
