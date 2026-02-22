<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Modules\Product\Support\ProductCreateValidation;

class StoreProductInfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('products.create');
    }

    public function rules(): array
    {
        $input = $this->all();

        return ProductCreateValidation::rules(array_merge($input, ProductCreateValidation::normalize($input)));
    }

    public function messages(): array
    {
        return ProductCreateValidation::messages();
    }

    /**
     * Normalize common checkbox/boolean inputs so required_if works reliably.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(ProductCreateValidation::normalize($this->all()));
    }

    protected function failedValidation(Validator $validator)
    {
        Log::info('Validation input:', $this->input());
        Log::error('Validation failed', $validator->errors()->toArray());

        throw new HttpResponseException(
            redirect()->back()->withErrors($validator)->withInput()
        );
    }
}
