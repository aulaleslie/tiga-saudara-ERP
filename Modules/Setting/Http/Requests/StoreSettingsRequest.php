<?php

namespace Modules\Setting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class StoreSettingsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'company_name' => 'required|string|max:255|unique:settings,company_name',
            'company_email' => 'required|email|max:255',
            'company_phone' => 'required|string|max:255',
            'document_prefix' => 'required|string|max:255',
            'purchase_prefix_document' => 'required|string|max:255',
            'sale_prefix_document' => 'required|string|max:255',
            'pos_document_prefix' => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    if (!empty($value)) {
                        $exists = DB::table('settings')
                            ->where('pos_document_prefix', $value)
                            ->exists();
                        if ($exists) {
                            $fail('Prefix dokumen POS sudah digunakan.');
                        }
                    }
                }
            ],
            'company_address' => 'required|string|max:500',
            'footer_text' => 'nullable|string|max:255',
            'pos_idle_threshold_minutes' => 'nullable|integer|min:0|max:1440',
            'pos_default_cash_threshold' => 'nullable|numeric|min:0',
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return Gate::allows('settings.access');
    }
}
