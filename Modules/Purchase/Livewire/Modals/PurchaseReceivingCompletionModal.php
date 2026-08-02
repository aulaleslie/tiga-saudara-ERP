<?php

namespace Modules\Purchase\Livewire\Modals;

use Exception;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Services\PurchaseReceivingCompletionService;

class PurchaseReceivingCompletionModal extends Component
{
    public $showModal = false;
    public ?Purchase $purchase = null;
    public $preview = null;
    public $reason = '';
    public $isSubmitting = false;
    public $error = null;

    protected $listeners = [
        'openReceivingCompletionModal' => 'openModal'
    ];

    public function openModal(Purchase $purchase)
    {
        $this->authorizeWorkflowStep($purchase);

        $this->purchase = $purchase;
        $this->reason = '';
        $this->error = null;
        $this->preview = null;
        $this->loadPreview();
        $this->showModal = true;
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function loadPreview()
    {
        $this->authorizeWorkflowStep($this->purchase);

        try {
            $this->error = null;
            $preview = app(PurchaseReceivingCompletionService::class)->preview($this->purchase);
            $this->preview = $preview;
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->preview = null;
        }
    }

    public function submit()
    {
        $this->authorizeWorkflowStep($this->purchase);

        $validated = $this->validate([
            'reason' => 'required|string|max:1000',
        ], [
            'reason.required' => 'Alasan kekurangan pemasok wajib diisi.',
        ]);

        $this->isSubmitting = true;

        try {
            app(PurchaseReceivingCompletionService::class)->complete(
                $this->purchase,
                $validated['reason'],
                auth()->id()
            );

            session()->flash('success', 'Penerimaan berhasil diselesaikan.');
            $this->dispatch('purchaseReceivingCompleted', ['purchase_id' => $this->purchase->id]);
            $this->closeModal();
        } catch (Exception $e) {
            $this->error = $e->getMessage();
        } finally {
            $this->isSubmitting = false;
        }
    }

    private function authorizeWorkflowStep(?Purchase $purchase): void
    {
        if (Gate::denies('purchases.receive.complete_shortfall')) {
            throw new Exception('Unauthorized');
        }

        if ($purchase === null) {
            throw new Exception('No purchase selected');
        }

        if ((int) $purchase->setting_id !== (int) session('setting_id')) {
            throw new Exception('Purchase does not belong to the active setting');
        }
    }

    private function resetForm()
    {
        $this->reason = '';
        $this->error = null;
        $this->preview = null;
        $this->resetErrorBag();
        $this->resetValidation();
    }

    public function render()
    {
        return view('purchase::livewire.modals.purchase-receiving-completion-modal');
    }
}
