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
        $preparedRows = [];

        if (empty($this->rows)) {
            $this->selfManagedValidationErrors['rows'] = 'Silakan pilih minimal satu produk.';
        } else {
            foreach ($this->rows as $i => $row) {
                $manualQuantities = [
                    'quantity_tax'            => max(0, (int) ($row['quantity_tax']            ?? 0)),
                    'quantity_non_tax'        => max(0, (int) ($row['quantity_non_tax']        ?? 0)),
                    'quantity_broken_tax'     => max(0, (int) ($row['broken_quantity_tax']     ?? 0)),
                    'quantity_broken_non_tax' => max(0, (int) ($row['broken_quantity_non_tax'] ?? 0)),
                ];

                $serials       = $this->normalizeSerialPayload($row['serial_numbers'] ?? []);
                $serialDetails = $this->calculateSerialBreakdown($serials);

                $finalQuantities = $manualQuantities;
                $requiresSerial  = ! empty($row['serial_number_required']);

                if (! empty($serials)) {
                    if ($manualQuantities !== $serialDetails['quantities']) {
                        $tableErrors["row.{$i}.serial_numbers"] =
                            'Jumlah nomor seri tidak sesuai dengan rincian kuantitas yang dimasukkan.';
                    }

                    $finalQuantities = $serialDetails['quantities'];
                }

                if ($requiresSerial && empty($serials)) {
                    $tableErrors["row.{$i}.serial_numbers"] = 'Produk ini memerlukan nomor seri.';
                }

                $total = array_sum($finalQuantities);

                if ($total <= 0) {
                    $tableErrors["row.{$i}"] = "Jumlah keseluruhan produk harus lebih besar dari 0.";
                }

                $stock = $row['stock'] ?? [];

                if ($finalQuantities['quantity_tax'] > ($stock['quantity_tax'] ?? 0)) {
                    $tableErrors["row.{$i}.quantity_tax"] =
                        "Jumlah Pajak tidak boleh lebih dari stok ({$stock['quantity_tax']}).";
                }
                if ($finalQuantities['quantity_non_tax'] > ($stock['quantity_non_tax'] ?? 0)) {
                    $tableErrors["row.{$i}.quantity_non_tax"] =
                        "Jumlah Non Pajak tidak boleh lebih dari stok ({$stock['quantity_non_tax']}).";
                }
                if ($finalQuantities['quantity_broken_tax'] > ($stock['broken_quantity_tax'] ?? 0)) {
                    $tableErrors["row.{$i}.broken_quantity_tax"] =
                        "Rusak Pajak tidak boleh lebih dari stok rusak ({$stock['broken_quantity_tax']}).";
                }
                if ($finalQuantities['quantity_broken_non_tax'] > ($stock['broken_quantity_non_tax'] ?? 0)) {
                    $tableErrors["row.{$i}.broken_quantity_non_tax"] =
                        "Rusak Non Pajak tidak boleh lebih dari stok rusak ({$stock['broken_quantity_non_tax']}).";
                }

                $preparedRows[] = [
                    'id'                       => $row['id'],
                    'serial_numbers'           => $serials,
                    'quantities'               => $finalQuantities,
                    'total'                    => $total,
                ];
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
            foreach ($preparedRows as $row) {
                $quantities = $row['quantities'];
                TransferProduct::create([
                    'transfer_id'               => $transfer->id,
                    'product_id'                => $row['id'],
                    'quantity'                  => $row['total'],
                    'quantity_tax'              => $quantities['quantity_tax'],
                    'quantity_non_tax'          => $quantities['quantity_non_tax'],
                    'quantity_broken_tax'       => $quantities['quantity_broken_tax'],
                    'quantity_broken_non_tax'   => $quantities['quantity_broken_non_tax'],
                    'serial_numbers'            => ! empty($row['serial_numbers']) ? $row['serial_numbers'] : null,
                ]);
            }

            DB::commit();

            // 3) reset form and show success
            toast('Transfer Stok Dibuat! No. Dokumen: ' . $transfer->document_number, 'success');
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

    private function normalizeSerialPayload(array $serials): array
    {
        return collect($serials)
            ->map(function ($serial) {
                $id = (int) ($serial['id'] ?? 0);

                if ($id <= 0) {
                    return null;
                }

                $taxId   = $serial['tax_id'] ?? null;
                $taxable = array_key_exists('taxable', $serial)
                    ? (bool) $serial['taxable']
                    : ! empty($taxId);

                return [
                    'id'            => $id,
                    'serial_number' => $serial['serial_number'] ?? null,
                    'tax_id'        => $taxId !== null ? (int) $taxId : null,
                    'taxable'       => $taxable,
                    'is_broken'     => (bool) ($serial['is_broken'] ?? false),
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }

    private function calculateSerialBreakdown(array $serials): array
    {
        $quantityTax           = 0;
        $quantityNonTax        = 0;
        $brokenQuantityTax     = 0;
        $brokenQuantityNonTax  = 0;

        foreach ($serials as $serial) {
            $isBroken = (bool) ($serial['is_broken'] ?? false);
            $isTaxed  = array_key_exists('taxable', $serial)
                ? (bool) $serial['taxable']
                : ! empty($serial['tax_id']);

            if ($isBroken && $isTaxed) {
                $brokenQuantityTax++;
            } elseif ($isBroken && ! $isTaxed) {
                $brokenQuantityNonTax++;
            } elseif ($isTaxed) {
                $quantityTax++;
            } else {
                $quantityNonTax++;
            }
        }

        return [
            'quantities' => [
                'quantity_tax'            => $quantityTax,
                'quantity_non_tax'        => $quantityNonTax,
                'quantity_broken_tax'     => $brokenQuantityTax,
                'quantity_broken_non_tax' => $brokenQuantityNonTax,
            ],
            'total' => $quantityTax + $quantityNonTax + $brokenQuantityTax + $brokenQuantityNonTax,
        ];
    }
}
