<?php

namespace App\Http\Controllers;

use Modules\Setting\Entities\Setting;

class PricePointController extends Controller
{
    /**
     * Display the Terminal Harga page for the currently active business.
     */
    public function index()
    {
        $settingId = session('setting_id');

        abort_unless($settingId, 403, 'No active business selected.');

        $setting = Setting::findOrFail($settingId);

        return view('price-point.index', compact('setting'));
    }
}
