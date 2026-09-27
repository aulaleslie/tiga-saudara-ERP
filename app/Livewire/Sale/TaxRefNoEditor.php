<?php

namespace App\Livewire\Sale;

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Sale\Entities\Sale;

class TaxRefNoEditor extends Component
{
    #[Locked]
    public int $saleId;

    public ?string $taxRefNo = null;
    public bool $editing = false;
    public bool $canEdit = false;
    public bool $isArchived = false;

    #[Locked]
    public bool $globalMode = false;

    public function mount(int $saleId, bool $isArchived = false, bool $globalMode = false): void
    {
        $this->globalMode = $globalMode;

        if ($this->globalMode) {
            abort_if(Gate::denies('salePayments.global.access'), 403);
            $sale = Sale::globalPaymentEligible()
                ->whereNull('archived_at')
                ->findOrFail($saleId);
        } else {
            $sale = Sale::withoutGlobalScopes()->findOrFail($saleId);
            $this->ensureSaleBelongsToCurrentSetting($sale);
        }

        $this->saleId = $saleId;
        $this->taxRefNo = $sale->tax_ref_no;
        $this->isArchived = $isArchived;
        $this->canEdit = $this->authorizeCanEdit($sale);
    }

    public function startEditing(): void
    {
        $this->authorizeEdit();
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->authorizeEdit();
        $sale = $this->findSale();
        $this->taxRefNo = $sale->tax_ref_no;
        $this->editing = false;
    }

    public function save(): void
    {
        $this->authorizeEdit();

        $data = $this->validate([
            'taxRefNo' => 'nullable|string|max:255',
        ]);

        $sale = $this->findSale();
        $value = $data['taxRefNo'];
        $normalizedValue = $value === '' ? null : $value;

        $sale->update([
            'tax_ref_no' => $normalizedValue,
        ]);

        $this->taxRefNo = $sale->tax_ref_no;
        $this->editing = false;

        $this->dispatch('notify', ['type' => 'success', 'message' => 'Nomor Faktur Pajak diperbarui.']);
    }

    public function render()
    {
        return view('livewire.sale.tax-ref-no-editor');
    }

    private function authorizeCanEdit(Sale $sale): bool
    {
        if ($sale->isArchived()) {
            return false;
        }

        if ($this->globalMode) {
            return Gate::allows('salePayments.global.access') && Gate::allows('sales.edit');
        }

        return Gate::allows('sales.edit');
    }

    private function authorizeEdit(): void
    {
        if ($this->globalMode) {
            abort_if(Gate::denies('salePayments.global.access') || Gate::denies('sales.edit'), 403);
            $sale = Sale::globalPaymentEligible()
                ->whereNull('archived_at')
                ->findOrFail($this->saleId);
        } else {
            abort_if(Gate::denies('sales.edit'), 403);
            $sale = Sale::withoutGlobalScopes()->findOrFail($this->saleId);
            $this->ensureSaleBelongsToCurrentSetting($sale);
            abort_if($sale->isArchived(), 403);
        }
    }

    private function findSale(): Sale
    {
        if ($this->globalMode) {
            return Sale::globalPaymentEligible()
                ->whereNull('archived_at')
                ->findOrFail($this->saleId);
        }

        $sale = Sale::withoutGlobalScopes()->findOrFail($this->saleId);
        $this->ensureSaleBelongsToCurrentSetting($sale);

        return $sale;
    }

    private function ensureSaleBelongsToCurrentSetting(Sale $sale): void
    {
        $currentSettingId = session('setting_id');

        if (! is_null($currentSettingId) && (int) $sale->setting_id !== (int) $currentSettingId) {
            abort(404);
        }
    }
}
