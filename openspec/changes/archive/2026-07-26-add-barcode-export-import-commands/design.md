## Context

The team cleans up production data with `php artisan migrate:fresh --seed`. This drops and recreates every table, so products are destroyed and later recreated by the regular data imports. Ids and product codes are regenerated and do not correspond to their pre-cleanup values.

Barcodes are entered manually, one product at a time, through the "Kelola Barcode" workspace (`BarcodeInitialization::save()` → `ProductBarcodeAssignmentService::assign()`). That accumulated work is currently lost on every cleanup.

Relevant existing structure:

- `products.barcode` — the only barcode in production use. Carries a `UNIQUE` index.
- `product_unit_conversions.barcode` — also carries a `UNIQUE` index, but **no rows in production use it**.
- `barcode_identities` — a registry with `canonical_key` `UNIQUE` globally, plus a `chk_single_owner` CHECK constraint requiring exactly one of `product_id` / `product_unit_conversion_id`. Written via `BarcodeIdentityService::reserve()`.
- `Modules\Product\Utils\BarcodeUtils::canonicalize()` — trims and lowercases a barcode to derive `canonical_key`.

Two facts shape the whole design:

1. **`PosScanResolverService` scans the columns directly**, not the registry. The registry exists to enforce uniqueness, not to serve lookups.
2. **All three unique constraints are already active** when the reimport runs. The original backfill migration ran its preflight *before* adding them; the reimport gets no such grace period.

## Goals / Non-Goals

**Goals:**
- Preserve manually entered product barcodes across a `migrate:fresh --seed` cleanup with no re-entry by users.
- Key the backup on product name, the only identifier that survives the cleanup.
- Give the operator a way to verify the backup is complete *before* the irreversible step.
- Leave the database in a state where subsequent UI barcode assignments remain safe — i.e. the registry must agree with the columns.
- Make the restore reviewable (`--dry-run`) and safely repeatable.

**Non-Goals:**
- Restoring `product_unit_conversions.barcode`. No conversion barcodes exist in production; supporting them would require a composite `(name, unit, factor)` key for no present benefit.
- Restoring ids, product codes, or any product attribute other than the barcode.
- Deduplicating products, or changing how any import service resolves or creates products.
- Reproducing the audit trail. Restored barcodes are prior state being reinstated, not new assignments.
- Any schema change. No migrations are added.

## Decisions

### D1: Key the file on raw `product_name`, with no normalization

Product names are written and matched **verbatim** — no marker stripping (`* PREFIX`, ` TP` suffix), no alias mapping, no case folding, no whitespace collapsing.

*Rationale:* The normalization in `SalesImportMarkerResolver` exists to collapse messy third-party CSV input onto canonical products. This file is not third-party input — both sides of the round trip are `products.product_name` written by this application. Applying a lossy collapse to already-canonical data would only merge distinct products and invent ambiguity that does not exist in the source.

*Alternatives considered:*
- *Normalized key (`normalizeProductName`)* — rejected. It would make `MOUSE VOTRE VOXY` and `MOUSE VOTRE SANURPRO` collide via the alias table, and would couple the restore to an alias table that can change between export and import.
- *Store both raw and normalized* — rejected as unnecessary once matching is exact; the extra column would never be read.

### D2: Export reads only barcoded products

`WHERE barcode IS NOT NULL AND barcode != ''`.

*Rationale:* This filter also resolves the common duplicate-name case for free. Where two near-identical product rows exist and only one carries a barcode, only the barcoded one enters the file, so the export is unambiguous without any extra disambiguation logic.

### D3: Reimport requires exactly one name match; 2+ matches skip

*Rationale:* MySQL's default collation is case-insensitive, so `WHERE product_name = 'KABEL LAN 5M'` can match both `KABEL LAN 5M` and `Kabel LAN 5m` if both were recreated post-cleanup. Assigning to an arbitrary row (e.g. lowest id) risks attaching a barcode to the wrong product — silently and unrecoverably.

Skipping loses one barcode in a case the team expects not to occur; guessing wrong corrupts data. The skip is also an assertion: an empty `ambiguous` count confirms the expectation held.

*Alternative considered:* pick lowest id and report — rejected as a silent wrong-product write.

### D4: Fill blanks only; never overwrite

A product that already has a non-blank barcode is skipped and reported as `has_barcode`.

*Rationale:* Protects any barcode assigned between the cleanup and the restore. It also makes the command naturally **idempotent** — a second run reports every row as `has_barcode` and writes nothing, so re-running after a partial failure is safe.

Note the product stock/price import CSV carries its own `barcode` column, so some products may arrive already barcoded. Those surface as `has_barcode` skips, which is benign and expected — not an error.

### D5: Write `products.barcode` and `barcode_identities` together, per row, in one transaction

*Rationale:* This is the load-bearing decision. Scanning would work from the column alone, but `BarcodeIdentityService::reserve()` checks the **registry** when a user assigns a barcode through the UI. If the registry were left empty, `reserve()` would happily hand out a barcode that a restored product already holds in its column, producing a duplicate that scanning then resolves nondeterministically — silent corruption discovered long after the fact.

Per-row (rather than whole-run) transactions keep a single bad row from rolling back an otherwise successful restore, which matters because the command's contract is skip-and-report rather than all-or-nothing.

Mirrors the approach in `2026_07_10_233122_backfill_barcode_registry_and_add_unique_constraints.php`, reusing `BarcodeUtils::canonicalize()` for `canonical_key`.

### D6: Do not route through `ProductBarcodeAssignmentService::assign()`

*Rationale:* Three independent blockers:
1. It requires an authenticated `User` and checks `products.barcodes.manage` — a console command has neither.
2. It writes a `ProductBarcodeAssignment` audit row per call. These are restorations of prior state, not new assignments; recording thousands of synthetic "assignments" would pollute the audit history.
3. It uses `replace()` semantics built around a stale-value snapshot check, which is the wrong shape for bulk restoration.

Writing the column plus `BarcodeIdentityService::reserve()` directly is the correct granularity.

### D7: Constraint violations become reported skips, not crashes

A duplicate `canonical_key` or a duplicate `products.barcode` is caught and reported as `barcode_taken`, and the run continues.

*Rationale:* Both unique constraints are active during the restore. A crash partway through would leave the operator with a partially restored database and no report of what remained. Since the command is idempotent (D4), continuing and reporting is strictly better.

### D8: No `setting_id` scoping on any query

*Rationale:* Product identity is global in this system. `setting_id` on `products` is legacy provenance; price, stock, and bundles are what is actually scoped per setting. Scoping the match by setting would fail to find products whose provenance changed across the cleanup.

## Risks / Trade-offs

**The export file is the only copy of the barcodes once the cleanup runs** → The export reports its row count so the operator can compare it against `SELECT COUNT(*) FROM products WHERE barcode IS NOT NULL AND barcode != ''` before proceeding. The cleanup step is irreversible; this verification is the sole safeguard and is deliberately surfaced in the command output.

**Products whose names change between export and restore become unrecoverable** → Reported as `not_found` rather than silently dropped. Running `--dry-run` first surfaces the count before any writes, so a large `not_found` number can halt the process while it is still actionable.

**Restore run before the data imports finish would report mass `not_found`** → Documented operator sequence puts the restore last. Idempotency (D4) means a premature run is harmless and can simply be repeated afterwards.

**Ambiguous (case-variant) duplicate names silently lose a barcode** → Reported explicitly by category with the affected names, so the loss is visible rather than silent. Expected to be zero in production.

**Per-row transactions mean a partially applied restore on interruption** → Acceptable because the command is idempotent; re-running completes the remainder and reports already-applied rows as `has_barcode`.

**Restored barcodes have no audit trail** (D6) → Accepted deliberately. The pre-cleanup audit history is destroyed by `migrate:fresh` regardless, so synthesising post-hoc audit rows would misrepresent when the barcodes were actually assigned.

## Migration Plan

No schema migration. Deployment is the addition of two console commands.

Operator sequence:

1. `php artisan product:export-barcodes` — verify the reported count against the source table.
2. `php artisan migrate:fresh --seed`
3. Run the usual data imports to completion.
4. `php artisan product:import-barcodes <path> --dry-run` — review projected outcome, especially `not_found`.
5. `php artisan product:import-barcodes <path>` — review the skip report.

Rollback: the export command is read-only. The import command only fills blank barcodes, so its effect can be undone by clearing `products.barcode` and the corresponding `barcode_identities` rows for the affected products.

## Open Questions

None blocking. Two points settled during exploration and recorded here for traceability:

- Conversion-level barcodes are out of scope because none exist in production. If that changes, the file format needs a `(name, unit, factor)` key rather than name alone.
- The handful of duplicate product names observed in production is **not** addressed by this change. Investigating their origin was explicitly deferred; note that `migrate:fresh` destroys the evidence, so that diagnosis is no longer possible after the cleanup runs.
