<?php

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Modules\Setting\Entities\Setting;
use Pusher\PusherException;

if (!function_exists('settings')) {
    function settings() {
        $currentSettingId = session('setting_id');
        $userSettings = session('user_settings');

        // If session is empty, repopulate from DB
        if (empty($userSettings) || !is_iterable($userSettings) || count($userSettings) === 0) {
            if (auth()->check()) {
                $user = auth()->user();

                if ($user->hasRole('Super Admin')) {
                    $userSettings = Setting::orderBy('id')->get();
                } else {
                    $userSettings = $user->settings()->orderBy('id')->get();
                }

                session(['user_settings' => $userSettings]);

                // Set current setting ID if not present
                if (!$currentSettingId && $userSettings->isNotEmpty()) {
                    $currentSettingId = $userSettings->first()->id;
                    session(['setting_id' => $currentSettingId]);
                }
            }
        }

        if (!$currentSettingId) {
            return null;
        }

        $cacheKey = 'settings_' . $currentSettingId;

        $settings = cache()->remember($cacheKey, 24 * 60, function () use ($currentSettingId) {
            return Setting::findOrFail($currentSettingId);
        });

        return $settings;
    }
}

if (!function_exists('format_currency')) {
    function format_currency($value, $format = true) {
        if (!$format) {
            return $value;
        }

        $settings = settings();
        $position = $settings->default_currency_position;
        $symbol = $settings->currency->symbol;
        $decimal_separator = $settings->currency->decimal_separator;
        $thousand_separator = $settings->currency->thousand_separator;

        if ($position == 'prefix') {
            $formatted_value = $symbol . number_format((float) $value, 2, $decimal_separator, $thousand_separator);
        } else {
            $formatted_value = number_format((float) $value, 2, $decimal_separator, $thousand_separator) . $symbol;
        }

        return $formatted_value;
    }
}

if (!function_exists('make_reference_id')) {
    function make_reference_id($prefix, $year, $month, $number): string
    {
        return $prefix . '-' . $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . str_pad($number, 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('array_merge_numeric_values')) {
    function array_merge_numeric_values(): array
    {
        $arrays = func_get_args();
        $merged = array();
        foreach ($arrays as $array) {
            foreach ($array as $key => $value) {
                if (!is_numeric($value)) {
                    continue;
                }
                if (!isset($merged[$key])) {
                    $merged[$key] = $value;
                } else {
                    $merged[$key] += $value;
                }
            }
        }

        return $merged;
    }
}

if (!function_exists('trigger_pusher_event')) {
    /**
     * @throws PusherException
     * @throws GuzzleException
     */
    function trigger_pusher_event(string $channel, string $event, array $data = []): void
    {
        Log::info('ENV', [
            'key' => env('PUSHER_APP_KEY', 'local'),
            'secret' => env('PUSHER_APP_SECRET', 'secret'),
            'app_id' => env('PUSHER_APP_ID', 'id'),
        ]);

        $pusher = new Pusher\Pusher(
            env('PUSHER_APP_KEY', '6708cf03d8d7ea319989'),
            env('PUSHER_APP_SECRET', 'e73a192d371f566811cc'),
            env('PUSHER_APP_ID', '1927651'),
            [
                'useTLS' => env('PUSHER_APP_USETLS', false),
                'cluster' => env('PUSHER_APP_CLUSTER', 'ap1'),
            ]
        );

        try {
            $pusher->trigger($channel, $event, $data);
        } catch (Exception $e) {
            // Log the error for debugging purposes
            Log::error("Failed to trigger Pusher event: " . $e->getMessage());
        }
    }
}

if (!function_exists('terbilang')) {
    function terbilang($angka)
    {
        $angka = abs($angka);
        $baca = ["", "Satu", "Dua", "Tiga", "Empat", "Lima", "Enam", "Tujuh", "Delapan", "Sembilan", "Sepuluh", "Sebelas"];
        $hasil = "";
        if ($angka < 12) {
            $hasil = " " . $baca[$angka];
        } else if ($angka < 20) {
            $hasil = terbilang($angka - 10) . " Belas ";
        } else if ($angka < 100) {
            $hasil = terbilang($angka / 10) . " Puluh " . terbilang($angka % 10);
        } else if ($angka < 200) {
            $hasil = " Seratus " . terbilang($angka - 100);
        } else if ($angka < 1000) {
            $hasil = terbilang($angka / 100) . " Ratus " . terbilang($angka % 100);
        } else if ($angka < 2000) {
            $hasil = " Seribu " . terbilang($angka - 1000);
        } else if ($angka < 1000000) {
            $hasil = terbilang($angka / 1000) . " Ribu " . terbilang($angka % 1000);
        } else if ($angka < 1000000000) {
            $hasil = terbilang($angka / 1000000) . " Juta " . terbilang($angka % 1000000);
        }
        return trim($hasil);
    }
}

