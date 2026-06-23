<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;

class WarehouseStockQuantityReportController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:stockMutationReports.access');
    }

    public function index(): Renderable
    {
        return view('reports::warehouse-stock-quantity-report.index');
    }
}
