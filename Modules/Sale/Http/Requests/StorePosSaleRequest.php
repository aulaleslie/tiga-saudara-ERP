<?php

namespace Modules\Sale\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use Modules\Setting\Entities\PaymentMethod;

class StorePosSaleRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'customer_id' => 'required|numeric',
            'tax_percentage' => 'required|integer|min:0|max:100',
            'discount_percentage' => 'required|integer|min:0|max:100',
            'shipping_amount' => 'nullable|numeric',
            'total_amount' => 'required|numeric',
            'paid_amount' => 'required|numeric',
            'payments' => 'required|array|min:1',
            'payments.*.method_id' => 'required|integer',
            'payments.*.amount' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:1000',
            'pos_location_assignment_id' => 'nullable|integer',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $paymentsInput = collect($this->input('payments', []));

            if ($paymentsInput->isEmpty()) {
                $validator->errors()->add('payments', 'Setidaknya satu pembayaran diperlukan.');
                return;
            }

            $payments = $paymentsInput->map(function ($payment) {
                return [
                    'method_id' => (int) data_get($payment, 'method_id'),
                    'amount' => (float) data_get($payment, 'amount', 0),
                ];
            });

            $methodIds = $payments->pluck('method_id')->filter()->unique()->all();

            $methods = PaymentMethod::query()
                ->whereIn('id', $methodIds)
                ->get()
                ->keyBy('id');

            $totalAmount = round((float) $this->input('total_amount', 0), 2);
            $declaredPaidAmount = round((float) $this->input('paid_amount', 0), 2);
            $runningBalance = $totalAmount;
            $totalPayments = 0.0;
            $hasCashPayment = false;

            foreach ($payments as $index => $payment) {
                $methodId = $payment['method_id'];
                $method = $methods->get($methodId);

                if (! $method || ! $method->is_available_in_pos) {
                    $validator->errors()->add("payments.$index.method_id", 'Metode pembayaran tidak tersedia untuk POS.');
                    continue;
                }

                $amount = round((float) $payment['amount'], 2);
                $totalPayments += $amount;

                if ($amount < 0) {
                    $validator->errors()->add("payments.$index.amount", 'Jumlah pembayaran tidak boleh bernilai negatif.');
                    continue;
                }

                if (! $method->is_cash && $amount > $runningBalance + 0.00001) {
                    $validator->errors()->add("payments.$index.amount", 'Pembayaran non-tunai tidak boleh melebihi sisa tagihan.');
                }

                if ($method->is_cash) {
                    $hasCashPayment = $hasCashPayment || $amount > 0;
                }

                $runningBalance = round($runningBalance - $amount, 2);

                if ($runningBalance < 0 && ! $method->is_cash) {
                    $validator->errors()->add("payments.$index.amount", 'Kelebihan pembayaran hanya diperbolehkan menggunakan tunai.');
                }

                if ($runningBalance < 0) {
                    $runningBalance = 0.0;
                }
            }

            $totalPayments = round($totalPayments, 2);

            if (abs($declaredPaidAmount - $totalPayments) > 0.01) {
                $validator->errors()->add('paid_amount', 'Jumlah pembayaran tidak konsisten dengan rincian pembayaran.');
            }

            if ($totalPayments > $totalAmount + 0.01 && ! $hasCashPayment) {
                $validator->errors()->add('payments', 'Kelebihan pembayaran hanya diperbolehkan jika terdapat entri pembayaran tunai.');
            }
        });
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return Gate::allows('pos.create');
    }
}
