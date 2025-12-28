<?php

namespace App\Livewire\Sale;

use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Modules\Sale\Entities\Sale;

class TaxRefNoEditor extends Component
{
    public int $saleId;
    public ?string $taxRefNo = null;
    public bool $editing = false;
    public bool $canEdit = false;

    public function mount(int $saleId): void
    {
        $sale = Sale::findOrFail($saleId);
        $this->ensureSaleBelongsToCurrentSetting($sale);

        $this->saleId = $saleId;
        $this->taxRefNo = $sale->tax_ref_no;
        $this->canEdit = Gate::allows('sales.edit');
    }

    public function startEditing(): void
    {
        $this->authorizeEdit();
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
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

    private function authorizeEdit(): void
    {
        abort_if(Gate::denies('sales.edit'), 403);
    }

    private function findSale(): Sale
    {
        $sale = Sale::findOrFail($this->saleId);
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
