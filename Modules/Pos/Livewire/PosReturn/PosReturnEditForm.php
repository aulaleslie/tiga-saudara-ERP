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
    public $returnOption;
    public $quantities = []; // sale_detail_id => quantity
    public $selectedSerials = []; // sale_detail_id => [serial_ids]
    public $error = null;

    public function mount(PosReturn $return)
    {
        abort_if(\Illuminate\Support\Facades\Gate::denies('pos.returns.edit'), 403);
        
        if ($return->status !== PosReturn::STATUS_PENDING_APPROVAL) {
            abort(403, 'Hanya retur yang masih menunggu persetujuan yang dapat diubah.');
        }

        $this->return = $return;
        $this->returnOption = $return->return_option;
        
        $snapshotService = app(PosReturnSnapshotService::class);
        $this->snapshot = $snapshotService->build($return->pos_transaction_id);
        
        // Initialize quantities and serials from existing return lines
        foreach ($this->snapshot['lines'] as $line) {
            $existingLine = $return->lines()->where('sale_detail_id', $line['sale_detail_id'])->first();
            $this->quantities[$line['sale_detail_id']] = $existingLine ? $existingLine->quantity : 0;
            
            if (!empty($line['serial_number_ids'])) {
                $this->selectedSerials[$line['sale_detail_id']] = $existingLine ? ($existingLine->serial_number_ids ?? []) : [];
            }
        }
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
            // Note: We might want a dedicated update method in submission service,
            // but for US2 simplicity, we'll just handle it here or reuse store logic.
            // Actually, submission service should handle the atomic update.
            
            \Illuminate\Support\Facades\DB::transaction(function () use ($lines) {
                // Remove old lines
                $this->return->lines()->delete();
                $this->return->saleReturns()->delete();
                
                // Re-calculate totals
                $totalAmount = 0;
                foreach ($lines as $lineData) {
                    $snapshotLine = collect($this->snapshot['lines'])->firstWhere('sale_detail_id', $lineData['sale_detail_id']);
                    $totalAmount += $lineData['quantity'] * ($snapshotLine['unit_price'] ?? 0);
                }

                $this->return->update([
                    'return_option' => $this->returnOption,
                    'total_amount' => $totalAmount,
                ]);

                // Create new lines (We can reuse submission service if we refactor it, but for now we'll do it manually)
                // Actually, it's better to refactor submission service later.
                // For now, let's just use the logic from store() but for update.
                
                $submissionService = app(PosReturnSubmissionService::class);
                // We'll trick the submission service by deleting the return first? No.
                // Let's just implement the logic here for now to satisfy T053.
                
                foreach ($lines as $lineData) {
                    $snapshotLine = collect($this->snapshot['lines'])->firstWhere('sale_detail_id', $lineData['sale_detail_id']);
                    
                    $this->return->lines()->create([
                        'setting_id' => $this->return->setting_id,
                        'product_id' => $snapshotLine['product_id'],
                        'sale_detail_id' => $lineData['sale_detail_id'],
                        'quantity' => $lineData['quantity'],
                        'unit_price' => $snapshotLine['unit_price'],
                        'sub_total' => $lineData['quantity'] * $snapshotLine['unit_price'],
                        'serial_number_ids' => $lineData['serial_number_ids'] ?? null,
                    ]);
                }
            });

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
