<?php

namespace App\Livewire\PosReturn;

use Livewire\Component;
use Modules\Pos\Services\PosReturnLookupService;
use Modules\Pos\Services\PosReturnSnapshotService;

class PosReturnCreateForm extends Component
{
    public $identifier = '';
    public $loading = false;
    public $error = null;
    public $snapshot = null;
    public $posTransactionId = null;
    public $posCheckoutId = null;

    public function lookup()
    {
        $this->validate([
            'identifier' => 'required|string|min:3',
        ]);

        $this->loading = true;
        $this->error = null;
        $this->snapshot = null;

        try {
            $lookupService = app(PosReturnLookupService::class);
            $snapshotService = app(PosReturnSnapshotService::class);

            $result = $lookupService->lookup($this->identifier);

            if ($result) {
                $this->posTransactionId = $result['pos_transaction_id'];
                $this->posCheckoutId = $result['pos_checkout_id'];
                $this->snapshot = $snapshotService->build($this->posTransactionId);
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
    }

    public function render()
    {
        return view('livewire.pos-return.pos-return-create-form');
    }
}
