<?php

use Modules\Setting\Entities\Setting;

if (!function_exists('settings')) {
    function settings() {
        // Get the default setting ID from the session
        $currentSettingId = session('setting_id');

        // Ensure the setting ID exists in the session
        if (!$currentSettingId) {
            return null; // or handle the case where no setting ID is available
        }

        // Use the default setting ID as part of the cache key
        $cacheKey = 'settings_' . $currentSettingId;

        // Retrieve settings from the cache or fetch and cache them
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
