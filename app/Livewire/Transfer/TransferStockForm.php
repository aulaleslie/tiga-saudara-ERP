<?php

namespace App\Livewire\Transfer;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferProduct;
use Modules\Setting\Entities\Setting;

class TransferStockForm extends Component
{
    public $currentSetting;
    public $settings;

    public $selectedBusiness    = null;
    public $originLocation      = null;
    public $destinationLocation = null;

    // holds the table rows data
    public $rows = [];

    // hold validation errors for locations only
    public $selfManagedValidationErrors = [];

    protected $listeners = [
        'originLocationSelected'      => 'onOriginLocationSelected',
        'destinationLocationSelected' => 'onDestinationLocationSelected',
        'rowsUpdated'                 => 'onRowsUpdated',
    ];

    public function mount()
    {
        $this->currentSetting = Setting::find(session('setting_id'));
        $this->settings       = Setting::all();
    }

    public function onOriginLocationSelected($payload)
    {
        Log::info('onOriginLocationSelected', ['payload' => $payload]);

        if ($payload) {
            $this->originLocation = $payload['id'];
            unset($this->selfManagedValidationErrors['origin_location']);
            $this->destinationLocation = null;
        } else {
            $this->originLocation = null;
        }

        $this->notifyLocationChange();
    }

    public function onDestinationLocationSelected($payload)
    {
        Log::info('onDestinationLocationSelected', ['payload' => $payload]);
        if ($payload) {
            $this->destinationLocation = $payload['id'];
            unset($this->selfManagedValidationErrors['destination_location']);
        } else {
            $this->destinationLocation = null;
        }

        $this->notifyLocationChange();
    }

    public function onRowsUpdated(array $rows)
    {
        Log::info('onRowsUpdated', ['rows' => $rows]);
        $this->rows = $rows;
    }

    public function submit()
    {
        // reset location errors
        $this->selfManagedValidationErrors = [];
        $tableErrors = [];

        // emit any existing table errors (will be reset below if none)
        $this->dispatch('tableValidationErrors', $tableErrors);

        // validate origin and destination
        if (! $this->originLocation) {
            $this->selfManagedValidationErrors['origin_location'] = 'Silakan pilih Lokasi Asal.';
        }
        if (! $this->destinationLocation) {
            $this->selfManagedValidationErrors['destination_location'] = 'Silakan pilih Lokasi Tujuan.';
        }

        if ($this->originLocation && $this->destinationLocation && $this->originLocation === $this->destinationLocation) {
            $this->selfManagedValidationErrors['destination_location'] = 'Lokasi tujuan harus berbeda dari lokasi asal.';
        }

        // validate rows
        if (empty($this->rows)) {
            $this->selfManagedValidationErrors['rows'] = 'Silakan pilih minimal satu produk.';
        } else {
            foreach ($this->rows as $i => $row) {
                $qt  = $row['quantity_tax']            ?? 0;
                $qn  = $row['quantity_non_tax']        ?? 0;
                $bqt = $row['broken_quantity_tax']     ?? 0;
                $bqn = $row['broken_quantity_non_tax'] ?? 0;
                $total = $qt + $qn + $bqt + $bqn;

                if ($total <= 0) {
                    $tableErrors["row.{$i}"] = "Jumlah keseluruhan produk harus lebih besar dari 0.";
                }
                if ($qt > ($row['stock']['quantity_tax'] ?? 0)) {
                    $tableErrors["row.{$i}.quantity_tax"] =
                        "Jumlah Pajak tidak boleh lebih dari stok ({$row['stock']['quantity_tax']}).";
                }
                if ($qn > ($row['stock']['quantity_non_tax'] ?? 0)) {
                    $tableErrors["row.{$i}.quantity_non_tax"] =
                        "Jumlah Non Pajak tidak boleh lebih dari stok ({$row['stock']['quantity_non_tax']}).";
                }
                if ($bqt > ($row['stock']['broken_quantity_tax'] ?? 0)) {
                    $tableErrors["row.{$i}.broken_quantity_tax"] =
                        "Rusak Pajak tidak boleh lebih dari stok rusak ({$row['stock']['broken_quantity_tax']}).";
                }
                if ($bqn > ($row['stock']['broken_quantity_non_tax'] ?? 0)) {
                    $tableErrors["row.{$i}.broken_quantity_non_tax"] =
                        "Rusak Non Pajak tidak boleh lebih dari stok rusak ({$row['stock']['broken_quantity_non_tax']}).";
                }
            }
        }

        // abort on validation errors
        if (! empty($tableErrors) || ! empty($this->selfManagedValidationErrors)) {
            if (! empty($tableErrors)) {
                $this->dispatch('tableValidationErrors', $tableErrors);
            }
            return;
        }

        // all validations passed → persist data
        DB::beginTransaction();

        try {
            // 1) create the transfer header
            $transfer = Transfer::create([
                'origin_location_id'      => $this->originLocation,
                'destination_location_id' => $this->destinationLocation,
                'created_by'              => auth()->id(),
                'status'                  => Transfer::STATUS_PENDING,
            ]);

            // 2) create each transfer_product row
            foreach ($this->rows as $row) {
                $qt  = $row['quantity_tax']            ?? 0;
                $qn  = $row['quantity_non_tax']        ?? 0;
                $bqt = $row['broken_quantity_tax']     ?? 0;
                $bqn = $row['broken_quantity_non_tax'] ?? 0;
                $total = $qt + $qn + $bqt + $bqn;

                TransferProduct::create([
                    'transfer_id'               => $transfer->id,
                    'product_id'                => $row['id'],
                    'quantity'                  => $total,
                    'quantity_tax'              => $qt,
                    'quantity_non_tax'          => $qn,
                    'quantity_broken_tax'       => $bqt,
                    'quantity_broken_non_tax'   => $bqn,
                    // serial_numbers left null here; will be filled at dispatch
                ]);
            }

            DB::commit();

            // 3) reset form and show success
            toast('Transfer Stok Dibuat!', 'success');
            //
            return redirect()->route('transfers.index');
        }
        catch (Exception $e) {
            DB::rollback();
            Log::error('Transfer submit error', ['error' => $e->getMessage()]);
            session()->flash('message', 'Terjadi kesalahan saat mengajukan transfer.');
        }
    }

    public function render()
    {
        return view('livewire.transfer.transfer-stock-form');
    }

    protected function notifyLocationChange(): void
    {
        $this->dispatch('locationsConfirmed', [
            'originLocationId'      => $this->originLocation,
            'destinationLocationId' => $this->destinationLocation,
        ]);
    }
}
