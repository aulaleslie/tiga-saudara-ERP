<?php

namespace App\Livewire\Pos;

class CashReconciliation extends CashMovementComponent
{
    public $recordedSalesTotal = null;

    public $cashPickupsTotal = null;

    protected function rules(): array
    {
        return [
            'recordedSalesTotal' => ['required', 'numeric', 'min:0'],
            'cashPickupsTotal' => ['nullable', 'numeric', 'min:0'],
            'denominations.*' => ['nullable', 'integer', 'min:0'],
            'manualAdjustment' => ['nullable', 'numeric', 'min:0'],
            'supportingDocuments.*' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function updatedRecordedSalesTotal($value): void
    {
        $this->recordedSalesTotal = $this->sanitizeFloat($value, allowNegative: false, default: null);
        $this->recalculateExpectedTotal();
    }

    public function updatedCashPickupsTotal($value): void
    {
        $this->cashPickupsTotal = $this->sanitizeFloat($value, allowNegative: false, default: null);
        $this->recalculateExpectedTotal();
    }

    public function submit(): void
    {
        $this->validate();

        if ($this->countedTotal <= 0) {
            $this->addError('denominations', 'Masukkan perhitungan kas aktual.');
            return;
        }

        $expectedOnHand = $this->expectedTotal ?? 0.0;

        $this->persistMovement('reconciliation', [
            'expected_total' => $expectedOnHand,
            'metadata' => [
                'recorded_sales_total' => $this->recordedSalesTotal,
                'cash_pickups_total' => $this->cashPickupsTotal,
            ],
        ]);

        session()->flash('success', 'Rekonsiliasi kas berhasil direkam.');

        $this->resetForm();
        $this->recordedSalesTotal = null;
        $this->cashPickupsTotal = null;
    }

    public function render()
    {
        return view('livewire.pos.cash-reconciliation', [
            'countedTotal' => $this->countedTotal,
            'variance' => $this->variance,
            'expectedOnHand' => $this->expectedTotal,
        ]);
    }

    protected function recalculateExpectedTotal(): void
    {
        if ($this->recordedSalesTotal === null && $this->cashPickupsTotal === null) {
            $this->expectedTotal = null;
            return;
        }

        $sales = $this->recordedSalesTotal ?? 0.0;
        $pickups = $this->cashPickupsTotal ?? 0.0;

        $this->expectedTotal = round($sales - $pickups, 2);
    }
}
