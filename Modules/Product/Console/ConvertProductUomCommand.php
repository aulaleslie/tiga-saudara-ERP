<?php

namespace Modules\Product\Console;

use Illuminate\Console\Command;
use Modules\Product\Entities\Product;
use Modules\Product\Services\ProductImportUomEligibilityService;
use Modules\Product\Services\ProductImportUomMutationService;
use Modules\Setting\Entities\Unit;

class ConvertProductUomCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'product:convert-uom {product_id : The ID of the product to convert} {target_unit : Target unit ID or unit name/code} {factor : Conversion factor multiplier} {--reason= : Mandatory reason explaining the correction} {--dry-run : Preview eligibility, projected impact, and removable documents without mutating}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebase an import-origin product base UOM, quantities, and cost basis in place.';

    public function handle(
        ProductImportUomEligibilityService $eligibilityService,
        ProductImportUomMutationService $mutationService
    ): int {
        $productId = $this->argument('product_id');
        $targetUnitArg = $this->argument('target_unit');
        $factorArg = $this->argument('factor');
        $reason = $this->option('reason');
        $isDryRun = (bool) $this->option('dry-run');

        // 1. Validate Product Exists
        /** @var Product|null $product */
        $product = Product::with(['baseUnit', 'unit'])->find($productId);
        if (!$product) {
            $this->error("Produk dengan ID {$productId} tidak ditemukan.");
            return 1;
        }

        // 2. Validate Target Unit Exists
        $targetUnit = null;
        if (is_numeric($targetUnitArg)) {
            $targetUnit = Unit::find((int) $targetUnitArg);
        } else {
            $targetUnit = Unit::where('name', $targetUnitArg)
                ->orWhere('short_name', $targetUnitArg)
                ->first();
        }

        if (!$targetUnit) {
            $this->error("Satuan target '{$targetUnitArg}' tidak ditemukan.");
            return 1;
        }

        // 3. Validate Factor
        if (!is_numeric($factorArg) || (float) $factorArg <= 0) {
            $this->error("Faktor konversi harus berupa angka positif lebih dari 0. Diberikan: '{$factorArg}'");
            return 1;
        }
        $factor = (float) $factorArg;

        // 4. Validate Reason when not dry-run
        if (!$isDryRun && empty(trim((string) $reason))) {
            $this->error("Opsi --reason wajib diisi ketika tidak menggunakan --dry-run.");
            return 1;
        }

        $this->line("================================================================================");
        $this->info("Koreksi Base UOM Produk (Import-Origin)");
        $this->line("Mode: " . ($isDryRun ? "<comment>DRY-RUN (Simulasi - Tidak ada perubahan data)</comment>" : "<fg=red;options=bold>EXECUTE (Perubahan akan disimpan)</>"));
        $this->line("Produk: [{$product->id}] {$product->product_name}");
        $this->line("Satuan Saat Ini: " . ($product->baseUnit?->name ?? (string) $product->base_unit_id) . " (ID: {$product->base_unit_id})");
        $this->line("Satuan Target: {$targetUnit->name} (ID: {$targetUnit->id})");
        $this->line("Faktor Pengali: {$factor}");
        if (!$isDryRun) {
            $this->line("Alasan: {$reason}");
        }
        $this->line("================================================================================");

        // 5. Run Eligibility Check
        $result = $eligibilityService->checkEligibility($product, $targetUnit, $factor);

        if (!$result->isEligible()) {
            $this->error("PRODUK TIDAK MEMENUHI SYARAT KOREKSI:");
            foreach ($result->blockingReasons as $i => $blockingReason) {
                $this->line("  " . ($i + 1) . ". <fg=red>{$blockingReason}</>");
            }

            if (!empty($result->removableDocuments)) {
                $this->line("");
                $this->warn("Dokumen terhubung yang terdeteksi:");
                $this->renderRemovableDocumentsTable($result->removableDocuments);
            }

            return 1;
        }

        // Eligible!
        $preview = $result->preview;

        $this->info("✓ Validasi Kelayakan Berhasil (Eligible)");
        $this->line("");

        // Render Impact Preview
        $this->line("<comment>Proyeksi Perubahan Kuantitas:</comment>");
        $this->table(
            ['Metrik / Lokasi', 'Sebelum', 'Sesudah (x' . $factor . ')'],
            [
                ['Global Quantity (product_quantity)', $preview['product_quantity'], $preview['projected_product_quantity']],
            ]
        );

        if (!empty($preview['stocks'])) {
            $stockRows = [];
            foreach ($preview['stocks'] as $st) {
                $stockRows[] = [
                    $st['location_name'] . " (ID: {$st['location_id']})",
                    $st['quantity'],
                    $st['projected_quantity'],
                    $st['quantity_tax'],
                    $st['projected_quantity_tax'],
                    $st['quantity_non_tax'],
                    $st['projected_quantity_non_tax'],
                ];
            }
            $this->table(
                ['Lokasi', 'Qty Sebelum', 'Qty Sesudah', 'Tax Sblm', 'Tax Ssdh', 'Non-Tax Sblm', 'Non-Tax Ssdh'],
                $stockRows
            );
        }

        if (!empty($preview['prices'])) {
            $this->line("<comment>Proyeksi Perubahan Harga Beli & Jual (/{$factor}):</comment>");
            $priceRows = [];
            foreach ($preview['prices'] as $pr) {
                $priceRows[] = [
                    $pr['business_name'] ?? "Setting #{$pr['setting_id']}",
                    $pr['average_purchase_price'] ?? '-',
                    $pr['projected_average_purchase_price'] ?? '-',
                    $pr['last_purchase_price'] ?? '-',
                    $pr['projected_last_purchase_price'] ?? '-',
                    $pr['sale_price'] ?? '-',
                    $pr['projected_sale_price'] ?? '-',
                    $pr['tier_1_price'] ?? '-',
                    $pr['projected_tier_1_price'] ?? '-',
                    $pr['tier_2_price'] ?? '-',
                    $pr['projected_tier_2_price'] ?? '-',
                ];
            }
            $this->table(
                ['Cabang/Bisnis', 'Avg Cost Sblm', 'Avg Cost Ssdh', 'Last Cost Sblm', 'Last Cost Ssdh', 'Sale Sblm', 'Sale Ssdh', 'Tier1 Sblm', 'Tier1 Ssdh', 'Tier2 Sblm', 'Tier2 Ssdh'],
                $priceRows
            );
        }

        if (!empty($preview['purchase_details'])) {
            $this->line("<comment>Proyeksi Perubahan Riwayat Pembelian (purchase_details):</comment>");
            $pdRows = [];
            foreach ($preview['purchase_details'] as $pd) {
                $pdRows[] = [
                    $pd['id'],
                    $pd['purchase_reference'] ?? "Purchase #{$pd['purchase_id']}",
                    $pd['quantity'],
                    $pd['projected_quantity'],
                    $pd['unit_price'],
                    $pd['projected_unit_price'],
                    $pd['sub_total'] . ' (tetap)',
                ];
            }
            $this->table(
                ['PurchaseDetail ID', 'No. Pembelian', 'Qty Sblm', 'Qty Ssdh', 'Unit Price Sblm', 'Unit Price Ssdh', 'Sub Total'],
                $pdRows
            );
        }

        if (!empty($preview['current_barcode'])) {
            $this->warn("Barcode '{$preview['current_barcode']}' AKAN DIKOSONGKAN (tidak dimigrasikan ke satuan lama sebagai konversi).");
        }

        if (!empty($preview['rounding_notes'])) {
            $this->warn("Catatan Pembulatan Tampilan:\n" . $preview['rounding_notes']);
        }

        // Render Documents to be Removed
        $this->line("");
        if (!empty($result->removableDocuments)) {
            $this->warn("Dokumen draft/belum terbayar yang AKAN DIHAPUS OTOMATIS (" . count($result->removableDocuments) . " dokumen):");
            $this->renderRemovableDocumentsTable($result->removableDocuments);
        } else {
            $this->info("Tidak ada draft POS atau Sales belum terbayar yang perlu dihapus.");
        }

        // Dry Run Exit
        if ($isDryRun) {
            $this->line("");
            $this->info("Simulasi selesai. Tidak ada perubahan data yang disimpan.");
            return 0;
        }

        // Execute Mode
        $this->line("");
        $this->info("Mengeksekusi perubahan ke database...");

        try {
            $audit = $mutationService->execute(
                product: $product,
                targetUnit: $targetUnit,
                factor: $factor,
                reason: $reason,
                actorUserId: null // Can be bound to auth user when run in web/auth context
            );

            $this->info("✓ Koreksi UOM berhasil dieksekusi!");
            $this->line("Audit ID: <comment>#{$audit->id}</comment>");
            $this->line("Total Dokumen Dihapus: <comment>" . $audit->removedDocuments->count() . "</comment>");

            return 0;
        } catch (\Throwable $e) {
            $this->error("Gagal mengeksekusi koreksi: " . $e->getMessage());
            return 1;
        }
    }

    private function renderRemovableDocumentsTable(array $docs): void
    {
        $rows = [];
        foreach ($docs as $doc) {
            $rows[] = [
                $doc['document_type'],
                $doc['reference'],
                $doc['status'],
                number_format($doc['payment_amount'], 2),
                $doc['owner_or_customer'] ?? '-',
                $doc['created_at'] ?? '-',
            ];
        }

        $this->table(
            ['Tipe', 'Referensi / Kode', 'Status', 'Terbayar', 'Pemilik / Pelanggan', 'Tanggal Dibuat'],
            $rows
        );
    }
}
