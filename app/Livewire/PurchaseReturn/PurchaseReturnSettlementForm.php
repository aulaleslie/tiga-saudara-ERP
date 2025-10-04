<?php

namespace App\Livewire\PurchaseReturn;

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
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnGood;
use Modules\PurchasesReturn\Entities\PurchaseReturnPayment;
use Modules\PurchasesReturn\Entities\SupplierCredit;

class PurchaseReturnSettlementForm extends Component
{
    use WithFileUploads;

    public PurchaseReturn $purchaseReturn;

    public int $purchaseReturnId;

    public string $return_type = '';

    public array $replacement_goods = [];

    public $cash_proof;

    public bool $isReadOnly = false;

    protected $listeners = [
        'replacementProductSelected' => 'handleReplacementProductSelected',
    ];

    public function mount(int $purchaseReturnId): void
    {
        $this->purchaseReturnId = $purchaseReturnId;
        $this->loadPurchaseReturn();
    }

    protected function loadPurchaseReturn(): void
    {
        $this->purchaseReturn = PurchaseReturn::with([
            'purchaseReturnDetails',
            'goods',
            'supplierCredit',
            'purchaseReturnPayments',
            'supplier',
            'location',
        ])->findOrFail($this->purchaseReturnId);

        $this->isReadOnly = ! empty($this->purchaseReturn->return_type);
        $this->return_type = $this->purchaseReturn->return_type
            ? Str::lower($this->purchaseReturn->return_type)
            : '';
        $this->replacement_goods = [];

        if ($this->purchaseReturn->goods->isNotEmpty()) {
            $this->replacement_goods = $this->purchaseReturn->goods->map(function ($good) {
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
        $this->replacement_goods[$index]['unit_value'] = (float) ($product['last_purchase_price'] ?? 0);
        $this->recalculateReplacement($index);
    }

    protected function rules(): array
    {
        return [
            'return_type' => 'required|in:exchange,deposit,cash',
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
        return round($this->purchaseReturn->purchaseReturnDetails->sum(function ($detail) {
            return (float) $detail->unit_price * (int) $detail->quantity;
        }), 2);
    }

    protected function settlementTotal(): float
    {
        return round((float) $this->purchaseReturn->total_amount, 2);
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
                if ($this->return_type === 'exchange') {
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

                if ($this->return_type === 'deposit' && $this->calculateCreditAmount() <= 0) {
                    $validator->errors()->add('return_type', 'Nilai retur tidak dapat dijadikan kredit.');
                }

                if ($this->return_type === 'cash' && empty($this->cash_proof)) {
                    $validator->errors()->add('cash_proof', 'Unggah bukti pengembalian tunai.');
                }
            });

            $validator->validate();

            $total = $this->settlementTotal();
            $paymentMethod = match ($this->return_type) {
                'exchange' => 'Replacement',
                'deposit' => 'Supplier Credit',
                default => 'Cash',
            };

            $storedProof = null;

            if ($this->return_type === 'cash' && $this->cash_proof) {
                $storedProof = $this->cash_proof->store('purchase-returns/proofs', 'public');
            }

            DB::transaction(function () use ($total, $paymentMethod, $storedProof) {
                $purchaseReturn = PurchaseReturn::lockForUpdate()->findOrFail($this->purchaseReturn->id);

                $purchaseReturn->goods()->delete();
                $purchaseReturn->purchaseReturnPayments()->delete();
                $purchaseReturn->supplierCredit()->delete();

                if ($this->return_type === 'exchange') {
                    foreach ($this->replacement_goods as $replacement) {
                        if (empty($replacement['product_id'])) {
                            continue;
                        }

                        $quantity = (int) ($replacement['quantity'] ?? 0);
                        $unitValue = (float) ($replacement['unit_value'] ?? 0);

                        PurchaseReturnGood::create([
                            'purchase_return_id' => $purchaseReturn->id,
                            'product_id' => $replacement['product_id'],
                            'product_name' => $replacement['product_name'],
                            'product_code' => $replacement['product_code'] ?? null,
                            'quantity' => $quantity,
                            'unit_value' => $unitValue,
                            'sub_total' => round($quantity * $unitValue, 2),
                        ]);
                    }
                }

                if ($this->return_type === 'deposit') {
                    $creditAmount = $this->calculateCreditAmount();

                    SupplierCredit::create([
                        'supplier_id' => $purchaseReturn->supplier_id,
                        'purchase_return_id' => $purchaseReturn->id,
                        'amount' => $creditAmount,
                        'remaining_amount' => $creditAmount,
                        'status' => 'open',
                    ]);
                }

                if ($this->return_type === 'cash') {
                    PurchaseReturnPayment::create([
                        'purchase_return_id' => $purchaseReturn->id,
                        'amount' => $total,
                        'date' => now()->toDateString(),
                        'reference' => 'PRPAY/' . $purchaseReturn->reference,
                        'payment_method' => 'Cash',
                        'payment_method_id' => null,
                        'note' => 'Pengembalian tunai',
                    ]);
                }

                if ($storedProof && $purchaseReturn->cash_proof_path) {
                    Storage::disk('public')->delete($purchaseReturn->cash_proof_path);
                }

                $purchaseReturn->update([
                    'return_type' => $this->return_type,
                    'payment_status' => 'Paid',
                    'payment_method' => $paymentMethod,
                    'paid_amount' => round($total, 2),
                    'due_amount' => 0,
                    'cash_proof_path' => $storedProof ?? $purchaseReturn->cash_proof_path,
                    'status' => 'Completed',
                    'settled_at' => now(),
                    'settled_by' => Auth::id(),
                ]);
            });

            session()->flash('success', 'Metode penyelesaian berhasil disimpan.');
            return redirect()->route('purchase-returns.show', $this->purchaseReturn->id);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Failed to save purchase return settlement', [
                'purchase_return_id' => $this->purchaseReturn->id,
                'message' => $e->getMessage(),
            ]);
            session()->flash('error', 'Terjadi kesalahan saat menyimpan metode penyelesaian.');
        }

        $this->loadPurchaseReturn();

        return null;
    }

    public function render(): Factory|Application|View
    {
        return view('livewire.purchase-return.purchase-return-settlement-form', [
            'purchaseReturn' => $this->purchaseReturn,
            'details' => $this->purchaseReturn->purchaseReturnDetails,
            'total' => $this->settlementTotal(),
            'creditAmount' => $this->calculateCreditAmount(),
            'isReadOnly' => $this->isReadOnly,
            'displayReturnType' => $this->return_type !== ''
                ? Str::of($this->return_type)->lower()->ucfirst()
                : '',
        ]);
    }
}
