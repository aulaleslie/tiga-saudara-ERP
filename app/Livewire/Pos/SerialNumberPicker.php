<?php

namespace App\Livewire\Pos;

use App\Support\PosLocationResolver;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

class SerialNumberPicker extends Component
{
    /** Target product */
    public ?int $productId = null;

    /** Optional bundle context */
    public ?array $bundle = null;

    /** Expected serial count (from conversion factor) */
    public ?int $expectedCount = null;

    /** Modal visibility */
    public bool $show = false;

    /** POS location id */
    private ?int $posLocationId = null;

    /** Last/ongoing scan text */
    public string $scan = '';

    /** Optional: show last few scanned serials (UX feedback only) */
    public array $recent = [];

    protected $listeners = [
        'openSerialPicker' => 'open',
    ];

    public function mount(?int $productId = null, array $preselected = []): void
    {
        $this->productId = $productId;
        $this->resolvePosLocation();
    }

    /** Open from parent: $dispatch('openSerialPicker', productId) */
    public function open($payload): void
    {
        if (is_array($payload)) {
            $this->productId    = isset($payload['product_id']) ? (int) $payload['product_id'] : $this->productId;
            $this->expectedCount = isset($payload['expected_count']) ? (int) $payload['expected_count'] : null;
            $this->bundle       = $payload['bundle'] ?? null;
        } else {
            $this->productId    = (int) $payload;
            $this->expectedCount = null;
            $this->bundle       = null;
        }

        $this->show = true;

        // Let the browser focus the input as soon as modal is visible
        $this->dispatch('focusSerialScanInput');
    }

    public function close(): void
    {
        $this->show = false;
        $this->scan = '';
        $this->bundle = null;
        $this->expectedCount = null;
    }

    public function render()
    {
        return view('livewire.pos.serial-number-picker');
    }

    /** Optional: re-focus handler (kept for reuse) */
    #[On('showSerialPickerModal')]
    public function focusScan(): void
    {
        $this->dispatch('focusSerialScanInput');
    }

    private function resolvePosLocation(): void
    {
        $this->posLocationId = PosLocationResolver::resolveId();
    }

    /** Enter pressed in the input (or scanner sends CR/LF) */
    public function scanSerial(): void
    {
        $code = trim($this->scan);

        if ($code === '') {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Masukkan/scan nomor serial.']);
            $this->dispatch('focusSerialScanInput');
            return;
        }
        if (!$this->productId || !$this->posLocationId) {
            $this->dispatch('toast', ['type' => 'danger', 'message' => 'Lokasi POS atau produk tidak valid.']);
            $this->dispatch('focusSerialScanInput');
            return;
        }

        $row = DB::table('product_serial_numbers as psn')
            ->select('psn.id', 'psn.serial_number')
            ->where('psn.product_id', $this->productId)
            ->where('psn.location_id', $this->posLocationId)
            ->where('psn.is_broken', 0)
            ->whereNull('psn.dispatch_detail_id')
            ->where('psn.serial_number', $code)
            ->first();

        if ($row) {
            // Immediately tell Checkout to add/append this serial
            $this->dispatch('serialScanned', [
                'product_id' => $this->productId,
                'serial' => [
                    'id' => (int)$row->id,
                    'serial_number' => (string)$row->serial_number,
                ],
                'bundle' => $this->bundle,
            ]);

            // UX: show as "recent", clear input, keep modal open for next scan
            array_unshift($this->recent, (string)$row->serial_number);
            $this->recent = array_slice($this->recent, 0, 6);

            $this->scan = '';
            $this->dispatch('focusSerialScanInput');
        } else {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Serial tidak ditemukan / sudah terpakai.']);
            $this->scan = '';
            $this->dispatch('focusSerialScanInput');
        }
    }
}
