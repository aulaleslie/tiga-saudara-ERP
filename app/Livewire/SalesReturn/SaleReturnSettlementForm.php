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

    public array $replacement_goods = [];

    public $cash_proof;

    public bool $isReadOnly = false;

    protected $listeners = [
        'replacementProductSelected' => 'handleReplacementProductSelected',
    ];

    public function mount(int $saleReturnId): void
    {
        $this->saleReturnId = $saleReturnId;
        $this->loadSaleReturn();
    }

    protected function loadSaleReturn(): void
    {
        $this->saleReturn = SaleReturn::with([
            'saleReturnDetails',
            'saleReturnGoods',
            'customerCredit',
            'saleReturnPayments',
            'sale',
            'location',
        ])->findOrFail($this->saleReturnId);

        $this->isReadOnly = ! empty($this->saleReturn->return_type);
        $this->return_type = $this->saleReturn->return_type
            ? Str::lower($this->saleReturn->return_type)
            : '';

        $this->replacement_goods = [];

        if ($this->saleReturn->saleReturnGoods->isNotEmpty()) {
            $this->replacement_goods = $this->saleReturn->saleReturnGoods->map(function ($good) {
                return [
                    'product_id' => $good->product_id,
                    'product_name' => $good->product_name,
                    'product_code' => $good->product_code,
                    'quantity' => (int) $good->quantity,
                    'unit_value' => (float) $good->unit_value,
                    'sub_total' => (float) $good->sub_total,
                ];
            })->toArray();
        }
    }

    public function addReplacementGood(): void
    {
        if ($this->isReadOnly) {
            return;
        }

        $this->replacement_goods[] = [
            'product_id' => null,
            'product_name' => '',
            'product_code' => '',
            'quantity' => 1,
            'unit_value' => 0.0,
            'sub_total' => 0.0,
        ];
    }

    public function removeReplacementGood(int $index): void
    {
        if ($this->isReadOnly) {
            return;
        }

        if (isset($this->replacement_goods[$index])) {
            unset($this->replacement_goods[$index]);
            $this->replacement_goods = array_values($this->replacement_goods);
        }
    }

    public function recalculateReplacement(int $index): void
    {
        if (! isset($this->replacement_goods[$index])) {
            return;
        }

        $quantity = max(0, (int) ($this->replacement_goods[$index]['quantity'] ?? 0));
        $unitValue = (float) ($this->replacement_goods[$index]['unit_value'] ?? 0);

        $this->replacement_goods[$index]['quantity'] = $quantity;
        $this->replacement_goods[$index]['unit_value'] = $unitValue;
        $this->replacement_goods[$index]['sub_total'] = round($quantity * $unitValue, 2);
    }

    public function handleReplacementProductSelected($payload): void
    {
        if ($this->isReadOnly) {
            return;
        }

        $index = $payload['index'] ?? null;
        $product = $payload['product'] ?? [];

        if ($index === null || ! isset($this->replacement_goods[$index])) {
            return;
        }

        $this->replacement_goods[$index]['product_id'] = $product['id'];
        $this->replacement_goods[$index]['product_name'] = $product['product_name'];
        $this->replacement_goods[$index]['product_code'] = $product['product_code'] ?? '';
        $this->replacement_goods[$index]['unit_value'] = (float) ($product['unit_price'] ?? 0);
        $this->recalculateReplacement($index);
    }

    protected function rules(): array
    {
        return [
            'return_type' => 'required|in:cash,replacement,credit',
            'replacement_goods' => 'array',
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

    protected function calculateCreditAmount(): float
    {
        return round($this->saleReturn->saleReturnDetails->sum(function ($detail) {
            return (float) $detail->unit_price * (int) $detail->quantity;
        }), 2);
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
            'replacement_goods' => $this->replacement_goods,
            'cash_proof' => $this->cash_proof,
        ];

        try {
            $validator = Validator::make($data, $this->rules(), $this->messages());

            $validator->after(function ($validator) {
                if ($this->return_type === 'replacement') {
                    if (empty($this->replacement_goods)) {
                        $validator->errors()->add('replacement_goods', 'Tambahkan setidaknya satu produk pengganti.');
                    } else {
                        foreach ($this->replacement_goods as $idx => $replacement) {
                            if (empty($replacement['product_id'])) {
                                $validator->errors()->add("replacement_goods.$idx.product_id", 'Produk pengganti wajib dipilih.');
                            }

                            if ((int) ($replacement['quantity'] ?? 0) <= 0) {
                                $validator->errors()->add("replacement_goods.$idx.quantity", 'Jumlah pengganti harus lebih dari 0.');
                            }
                        }
                    }
                }

                if ($this->return_type === 'credit' && $this->calculateCreditAmount() <= 0) {
                    $validator->errors()->add('return_type', 'Nilai retur tidak dapat dijadikan kredit.');
                }

                if ($this->return_type === 'cash' && empty($this->cash_proof)) {
                    $validator->errors()->add('cash_proof', 'Unggah bukti pengembalian tunai.');
                }
            });

            $validator->validate();

            $total = $this->settlementTotal();
            $paymentMethod = match ($this->return_type) {
                'replacement' => 'Replacement',
                'credit' => 'Customer Credit',
                default => 'Cash',
            };

            $storedProof = null;

            if ($this->return_type === 'cash' && $this->cash_proof) {
                $storedProof = $this->cash_proof->store('sale-returns/proofs', 'public');
            }

            DB::transaction(function () use ($total, $paymentMethod, $storedProof) {
                $saleReturn = SaleReturn::lockForUpdate()->findOrFail($this->saleReturn->id);

                $saleReturn->saleReturnGoods()->delete();
                $saleReturn->saleReturnPayments()->delete();
                $saleReturn->customerCredit()->delete();

                if ($this->return_type === 'replacement') {
                    $goods = [];

                    foreach ($this->replacement_goods as $replacement) {
                        if (empty($replacement['product_id'])) {
                            continue;
                        }

                        $quantity = (int) ($replacement['quantity'] ?? 0);
                        $unitValue = (float) ($replacement['unit_value'] ?? 0);

                        $goods[] = SaleReturnGood::create([
                            'sale_return_id' => $saleReturn->id,
                            'product_id' => $replacement['product_id'],
                            'product_name' => $replacement['product_name'],
                            'product_code' => $replacement['product_code'] ?? null,
                            'quantity' => $quantity,
                            'unit_value' => $unitValue,
                            'sub_total' => round($quantity * $unitValue, 2),
                        ]);
                    }

                    if (! empty($goods)) {
                        QueueSaleReturnReplacementJob::dispatch($saleReturn->id)->afterCommit();
                    }
                }

                if ($this->return_type === 'credit') {
                    $creditAmount = $this->calculateCreditAmount();

                    CustomerCredit::create([
                        'customer_id' => $saleReturn->customer_id,
                        'sale_return_id' => $saleReturn->id,
                        'amount' => $creditAmount,
                        'remaining_amount' => $creditAmount,
                        'status' => 'open',
                    ]);
                }

                if ($this->return_type === 'cash') {
                    SaleReturnPayment::create([
                        'sale_return_id' => $saleReturn->id,
                        'amount' => $total,
                        'date' => now()->toDateString(),
                        'reference' => 'SRPAY/' . $saleReturn->reference,
                        'payment_method' => 'Cash',
                        'payment_method_id' => null,
                        'note' => 'Pengembalian tunai',
                    ]);
                }

                if ($storedProof && $saleReturn->cash_proof_path) {
                    Storage::disk('public')->delete($saleReturn->cash_proof_path);
                }

                $saleReturn->update([
                    'return_type' => $this->return_type,
                    'payment_status' => 'Paid',
                    'payment_method' => $paymentMethod,
                    'paid_amount' => round($total, 2),
                    'due_amount' => 0,
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
            'creditAmount' => $this->calculateCreditAmount(),
            'isReadOnly' => $this->isReadOnly,
            'displayReturnType' => $this->return_type !== ''
                ? Str::of($this->return_type)->lower()->ucfirst()
                : '',
        ]);
    }
}
