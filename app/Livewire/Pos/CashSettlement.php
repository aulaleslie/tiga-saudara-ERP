<?php

namespace App\Livewire\Pos;

class CashSettlement extends CashMovementComponent
{
    protected function rules(): array
    {
        return [
            'expectedTotal' => ['nullable', 'numeric'],
            'denominations.*' => ['nullable', 'integer', 'min:0'],
            'manualAdjustment' => ['nullable', 'numeric', 'min:0'],
            'supportingDocuments.*' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function submit(): void
    {
        $this->validate();

        if ($this->countedTotal <= 0) {
            $this->addError('denominations', 'Masukkan setidaknya satu hitungan uang tunai.');
            return;
        }

        $this->persistMovement('settlement');

        session()->flash('success', 'Penyetoran kas berhasil direkam.');

        $this->resetForm();
    }

    public function render()
    {
        return view('livewire.pos.cash-settlement', [
            'countedTotal' => $this->countedTotal,
            'variance' => $this->variance,
        ]);
    }
}
