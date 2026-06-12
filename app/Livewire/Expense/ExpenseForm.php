<?php

namespace App\Livewire\Expense;

use App\Services\IdempotencyService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Expense\Entities\Expense;
use Modules\Expense\Entities\ExpenseCategory;
use Modules\Setting\Entities\Tax;

class ExpenseForm extends Component
{
    use WithFileUploads;

    public $reference = 'EXP';
    public $date;
    public $category_id;
    public $details = [];
    public $files = [];
    public $existingAttachments = [];
    public $removedAttachmentIds = [];
    public $is_tax_included = false;
    public $taxRates = [];
    public $expenseId;
    public string $idempotencyToken;
    public $is_pkp = false;
    public $default_tax_id = null;

    public function mount(?Expense $expense = null, ?string $idempotencyToken = null): void
    {
        $this->idempotencyToken = $idempotencyToken ?? (string) Str::uuid();
        $this->taxRates = Tax::pluck('value', 'id')->map(fn ($value) => (float) $value)->toArray();
        $this->is_pkp = (bool) (\Modules\Setting\Entities\Setting::query()->whereKey((int) session('setting_id'))->value('is_pkp') ?? false);

        if ($this->is_pkp) {
            $this->default_tax_id = Tax::where('is_default', true)->value('id') ?? Tax::first()?->id;
        }

        if ($expense && $expense->exists) {
            app(\Modules\Expense\Services\ExpenseService::class)->verifySettingOwnership($expense);
            $this->hydrateFromExpense($expense);
            return;
        }

        if ($this->is_pkp) {
            $this->is_tax_included = true;
        }

        $this->date = now()->format('Y-m-d');
        $this->details[] = ['id' => null, 'name' => '', 'tax_id' => $this->default_tax_id, 'amount' => 0];
    }

    public function addDetail(): void
    {
        $this->details[] = ['id' => null, 'name' => '', 'tax_id' => $this->default_tax_id, 'amount' => 0];
    }

    public function removeDetail($index): void
    {
        unset($this->details[$index]);
        $this->details = array_values($this->details);
    }

    public function formatAmount($index): void
    {
        if (!isset($this->details[$index]['amount'])) {
            return;
        }

        $amount = $this->details[$index]['amount'];
        $amount = floatval(preg_replace('/[^0-9]/', '', $amount));
        $this->details[$index]['amount'] = 'Rp ' . number_format($amount, 0, ',', '.');
    }

    public function unformatAmount($index): void
    {
        if (!isset($this->details[$index]['amount'])) {
            return;
        }

        $raw = preg_replace('/[^0-9]/', '', $this->details[$index]['amount']);
        $this->details[$index]['amount'] = floatval($raw);
    }

    public function updatedIsTaxIncluded(): void
    {
        // force re-render for recalculation
    }

    public function getTotalFormattedProperty(): string
    {
        return $this->formatRupiah($this->calculateTotalAmount());
    }

    public function handleTaxIncluded(): void
    {
        $this->details = $this->details; // triggers Livewire reactivity
    }

    public function removeExistingAttachment($mediaId): void
    {
        $this->existingAttachments = array_values(array_filter(
            $this->existingAttachments,
            fn ($media) => $media['id'] !== $mediaId
        ));

        if (!in_array($mediaId, $this->removedAttachmentIds, true)) {
            $this->removedAttachmentIds[] = $mediaId;
        }
    }

    public function saveDraft()
    {
        $this->processSave(Expense::STATUS_DRAFT);
    }

    public function submitForApproval()
    {
        $this->processSave(Expense::STATUS_SUBMITTED);
    }

    private function processSave($status)
    {
        $this->dispatch('expense:submit-start');

        try {
            $this->validate([
                'date' => 'required|date',
                'category_id' => 'required|exists:expense_categories,id',
                'details.*.name' => 'required|string|max:255',
                'details.*.amount' => 'required',
                'details.*.tax_id' => 'nullable|exists:taxes,id',
                'files.*' => 'nullable|file|max:10240',
            ]);

            if (!$this->expenseId && ! IdempotencyService::claim($this->idempotencyToken, 'expenses.store', auth()->id())) {
                $this->addError('idempotency', 'Pengajuan biaya sedang diproses. Mohon tunggu sebelum mencoba lagi.');
                return;
            }

            $this->normalizeAmounts();

            $data = [
                'setting_id' => session('setting_id'),
                'date' => $this->date,
                'category_id' => $this->category_id,
                'details' => $this->details,
                'files' => $this->files,
                'removed_attachment_ids' => $this->removedAttachmentIds,
                'is_tax_included' => $this->is_tax_included,
                'status' => $status,
            ];

            if ($this->expenseId) {
                $expense = Expense::with('detailRows', 'media')->findOrFail($this->expenseId);
                app(\Modules\Expense\Services\ExpenseService::class)->saveExpense($data, $expense);
            } else {
                app(\Modules\Expense\Services\ExpenseService::class)->saveExpense($data);
            }

            return redirect()->route('expenses.index');
        } finally {
            $this->dispatch('expense:submit-finish');
        }
    }

    public function render()
    {
        $suggestedNames = \Illuminate\Support\Facades\DB::table('expense_details')
            ->join('expenses', 'expense_details.expense_id', '=', 'expenses.id')
            ->where('expenses.status', Expense::STATUS_APPROVED)
            ->whereNull('expenses.archived_at')
            ->select('expense_details.name')
            ->distinct()
            ->limit(50)
            ->pluck('name')
            ->toArray();

        return view('livewire.expense.expense-form', [
            'categories' => ExpenseCategory::all(),
            'taxes' => Tax::all(),
            'suggestedNames' => $suggestedNames,
        ]);
    }

    public function getTotalBeforeTaxFormattedProperty(): string
    {
        return $this->formatRupiah($this->calculateBeforeTax());
    }

    public function getTotalTaxFormattedProperty(): string
    {
        return $this->formatRupiah($this->calculateTaxTotal());
    }

    private function hydrateFromExpense(Expense $expense): void
    {
        $this->expenseId = $expense->id;
        $this->reference = $expense->reference;
        $this->date = $expense->getRawOriginal('date');
        $this->category_id = $expense->category_id;
        $this->is_tax_included = (bool) data_get($expense, 'is_tax_included', false);

        $this->details = $expense->detailRows
            ->map(function ($detail) {
                return [
                    'id' => $detail->id,
                    'name' => $detail->name,
                    'tax_id' => $detail->tax_id,
                    'amount' => $this->formatRupiah($detail->amount),
                ];
            })
            ->toArray();

        $this->existingAttachments = $expense->getMedia('attachments')
            ->map(function ($media) {
                return [
                    'id' => $media->id,
                    'name' => $media->file_name,
                    'size' => $media->humanReadableSize,
                ];
            })
            ->toArray();

        if (empty($this->details)) {
            $this->details[] = ['id' => null, 'name' => '', 'tax_id' => $this->default_tax_id, 'amount' => 0];
        }
    }

    private function formatRupiah($number): string
    {
        return 'Rp ' . number_format($number, 0, ',', '.');
    }

    private function normalizeAmounts(): void
    {
        $normalized = [];

        foreach ($this->details as $row) {
            $amount = floatval(preg_replace('/[^0-9]/', '', $row['amount']));
            $taxId = $row['tax_id'] ?? null;
            $taxId = empty($taxId) ? null : (int) $taxId;

            $normalized[] = [
                'id' => $row['id'] ?? null,
                'name' => $row['name'],
                'amount' => $amount,
                'tax_id' => $taxId,
            ];
        }

        $this->details = $normalized;
    }

    private function calculateBeforeTax(): float
    {
        $total = 0;

        foreach ($this->details as $detail) {
            $amount = $this->extractFloat($detail['amount'] ?? 0);

            if ($this->is_tax_included) {
                $taxRate = $this->getTaxRate($detail['tax_id'] ?? null);
                if ($taxRate > 0) {
                    $base = $amount / (1 + ($taxRate / 100));
                    $total += $base;
                } else {
                    $total += $amount;
                }
            } else {
                $total += $amount;
            }
        }

        return $total;
    }

    private function calculateTaxTotal(): float
    {
        $taxTotal = 0;

        foreach ($this->details as $detail) {
            $amount = $this->extractFloat($detail['amount'] ?? 0);
            $taxRate = $this->getTaxRate($detail['tax_id'] ?? null);

            if ($taxRate > 0) {
                if ($this->is_tax_included) {
                    $base = $amount / (1 + ($taxRate / 100));
                    $taxTotal += ($amount - $base);
                } else {
                    $taxTotal += ($amount * $taxRate) / 100;
                }
            }
        }

        return $taxTotal;
    }

    private function calculateTotalAmount(): float
    {
        if ($this->is_tax_included) {
            $total = 0;
            foreach ($this->details as $detail) {
                $total += $this->extractFloat($detail['amount'] ?? 0);
            }
            return $total;
        }

        return $this->calculateBeforeTax() + $this->calculateTaxTotal();
    }

    private function getTaxRate($taxId): float
    {
        if (empty($taxId)) {
            return 0;
        }

        return (float) ($this->taxRates[$taxId] ?? 0);
    }



    private function extractFloat($value): float
    {
        $clean = preg_replace('/[^0-9]/', '', $value);

        return floatval($clean);
    }
}

