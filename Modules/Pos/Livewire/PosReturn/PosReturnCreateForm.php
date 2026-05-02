<?php

namespace Modules\Pos\Livewire\PosReturn;

use Livewire\Component;
use Modules\Pos\Services\PosReturnLookupService;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;
use Modules\Pos\Entities\PosReturn;

class PosReturnCreateForm extends Component
{
    public $identifier = '';
    public $loading = false;
    public $error = null;
    public $snapshot = null;
    public $posTransactionId = null;
    public $posCheckoutId = null;

    // Submission data
    public $returnOption = PosReturn::OPTION_CASH_RETURN;
    public $quantities = []; // sale_detail_id => quantity
    public $selectedSerials = []; // sale_detail_id => [serial_ids]

    public function mount()
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.create'), 403);
    }

    public function lookup()
    {
        $this->validate([
            'identifier' => 'required|string|min:3',
        ]);

        $this->loading = true;
        $this->error = null;
        $this->snapshot = null;
        $this->quantities = [];
        $this->selectedSerials = [];

        try {
            $lookupService = app(PosReturnLookupService::class);
            $snapshotService = app(PosReturnSnapshotService::class);

            $result = $lookupService->lookup($this->identifier);

            if ($result) {
                $this->posTransactionId = $result['pos_transaction_id'];
                $this->posCheckoutId = $result['pos_checkout_id'];
                $this->snapshot = $snapshotService->build($this->posTransactionId);
                
                // Initialize quantities and serials
                foreach ($this->snapshot['lines'] as $line) {
                    $this->quantities[$line['sale_detail_id']] = 0;
                    if (!empty($line['serial_number_ids'])) {
                        $this->selectedSerials[$line['sale_detail_id']] = [];
                    }
                }
            } else {
                $this->error = 'Transaksi tidak ditemukan atau belum diposting.';
            }
        } catch (\Exception $e) {
            $this->error = 'Terjadi kesalahan: ' . $e->getMessage();
        } finally {
            $this->loading = false;
        }
    }

    public function resetLookup()
    {
        $this->identifier = '';
        $this->snapshot = null;
        $this->error = null;
        $this->quantities = [];
        $this->selectedSerials = [];
    }

    public function submit()
    {
        $this->validate([
            'returnOption' => 'required|in:' . PosReturn::OPTION_CASH_RETURN . ',' . PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'quantities.*' => 'numeric|min:0',
        ]);

        $lines = [];
        foreach ($this->quantities as $saleDetailId => $qty) {
            if ($qty > 0) {
                $line = [
                    'sale_detail_id' => $saleDetailId,
                    'quantity' => $qty,
                ];
                
                if (isset($this->selectedSerials[$saleDetailId])) {
                    $line['serial_number_ids'] = $this->selectedSerials[$saleDetailId];
                }
                
                $lines[] = $line;
            }
        }

        if (empty($lines)) {
            $this->addError('quantities', 'Pilih setidaknya satu produk untuk diretur.');
            return;
        }

        try {
            $submissionService = app(PosReturnSubmissionService::class);
            $posReturn = $submissionService->store([
                'pos_transaction_id' => $this->posTransactionId,
                'return_option' => $this->returnOption,
                'source_snapshot' => $this->snapshot,
                'source_snapshot_hash' => $this->snapshot['hash'],
                'lines' => $lines,
            ]);

            toast('Retur POS berhasil disubmit.', 'success');
            return redirect()->route('pos.returns.show', $posReturn->id);
        } catch (\Exception $e) {
            $this->error = 'Gagal menyimpan retur: ' . $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.modules.pos.pos-return.create-form');
    }
}
