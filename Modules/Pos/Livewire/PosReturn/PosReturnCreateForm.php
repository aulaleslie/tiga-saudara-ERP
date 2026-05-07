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
    public $quantities = []; // product_id => quantity
    public $selectedSerials = []; // product_id => [serial_ids]
    public $serialInputs = []; // product_id => string (for barcode scanner)
    public $showAvailableSerials = []; // product_id => bool

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
        $this->serialInputs = [];

        try {
            $lookupService = app(PosReturnLookupService::class);
            $snapshotService = app(PosReturnSnapshotService::class);

            $result = $lookupService->lookup($this->identifier);

            if ($result) {
                $this->posTransactionId = $result['pos_transaction_id'];
                $this->posCheckoutId = $result['pos_checkout_id'];
                $this->snapshot = $snapshotService->build($this->posTransactionId);
                
                // Initialize quantities, serials, and inputs
                foreach ($this->snapshot['lines'] as $line) {
                    $productId = $line['product_id'];
                    $this->quantities[$productId] = 0;
                    $this->serialInputs[$productId] = '';
                    $this->showAvailableSerials[$productId] = false;
                    if ($line['is_tracked'] || !empty($line['serial_number_ids'])) {
                        $this->selectedSerials[$productId] = [];
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

    public function addSerialByScan($productId)
    {
        $input = trim($this->serialInputs[$productId] ?? '');
        if (empty($input)) return;

        // Find product line in snapshot
        $line = collect($this->snapshot['lines'])->firstWhere('product_id', $productId);
        if (!$line || empty($line['serial_numbers'])) {
            $this->serialInputs[$productId] = '';
            return;
        }

        // Find serial in the available serials for this product
        $serial = collect($line['serial_numbers'])->first(function ($sn) use ($input) {
            return strtoupper($sn['serial_number']) === strtoupper($input);
        });

        if ($serial) {
            if (!in_array($serial['id'], $this->selectedSerials[$productId])) {
                $this->selectedSerials[$productId][] = $serial['id'];
                $this->quantities[$productId] = count($this->selectedSerials[$productId]);
            }
        } else {
            // Check if it's already selected but maybe scanned again? Or just invalid.
            // For now, if not found in available list, it's invalid for this transaction.
            $this->addError("serialInputs.{$productId}", "Serial number {$input} tidak ditemukan dalam transaksi ini.");
        }

        $this->serialInputs[$productId] = '';
        $this->dispatch('serial-scanned', productId: $productId);
    }

    public function removeSerial($productId, $serialId)
    {
        if (isset($this->selectedSerials[$productId])) {
            $this->selectedSerials[$productId] = array_values(array_diff($this->selectedSerials[$productId], [$serialId]));
            $this->quantities[$productId] = count($this->selectedSerials[$productId]);
        }
    }

    public function toggleSerial($productId, $serialId)
    {
        if (!isset($this->selectedSerials[$productId])) {
            $this->selectedSerials[$productId] = [];
        }

        if (in_array($serialId, $this->selectedSerials[$productId])) {
            $this->selectedSerials[$productId] = array_values(array_diff($this->selectedSerials[$productId], [$serialId]));
        } else {
            $this->selectedSerials[$productId][] = $serialId;
        }
        
        $this->quantities[$productId] = count($this->selectedSerials[$productId]);
    }

    public function toggleAvailableSerials($productId)
    {
        $this->showAvailableSerials[$productId] = !($this->showAvailableSerials[$productId] ?? false);
    }

    public function resetLookup()
    {
        $this->identifier = '';
        $this->snapshot = null;
        $this->error = null;
        $this->quantities = [];
        $this->selectedSerials = [];
        $this->serialInputs = [];
    }

    public function submit()
    {
        $this->validate([
            'quantities.*' => 'numeric|min:0',
        ]);

        $lines = [];
        foreach ($this->quantities as $productId => $qty) {
            if ($qty > 0) {
                $line = [
                    'product_id' => $productId,
                    'quantity' => $qty,
                ];
                
                if (isset($this->selectedSerials[$productId])) {
                    $line['serial_number_ids'] = $this->selectedSerials[$productId];
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
                'return_option' => PosReturn::OPTION_CASH_RETURN, // Default, will be finalized in approval
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
