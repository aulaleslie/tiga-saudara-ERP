<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Routing\Controller;

class SoldSerialNumberReportController extends Controller
{
    public function index(): Renderable
    {
        return view('reports::sold-serial-number.index');
    }
}
