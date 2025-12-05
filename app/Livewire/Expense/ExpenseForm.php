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

    public function mount(?Expense $expense = null, ?string $idempotencyToken = null): void
    {
        $this->idempotencyToken = $idempotencyToken ?? (string) Str::uuid();
        $this->taxRates = Tax::pluck('value', 'id')->map(fn ($value) => (float) $value)->toArray();

        if ($expense) {
            $this->hydrateFromExpense($expense);
            return;
        }

        $this->date = now()->format('Y-m-d');
        $this->details[] = ['id' => null, 'name' => '', 'tax_id' => null, 'amount' => 0];
    }

    public function addDetail(): void
    {
        $this->details[] = ['id' => null, 'name' => '', 'tax_id' => null, 'amount' => 0];
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

    public function save()
    {
        $this->dispatchBrowserEvent('expense:submit-start');

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

            $settingId = session('setting_id');
            $totalAmount = $this->calculateTotalAmount();

            if ($this->expenseId) {
                $this->updateExpense($totalAmount);
            } else {
                $this->createExpense($settingId, $totalAmount);
            }

            return redirect()->route('expenses.index');
        } finally {
            $this->dispatchBrowserEvent('expense:submit-finish');
        }
    }

    public function render()
    {
        return view('livewire.expense.expense-form', [
            'categories' => ExpenseCategory::all(),
            'taxes' => Tax::all(),
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

        $this->details = $expense->details
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
            $this->details[] = ['id' => null, 'name' => '', 'tax_id' => null, 'amount' => 0];
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
            $total += $this->extractFloat($detail['amount'] ?? 0);
        }

        return $total;
    }

    private function calculateTaxTotal(): float
    {
        if ($this->is_tax_included) {
            return 0;
        }

        $taxTotal = 0;

        foreach ($this->details as $detail) {
            $amount = $this->extractFloat($detail['amount'] ?? 0);
            $taxRate = $this->getTaxRate($detail['tax_id'] ?? null);

            if ($taxRate > 0) {
                $taxTotal += ($amount * $taxRate) / 100;
            }
        }

        return $taxTotal;
    }

    private function calculateTotalAmount(): float
    {
        return $this->calculateBeforeTax() + $this->calculateTaxTotal();
    }

    private function getTaxRate($taxId): float
    {
        if (empty($taxId)) {
            return 0;
        }

        return (float) ($this->taxRates[$taxId] ?? 0);
    }

    private function createExpense($settingId, float $totalAmount): void
    {
        $expense = Expense::create([
            'date' => $this->date,
            'category_id' => $this->category_id,
            'amount' => $totalAmount,
            'setting_id' => $settingId,
        ]);

        foreach ($this->details as $detail) {
            $expense->details()->create([
                'name' => $detail['name'],
                'tax_id' => $detail['tax_id'],
                'amount' => $detail['amount'],
            ]);
        }

        foreach ($this->files as $file) {
            $expense->addMedia($file)->toMediaCollection('attachments');
        }

        toast('Expense Created!', 'success');
    }

    private function updateExpense(float $totalAmount): void
    {
        $expense = Expense::with('details', 'media')->findOrFail($this->expenseId);

        $expense->update([
            'date' => $this->date,
            'category_id' => $this->category_id,
            'amount' => $totalAmount,
        ]);

        $existingIds = $expense->details->pluck('id')->all();
        $retainedIds = [];

        foreach ($this->details as $detail) {
            $detailData = Arr::only($detail, ['name', 'tax_id', 'amount']);

            if (!empty($detail['id']) && in_array($detail['id'], $existingIds, true)) {
                $expense->details()->whereKey($detail['id'])->update($detailData);
                $retainedIds[] = $detail['id'];
            } else {
                $newDetail = $expense->details()->create($detailData);
                $retainedIds[] = $newDetail->id;
            }
        }

        $idsToDelete = array_diff($existingIds, $retainedIds);
        if (!empty($idsToDelete)) {
            $expense->details()->whereIn('id', $idsToDelete)->delete();
        }

        if (!empty($this->removedAttachmentIds)) {
            $expense->media()->whereIn('id', $this->removedAttachmentIds)->get()->each->delete();
        }

        foreach ($this->files as $file) {
            $expense->addMedia($file)->toMediaCollection('attachments');
        }

        toast('Expense Updated!', 'info');
    }

    private function extractFloat($value): float
    {
        $clean = preg_replace('/[^0-9]/', '', $value);

        return floatval($clean);
    }
}

