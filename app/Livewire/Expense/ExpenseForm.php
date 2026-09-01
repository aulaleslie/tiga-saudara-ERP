<?php

namespace App\Livewire\Expense;

use App\Services\IdempotencyService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Expense\Entities\Expense;
use Modules\Expense\Entities\ExpenseCategory;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Tax;
use Spatie\Tags\Tag;

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

    // Supplier selection
    public $supplier_id = null;
    public $supplierSearch = '';
    public $supplierOptions = [];
    public $supplierLabel = '';

    // Tag selection
    public $tagIds = [];
    public $tagSearch = '';
    public $tagOptions = [];
    public $tagLabels = [];

    public function mount(?Expense $expense = null, ?string $idempotencyToken = null): void
    {
        $this->idempotencyToken = $idempotencyToken ?? (string) Str::uuid();
        $this->taxRates = Tax::pluck('value', 'id')->map(fn ($value) => (float) $value)->toArray();
        $this->is_pkp = (bool) (\Modules\Setting\Entities\Setting::query()->whereKey((int) session('setting_id'))->value('is_pkp') ?? false);

        if ($this->is_pkp) {
            $this->default_tax_id = Tax::where('is_active', true)->where('is_default', true)->value('id')
                ?? Tax::where('is_active', true)->first()?->id;
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

    // Supplier search/select/remove
    public function updatedSupplierSearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->supplierOptions = [];
            return;
        }
        $settingId = session('setting_id');
        $this->supplierOptions = Supplier::query()
            ->where('setting_id', $settingId)
            ->whereRaw('LOWER(supplier_name) LIKE ?', ['%' . mb_strtolower($value) . '%'])
            ->limit(10)->get(['id', 'supplier_name'])->toArray();
    }

    public function selectSupplier(int $id, string $name): void
    {
        $this->supplier_id = $id;
        $this->supplierLabel = $name;
        $this->supplierSearch = '';
        $this->supplierOptions = [];
    }

    public function removeSupplier(): void
    {
        $this->supplier_id = null;
        $this->supplierLabel = '';
    }

    // Tag search/select/remove
    public function updatedTagSearch($value): void
    {
        $value = trim($value);
        if (strlen($value) < 2) {
            $this->tagOptions = [];
            return;
        }
        $locale = app()->getLocale();
        $tags = Tag::query()
            ->where(fn ($q) => $q->containing($value, $locale)->orWhere(fn($sq) => $sq->containing($value, 'en')))
            ->limit(10)->get(['id', 'name']);
            
        $this->tagOptions = $tags->map(function ($tag) use ($locale) {
            $nameData = is_string($tag->name) ? json_decode($tag->name, true) : $tag->name;
            $name = is_array($nameData) ? ($nameData[$locale] ?? ($nameData['en'] ?? reset($nameData))) : (string) $tag->name;
            return ['id' => $tag->id, 'name' => $name];
        })->toArray();
    }

    public function selectTag(int $id, string $name): void
    {
        if (!in_array($id, $this->tagIds)) {
            $this->tagIds[] = $id;
            $this->tagLabels[$id] = $name;
        }
        $this->tagSearch = '';
        $this->tagOptions = [];
    }

    public function removeTag(int $id): void
    {
        $this->tagIds = array_values(array_diff($this->tagIds, [$id]));
        unset($this->tagLabels[$id]);
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

    #[On('expenseCategoryCreated')]
    public function handleExpenseCategoryCreated($id, $name = null, $requester = null): void
    {
        if ($requester === 'expense-form') {
            $this->category_id = (int) $id;
        }
    }

    #[On('taxCreated')]
    public function handleTaxCreated($id, $name = null, $value = null, $product_id = null, $requester = null): void
    {
        if ($requester === 'expense-form') {
            $this->taxRates[$id] = (float) $value;
            if (isset($this->details[$product_id])) {
                $this->details[$product_id]['tax_id'] = (int) $id;
                $this->handleTaxIncluded();
            }
        }
    }

    public function getSuggestions($query)
    {
        if (empty(trim($query))) {
            return [];
        }

        return \Illuminate\Support\Facades\DB::table('expense_details')
            ->join('expenses', 'expense_details.expense_id', '=', 'expenses.id')
            ->where('expenses.status', Expense::STATUS_APPROVED)
            ->whereNull('expenses.archived_at')
            ->where('expense_details.name', 'like', '%' . trim($query) . '%')
            ->select('expense_details.name')
            ->distinct()
            ->limit(20)
            ->pluck('name')
            ->toArray();
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
                'supplier_id' => 'nullable|exists:suppliers,id',
                'tagIds.*' => 'nullable|exists:tags,id',
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
                'supplier_id' => $this->supplier_id,
                'tag_ids' => $this->tagIds,
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
        $retainedTaxIds = [];
        if ($this->expenseId) {
            $retainedTaxIds = \Modules\Expense\Entities\ExpenseDetail::query()
                ->where('expense_id', $this->expenseId)
                ->whereNotNull('tax_id')
                ->pluck('tax_id')
                ->unique()
                ->all();
        }

        return view('livewire.expense.expense-form', [
            'categories' => ExpenseCategory::all(),
            'taxes' => Tax::query()
                ->where(function ($query) use ($retainedTaxIds) {
                    $query->where('is_active', true);

                    if (!empty($retainedTaxIds)) {
                        $query->orWhereIn('id', $retainedTaxIds);
                    }
                })
                ->get(),
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

        // Hydrate supplier
        if ($expense->supplier_id) {
            $this->supplier_id = $expense->supplier_id;
            $this->supplierLabel = $expense->supplier?->supplier_name ?? '';
        }

        // Hydrate tags
        $tags = $expense->tags;
        if ($tags && $tags->isNotEmpty()) {
            $locale = app()->getLocale();
            foreach ($tags as $tag) {
                $this->tagIds[] = $tag->id;
                $nameData = is_string($tag->name) ? json_decode($tag->name, true) : $tag->name;
                $this->tagLabels[$tag->id] = is_array($nameData)
                    ? ($nameData[$locale] ?? ($nameData['en'] ?? reset($nameData)))
                    : (string) $tag->name;
            }
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
