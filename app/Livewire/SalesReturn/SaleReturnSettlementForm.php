<?php

namespace App\Livewire\SalesReturn;

use Exception;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\SalesReturn\Entities\CustomerCredit;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnGood;
use Modules\SalesReturn\Entities\SaleReturnPayment;
use Modules\SalesReturn\Jobs\QueueSaleReturnReplacementJob;

class SaleReturnSettlementForm extends Component
{
    use WithFileUploads;

    public SaleReturn $saleReturn;

    public int $saleReturnId;

    public string $return_type = '';

    public $cash_proof;

    public bool $isReadOnly = false;

    public function mount(int $saleReturnId): void
    {
        $this->saleReturnId = $saleReturnId;
        $this->loadSaleReturn();
    }

    protected function loadSaleReturn(): void
    {
        $this->saleReturn = SaleReturn::with([
            'saleReturnDetails',
            'saleReturnPayments',
            'sale',
            'location',
        ])->findOrFail($this->saleReturnId);

        $this->isReadOnly = ! empty($this->saleReturn->return_type);
        $this->return_type = $this->saleReturn->return_type
            ? Str::lower($this->saleReturn->return_type)
            : '';
    }

    protected function rules(): array
    {
        return [
            'return_type' => 'required|in:cash_refund,repair,unprocessed',
            'cash_proof' => 'nullable|file|max:4096|mimes:jpg,jpeg,png,pdf',
        ];
    }

    protected function messages(): array
    {
        return [
            'return_type.required' => 'Pilih metode penyelesaian.',
            'return_type.in' => 'Metode penyelesaian tidak valid.',
            'cash_proof.file' => 'Bukti pengembalian harus berupa berkas.',
            'cash_proof.mimes' => 'Format bukti tidak didukung.',
            'cash_proof.max' => 'Ukuran bukti maksimal 4MB.',
        ];
    }

    protected function settlementTotal(): float
    {
        return round((float) $this->saleReturn->total_amount, 2);
    }

    public function submit()
    {
        if ($this->isReadOnly) {
            session()->flash('info', 'Metode penyelesaian sudah ditentukan.');
            return null;
        }

        $data = [
            'return_type' => $this->return_type,
            'cash_proof' => $this->cash_proof,
        ];

        try {
            $validator = Validator::make($data, $this->rules(), $this->messages());

            $validator->after(function ($validator) {
                if ($this->return_type === 'cash_refund' && empty($this->cash_proof)) {
                    $validator->errors()->add('cash_proof', 'Unggah bukti pengembalian tunai.');
                }
            });

            $validator->validate();

            $total = $this->settlementTotal();
            $paymentMethod = match ($this->return_type) {
                'cash_refund' => 'Cash Refund',
                'repair' => 'Repair',
                'unprocessed' => 'Unprocessed',
                default => 'Other',
            };

            DB::transaction(function () use ($total, $paymentMethod) {
                $saleReturn = SaleReturn::lockForUpdate()->findOrFail($this->saleReturn->id);

                $oldProofPath = $saleReturn->cash_proof_path;
                $storedProof = null;

                // Upload file inside transaction to ensure rollback on failure
                if ($this->return_type === 'cash_refund' && $this->cash_proof) {
                    $storedProof = $this->cash_proof->store('sale-returns/proofs', 'public');
                }

                // Delete potential legacy artifacts if re-submitting (though isReadOnly usually prevents this)
                $saleReturn->saleReturnGoods()->delete();
                $saleReturn->saleReturnPayments()->delete();
                $saleReturn->customerCredit()->delete();

                if ($this->return_type === 'cash_refund') {
                    SaleReturnPayment::create([
                        'sale_return_id' => $saleReturn->id,
                        'amount' => $total,
                        'date' => now()->toDateString(),
                        'reference' => 'SRPAY/' . $saleReturn->reference,
                        'payment_method' => 'Cash Refund',
                        'note' => 'Pengembalian tunai',
                    ]);
                }

                // Delete old proof file after successful update
                if ($storedProof && $oldProofPath) {
                    Storage::disk('public')->delete($oldProofPath);
                }

                $saleReturn->update([
                    'return_type' => $this->return_type,
                    'payment_status' => $this->return_type === 'cash_refund' ? 'Paid' : 'Unpaid',
                    'payment_method' => $paymentMethod,
                    'paid_amount' => $this->return_type === 'cash_refund' ? round($total, 2) : 0,
                    'due_amount' => $this->return_type === 'cash_refund' ? 0 : round($total, 2),
                    'cash_proof_path' => $storedProof ?? $saleReturn->cash_proof_path,
                    'status' => 'Completed',
                    'settled_at' => now(),
                    'settled_by' => Auth::id(),
                ]);
            });

            session()->flash('success', 'Metode penyelesaian berhasil disimpan.');
            return redirect()->route('sale-returns.show', $this->saleReturn->id);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Failed to save sale return settlement', [
                'sale_return_id' => $this->saleReturn->id,
                'message' => $e->getMessage(),
            ]);
            session()->flash('error', 'Terjadi kesalahan saat menyimpan metode penyelesaian.');
        }

        $this->loadSaleReturn();

        return null;
    }

    public function render(): Factory|Application|View
    {
        return view('livewire.sales-return.sale-return-settlement-form', [
            'saleReturn' => $this->saleReturn,
            'details' => $this->saleReturn->saleReturnDetails,
            'total' => $this->settlementTotal(),
            'isReadOnly' => $this->isReadOnly,
            'displayReturnType' => $this->return_type !== ''
                ? Str::of($this->return_type)->replace('_', ' ')->lower()->ucfirst()
                : '',
        ]);
    }
}
