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
        $product = Product::with('baseUnit')->find($productId);
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
        if (empty($cleanBarcode)) {
            $this->candidateError = 'Barcode tidak boleh kosong.';
            $this->currentState = 'READY_TO_SCAN';
            $this->dispatch('scan-error');
            return;
        }

        $this->candidateBarcode = $cleanBarcode;
        $this->candidateError = null;
        $this->currentState = 'REVIEW';

        $this->dispatch('review-ready');
    }

    public function save()
    {
        if ($this->currentState !== 'REVIEW' || empty($this->candidateBarcode) || !$this->selectedProductId) {
            return;
        }

        $this->currentState = 'SAVING';
        $this->candidateError = null;

        $identityService = app(BarcodeIdentityService::class);

        try {
            DB::beginTransaction();

            $product = Product::lockForUpdate()->find($this->selectedProductId);
            
            if (!$product) {
                throw new \Exception("Product not found.");
            }

            if ($product->barcode !== $this->originalBarcode) {
                $this->candidateError = 'Status barcode produk telah berubah oleh pengguna lain. Silakan pilih kembali.';
                $this->currentState = 'READY_TO_SCAN';
                $this->originalBarcode = $product->barcode;
                DB::rollBack();
                $this->dispatch('scan-error');
                return;
            }

            if ($product->barcode === $this->candidateBarcode) {
                $this->candidateError = 'Barcode baru sama dengan barcode lama (No-op).';
                $this->currentState = 'READY_TO_SCAN';
                DB::rollBack();
                $this->dispatch('scan-error');
                return;
            }

            if ($product->barcode && !$this->candidateBarcode) {
                // Not supported via this UI to clear barcode
                $this->candidateError = 'Tidak bisa menghapus barcode dari halaman ini.';
                $this->currentState = 'READY_TO_SCAN';
                DB::rollBack();
                $this->dispatch('scan-error');
                return;
            }

            $action = $product->barcode ? 'replace' : 'initialize';
            
            if ($action === 'replace') {
                $result = $identityService->replace($product->barcode, $this->candidateBarcode, $product->id);
            } else {
                $result = $identityService->reserve($this->candidateBarcode, $product->id);
            }

            if (!$result['success']) {
                if ($result['error'] === 'duplicate') {
                    $conflict = $result['conflict'] ?? [];
                    $ownerType = ($conflict['type'] ?? 'unknown') === 'product' ? 'Produk Utama' : 'Konversi Unit';
                    $ownerId = $conflict['product_id'] ?? 'Unknown';
                    $this->candidateError = "Barcode sudah digunakan oleh {$ownerType} (ID Produk: {$ownerId}).";
                } else {
                    $this->candidateError = 'Barcode tidak valid.';
                }
                $this->currentState = 'READY_TO_SCAN';
                DB::rollBack();
                $this->dispatch('scan-error');
                return;
            }

            // Update the product
            $product->barcode = $this->candidateBarcode;
            $product->save();

            // Record audit history
            ProductBarcodeAssignment::create([
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'old_barcode' => $this->originalBarcode,
                'new_barcode' => $this->candidateBarcode,
                'action' => $action,
                'actor_id' => auth()->id(),
            ]);

            DB::commit();

            // Record success
            $this->sessionSavedCount++;
            array_unshift($this->recentSuccesses, [
                'name' => $product->product_name,
                'barcode' => $this->candidateBarcode,
                'time' => now()->format('H:i:s')
            ]);

            // Keep only last 5 successes
            if (count($this->recentSuccesses) > 5) {
                array_pop($this->recentSuccesses);
            }

            // Reset to searching
            $this->cancelSelection();
            $this->dispatch('save-success', ['message' => 'Barcode berhasil disimpan.']);

        } catch (\Exception $e) {
            DB::rollBack();
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
            ->when($this->searchQuery, function ($q) {
                $q->where(function ($sub) {
                    $sub->where('product_name', 'like', '%' . $this->searchQuery . '%')
                        ->orWhere('product_code', 'like', '%' . $this->searchQuery . '%');
                });
            })
            ->when($this->filterUninitializedOnly, function ($q) {
                $q->where(function ($sub) {
                    $sub->whereNull('barcode')->orWhere('barcode', '');
                });
            })
            ->orderBy('product_name');

        // Note: apply other visibility rules if required (e.g. active setting)
        $settingId = session('setting_id');
        if ($settingId) {
            $query->where('setting_id', $settingId);
        }

        $products = $query->paginate(10);

        return view('product::livewire.barcode-initialization', [
            'products' => $products
        ]);
    }
}
