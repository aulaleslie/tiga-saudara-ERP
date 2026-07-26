<?php

namespace App\Livewire\Purchase;

use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Modules\Purchase\Entities\Purchase;

class SupplierPurchaseNumberEditor extends Component
{
    public int $purchaseId;
    public ?string $supplierPurchaseNumber = null;
    public bool $editing = false;
    public bool $canEdit = false;

    public function mount(int $purchaseId): void
    {
        $purchase = Purchase::withArchived()->findOrFail($purchaseId);
        $this->ensurePurchaseBelongsToCurrentSetting($purchase);

        $this->purchaseId = $purchaseId;
        $this->supplierPurchaseNumber = $purchase->supplier_purchase_number;
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
        $this->supplierPurchaseNumber = $purchase->supplier_purchase_number;
        $this->editing = false;
    }

    public function save(): void
    {
        $this->authorizeEdit();

        $data = $this->validate([
            'supplierPurchaseNumber' => 'nullable|string|max:255|unique:purchases,supplier_purchase_number,' . $this->purchaseId . ',id,setting_id,' . session('setting_id'),
        ]);

        $purchase = $this->findPurchase();
        $value = $data['supplierPurchaseNumber'];
        $normalizedValue = $value === '' ? null : $value;

        $purchase->update([
            'supplier_purchase_number' => $normalizedValue,
        ]);

        $this->supplierPurchaseNumber = $purchase->supplier_purchase_number;
        $this->editing = false;

        $this->dispatch('notify', ['type' => 'success', 'message' => 'Nomor pembelian pemasok diperbarui.']);
    }

    public function render()
    {
        return view('livewire.purchase.supplier-purchase-number-editor');
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
