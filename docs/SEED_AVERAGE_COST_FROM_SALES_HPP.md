# Seeding Average Purchase Prices from Sales HPP Snapshots

## Overview

After importing historical sales HPP (Harga Pokok Penjualan) snapshots via `product:sales-hpp-snapshot-import`, the system must establish current average purchase prices in `product_prices` for live POS and standard sales. The `product:seed-average-cost-from-sales-hpp` command provides an operator-controlled reconciliation step that previews and applies this seeding.

## Why This Step Is Needed

The sales HPP importer correctly applies source-ledger cost snapshots to matched historical `sale_details`, but it intentionally does not mutate `product_prices`. This separation ensures:
- Historical sale snapshots remain immutable
- Current catalog prices are not accidentally overwritten during partial imports
- Operators can review and approve the seeding process

## Operator Workflow

### Step 1: Complete and Review HPP Import

1. Run the HPP CSV import through the product import batch workflow
2. Verify that the import completed successfully with the expected row counts
3. Check for failed rows and resolve any data issues

### Step 2: Run Dry-Run Preview

```bash
php artisan product:seed-average-cost-from-sales-hpp
```

This command runs in **dry-run mode by default** (does not write to the database).

**Expected output:**
- `== DRY-RUN MODE ==`
- Considered products count
- Skipped (products with no eligible candidates)
- Created (new rows to create)
- Updated (existing rows to update)
- Unchanged (rows already matching the candidate)

**Review the output to:**
- Verify the number of products being seeded
- Check if source sale dates are recent and expected
- Confirm that special settings (CV TIGA NUSA COMPUTER, CV TOP IT INTERNUSA) are using their own bucket when available
- Ensure that regular settings share the REST/global cost correctly

### Step 3: Apply Changes

Once confirmed, apply the changes:

```bash
php artisan product:seed-average-cost-from-sales-hpp --write
```

This mode:
- Creates missing `product_prices` rows with the seeded average cost
- Updates existing rows, changing only `average_purchase_price`
- Preserves all other price metadata (last_purchase_price, selling prices, taxes)
- Reports the number of actual writes performed

## Behavior Details

### Candidate Selection

Only the latest authoritative imported snapshot per product and cost bucket is used:
- Source: `cost_snapshot_source = 'HPP_SNAPSHOT_IMPORT'`
- Positive cost: `cost_unit_snapshot > 0`
- Stock-managed products only
- Latest by: sale date → sale ID → sale-detail ID (deterministic tiebreaking)

### Cost Buckets

Three buckets are recognized:
1. **Tiga Nusa**: `CV TIGA NUSA COMPUTER` setting
2. **Top IT**: `CV TOP IT INTERNUSA` setting
3. **REST/global**: All other settings

Each setting receives:
- **Special settings** (Tiga Nusa, Top IT): Own bucket if available, else REST/global fallback
- **Regular settings**: REST/global only

### Row Creation

When a new `product_prices` row is needed, the command:
1. Copies `sale_price`, `tier_1_price`, `tier_2_price`, `purchase_tax_id`, `sale_tax_id` from an existing same-product row (if available)
2. Sets `average_purchase_price` to the selected snapshot cost
3. Defaults other fields to 0 or null

## Rollback

If changes must be reverted:
1. **Code rollback**: Uninstall the feature branch or revert to a prior release
2. **Data rollback**: Restore `product_prices.average_purchase_price` from a backup or re-run the seeding from a corrected imported snapshot dataset

For urgent recovery, manually update affected rows or contact database administration.

## Troubleshooting

### "Skipped" count is high

**Cause**: Few stock-managed products have imported HPP snapshots.

**Actions**:
- Verify HPP import completed successfully
- Check that imported sale details have `cost_snapshot_source = 'HPP_SNAPSHOT_IMPORT'`
- Ensure products are marked as `stock_managed = true`

### Created/Updated counts differ from dry-run

**Cause**: Expected; dry-run reports all target settings, actual writes may be fewer if no candidate exists for some buckets.

**Actions**: This is normal. Review the dry-run output for settings without candidates and decide if they should be seeded later.

### Command runs very slowly

**Cause**: Large product volume with many sale details.

**Actions**:
- This is expected on first run; subsequent runs are cached
- Consider running during low-traffic windows if on production

## Example Dry-Run Output

```
Starting product average-cost seeding in DRY-RUN mode...

== DRY-RUN MODE ==
Considered products: 1250
Skipped: 45
Created: 320
Updated: 885
Unchanged: 0
```

## See Also

- `product:normalize-purchase-prices` - Normalizes prices from approved received purchases
- Sales HPP Snapshot Import spec - Details on the CSV import workflow
