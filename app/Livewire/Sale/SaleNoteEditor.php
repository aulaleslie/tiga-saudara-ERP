<?php

namespace App\Livewire\Sale;

use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Modules\Sale\Entities\Sale;

class SaleNoteEditor extends Component
{
    public int $saleId;
    public ?string $note = null;
    public bool $editing = false;
    public bool $canEdit = false;

    public function mount(int $saleId): void
    {
        $sale = Sale::withArchived()->findOrFail($saleId);
        $this->ensureSaleBelongsToCurrentSetting($sale);

        $this->saleId = $saleId;
        $this->note = $sale->note;
        $this->canEdit = Gate::allows('sales.edit') && !$sale->isArchived();
    }

    public function startEditing(): void
    {
        $this->authorizeEdit();
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $sale = $this->findSale();
        $this->note = $sale->note;
        $this->editing = false;
    }

    public function save(): void
    {
        $this->authorizeEdit();

        $data = $this->validate([
            'note' => 'nullable|string|max:1000',
        ]);

        $sale = $this->findSale();
        $value = $data['note'];
        $normalizedValue = $value === '' ? null : $value;

        $sale->update([
            'note' => $normalizedValue,
        ]);

        $this->note = $sale->note;
        $this->editing = false;

        $this->dispatch('notify', ['type' => 'success', 'message' => 'Catatan penjualan diperbarui.']);
    }

    public function render()
    {
        return view('livewire.sale.sale-note-editor');
    }

    private function authorizeEdit(): void
    {
        $sale = Sale::withArchived()->findOrFail($this->saleId);
        abort_if(Gate::denies('sales.edit') || $sale->isArchived(), 403);
    }

    private function findSale(): Sale
    {
        $sale = Sale::withArchived()->findOrFail($this->saleId);
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
