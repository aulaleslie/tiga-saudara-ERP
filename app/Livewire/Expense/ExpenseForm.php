<?php

namespace App\Livewire\Expense;

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
    public $is_tax_included = false;
    public $taxRates = [];

    public function mount()
    {
        $this->date = now()->format('Y-m-d');
        $this->details[] = ['name' => '', 'tax_id' => null, 'amount' => 0];

        // Cache tax rates for live calculation
        $this->taxRates = Tax::pluck('value', 'id')->toArray();
    }

    public function addDetail()
    {
        $this->details[] = ['name' => '', 'tax_id' => null, 'amount' => 0];
    }

    public function removeDetail($index)
    {
        unset($this->details[$index]);
        $this->details = array_values($this->details);
    }

    public function formatAmount($index)
    {
        if (!isset($this->details[$index]['amount'])) return;
        $amount = $this->details[$index]['amount'];
        $amount = floatval(preg_replace('/[^0-9]/', '', $amount));
        $this->details[$index]['amount'] = 'Rp ' . number_format($amount, 0, ',', '.');
    }

    public function unformatAmount($index)
    {
        if (!isset($this->details[$index]['amount'])) return;
        $raw = preg_replace('/[^0-9]/', '', $this->details[$index]['amount']);
        $this->details[$index]['amount'] = floatval($raw);
    }

    public function updatedIsTaxIncluded()
    {
        // force re-render for recalculation
    }

    public function getTotalFormattedProperty()
    {
        $total = 0;

        foreach ($this->details as $detail) {
            $amount = $this->extractFloat($detail['amount'] ?? 0);
            $taxRate = Tax::find($detail['tax_id'])?->value ?? 0;

            if ($this->is_tax_included || $taxRate == 0) {
                $total += $amount;
            } else {
                $total += $amount + ($amount * $taxRate / 100);
            }
        }

        return $this->formatRupiah($total);
    }

    public function handleTaxIncluded()
    {
        $this->details = $this->details; // triggers Livewire reactivity
    }

    private function formatRupiah($number)
    {
        return 'Rp ' . number_format($number, 0, ',', '.');
    }

    private function normalizeAmounts()
    {
        $normalized = [];
        foreach ($this->details as $row) {
            $amount = floatval(preg_replace('/[^0-9]/', '', $row['amount']));
            $normalized[] = array_merge($row, ['amount' => $amount]);
        }
        $this->details = $normalized;
    }

    public function save()
    {
        $this->validate([
            'date' => 'required|date',
            'category_id' => 'required|exists:expense_categories,id',
            'details.*.name' => 'required|string|max:255',
            'details.*.amount' => 'required',
            'details.*.tax_id' => 'nullable|exists:taxes,id',
            'files.*' => 'nullable|file|max:10240',
        ]);

        $this->normalizeAmounts();

        $settingId = session('setting_id');
        $totalAmount = collect($this->details)->sum('amount');

        $expense = Expense::create([
            'date' => $this->date,
            'category_id' => $this->category_id,
            'amount' => $totalAmount,
            'setting_id' => $settingId,
        ]);

        foreach ($this->details as $detail) {
            $expense->details()->create($detail);
        }

        foreach ($this->files as $file) {
            $expense->addMedia($file)->toMediaCollection('attachments');
        }

        toast('Expense Created!', 'success');
        return redirect()->route('expenses.index');
    }

    public function render()
    {
        return view('livewire.expense.expense-form', [
            'categories' => ExpenseCategory::all(),
            'taxes' => Tax::all(),
        ]);
    }

    public function getTotalBeforeTaxFormattedProperty()
    {
        $total = 0;

        foreach ($this->details as $detail) {
            $amount = $this->extractFloat($detail['amount'] ?? 0);
            $total += $amount;
        }

        return $this->formatRupiah($total);
    }

    public function getTotalTaxFormattedProperty()
    {
        $taxTotal = 0;

        foreach ($this->details as $detail) {
            $amount = $this->extractFloat($detail['amount'] ?? 0);

            if (!empty($detail['tax_id'])) {
                $taxRate = Tax::find($detail['tax_id'])?->value ?? 0;

                if (!$this->is_tax_included) {
                    $taxTotal += ($amount * $taxRate) / 100;
                }
            }
        }

        return $this->formatRupiah($taxTotal);
    }

    private function extractFloat($value)
    {
        $clean = preg_replace('/[^0-9]/', '', $value);
        return floatval($clean);
    }
}
