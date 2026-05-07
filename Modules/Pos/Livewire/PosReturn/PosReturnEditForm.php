<?php

namespace Modules\Pos\Livewire\PosReturn;

use Livewire\Component;
use Modules\Pos\Entities\PosReturn;
use Modules\Pos\Services\PosReturnSnapshotService;
use Modules\Pos\Services\PosReturnSubmissionService;

class PosReturnEditForm extends Component
{
    public PosReturn $return;
    public $snapshot = null;
    
    // Submission data
    public $quantities = []; // product_id => quantity
    public $selectedSerials = []; // product_id => [serial_ids]
    public $serialInputs = []; // product_id => string
    public $showAvailableSerials = []; // product_id => bool
    public $error = null;

    public function mount(PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.edit'), 403);
        
        if ($return->status !== PosReturn::STATUS_PENDING_APPROVAL) {
            abort(403, 'Hanya retur yang masih menunggu persetujuan yang dapat diubah.');
        }

        $this->return = $return;
        
        $snapshotService = app(PosReturnSnapshotService::class);
        $this->snapshot = $snapshotService->build($return->pos_transaction_id);
        
        // Initialize quantities, serials, and inputs from existing return lines
        foreach ($this->snapshot['lines'] as $line) {
            $productId = $line['product_id'];
            $existingQty = $return->lines()->where('product_id', $productId)->sum('quantity');
            
            $this->quantities[$productId] = (float) $existingQty;
            $this->serialInputs[$productId] = '';
            $this->showAvailableSerials[$productId] = false;
            
            if ($line['is_tracked'] || !empty($line['serial_number_ids'])) {
                // Collect all serials for this product from all lines
                $this->selectedSerials[$productId] = $return->lines()
                    ->where('product_id', $productId)
                    ->get()
                    ->pluck('serial_number_ids')
                    ->flatten()
                    ->filter()
                    ->toArray();
            }
        }
    }

    public function addSerialByScan($productId)
    {
        $input = trim($this->serialInputs[$productId] ?? '');
        if (empty($input)) return;

        $line = collect($this->snapshot['lines'])->firstWhere('product_id', $productId);
        if (!$line || empty($line['serial_numbers'])) {
            $this->serialInputs[$productId] = '';
            return;
        }

        $serial = collect($line['serial_numbers'])->first(function ($sn) use ($input) {
            return strtoupper($sn['serial_number']) === strtoupper($input);
        });

        if ($serial) {
            if (!in_array($serial['id'], $this->selectedSerials[$productId])) {
                $this->selectedSerials[$productId][] = $serial['id'];
                $this->quantities[$productId] = count($this->selectedSerials[$productId]);
            }
        } else {
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
            $submissionService->update($this->return, [
                'source_snapshot_hash' => $this->snapshot['hash'],
                'lines' => $lines,
            ]);

            toast('Retur POS berhasil diperbarui.', 'success');
            return redirect()->route('pos.returns.show', $this->return->id);
        } catch (\Exception $e) {
            $this->error = 'Gagal memperbarui retur: ' . $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.modules.pos.pos-return.edit-form');
    }
}
