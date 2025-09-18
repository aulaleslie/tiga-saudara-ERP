<?php

namespace App\Http\Controllers;

use Modules\Setting\Entities\Setting;

class PricePointController extends Controller
{
    // Public page; later we’ll plug in Livewire & pagination
    public function index(Setting $setting)
    {
        // simple stub view so the button works now
        return view('price-point.index', compact('setting'));
    }
}
