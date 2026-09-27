<?php

namespace App\Livewire\Purchase;

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Purchase\Entities\Purchase;

class PurchaseNoteEditor extends Component
{
    #[Locked]
    public int $purchaseId;

    public ?string $note = null;
    public bool $editing = false;
    public bool $canEdit = false;

    #[Locked]
    public bool $globalMode = false;

    public function mount(int $purchaseId, bool $globalMode = false): void
    {
        $this->globalMode = $globalMode;

        if ($this->globalMode) {
            abort_if(Gate::denies('purchasePayments.global.access'), 403);
            $purchase = Purchase::globalPaymentEligible()
                ->whereNull('archived_at')
                ->findOrFail($purchaseId);
        } else {
            $purchase = Purchase::withArchived()->findOrFail($purchaseId);
            $this->ensurePurchaseBelongsToCurrentSetting($purchase);
        }

        $this->purchaseId = $purchaseId;
        $this->note = $purchase->note;
        $this->canEdit = $this->authorizeCanEdit($purchase);
    }

    public function startEditing(): void
    {
        $this->authorizeEdit();
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->authorizeEdit();
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

    private function authorizeCanEdit(Purchase $purchase): bool
    {
        if ($purchase->isArchived()) {
            return false;
        }

        if ($this->globalMode) {
            return Gate::allows('purchasePayments.global.access') && Gate::allows('purchases.update');
        }

        return Gate::allows('purchases.update');
    }

    private function authorizeEdit(): void
    {
        if ($this->globalMode) {
            abort_if(Gate::denies('purchasePayments.global.access') || Gate::denies('purchases.update'), 403);
            $purchase = Purchase::globalPaymentEligible()
                ->whereNull('archived_at')
                ->findOrFail($this->purchaseId);
        } else {
            abort_if(Gate::denies('purchases.update'), 403);
            $purchase = Purchase::withArchived()->findOrFail($this->purchaseId);
            $this->ensurePurchaseBelongsToCurrentSetting($purchase);
            abort_if($purchase->isArchived(), 403);
        }
    }

    private function findPurchase(): Purchase
    {
        if ($this->globalMode) {
            return Purchase::globalPaymentEligible()
                ->whereNull('archived_at')
                ->findOrFail($this->purchaseId);
        }

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
