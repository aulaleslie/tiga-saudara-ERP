<?php

namespace Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CrossBusinessStockInventoryReportController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('inventory.view_remaining_stock');

        return view('reports::cross-business-stock-inventory.index');
    }
}
