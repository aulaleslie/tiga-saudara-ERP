<?php

namespace Modules\Setting\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Http\Requests\StoreSettingsRequest;
use Modules\Setting\Http\Requests\StoreSmtpSettingsRequest;

class SettingController extends Controller
{

    public function index() {
        abort_if(Gate::denies('settings.access'), 403);

        $currentSettingId = session('setting_id');
        $settings = Setting::findOrFail($currentSettingId);

        return view('setting::index', compact('settings'));
    }


    public function update(StoreSettingsRequest $request)
    {
        abort_if(Gate::denies('settings.edit'), 403);

        $settingId = session('setting_id');
        $setting   = Setting::findOrFail($settingId);

        $data = [
            'company_name'             => $request->company_name,
            'company_email'            => $request->company_email,
            'company_phone'            => $request->company_phone,
            'company_address'          => $request->company_address,
            'document_prefix'          => $request->document_prefix,
            'purchase_prefix_document' => $request->purchase_prefix_document,
            'sale_prefix_document'     => $request->sale_prefix_document,
            'pos_document_prefix'      => $request->pos_document_prefix,
            'pos_idle_threshold_minutes' => $request->pos_idle_threshold_minutes,
            'pos_default_cash_threshold' => $request->pos_default_cash_threshold,
        ];

        // Uppercase text-type columns
        foreach ([
                     'company_name',
                     'company_address',
                     'document_prefix',
                     'purchase_prefix_document',
                     'sale_prefix_document',
                     'pos_document_prefix',
                 ] as $key) {
            if (isset($data[$key])) {
                $data[$key] = mb_strtoupper(trim((string) $data[$key]), 'UTF-8');
            }
        }

        // Normalize email (lowercase) & trim phone
        if (isset($data['company_email'])) {
            $data['company_email'] = trim(mb_strtolower($data['company_email'], 'UTF-8'));
        }
        if (isset($data['company_phone'])) {
            $data['company_phone'] = trim((string) $data['company_phone']);
        }

        $data['pos_idle_threshold_minutes'] = max(0, (int) ($data['pos_idle_threshold_minutes'] ?? 0));
        $data['pos_default_cash_threshold'] = $data['pos_default_cash_threshold'] !== null
            ? round((float) $data['pos_default_cash_threshold'], 2)
            : 0.0;

        // Persist
        $setting->update($data);
        $setting = $setting->fresh();

        // --- Sync the in-session "user_settings" list (if present)
        if (session()->has('user_settings')) {
            $fieldsToSync = [
                'id',
                'company_name',
                'company_email',
                'company_phone',
                'company_address',
                'document_prefix',
                'purchase_prefix_document',
                'sale_prefix_document',
                'pos_document_prefix',
                'pos_idle_threshold_minutes',
                'pos_default_cash_threshold',
            ];

            $current = session('user_settings');
            $coll    = $current instanceof \Illuminate\Support\Collection ? $current : collect($current);

            $updated = $coll->map(function ($item) use ($setting, $fieldsToSync) {
                if ((string) data_get($item, 'id') !== (string) $setting->id) {
                    return $item;
                }

                // Preserve original shape
                if ($item instanceof \Illuminate\Database\Eloquent\Model) {
                    return $setting; // replace with the freshly updated model
                }

                if (is_array($item)) {
                    return array_replace($item, $setting->only($fieldsToSync));
                }

                // stdClass / other objects
                foreach ($setting->only($fieldsToSync) as $k => $v) {
                    $item->{$k} = $v;
                }
                return $item;
            });

            session(['user_settings' => $updated]);
        }

        // Bust cache and finish
        cache()->forget('settings');
        toast('Settings Updated!', 'info');

        return redirect()->route('settings.index');
    }


    public function updateSmtp(StoreSmtpSettingsRequest $request) {
        abort_if(Gate::denies('settings.edit'), 403);
        $toReplace = array(
            'MAIL_MAILER='.env('MAIL_HOST'),
            'MAIL_HOST="'.env('MAIL_HOST').'"',
            'MAIL_PORT='.env('MAIL_PORT'),
            'MAIL_FROM_ADDRESS="'.env('MAIL_FROM_ADDRESS').'"',
            'MAIL_FROM_NAME="'.env('MAIL_FROM_NAME').'"',
            'MAIL_USERNAME="'.env('MAIL_USERNAME').'"',
            'MAIL_PASSWORD="'.env('MAIL_PASSWORD').'"',
            'MAIL_ENCRYPTION="'.env('MAIL_ENCRYPTION').'"'
        );

        $replaceWith = array(
            'MAIL_MAILER='.$request->mail_mailer,
            'MAIL_HOST="'.$request->mail_host.'"',
            'MAIL_PORT='.$request->mail_port,
            'MAIL_FROM_ADDRESS="'.$request->mail_from_address.'"',
            'MAIL_FROM_NAME="'.$request->mail_from_name.'"',
            'MAIL_USERNAME="'.$request->mail_username.'"',
            'MAIL_PASSWORD="'.$request->mail_password.'"',
            'MAIL_ENCRYPTION="'.$request->mail_encryption.'"');

        try {
            file_put_contents(base_path('.env'), str_replace($toReplace, $replaceWith, file_get_contents(base_path('.env'))));
            Artisan::call('cache:clear');

            toast('Mail Settings Updated!', 'info');
        } catch (\Exception $exception) {
            Log::error($exception);
            session()->flash('settings_smtp_message', 'Something Went Wrong!');
        }

        return redirect()->route('settings.index');
    }
}
