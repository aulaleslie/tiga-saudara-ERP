<?php

namespace App\Livewire\Transfer;

use Illuminate\Support\Facades\Log;
use Livewire\Component;
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
        }
    }

    public function onDestinationLocationSelected($payload)
    {
        Log::info('onDestinationLocationSelected', ['payload' => $payload]);
        if ($payload) {
            $this->destinationLocation = $payload['id'];
            unset($this->selfManagedValidationErrors['destination_location']);
        }
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

        $this->dispatch('tableValidationErrors', $tableErrors);

        // validate origin and destination
        if (! $this->originLocation) {
            $this->selfManagedValidationErrors['origin_location'] = 'Silakan pilih Lokasi Asal.';
        }
        if (! $this->destinationLocation) {
            $this->selfManagedValidationErrors['destination_location'] = 'Silakan pilih Lokasi Tujuan.';
        }

        // now validate rows separately
        if (empty($this->rows)) {
            $this->selfManagedValidationErrors['rows'] = 'Silakan pilih minimal satu produk.';
        } else {
            foreach ($this->rows as $i => $row) {
                $qt  = $row['quantity_tax']            ?? 0;
                $qn  = $row['quantity_non_tax']        ?? 0;
                $bqt = $row['broken_quantity_tax']     ?? 0;
                $bqn = $row['broken_quantity_non_tax'] ?? 0;
                $totalTransfer = $qt + $qn + $bqt + $bqn;

                if ($totalTransfer <= 0) {
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

        // if row validation errors, emit event and abort
        if (!empty($tableErrors) || ! empty($this->selfManagedValidationErrors)) {
            if (!empty($tableErrors)) {
                $this->dispatch('tableValidationErrors', $tableErrors);
            }
            return;
        }

        // All validations passed
        // TODO: Save logic
    }

    public function render()
    {
        return view('livewire.transfer.transfer-stock-form');
    }
}
