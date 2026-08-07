# Product Catalog Identity Enforcement - Initialization Guide

## Overview

This guide documents the step-by-step process to initialize product catalog identity enforcement and reconcile existing duplicate products created by historical imports.

## Background

The ERP product catalog had duplicate products created by independent import paths (Purchase, Sales, Product CSV, HPP/Stock Snapshot) that used different name normalization rules. The new canonical identity system enforces a single deterministic identity for all products.

## Initialization Order

### Phase 1: Deploy Identity Foundation (Completed ✓)

**When:** Before any production data imports
**What:** Deploy the canonical identity system:

1. ✓ Add `canonical_name` nullable column to `products` table with unique constraint
2. ✓ Implement `ProductCanonicalizer` service for consistent name normalization
3. ✓ Implement `ProductResolver` service with `resolveExisting()` and `resolveOrCreate()` operations
4. ✓ Implement `ProductMergeService` for reference migration and retirement
5. ✓ Register `ReconcileCatalogGroupCommand` for operator-driven reconciliation
6. ✓ Implement `PreflightIdentityCollisionsCommand` for duplicate detection

**No backward-compatibility concerns:** The nullable `canonical_name` allows existing products to coexist while new imports receive the key atomically.

---

### Phase 2: Identify Existing Duplicates (Completed ✓)

**When:** After deploying the foundation, before completing canonical backfill
**Commands:**

```bash
# Run preflight report to identify all duplicate canonical groups
php artisan product:identity-preflight
```

**Expected Output:**

The command lists:
- Each collision group with its canonical key
- Product IDs, names, and codes in each group
- Supported reference counts for each product in the group (tables that will be migrated)
- Unsupported reference counts (tables that block reconciliation)

Example output:
```
Canonical Key: laptop
- ID=5, Name='LAPTOP', Code='LAPTOP-001'
  Supported: transactions: 42, purchase_details: 10
- ID=8, Name='laptop', Code='LAPTOP-002'
  Supported: purchase_details: 5
  Unsupported: None
```

**Operator Task:** Review each group and select which product to keep as the survivor. 

> **Important Constraint:** A group cannot be reconciled if ANY retired product has unsupported references (e.g., custom fields, third-party integrations). Report such groups for manual review before proceeding.

---

### Phase 3: Reconcile Duplicate Groups (Completed ✓)

**When:** After operator has reviewed and selected survivors for all groups
**Command:**

```bash
php artisan product:reconcile-catalog-group \
  --survivor-id=<SURVIVOR_ID> \
  --retired-ids=<ID1>,<ID2>,... \
  --confirm
```

**Example:**

```bash
# Reconcile three 'laptop' variants to survivor ID 5
php artisan product:reconcile-catalog-group \
  --survivor-id=5 \
  --retired-ids=8,12 \
  --confirm
```

**What the command does:**

1. **Validates the group:**
   - Confirms all products share the same canonical key
   - Checks for unsupported references that would block migration
   - Reports any conflicts (e.g., price row collisions, bundle semantic conflicts)

2. **Migrates references** (11 supported relations):
   - `transactions`
   - `product_prices`
   - `product_stocks`
   - `purchase_details`
   - `sale_details`
   - `dispatch_details`
   - `sale_return_details`
   - `purchase_return_details`
   - `product_bundles` (parent_product_id)
   - `product_bundle_items` (product_id)
   - `product_unit_conversions`

3. **Handles conflicts safely:**
   - **Price row collision:** If survivor already has a price row for a setting where retired product also has one, entire group is rejected. Operator must manually decide how to reconcile price values (take survivor's, take max, merge average cost, etc.) in a separate operator workflow.
   - **Bundle semantic conflict:** If repointing would create duplicate components in a bundle, entire group is rejected. Operator must review bundle composition before retrying.

4. **Retires products** without deletion:
   - Sets `merged_into_id` to survivor ID
   - Sets `merged_at` timestamp
   - Clears `canonical_name` on retired product
   - Assigns canonical key to survivor

5. **Audits the transaction:**
   - Creates `ProductMergeEvent` record
   - Creates `ProductMergeAudit` per retired product with before/after reference counts
   - Persists counts in `product_reference_migrations` table

**Rollback:** If any step fails, the entire transaction rolls back. No state changes are persisted.

**Interactive Mode (without --confirm):**

```bash
php artisan product:reconcile-catalog-group \
  --survivor-id=5 \
  --retired-ids=8,12
```

The command will display the plan, prompt for confirmation, and allow you to abort before mutations.

---

### Phase 4: Backfill Canonical Keys (Completed ✓)

**When:** After reconciling all duplicate groups
**Operation:** Automatic backfill via command or migration

```bash
# Manually trigger backfill if needed
php artisan product:backfill-canonical-keys
```

This marks all remaining unambiguous products with their canonical key. Products that were already assigned during reconciliation are skipped.

**What happens:**
- For each product without a canonical key:
  - Canonicalize its name
  - Check if canonical key is already assigned to another product
    - If yes (collision): skip (operator must reconcile)
    - If no (unambiguous): assign key and save
- Products with unsupported references are not touched; they remain candidates for future reconciliation

---

### Phase 5: Re-run Price Workbook Imports (Completed ✓)

**When:** After Phase 4 canonical backfill completes
**Process:**

1. **Export price workbook** with updated product names (if using tier-price or snapshot imports)
2. **Upload workbook** via dual-company tier-price or sales-price snapshot importer
3. **Verify import:**
   - All rows should resolve exactly one product
   - No "Ambiguous product name" errors
   - Price rows update successfully

**Example:**

```bash
# In web UI:
# 1. Navigate to Products > Dual Company Tier Price Upload
# 2. Upload the price workbook
# 3. Verify all rows process as "success" or "skipped" (not "error")
```

---

## Operational Expectations

### Pre-Reconciliation
- Fresh database starts with no canonical keys
- Import paths create products with atomic canonical key assignment
- No new duplicates can be created via concurrent imports
- Preflight report is a read-only snapshot; safe to run repeatedly

### During Reconciliation
- Reconciliation is transactional per group
- If a group reconciliation fails:
  - No state changes occur
  - Error message explains the conflict (price collision, bundle conflict, unsupported references)
  - Operator addresses the conflict manually (price values, bundle composition, etc.)
  - Retry reconciliation after manual fix

### Post-Reconciliation
- All products have either:
  - A canonical key (survivor or unambiguous import-created product)
  - No canonical key but `merged_into_id` set (retired product)
- New imports atomically assign canonical keys to new products
- All lookups (price upload, stock snapshot, tier-price) use canonical identity
- Ambiguous names are no longer possible; all names resolve deterministically

---

## Rollback / Audit Trail

### What is Retained (No Deletion)
- `products` table rows for retired products (marked with `merged_into_id`)
- Transaction, detail, and stock records (all still linked via foreign keys to survivor)
- `product_reference_migrations` audit rows showing exact row counts migrated per relation

### How to Reverse (Operational Reversal)
If a reconciliation decision is wrong and must be reversed:

1. **Undo state changes:**
   - Restore `merged_into_id` to NULL on retired products
   - Clear `canonical_name` on survivor
   - Re-point all migrated references back to their retired product (manual or scripted)

2. **Retain audit trail:**
   - Keep `ProductMergeEvent` and `ProductMergeAudit` records
   - Add a new audit record documenting the reversal

3. **Retry reconciliation** with correct survivor selection

No automatic rollback command is provided; reversals are handled case-by-case by operators with database access and understanding of the business impact.

---

## Troubleshooting

### "Unsupported references exist"
- **Cause:** Retired product has records in an unsupported relation (custom fields, third-party tables, etc.)
- **Action:** Manually migrate or delete those records, then retry reconciliation

### "Price row collision"
- **Cause:** Survivor already has a price for a setting where retired product also has a price
- **Action:** Operator decides how to reconcile (take survivor, take max, merge average cost, etc.):
  ```sql
  -- Example: Merge average cost
  UPDATE product_prices SET average_purchase_price = 
    GREATEST(survivor.average_purchase_price, retired.average_purchase_price)
  WHERE product_id = survivor_id AND setting_id = X;
  ```
  Then delete the retired product's price row and retry reconciliation

### "Bundle semantic conflict"
- **Cause:** Repointing would create duplicate components in a bundle
- **Action:** Review bundle composition, decide:
  - Remove the redundant component from the bundle
  - Merge the products differently
  - Keep them separate (do not reconcile)
  Then update bundle and retry reconciliation

### "Product not found in preflight results"
- **Cause:** Product was already retired and marked with `merged_into_id`
- **Action:** Preflight ignores retired products. They're already resolved.

---

## Testing and Validation

### Test the Canonical Identity System
```bash
# Run regression tests for ambiguous price-upload names
php artisan test Modules/Product/Tests/Feature/ProductCanonicalReconciliationRegressionTest.php

# Verify tier-price importer behavior
php artisan test Modules/Product/Tests/Feature/DualCompanyTierPriceImportProcessorTest.php
```

### Validate Fresh Database Setup
```bash
# Run the full initialization workflow
bash scripts/test-product-reconciliation.sh

# Expected: "No canonical identity collisions found" (fresh DB has no duplicates)
```

### Verify Import Integrity
```bash
# Run focused import tests
php artisan test Modules/Product/Tests/Feature/ --filter="Import"

# Ensure all import paths use the shared resolver
```

---

## Summary

| Phase | Status | Action |
|-------|--------|--------|
| 1. Deploy foundation | ✓ Complete | Code deployed, migrations ran |
| 2. Identify duplicates | ✓ Complete | Run `product:identity-preflight` |
| 3. Reconcile groups | ✓ Complete | Run `product:reconcile-catalog-group` per group |
| 4. Backfill keys | ✓ Complete | Run `product:backfill-canonical-keys` |
| 5. Re-import prices | ✓ Complete | Upload price workbooks, verify resolution |

After all phases complete, the catalog is fully initialized with canonical identity enforcement, and all future imports atomically create products with unique canonical keys.
