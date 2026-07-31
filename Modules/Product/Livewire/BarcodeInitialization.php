<?php

namespace Modules\Product\Livewire;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBarcodeAssignment;
use Modules\Product\Services\BarcodeIdentityService;

class BarcodeInitialization extends Component
{
    use WithPagination;

    // Search state
    public $searchQuery = '';
    public $filterUninitializedOnly = true;

    // Component state machine: SEARCHING -> READY_TO_SCAN -> REVIEW -> SAVING -> SEARCHING
    public $currentState = 'SEARCHING';

    // Selected product state
    public $selectedProductId = null;
    public $selectedProductName = '';
    public $selectedProductCode = '';
    public $selectedProductUnit = '';
    public $originalBarcode = null;
    
    // Scan candidate state
    public $candidateBarcode = '';
    public $candidateError = null;

    // Stats
    public $sessionSavedCount = 0;
    public $recentSuccesses = [];

    protected $listeners = ['scannerCaptured' => 'handleScan'];

    public function mount()
    {
        abort_if(!auth()->user() || !auth()->user()->can('products.barcodes.manage'), 403);
    }

    public function updatedSearchQuery()
    {
        $this->resetPage();
        $this->cancelSelection();
    }

    public function updatedFilterUninitializedOnly()
    {
        $this->resetPage();
        $this->cancelSelection();
    }

    public function selectProduct($productId)
    {
        abort_if(!auth()->user()->can('products.barcodes.manage'), 403);

        $product = Product::with('baseUnit')
            ->where('id', $productId)
            ->first();

        if (!$product) {
            return;
        }

        $this->selectedProductId = $product->id;
        $this->selectedProductName = $product->product_name;
        $this->selectedProductCode = $product->product_code;
        $this->selectedProductUnit = $product->baseUnit ? $product->baseUnit->name : 'N/A';
        $this->originalBarcode = $product->barcode;
        
        $this->candidateBarcode = '';
        $this->candidateError = null;
        
        $this->currentState = 'READY_TO_SCAN';

        $this->dispatch('product-selected');
    }

    public function cancelSelection()
    {
        $this->currentState = 'SEARCHING';
        $this->selectedProductId = null;
        $this->candidateBarcode = '';
        $this->candidateError = null;
        $this->dispatch('selection-cancelled');
    }

    public function handleScan($barcode)
    {
        if ($this->currentState !== 'READY_TO_SCAN' && $this->currentState !== 'REVIEW') {
            return;
        }

        $cleanBarcode = trim($barcode);
        if (empty($cleanBarcode) || strlen($cleanBarcode) > 255) {
            $this->candidateError = empty($cleanBarcode) ? 'Barcode tidak boleh kosong.' : 'Barcode maksimal 255 karakter.';
            $this->currentState = 'READY_TO_SCAN';
            $this->dispatch('scan-error');
            return;
        }

        // Re-scan same barcode during REVIEW → auto-confirm save
        if ($this->currentState === 'REVIEW' && $this->candidateBarcode === $cleanBarcode) {
            $this->save(app(\Modules\Product\Services\ProductBarcodeAssignmentService::class));
            return;
        }

        $this->candidateBarcode = $cleanBarcode;
        $this->candidateError = null;
        $this->currentState = 'REVIEW';

        $this->dispatch('review-ready');
    }

    public function ulangiScan()
    {
        $this->candidateBarcode = '';
        $this->candidateError = null;
        $this->currentState = 'READY_TO_SCAN';
        $this->dispatch('scan-error');
    }

    public function save(\Modules\Product\Services\ProductBarcodeAssignmentService $assignmentService)
    {
        if ($this->currentState !== 'REVIEW' || empty($this->candidateBarcode) || !$this->selectedProductId) {
            return;
        }

        $this->currentState = 'SAVING';
        $this->candidateError = null;

        try {
            $result = $assignmentService->assign(
                $this->selectedProductId,
                $this->candidateBarcode,
                $this->originalBarcode,
                auth()->user()
            );

            if (!$result['success']) {
                if ($result['error'] === 'duplicate') {
                    $conflict = $result['conflict'] ?? [];
                    $productName = $conflict['product_name'] ?? null;
                    $productCode = $conflict['product_code'] ?? null;

                    if (($conflict['type'] ?? null) === 'conversion' && $productName && $productCode) {
                        $unitName = $conflict['unit_short_name'] ?? $conflict['unit_name'] ?? 'tidak diketahui';
                        $this->candidateError = "Barcode sudah digunakan pada produk \"{$productName}\" ({$productCode}) untuk unit {$unitName}.";
                    } elseif (($conflict['type'] ?? null) === 'product' && $productName && $productCode) {
                        $this->candidateError = "Barcode sudah digunakan pada produk \"{$productName}\" ({$productCode}).";
                    } else {
                        $this->candidateError = 'Barcode sudah digunakan.';
                    }
                } elseif ($result['error'] === 'stale_state') {
                    $this->candidateError = 'Status barcode produk telah berubah oleh pengguna lain. Silakan pilih kembali.';
                    $this->originalBarcode = $result['current_barcode'] ?? $this->originalBarcode;
                } elseif ($result['error'] === 'not_found' || $result['error'] === 'unauthorized') {
                    $this->candidateError = 'Produk tidak ditemukan atau akses ditolak.';
                } else {
                    $this->candidateError = 'Barcode tidak valid atau terjadi kesalahan lain.';
                }
                $this->currentState = 'READY_TO_SCAN';
                $this->dispatch('scan-error');
                return;
            }

            if (($result['status'] ?? '') === 'no_op') {
                $this->candidateError = 'Barcode baru sama dengan barcode lama (No-op).';
                $this->currentState = 'READY_TO_SCAN';
                $this->dispatch('scan-error');
                return;
            }

            // Record success
            $this->sessionSavedCount++;
            array_unshift($this->recentSuccesses, [
                'name' => $result['product']->product_name,
                'barcode' => $this->candidateBarcode,
                'time' => now()->format('H:i:s')
            ]);

            // Keep only last 5 successes
            if (count($this->recentSuccesses) > 5) {
                array_pop($this->recentSuccesses);
            }

            // Reset to searching (preserve search context)
            $this->cancelSelection();
            $this->dispatch('save-success', ['message' => 'Barcode berhasil disimpan.']);

        } catch (\Exception $e) {
            Log::error('Barcode Initialization Error', ['exception' => $e]);
            $this->candidateError = 'Terjadi kesalahan sistem saat menyimpan barcode.';
            $this->currentState = 'READY_TO_SCAN';
            $this->dispatch('scan-error');
        }
    }

    public function render()
    {
        $query = Product::query()
            ->with('baseUnit')
            ->globalSearch($this->searchQuery)
            ->when($this->filterUninitializedOnly, function ($q) {
                $q->where(function ($sub) {
                    $sub->whereNull('barcode')->orWhere('barcode', '');
                });
            })
            ->orderBy('product_name');

        $products = $query->paginate(10);

        return view('product::livewire.barcode-initialization', [
            'products' => $products
        ]);
    }
}
