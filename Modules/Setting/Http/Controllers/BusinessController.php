<?php

namespace Modules\Setting\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Modules\Setting\DataTables\BusinessDataTable;
use Modules\Setting\Entities\Setting;

class BusinessController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(BusinessDataTable $dataTable)
    {
        abort_if(Gate::denies('access_settings'), 403);

        return $dataTable->render('setting::businesses.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        abort_if(Gate::denies('access_settings'), 403);

        return view('setting::businesses.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $currentYear = date("Y");
        $footer_text = "$request->company_name © $currentYear";
        Setting::create([
            'company_name' => $request->company_name,
            'company_email' => $request->company_email,
            'company_phone' => $request->company_phone,
            'notification_email' => $request->company_email,
            'company_address' => $request->company_address,
            'default_currency_id' => $request->default_currency_id,
            'default_currency_position' => $request->default_currency_position,
            'footer_text' => $footer_text,
        ]);

        if (auth()->user()->hasRole('Super Admin')) {
            $userSettings = Setting::orderBy('id')->get();
        } else {
            $userSettings = auth()->user()->settings()->orderBy('id')->get();
        }

        session(['user_settings' => $userSettings]);

        toast('Bisnis Telah Dibuat!', 'success');

        return redirect()->route('businesses.index');
    }

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('setting::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Setting $business)
    {
        abort_if(Gate::denies('access_settings'), 403);

        return view('setting::businesses.edit', compact('business'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Setting $business)
    {
       $business->update([
            'company_name' => $request->company_name,
            'company_email' => $request->company_email,
            'company_phone' => $request->company_phone,
            'notification_email' => $request->company_email,
            'company_address' => $request->company_address,
            'default_currency_id' => $request->default_currency_id,
            'default_currency_position' => $request->default_currency_position,
        ]);

        if (auth()->user()->hasRole('Super Admin')) {
            $userSettings = Setting::orderBy('id')->get();
        } else {
            $userSettings = auth()->user()->settings()->orderBy('id')->get();
        }

        session(['user_settings' => $userSettings]);

        toast('Informasi Bisnis Telah Berhasil Diubah!', 'info');

        return redirect()->route('businesses.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Setting $business): RedirectResponse
    {
        abort_if(Gate::denies('access_settings'), 403);
        // Check if the setting ID is 1
        if ($business->id == 1) {
            // Toast a warning message
            toast('Bisnis Utama Tidak Dapat Dihapus', 'warning');

            // Redirect back to the previous page
            return redirect()->back();
        }

        if (auth()->user()->hasRole('Super Admin')) {
            $userSettings = Setting::orderBy('id')->get();
        } else {
            $userSettings = auth()->user()->settings()->orderBy('id')->get();
        }

        session(['user_settings' => $userSettings]);

        // Delete the setting
        $business->delete();

        // Toast a success message
        toast('Bisnis Telah Dihapus!', 'success');

        // Redirect to the settings index page
        return redirect()->route('businesses.index');
    }

    public function updateActiveBusiness(Request $request)
    {
        $settingId = $request->input('setting_id');

        // Update the session with the new setting ID
        $request->session()->put('setting_id', $settingId);

        // Refresh the settings cache
        cache()->forget('settings_' . $settingId);
        $settings = Setting::findOrFail($settingId);
        cache()->put('settings_' . $settingId, $settings, 24 * 60);

        $user = Auth::user();

        // Assign the role for the new setting
        $role = $user->getCurrentSettingRole();
        if ($role) {
            $user->syncRoles([$role->name]);
        }

        // Redirect back to the previous page
        return redirect()->back();
    }
}
