<?php

namespace App\Livewire\Pos;

class CashPickup extends CashMovementComponent
{
    public $pickupAmount = null;

    public string $recipient = '';

    public string $reference = '';

    protected function rules(): array
    {
        return [
            'pickupAmount' => ['required', 'numeric', 'min:0.01'],
            'denominations.*' => ['nullable', 'integer', 'min:0'],
            'manualAdjustment' => ['nullable', 'numeric', 'min:0'],
            'supportingDocuments.*' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'recipient' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function updatedPickupAmount($value): void
    {
        $this->pickupAmount = $this->sanitizeFloat($value, allowNegative: false, default: null);
        $this->expectedTotal = $this->pickupAmount;
    }

    public function submit(): void
    {
        $this->validate();

        $cashTotal = $this->countedTotal > 0 ? $this->countedTotal : ($this->pickupAmount ?? 0.0);

        $this->persistMovement('pickup', [
            'cash_total' => $cashTotal,
            'expected_total' => $this->pickupAmount,
            'metadata' => [
                'recipient' => $this->recipient ? trim($this->recipient) : null,
                'reference' => $this->reference ? trim($this->reference) : null,
            ],
        ]);

        session()->flash('success', 'Penjemputan kas berhasil direkam.');

        $this->resetForm();
        $this->pickupAmount = null;
        $this->recipient = '';
        $this->reference = '';
    }

    public function render()
    {
        return view('livewire.pos.cash-pickup', [
            'countedTotal' => $this->countedTotal,
            'variance' => $this->variance,
        ]);
    }
}
