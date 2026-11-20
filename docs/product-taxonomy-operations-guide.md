# Product Taxonomy Operations Guide

This guide covers previewing, executing, auditing, and finalizing global product category and brand deduplication.

## 1. Read-Only Reconciliation Preview

Run the preview command before making any changes. It does not mutate any rows.

```bash
php artisan product:reconcile-taxonomy
```

For machine-readable output (including full duplicate groups, metadata conflicts, and deleted brands):

```bash
php artisan product:reconcile-taxonomy --json
```

Inspect:
- **Plan Fingerprint**: SHA-256 hash of all category/brand rows, proposed mappings, and affected reference state.
- **Status**: Must be `READY` before execution. Any `BLOCKED` items (hierarchy cycles, orphan parents, unsupported incoming foreign keys, or deleted-brand collisions) must be resolved first.
- **Metadata Conflicts**: Survivor metadata (lowest existing active ID and its `category_code`, `name`, `description`) is preserved; original removed rows are stored in the audit table.
- **Deleted Brands**: Reported separately and never automatically merged or restored.

## 2. Explicit Confirmed Execution

Execution requires `--execute`, `--confirm`, the exact `--fingerprint` from the reviewed preview, and `--operator`:

```bash
php artisan product:reconcile-taxonomy \
  --execute \
  --confirm \
  --fingerprint="<sha256-from-preview>" \
  --operator="your-name-or-email"
```

Safety guarantees:
- Recomputes the plan inside a database transaction with deterministic row locks.
- Rejects stale fingerprints if any taxonomy row or affected product reference changed since preview.
- Remaps `products.category_id`, `categories.parent_id`, and `products.brand_id` before removing redundant rows.
- Rolls back all changes if any error occurs before commit.
- Rerunning after completion reports `no_op` without modifying data.

## 3. Deleted-Brand Conflicts

Soft-deleted brands reserve their normalized name. If a soft-deleted brand collides with an active brand or another soft-deleted brand:
- Preview reports the exact IDs and normalized names under `deleted_brand_conflicts` and blocks execution.
- Operators must explicitly decide how to resolve the colliding soft-deleted record (for example, renaming or permanently removing the obsolete soft-deleted record outside automatic merge) before re-running preview and execution.

## 4. Audit Retrieval

Every completed execution writes a durable record to `product_taxonomy_reconciliation_audits`:

```sql
SELECT id, plan_fingerprint, operator, status,
       category_groups_merged, categories_removed,
       brand_groups_merged, brands_removed,
       product_category_references_updated,
       category_parent_references_updated,
       product_brand_references_updated,
       executed_at
FROM product_taxonomy_reconciliation_audits
ORDER BY id DESC;
```

`mapping_payload` contains the exact JSON `category_mappings`, `brand_mappings`, `removed_categories`, and `removed_brands` snapshots.

## 5. Phased Deployment & Explicit Schema Finalization

Follow this exact sequence for populated databases (**prepare → preview → confirmed reconciliation → finalize**):

1. **Backup & Maintenance Pause**
   - Take a verified database backup (e.g. `mysqldump --single-transaction`).
   - Pause taxonomy mutations and stop/pause product import queue workers so no concurrent writer creates duplicates.
2. **Prepare (`php artisan migrate`)**
   - Run `php artisan migrate`.
   - On a populated database, this applies the preparation migrations (`2026_11_20_100000` and `2026_11_20_100001`), adding nullable canonical columns and the audit table while retaining legacy `setting_id` columns. It does **not** merge/delete records or run finalization.
   - *(On a fresh empty database with 0 brands and 0 categories, `2026_11_20_100000` automatically reaches the finalized global schema.)*
   - *(Recovery from an earlier failed `2026_11_20_100002` migration: because `2026_11_20_100000` and `2026_11_20_100001` are already recorded and `2026_11_20_100002` was removed in favor of the explicit command, running `php artisan migrate` now completes cleanly and you can proceed directly to step 3.)*
3. **Preview (`php artisan product:reconcile-taxonomy`)**
   - Run `php artisan product:reconcile-taxonomy` (read-only) and review the fingerprint, survivor mappings, metadata conflicts, and deleted brands.
4. **Confirmed Reconciliation (`php artisan product:reconcile-taxonomy --execute`)**
   - Run:
     ```bash
     php artisan product:reconcile-taxonomy \
       --execute \
       --confirm \
       --fingerprint="<sha256-from-preview>" \
       --operator="<operator>"
     ```
   - Verify exit code 0 and inspect the new row in `product_taxonomy_reconciliation_audits`.
5. **Finalize (`php artisan product:finalize-taxonomy`)**
   - Optionally check preflight first:
     ```bash
     php artisan product:finalize-taxonomy --check
     ```
   - Execute explicit finalization:
     ```bash
     php artisan product:finalize-taxonomy
     ```
   - The command preflights that all `normalized_name` and `parent_namespace` values are populated and zero duplicates exist before adding unique indexes (`brands_normalized_name_unique`, `categories_parent_namespace_normalized_name_unique`) and dropping `setting_id` foreign keys/columns.
   - If MySQL DDL fails partway, remain paused, resolve the cause, and re-run `php artisan product:finalize-taxonomy` — every DDL step is idempotent.
6. **Deploy Final Readers/Writers & Smoke Check**
   - Ensure all web and queue worker processes are running the updated global-taxonomy code.
   - Smoke check across two businesses: switch active business, verify shared brands/categories and selected product values remain visible, verify duplicate creation returns a validation error.
   - Resume queue workers.

## 6. Recovery & Rollback

- **Before finalization**: A failed reconciliation transaction rolls back automatically, leaving all rows and references intact.
- **After finalization**: Ordinary `migrate:rollback` cannot reconstruct deduplicated records or dropped `setting_id` ownership. To roll back, restore the verified pre-maintenance database backup and deploy the matching prior application version during the maintenance window.

