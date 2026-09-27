<?php

namespace App\Livewire\Expense;

use Livewire\Component;
use Modules\Expense\Entities\ExpenseCategory;
use Illuminate\Validation\Rule;

class ExpenseCategoryQuickAddModal extends Component
{
    public $showModal = false;
    public $category_name = '';
    public $category_description = '';
    public $requester = null;
    public $listenEvent = 'openExpenseCategoryModal';
    public int $formResetVersion = 1;

    public function getListeners()
    {
        return [
            $this->listenEvent => 'openModal',
        ];
    }

    protected function rules()
    {
        return [
            'category_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('expense_categories', 'category_name')
            ],
            'category_description' => 'nullable|string|max:1000',
        ];
    }

    protected function validationAttributes()
    {
        return [
            'category_name' => 'nama kategori',
            'category_description' => 'deskripsi kategori',
        ];
    }

    public function openModal($requester = null, $target = null)
    {
        $this->resetForm();

        if ($target === null && is_array($requester)) {
            $this->requester = $requester['requester'] ?? null;
        } elseif ($target === null && is_string($requester) && !is_numeric($requester)) {
            $this->requester = $requester;
        } else {
            $this->requester = $requester ?? (is_array($target) ? ($target['requester'] ?? null) : (is_string($target) ? $target : null));
        }

        $this->showModal = true;
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function save()
    {
        $this->validate();

        $category = ExpenseCategory::create([
            'category_name' => $this->category_name,
            'category_description' => $this->category_description,
            'setting_id' => session('setting_id')
        ]);

        $this->dispatch('expenseCategoryCreated', 
            id: $category->id,
            name: $category->category_name,
            requester: $this->requester
        )->to(\App\Livewire\Expense\ExpenseForm::class);

        $this->closeModal();
    }

    private function resetForm()
    {
        $this->category_name = '';
        $this->category_description = '';
        $this->requester = null;
        $this->formResetVersion++;
        $this->resetErrorBag();
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.expense.expense-category-quick-add-modal');
    }
}
