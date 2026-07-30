<?php

namespace App\Livewire\Purchase;

use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Modules\Purchase\Entities\Purchase;

class PurchaseNoteEditor extends Component
{
    public int $purchaseId;
    public ?string $note = null;
    public bool $editing = false;
    public bool $canEdit = false;

    public function mount(int $purchaseId): void
    {
        $purchase = Purchase::withArchived()->findOrFail($purchaseId);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        $this->purchaseId = $purchaseId;
        $this->note = $purchase->note;
        $this->canEdit = Gate::allows('purchases.update') && !$purchase->isArchived();
    }

    public function startEditing(): void
    {
        $this->authorizeEdit();
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $purchase = $this->findPurchase();
        $this->note = $purchase->note;
        $this->editing = false;
    }

    public function save(): void
    {
        $this->authorizeEdit();

        $data = $this->validate([
            'note' => 'nullable|string|max:1000',
        ]);

        $purchase = $this->findPurchase();
        $value = $data['note'];
        $normalizedValue = $value === '' ? null : $value;

        $purchase->update([
            'note' => $normalizedValue,
        ]);

        $this->note = $purchase->note;
        $this->editing = false;

        $this->dispatch('notify', ['type' => 'success', 'message' => 'Catatan pembelian diperbarui.']);
    }

    public function render()
    {
        return view('livewire.purchase.purchase-note-editor');
    }

    private function authorizeEdit(): void
    {
        $purchase = Purchase::withArchived()->findOrFail($this->purchaseId);
        abort_if(Gate::denies('purchases.update') || $purchase->isArchived(), 403);
    }

    private function findPurchase(): Purchase
    {
        $purchase = Purchase::withArchived()->findOrFail($this->purchaseId);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        return $purchase;
    }

    private function ensurePurchaseBelongsToCurrentSetting(Purchase $purchase): void
    {
        $currentSettingId = session('setting_id');

        if (! is_null($currentSettingId) && (int) $purchase->setting_id !== (int) $currentSettingId) {
            abort(404);
        }
    }
}
