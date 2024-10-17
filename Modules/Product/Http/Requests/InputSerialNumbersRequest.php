<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class InputSerialNumbersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Check if the user has permission to create products
        return Gate::allows('create_products');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'serial_numbers' => ['required', 'array'],
            'serial_numbers.*' => ['required', 'string', 'max:255', 'distinct', 'unique:product_serial_numbers,serial_number'], // Ensure uniqueness in the table
            'tax_ids' => ['nullable', 'array'],
            'tax_ids.*' => ['nullable', 'integer', 'exists:taxes,id'], // Validate each tax ID (if provided)
        ];
    }

    /**
     * Customize the error messages.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'location_id.required' => 'Lokasi harus dipilih.',
            'location_id.integer' => 'Lokasi yang dipilih tidak valid.',
            'location_id.exists' => 'Lokasi yang dipilih tidak ditemukan dalam database.',
            'serial_numbers.required' => 'Nomor serial diperlukan.',
            'serial_numbers.array' => 'Nomor serial harus berupa array.',
            'serial_numbers.*.serial_number.required' => 'Setiap nomor serial harus diisi.',
            'serial_numbers.*.serial_number.string' => 'Setiap nomor serial harus berupa string.',
            'serial_numbers.*.serial_number.max' => 'Setiap nomor serial tidak boleh lebih dari 255 karakter.',
            'serial_numbers.*.tax_id.integer' => 'ID pajak harus berupa angka.',
            'serial_numbers.*.tax_id.exists' => 'ID pajak yang dipilih tidak valid.',
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param Validator $validator
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        Log::info('Validation input:', $this->input());
        Log::error('Validation failed', $validator->errors()->toArray());

        throw new HttpResponseException(
            redirect()->back()->withErrors($validator)->withInput()
        );
    }
}
