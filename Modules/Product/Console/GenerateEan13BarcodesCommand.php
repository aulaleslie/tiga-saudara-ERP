<?php

namespace Modules\Product\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Services\Ean13Service;
use Modules\Product\Services\BarcodeIdentityService;

class GenerateEan13BarcodesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'product:generate-ean13-barcodes {--dry-run : Preview changes without persisting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Normalize product barcodes to valid EAN-13 values.';

    private Ean13Service $ean13Service;
    private BarcodeIdentityService $identityService;
    private bool $isDryRun = false;

    private array $stats = [
        'preserved' => 0,
        'generated' => 0,
        'symbology_updated' => 0,
        'identity_repaired' => 0,
        'conflicts' => 0,
        'errors' => 0,
    ];

    private array $errors = [];
    private array $dryRunGeneratedCandidates = [];

    public function __construct(Ean13Service $ean13Service = null, BarcodeIdentityService $identityService = null)
    {
        parent::__construct();
        $this->ean13Service = $ean13Service ?? new Ean13Service();
        $this->identityService = $identityService ?? new BarcodeIdentityService();
    }

    public function handle()
    {
        $this->isDryRun = $this->option('dry-run');

        if ($this->isDryRun) {
            $this->info('Running in DRY-RUN mode. No changes will be persisted.');
        }

        $totalProducts = Product::query()->count();
        $this->info("Processing {$totalProducts} products...");

        Product::query()
            ->orderBy('id')
            ->chunk(1000, fn($products) => $this->processChunk($products));

        $this->reportSummary();

        return 0;
    }

    private function processChunk($products)
    {
        foreach ($products as $product) {
            try {
                $this->processProduct($product);
            } catch (\Exception $e) {
                $this->stats['errors']++;
                $this->errors[] = "Product {$product->id} ({$product->product_name}): {$e->getMessage()}";
            }
        }
    }

    private function processProduct(Product $product)
    {
        $currentBarcode = $product->barcode;

        if ($this->ean13Service->isValidEan13($currentBarcode)) {
            $this->handleValidEan13($product);
        } else {
            $this->handleInvalidBarcode($product);
        }
    }

    private function handleValidEan13(Product $product)
    {
        $conflict = $this->identityService->findConflict($product->barcode);
        $isBarcodeOwned = $conflict !== null && !($conflict['type'] === 'product' && $conflict['product_id'] === $product->id);

        if ($isBarcodeOwned) {
            $this->stats['conflicts']++;
            $this->errors[] = "Product {$product->id} ({$product->product_name}): Valid EAN-13 {$product->barcode} is owned by another record";
            return;
        }

        $symbologyNeedsUpdate = $product->product_barcode_symbology !== 'EAN13';

        if (!$this->isDryRun) {
            DB::transaction(function () use ($product) {
                $lockedProduct = Product::query()->lockForUpdate()->find($product->id);
                if (!$lockedProduct || $lockedProduct->barcode !== $product->barcode) {
                    throw new \RuntimeException('Product barcode changed concurrently');
                }

                $this->reconcileIdentity($product->id, $product->barcode);
                $lockedProduct->update(['product_barcode_symbology' => 'EAN13']);
            });
        } else {
            $this->countIdentityRepairs($product->id, $product->barcode);
        }

        if ($symbologyNeedsUpdate) {
            $this->stats['symbology_updated']++;
        } else {
            $this->stats['preserved']++;
        }
    }

    private function countIdentityRepairs(int $productId, string $barcode)
    {
        $conflict = $this->identityService->findConflict($barcode);

        if (!$conflict) {
            $this->stats['identity_repaired']++;
        } elseif ($conflict['type'] === 'product' && $conflict['product_id'] === $productId) {
            // Identity already owned by this product
        } else {
            $this->stats['conflicts']++;
        }
    }

    private function handleInvalidBarcode(Product $product)
    {
        $maxRetries = 10;
        $generated = null;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $candidate = $this->ean13Service->generateCandidate();

            if (!$this->ean13Service->isValidEan13($candidate)) {
                continue;
            }

            if (!$this->isDryRun) {
                try {
                    DB::transaction(function () use ($product, $candidate) {
                        $lockedProduct = Product::query()->lockForUpdate()->find($product->id);
                        if (!$lockedProduct) {
                            throw new \RuntimeException('Product not found');
                        }

                        if ($lockedProduct->barcode !== $product->barcode) {
                            throw new \RuntimeException('Product barcode changed concurrently');
                        }

                        $oldBarcode = $lockedProduct->barcode;

                        if (!$this->isCandidateUnique($candidate)) {
                            throw new \RuntimeException(json_encode(['success' => false, 'error' => 'duplicate']));
                        }

                        $reservation = $this->identityService->replace($oldBarcode ?? '', $candidate, $lockedProduct->id);
                        if (!$reservation['success']) {
                            throw new \RuntimeException(json_encode($reservation));
                        }

                        $lockedProduct->update([
                            'barcode' => $candidate,
                            'product_barcode_symbology' => 'EAN13',
                        ]);
                    });

                    $generated = $candidate;
                    break;
                } catch (\RuntimeException $e) {
                    $decoded = json_decode($e->getMessage(), true);
                    if (is_array($decoded) && isset($decoded['error']) && $decoded['error'] === 'duplicate') {
                        continue;
                    }
                    if ($e->getMessage() === 'Product barcode changed concurrently') {
                        $this->stats['errors']++;
                        $this->errors[] = "Product {$product->id} ({$product->product_name}): Barcode changed concurrently, skipping";
                        return;
                    }
                    throw $e;
                }
            } else {
                if ($this->isCandidateUniqueDryRun($candidate)) {
                    $this->dryRunGeneratedCandidates[] = $candidate;
                    $generated = $candidate;
                    break;
                }
            }
        }

        if ($generated) {
            $this->stats['generated']++;
        } else {
            $this->stats['errors']++;
            $this->errors[] = "Product {$product->id} ({$product->product_name}): Failed to generate unique EAN-13 after {$maxRetries} attempts";
        }
    }

    private function isCandidateUnique(string $candidate): bool
    {
        if (Product::where('barcode', $candidate)->exists()) {
            return false;
        }

        if (DB::table('product_unit_conversions')->where('barcode', $candidate)->exists()) {
            return false;
        }

        if ($this->identityService->findConflict($candidate) !== null) {
            return false;
        }

        return true;
    }

    private function isCandidateUniqueDryRun(string $candidate): bool
    {
        if ($this->isCandidateUnique($candidate)) {
            return !in_array($candidate, $this->dryRunGeneratedCandidates, true);
        }
        return false;
    }

    private function reconcileIdentity(int $productId, string $barcode)
    {
        $conflict = $this->identityService->findConflict($barcode);

        if (!$conflict) {
            $reservation = $this->identityService->reserve($barcode, $productId);
            if ($reservation['success']) {
                $this->stats['identity_repaired']++;
            } else if ($reservation['error'] !== 'duplicate') {
                throw new \RuntimeException("Failed to reserve identity: {$reservation['error']}");
            }
        } elseif ($conflict['type'] === 'product' && $conflict['product_id'] === $productId) {
            // Identity already owned by this product
        } else {
            $this->stats['conflicts']++;
            $this->errors[] = "Product {$productId}: Barcode {$barcode} is already owned by another record";
        }
    }

    private function reportSummary()
    {
        $this->info("\n====== Summary ======");
        $this->info("Preserved valid EAN-13s: {$this->stats['preserved']}");
        $this->info("Generated replacements: {$this->stats['generated']}");
        $this->info("Symbology updates: {$this->stats['symbology_updated']}");
        $this->info("Identity repairs: {$this->stats['identity_repaired']}");
        $this->info("Conflicts: {$this->stats['conflicts']}");
        $this->info("Errors: {$this->stats['errors']}");

        if ($this->isDryRun) {
            $this->info("(DRY-RUN: no changes persisted)");
        }

        if (!empty($this->errors) && $this->stats['errors'] > 0) {
            $this->warn("\n====== Errors ======");
            foreach (array_slice($this->errors, 0, 10) as $error) {
                $this->warn($error);
            }
            if (count($this->errors) > 10) {
                $this->warn("... and " . (count($this->errors) - 10) . " more errors");
            }
        }
    }
}
