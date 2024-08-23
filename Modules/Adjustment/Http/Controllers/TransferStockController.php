<?php

namespace Modules\Adjustment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

class TransferStockController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('adjustment::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $currentSettingId = session('setting_id');
        $currentSetting = Setting::find($currentSettingId);
        $settings = Setting::where('id', '!=', $currentSettingId)->get();
        $locations = Location::where('setting_id', $currentSettingId)->get();

        return view('adjustment::transfers.create', compact('currentSetting', 'settings', 'locations', 'currentSettingId'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        //
        return redirect()->route('adjustment::transferStock');
    }

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('adjustment::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('adjustment::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): RedirectResponse
    {
        //
        return redirect()->route('adjustment::transferStock');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        //
    }
}
