<?php

namespace Modules\Setting\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Modules\Currency\Entities\Currency;
use Modules\Setting\DataTables\BusinessDataTable;
use Modules\Setting\Entities\Setting;

class BusinessController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(BusinessDataTable $dataTable)
    {

        abort_if(Gate::denies('businesses.access'), 403);

        return $dataTable->render('setting::businesses.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        abort_if(Gate::denies('businesses.create'), 403);

        return view('setting::businesses.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        abort_if(Gate::denies('businesses.create'), 403);

        $currentYear = date("Y");
        $currency    = Currency::first();
        $currencyId  = $request->default_currency_id ?: optional($currency)->id;

        $data = $this->normalizeData($request, $currencyId, $currentYear);

        Setting::create($data);

        // Refresh session user_settings
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
        abort_if(Gate::denies('businesses.show'), 403);
        return view('setting::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Setting $business)
    {
        abort_if(Gate::denies('businesses.edit'), 403);

        return view('setting::businesses.edit', compact('business'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Setting $business)
    {
        abort_if(Gate::denies('businesses.edit'), 403);

        $currency   = Currency::first();
        $currencyId = $request->default_currency_id ?: optional($currency)->id;
        $currentYear = date("Y");

        $data = $this->normalizeData($request, $currencyId, $currentYear);

        $business->update($data);

        // Refresh session user_settings
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
        abort_if(Gate::denies('businesses.delete'), 403);

        // Lindungi bisnis utama
        if ($business->id == 1) {
            toast('Bisnis Utama Tidak Dapat Dihapus', 'warning');
            return redirect()->back();
        }

        $user = Auth::user();
        $wasActive = session('setting_id') == $business->id;

        // Hapus cache setting lama (kalau ada)
        cache()->forget('settings_' . $business->id);

        // Hapus bisnisnya dulu
        $business->delete();

        // Ambil ulang daftar bisnis setelah delete
        if ($user->hasRole('Super Admin')) {
            $userSettings = Setting::orderBy('id')->get();
        } else {
            // Jika relasi user-settings adalah many-to-many, ini otomatis exclude yg sudah terhapus
            $userSettings = $user->settings()->orderBy('id')->get();
        }

        // Pastikan session user_settings diperbarui
        session(['user_settings' => $userSettings]);

        // Jika bisnis yang dihapus adalah yang sedang aktif, pilih fallback & refresh cache/role
        if ($wasActive) {
            // fallback: pakai bisnis pertama yang tersisa (id 1 tidak bisa dihapus, jadi aman)
            $newActive = optional($userSettings->first())->id;

            if ($newActive) {
                session(['setting_id' => $newActive]);

                // refresh cache setting aktif
                cache()->forget('settings_' . $newActive);
                $settings = Setting::findOrFail($newActive);
                cache()->put('settings_' . $newActive, $settings, 24 * 60);

                // perbarui role sesuai bisnis aktif
                $role = $user->getCurrentSettingRole();
                if ($role) {
                    $user->syncRoles([$role->name]);
                }
            } else {
                // Guard ekstra kalau benar-benar tidak ada bisnis tersisa (seharusnya tidak terjadi)
                session()->forget('setting_id');
            }
        }

        toast('Bisnis Telah Dihapus!', 'success');
        return redirect()->route('businesses.index');
    }

    public function updateActiveBusiness(Request $request): RedirectResponse
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

    /**
     * @param Request $request
     * @param mixed $currencyId
     * @param string $currentYear
     * @return array
     */
    public function normalizeData(Request $request, mixed $currencyId, string $currentYear): array
    {
        $data = [
            'company_name' => $request->company_name,
            'company_email' => $request->company_email,
            'company_phone' => $request->company_phone,
            'notification_email' => $request->company_email,
            'company_address' => $request->company_address,
            'default_currency_id' => $currencyId,
            'default_currency_position' => 'prefix',
            'document_prefix' => $request->document_prefix,
            'purchase_prefix_document' => $request->purchase_prefix_document,
            'sale_prefix_document' => $request->sale_prefix_document,
            // footer_text will be set after uppercasing company_name
        ];

        // Uppercase user-typed text fields
        foreach ([
                     'company_name',
                     'company_address',
                     'document_prefix',
                     'purchase_prefix_document',
                     'sale_prefix_document',
                 ] as $key) {
            if (isset($data[$key]) && $data[$key] !== null) {
                $data[$key] = mb_strtoupper(trim((string)$data[$key]), 'UTF-8');
            }
        }

        // Normalize emails (lowercase) & trim phone
        foreach (['company_email', 'notification_email'] as $ek) {
            if (isset($data[$ek])) {
                $data[$ek] = trim(mb_strtolower($data[$ek], 'UTF-8'));
            }
        }
        if (isset($data['company_phone'])) {
            $data['company_phone'] = trim((string)$data['company_phone']);
        }

        // Keep footer_text in sync with company_name on updates
        $data['footer_text'] = sprintf('%s © %s', $data['company_name'] ?? '', $currentYear);
        return $data;
    }
}
