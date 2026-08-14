<?php

namespace Modules\Product\Livewire;

use App\Services\EffectiveDocumentBusinessResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Product\Entities\Product;
use Modules\Product\Services\BarcodeBatchService;

class BarcodeBatchWorkspace extends Component
{
    /** @var array<int, array{product_id:int, product_name:string, product_code:string, quantity:int}> */
    public array $rows = [];

    public ?int $selectedSettingId = null;

    /** @var array<int, string> */
    public array $batchErrors = [];

    /** @var array<int, array<string, mixed>> */
    public array $previewLabels = [];

    /** @var array<int, array<string, mixed>> */
    #[Locked]
    public array $productPreviews = [];

    public bool $previewed = false;

    protected $listeners = [
        'productSelected' => 'addProduct',
        'business-selector-changed' => 'businessChanged',
    ];

    public function mount(): void
    {
        $this->selectedSettingId = session('setting_id');
        $this->refreshProductPreviews();
    }

    public function getCanOverrideBusinessProperty(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        try {
            return $user->hasRole('Super Admin') || $user->hasPermissionTo('documents.business.override');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getTotalLabelsProperty(): int
    {
        return array_sum(array_column($this->rows, 'quantity'));
    }

    public function addProduct($product): void
    {
        $productId = (int) (is_array($product) ? ($product['id'] ?? 0) : $product);

        if ($productId <= 0) {
            return;
        }

        $this->resetPreview();

        foreach ($this->rows as $index => $row) {
            if ($row['product_id'] === $productId) {
                $this->rows[$index]['quantity'] = min(
                    BarcodeBatchService::MAX_PER_PRODUCT,
                    $row['quantity'] + 1
                );
                $this->refreshProductPreviews();

                return;
            }
        }

        $model = Product::find($productId);

        if (! $model) {
            return;
        }

        $this->rows[] = [
            'product_id' => (int) $model->id,
            'product_name' => (string) $model->product_name,
            'product_code' => (string) $model->product_code,
            'quantity' => 1,
        ];

        $this->refreshProductPreviews();
    }

    public function removeRow(int $index): void
    {
        if (! array_key_exists($index, $this->rows)) {
            return;
        }

        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
        $this->resetPreview();
        $this->refreshProductPreviews();
    }

    public function updatedRows(): void
    {
        foreach ($this->rows as $index => $row) {
            $quantity = (int) $row['quantity'];
            $this->rows[$index]['quantity'] = max(1, min(BarcodeBatchService::MAX_PER_PRODUCT, $quantity));
        }

        $this->resetPreview();
    }

    public function businessChanged($payload = null): void
    {
        $settingId = is_array($payload) ? ($payload['settingId'] ?? null) : $payload;
        $this->selectedSettingId = $settingId !== null ? (int) $settingId : null;
        $this->resetPreview();
        $this->refreshProductPreviews();
    }

    public function updatedSelectedSettingId(): void
    {
        $this->resetPreview();
        $this->refreshProductPreviews();
    }

    /**
     * Refresh the product previews map for current rows and selected setting.
     */
    public function refreshProductPreviews(): void
    {
        if ($this->rows === []) {
            $this->productPreviews = [];

            return;
        }

        $productIds = array_column($this->rows, 'product_id');

        try {
            $resolver = app(EffectiveDocumentBusinessResolver::class);
            $resolved = $resolver->resolve($this->selectedSettingId);
            $resolvedSettingId = $resolved['setting_id'];
        } catch (AuthorizationException $e) {
            $this->productPreviews = [];
            foreach ($productIds as $pId) {
                $this->productPreviews[(int) $pId] = [
                    'valid' => false,
                    'product_id' => (int) $pId,
                    'product_name' => '',
                    'product_code' => '',
                    'display_sku' => '',
                    'barcode' => '',
                    'symbology' => '',
                    'sale_price' => null,
                    'svg' => null,
                    'error' => 'Perusahaan yang dipilih tidak dapat diakses.',
                ];
            }

            return;
        }

        /** @var BarcodeBatchService $service */
        $service = app(BarcodeBatchService::class);
        $this->productPreviews = $service->resolvePreviewMap($productIds, $resolvedSettingId);
    }

    /**
     * Validate the batch server-side. Returns the expanded labels, or null when
     * the batch is invalid — in which case `$batchErrors` explains why.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function validateBatch(BarcodeBatchService $service, EffectiveDocumentBusinessResolver $resolver): ?array
    {
        $this->resetPreview();

        if ($this->rows === []) {
            $this->batchErrors = ['Pilih minimal satu produk untuk dicetak.'];

            return null;
        }

        if ($this->totalLabels > BarcodeBatchService::MAX_TOTAL_LABELS) {
            $this->batchErrors = [
                'Total label melebihi batas ' . BarcodeBatchService::MAX_TOTAL_LABELS . ' label per batch.',
            ];

            return null;
        }

        try {
            $resolved = $resolver->resolve($this->selectedSettingId);
        } catch (AuthorizationException $e) {
            $this->batchErrors = ['Perusahaan yang dipilih tidak dapat diakses.'];

            return null;
        }

        $result = $service->expand(
            array_map(fn ($row) => [
                'product_id' => $row['product_id'],
                'quantity' => $row['quantity'],
            ], $this->rows),
            $resolved['setting_id']
        );

        if ($result['errors'] !== []) {
            $this->batchErrors = $result['errors'];

            return null;
        }

        return $result['labels'];
    }

    public function preview(BarcodeBatchService $service, EffectiveDocumentBusinessResolver $resolver): void
    {
        $labels = $this->validateBatch($service, $resolver);

        if ($labels === null) {
            return;
        }

        $this->previewLabels = $labels;
        $this->previewed = true;
    }

    /**
     * Validate first, then hand off to the standalone print document. Errors stay
     * visible in this workspace and no print tab is opened for an invalid batch.
     */
    public function print(BarcodeBatchService $service, EffectiveDocumentBusinessResolver $resolver): void
    {
        $labels = $this->validateBatch($service, $resolver);

        if ($labels === null) {
            $this->dispatch('barcode-batch-invalid');

            return;
        }

        $this->previewLabels = $labels;
        $this->previewed = true;

        $this->dispatch('barcode-batch-ready');
    }

    private function resetPreview(): void
    {
        $this->previewLabels = [];
        $this->batchErrors = [];
        $this->previewed = false;
    }

    public function render()
    {
        return view('product::livewire.barcode-batch-workspace', [
            'totalLabels' => $this->totalLabels,
            'canOverrideBusiness' => $this->canOverrideBusiness,
        ]);
    }
}
