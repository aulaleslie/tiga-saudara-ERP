<?php

namespace App\Livewire\Purchase;

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Purchase\Entities\Purchase;

class TaxRefNoEditor extends Component
{
    #[Locked]
    public int $purchaseId;

    public ?string $taxRefNo = null;
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
        $this->taxRefNo = $purchase->tax_ref_no;
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
        $this->taxRefNo = $purchase->tax_ref_no;
        $this->editing = false;
    }

    public function save(): void
    {
        $this->authorizeEdit();
        $purchase = $this->findPurchase();

        $data = $this->validate([
            'taxRefNo' => 'nullable|string|max:255|unique:purchases,tax_ref_no,' . $this->purchaseId . ',id,setting_id,' . $purchase->setting_id,
        ]);

        $value = $data['taxRefNo'];
        $normalizedValue = $value === '' ? null : $value;

        $purchase->update([
            'tax_ref_no' => $normalizedValue,
        ]);

        $this->taxRefNo = $purchase->tax_ref_no;
        $this->editing = false;

        $this->dispatch('notify', ['type' => 'success', 'message' => 'Nomor Faktur Pajak diperbarui.']);
    }

    public function render()
    {
        return view('livewire.purchase.tax-ref-no-editor');
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
