<?php

namespace Modules\Sale\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use Modules\Setting\Entities\PaymentMethod;

class SubmitPosDraftPaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'numeric', 'min:0'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
            'pos_location_assignment_id' => ['nullable', 'integer', 'exists:setting_sale_locations,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $methodIds = collect($this->input('payments', []))
                ->pluck('method_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($methodIds->isEmpty()) {
                return;
            }

            $availableMethodIds = PaymentMethod::query()
                ->whereIn('id', $methodIds)
                ->where('is_available_in_pos', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            foreach ($this->input('payments', []) as $index => $payment) {
                $methodId = (int) data_get($payment, 'method_id');
                if (! in_array($methodId, $availableMethodIds, true)) {
                    $validator->errors()->add("payments.$index.method_id", 'Metode pembayaran tidak tersedia untuk POS.');
                }
            }
        });
    }

    public function authorize(): bool
    {
        return Gate::allows('pos.drafts.submit') || Gate::allows('pos.create');
    }
}
