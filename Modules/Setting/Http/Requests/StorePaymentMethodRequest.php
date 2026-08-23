<?php

namespace Modules\Setting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Setting\Entities\PaymentMethod;

class StorePaymentMethodRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return Gate::allows('paymentMethods.create');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'name' => 'required|string|max:255|unique:payment_methods,name',
            'coa_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('chart_of_accounts', 'id')->where('is_active', true),
            ],
            'requires_reference' => 'nullable|boolean',
            'is_cash' => [
                'nullable',
                'boolean',
                function ($attribute, $value, $fail) {
                    if ($value && PaymentMethod::where('is_cash', true)->exists()) {
                        $fail(__('A cash payment method already exists. You must disable the existing one before designating another.'));
                    }
                },
            ],
        ];
    }
}
