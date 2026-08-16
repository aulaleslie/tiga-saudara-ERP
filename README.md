<picture>
    <source srcset="public/images/logo.png"  
            media="(prefers-color-scheme: dark)">
    <img src="public/images/logo-dark.png" alt="App Logo">
</picture>

> **Important Note:** This Project is ready for Production. But use code from main branch only. If you find any bug or have any suggestion please create an Issue.

# Local Installation

- run `` git clone https://github.com/aulaleslie/tiga-saudara-ERP.git ``
- run ``composer install `` 
- run `` npm install ``
- run ``npm run dev``
- copy .env.example to .env
- run `` php artisan key:generate ``
- set up your database in the .env
- run `` php artisan migrate --seed ``
- run `` php artisan storage:link ``
- run `` php artisan serve ``
- then visit `` http://localhost:8000 or http://127.0.0.1:8000 ``.

### Browser Extension Console Noise (Local Dev)

If your browser console shows intermittent `sw.js`, `runtime.lastError`, `mobx-state-tree`, or `lockdown-install.js` warnings during local development, follow this runbook first:

- [`docs/troubleshooting/browser-extension-console-noise.md`](docs/troubleshooting/browser-extension-console-noise.md)

# Admin Credentials
> Email: super.admin@test.com || Password: 12345678

## Demo
![Tiga Saudara ERP](public/images/screenshot.jpg)
**Live Demo:** will update soon

## Tiga Saudara ERP Features

- **Products Management & Barcode Printing**
- **Stock Management**
- **Make Quotation & Send Via Email**
- **Purchase Management**
- **Sale Management**
- **Purchase & Sale Return Management**
- **Expense Management**
- **Customer & Supplier Management**
- **Pengaturan Pengguna (Peran & Izin)**
- **Product Multiple Images**
- **Multiple Currency Settings**
- **Unit Settings**
- **System Settings**
- **Reports**

### PDF Configuration for Windows

> **Important Note:** "Tiga Saudara ERP" now uses the vendor-provided wkhtmltopdf binaries by default—no `.env` override is required.

- **Linux:** bundled via `h4cc/wkhtmltopdf-amd64` and used from `vendor/bin/wkhtmltopdf-amd64`.
- **Windows:** install the Windows binary package with Composer so the vendor path is available:
  ```bash
  composer require wemersonjanuario/wkhtmltopdf-windows:^0.12
  ```
- **Config cache:** if you change packages, clear config to pick up the new binary location:
  ```bash
  php artisan config:clear
  ```

# License
**[Creative Commons Attribution 4.0	cc-by-4.0](https://creativecommons.org/licenses/by/4.0/)**

## Maintenance Commands

```bash
php artisan queue:work --queue=default --tries=3 --timeout=7200
php artisan db:seed --class="Modules\User\Database\Seeders\PermissionsTableSeeder"
php artisan product:normalize-purchase-prices
php artisan product:normalize-purchase-prices --write

# Seed current average HPP from the latest authoritative imported sales-HPP snapshots.
# Run the dry-run first, review the selected source dates and row counts, then add --write.
php artisan product:seed-average-cost-from-sales-hpp
php artisan product:seed-average-cost-from-sales-hpp --write

# Initialization only: rebuild imported purchase/sales transaction history.
# Dry-run first, then run with both flags when ready. The write command truncates transactions.
php artisan inventory:normalize-import-transactions
php artisan inventory:normalize-import-transactions --initialize --write

# Backfill historical sale detail cost snapshots for Laporan Laba Rugi.
# Run dry-run first, inspect warning counts, then run with --write.
php artisan sales:backfill-cost-snapshots
php artisan sales:backfill-cost-snapshots --write

# Recompute existing backfilled snapshots when historical purchase/receiving data changed.
php artisan sales:backfill-cost-snapshots --write --force

# Optional filters for smaller/manual runs.
php artisan sales:backfill-cost-snapshots --product=123
php artisan sales:backfill-cost-snapshots --setting=1 --start=2026-01-01 --end=2026-06-30
php artisan sales:backfill-cost-snapshots --write --setting=1 --start=2026-01-01 --end=2026-06-30

# Repair and sync persisted notification rows for stock, approval, and revision states.
php artisan notifications:sync

# Manually prune old notifications. Notifications are retained unless this command is run.
php artisan notifications:prune --days=30

# Export product barcodes. The default output is storage/app/product_barcodes_export.csv.
php artisan product:export-barcodes

# Export to a specific CSV file and overwrite it without confirmation.
php artisan product:export-barcodes --path=/path/to/barcodes.csv --force

# Normalize all product base-unit barcodes to EAN-13 values.
# Run the dry-run first; generated internal codes use the 200–299 prefix range.
php artisan product:generate-ean13-barcodes --dry-run
php artisan product:generate-ean13-barcodes

# Export the CV TIGA NUSA COMPUTER product price list to Excel.
# The default output is storage/app/product_prices_tiga_nusa_export.xlsx.
php artisan product:export-tiga-nusa-prices

# Export to a specific Excel file and overwrite it without confirmation.
php artisan product:export-tiga-nusa-prices --path=/path/to/tiga-nusa-prices.xlsx --force

# Import requires the CSV path. Run a dry-run before applying changes.
php artisan product:import-barcodes storage/app/product_barcodes_export.csv --dry-run
php artisan product:import-barcodes storage/app/product_barcodes_export.csv
```

### Barcode Import and Export

Barcode CSV files must contain `product_name,barcode`. Import matches an exact product name and skips missing or ambiguous products, products that already have a barcode, invalid barcodes, and barcodes already in use.

### CV TIGA NUSA COMPUTER Product Price Export

`product:export-tiga-nusa-prices` creates an Excel price list with product name, sale price, Tier 1 price, and Tier 2 price. It includes every product ordered alphabetically by name; products without a CV TIGA NUSA COMPUTER price row have blank price cells. The command resolves CV TIGA NUSA COMPUTER by its exact company name and stops without writing a file if the setting is missing or duplicated.

### Sales Cost Snapshot Backfill

`sales:backfill-cost-snapshots` normalizes historical sale detail cost snapshots used by Laporan Laba Rugi. The command replays product purchases, approved receiving notes, purchase returns, and sales by effective date so profit/loss uses `sales - sales cost - expenses` instead of current mutable product average prices.

- Dry-run is the default and does not write changes.
- `--write` persists calculated `cost_unit_snapshot`, `cost_total_snapshot`, `cost_snapshot_source`, and `cost_snapshot_at` on `sale_details`.
- `--force` recomputes existing backfilled snapshots; use it after correcting historical purchase or receiving data.
- `--product`, `--setting`, `--start`, and `--end` can limit the replay scope for manual checks.
- Review summary warnings before writing, especially `negative_stock`, `missing_receipt_data`, `future_purchase_fallback`, `no_purchase_fallback`, and `non_stock_zero`.

### Seed Current Average HPP from Imported Sales

`product:seed-average-cost-from-sales-hpp` is the explicit post-import reconciliation step for historical sales HPP imports. It seeds `product_prices.average_purchase_price` from the latest successful `HPP_SNAPSHOT_IMPORT` cost snapshots for each stock-managed product, creating missing product-price rows as needed.

- Dry-run is the default; review selected source sale dates and created, updated, unchanged, and unresolved row counts before writing.
- `--write` seeds only `product_prices.average_purchase_price`; it preserves existing `last_purchase_price`, selling/tier prices, and tax metadata unchanged.
- Tiga Nusa and Top IT use their own latest HPP when available; other businesses fall back to shared baseline. Non-special settings use shared baseline only.
- Purchase-import owns `last_purchase_price` reconciliation exclusively; this command does not touch it.
- Run this after reviewing the historical HPP import. It does not change historical sale snapshots, stock, purchases, or inventory transactions.

See [the full operator guide](docs/SEED_AVERAGE_COST_FROM_SALES_HPP.md) for the workflow and troubleshooting.

### Import Transaction Normalization

`inventory:normalize-import-transactions` is an initialization-only command for rebuilding the stock transaction ledger from imported purchase and sales documents.

- Dry-run is the default and does not write changes.
- `--initialize --write` truncates `transactions` and recreates normalized import movements.
- Imported purchases create `BUY` transactions with positive quantities.
- Imported sales create `SELL` transactions with negative quantities.
- The command does not update `product_stocks`; product stock snapshot import remains the only import path that hardens current stock quantities.
- Run this before product stock snapshot import. Stock snapshot import then updates `product_stocks` and creates `ADJ` transactions from the latest normalized ledger balance to the snapshot quantity.

Recommended initialization order:

```bash
# 1. Import purchase and sales documents through the import screens.

# 2. Rebuild historical BUY/SELL transaction ledger.
php artisan inventory:normalize-import-transactions
php artisan inventory:normalize-import-transactions --initialize --write

# 3. Import product stock snapshot through the product stock import screen.
```

### Import-Origin Base UOM Quick Conversion

`product:convert-uom` is an operator tool to correct the base Unit of Measure (UOM) for products whose stock originated from an import/adjustment (`ADJ`) snapshot rather than receiving notes (`BUY`).

```bash
# Preview eligibility, projected stock/cost-basis changes, and removable draft documents
php artisan product:convert-uom 4669 PCS 82 --dry-run

# Execute correction with mandatory reason
php artisan product:convert-uom 4669 PCS 82 --reason="Koreksi base UOM rokok dari BKS ke PCS"
```

**Eligibility & Safety Rules:**
- **Zero Fulfillment History:** Refuses if any `DISPATCH`-type transaction or paid/dispatched `Sale` exists for the product.
- **No BUY-Lineage:** Refuses if any `BUY`-type transaction exists (use Received Purchase Normalization instead).
- **Ledger Integrity:** Verifies live stock matches running balance recorded across all locations and globally.
- **Broken Stock:** Refuses if any `broken_quantity` bucket is non-zero.
- **Unhandled Complexity:** Refuses if unit conversions exist, if barcode is set, or if footprint spans multiple settings.
- **Automatic Draft Cleanup:** Force-deletes any pending POS drafts (`DRAFT`/`LOADED`) and unpaid/undispatched Sales referencing the product to prevent silent wrong-quantity dispatch, recording all deleted documents in the audit log (`product_uom_correction_audits` and `product_uom_correction_removed_documents`).

