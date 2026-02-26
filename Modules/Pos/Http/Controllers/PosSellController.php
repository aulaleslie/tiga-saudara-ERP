<?php

namespace Modules\Pos\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Routing\Controller;

class PosSellController extends Controller
{
    public function index(): Renderable
    {
        return view('pos::sell');
    }
}
