## Context

The ERP has one global `products` catalog while historical imports create documents for multiple owners. Product identity is currently inferred independently by purchase, sales, product CSV, HPP/stock snapshot, and tier-price workflows. The implementations differ in their handling of case, repeated/Unicode whitespace, `*` and trailing `TP` import markers, and concurrent creates. The database enforces unique product codes but has no equivalent guarantee for the product-name identity.

The fresh initialization database already contains pairs of globally duplicated products. The dual-company tier-price importer is correctly declining to update them because it normalizes a workbook name and finds more than one candidate product.

## Goals / Non-Goals

**Goals:**

- Define one deterministic, Unicode-safe canonical product identity for all import paths.
- Store a normalized display name and a unique canonical key for each newly created import product.
- Make product lookup/create atomic so parallel queued imports resolve one catalog product.
- Make non-creating imports use the same identity and surface actionable unresolved/conflict results.
- Provide a controlled way to identify and reconcile existing duplicate canonical identities before all historical rows are backfilled.
- Preserve global catalog semantics: owner markers determine document/price/stock ownership, not separate product identities.

**Non-Goals:**

- Fuzzy matching, typo correction, manufacturer/model equivalence, or automatic merging of genuinely different products.
- Changing purchase/sales owner routing, stock effects, price calculations, document grouping, or product-code authority.
- Automatically deleting products or rewriting references without explicit operator confirmation.
- Retrofitting manual product CRUD into this change, except where its stored data must coexist with the new identity constraint.

## Decisions

### 1. Use one canonical identity contract

Introduce a shared Product-module canonicalizer used by every import creation and lookup path. It shall:

1. replace non-breaking and narrow Unicode spaces with ordinary spaces;
2. trim and collapse all whitespace runs to one space;
3. remove only import-context owner markers (leading `*` and trailing standalone `TP`) before identity calculation;
4. retain the cleaned, case-preserving value as the stored display name; and
5. case-fold the cleaned value for the canonical key.

Owner-marker removal remains contextual to source formats that define those markers. It must not remove arbitrary asterisks or `TP` text from normal product CRUD input.

Alternative: rely on database collation or `LOWER(product_name)`. Rejected because behavior varies between MySQL and SQLite and does not normalize Unicode/repeated whitespace or import markers.

### 2. Make canonical identity database-enforced

Add a nullable canonical-name key to `products`, unique when present. New import-created products must persist both the normalized display name and canonical key in the same transaction. The resolver shall catch a unique-key collision, reload the product holding that key, and use it rather than creating a suffix product.

The key is nullable during rollout so legacy data can be assessed and reconciled safely. Once a product receives a canonical key, no two products can claim that identity. New importer writes must never leave it null.

Alternative: cache product names per import batch. Rejected because caches do not coordinate concurrent jobs or different import types. Alternative: unique raw `product_name`. Rejected because raw formatting variants must resolve to one identity and database collation differs by environment.

### 3. Centralize import resolution and creation

Create a Product-module resolver with two explicit operations:

- `resolveExisting`: return the sole canonical product, an unmatched result, or an existing-duplicate conflict without mutation.
- `resolveOrCreate`: atomically reuse the sole product or create one product with the supplied permitted defaults; on canonical-key conflict, reload the winner.

Purchase and sales imports will use `resolveOrCreate`. Generic product CSV and HPP/stock processing will use the operation appropriate to their established behavior. Sales-price, stock snapshot, and dual-company tier-price imports will use `resolveExisting` and retain their rule not to create products.

The resolver accepts a source-context policy for marker handling and creation defaults, so it centralizes identity without moving owner, unit, price, or stock business rules out of their existing modules.

Alternative: make the first product CSV import the only path permitted to create products. Rejected for this change because existing historical purchase/sales import behavior is intentionally able to introduce source products; removal would make the migration workflow fail on valid source rows.

### 4. Preserve source product codes without letting them split identity

Product name is the catalog identity source of truth. A supplied product code can populate a newly created product only when it is available. When a name resolves to an existing product, its product identity wins; code conflicts are recorded according to the existing import policy and must not cause another product with the same canonical name to be created.

### 5. Reconcile existing duplicates explicitly and auditably

Provide a read-only preflight that groups products by the new canonical key and reports IDs, stored names, codes, and reference counts. Provide a separate operator-confirmed reconciliation command/workflow that maps a duplicate group to a selected survivor and repoints supported product foreign keys transactionally, then records the decision. It must refuse a group where required references cannot be safely migrated.

The survivor default may be the lowest ID for operator convenience, but it must remain reviewable and overrideable. After a group is reconciled, only the survivor receives the canonical key; redundant rows are archived/retired rather than silently deleted.

Alternative: automatically merge every group to the lowest ID in a migration. Rejected because product codes, price rows, and historical links can require operator review and a migration cannot safely request it.

### 5a. Conflict handling in reconciliation (Task 3.3 specifics)

Reference migration must reject the entire group on conflict rather than auto-deleting or merging. Specific conflict types:

**Product Prices Collisions**: The `(product_id, setting_id)` unique key prevents moving a retired product's price rows if the survivor already has a price row for that setting.
- **Action:** Reject the collision group and report both conflicting price row IDs and values.
- **Rationale:** Price reconciliation requires operator judgment (take survivor's, take max, merge average cost, etc.); no automatic behavior is safe.
- **Future:** A separate, explicitly-designed operator decision workflow may reconcile price values after this merge completes.

**Bundle Item Semantic Conflicts**: After repointing bundle item FK to survivor, if any bundle would contain both the survivor and a retired product as components, reject the group.
- **Example:** Bundle "Combo A" contains [Retired-5, Item-B]. Repointing Retired-5 → Survivor-2 would create [Survivor-2, Item-B, Survivor-2], a duplicate component.
- **Action:** Detect semantic duplicates before mutation and reject with full bundle/item details.
- **Rationale:** Bundle composition changes product cost/pricing; operator must review.

**No Automatic Deletion**: The "action='delete' for conflicting prices" pattern is rejected entirely. Every reference must either migrate (FK update) or be rejected. Deletion is destructive and violates the non-destructive retirement goal.

### 5b. Reconciliation workflow specifics (Task 3.3)

The operator workflow is a strict CLI command (not UI) with explicit arguments:

```
php artisan product:reconcile-catalog-group \
  --survivor-id=<ID> \
  --retired-ids=<ID1>,<ID2>,... \
  --confirm
```

Workflow steps inside a single transaction:

1. Re-run the canonical-group plan: verify survivor and all retired IDs share the canonical key, have no unsupported references.
2. **Preflight conflicts** before any updates:
   - Iterate `product_prices`: for each retired product's price row, check if survivor already has a row for (product_id, setting_id). If yes, report both rows with values and reject the entire group.
   - Iterate `product_bundle_items`: for each bundle item referencing a retired product, simulate the FK update and detect semantic duplicates. If any bundle would contain both survivor and retired post-migration, report and reject.
3. **Migrate simple relations** (11 supported): iterate and update FKs by primary key, recording each moved row in `product_reference_migrations`.
4. **Verify zero supported refs** on every retired product post-migration.
5. **Retire products** and assign canonical key to survivor.
6. On any error in steps 1–5: rollback entire transaction and report reason.

**Result counts:** Return exact count map for all 11 supported relations; zero counts for all unsupported (enforced pre-migration).

## Risks / Trade-offs

- [Existing duplicate rows prevent a complete backfill] → Add the nullable key first, publish a preflight report, and make duplicate groups explicit conflicts until reconciled.
- [A canonicalizer accidentally treats distinct names as equal] → Limit normalization to whitespace, case identity, and documented source markers; do not add punctuation stripping or fuzzy similarity.
- [Concurrent imports encounter a unique-key exception] → Catch only the canonical-key unique violation, reload the winner in the same resolver contract, and retain other errors.
- [Merging duplicate products changes price/history joins] → Require explicit survivor confirmation, reference preflight, transactional repointing, and audit records; never delete data automatically.
- [Import paths drift later] → Keep canonicalization and resolution in one Product-module service and test each public import route against the shared behavior.
- [SQLite/MySQL differ] → Build the canonical key in PHP and exercise collision behavior in focused SQLite tests plus migration-compatible integration tests.

## Migration Plan

1. Add the nullable canonical key, supporting index/unique constraint, and audit/reconciliation storage without changing historical document records.
2. Backfill canonical keys for unambiguous existing products; emit a durable preflight/report for collision groups and leave their keys unset.
3. Deploy the shared resolver and migrate every import creation/matching path to it. New products receive a canonical key atomically; conflicting legacy identities remain actionable errors rather than being guessed.
4. Review and explicitly reconcile the fresh-database duplicate groups, preserving a selected survivor and product references.
5. Re-run preflight, complete canonical-key backfill, and run the price workbook; it should resolve exactly one product for every normalized source name.

Rollback: code can be reverted while retaining the nullable key and audit records. The reconciliation workflow must be transactional per group and retain a mapping/audit trail so a deliberate operational reversal can be performed; no automatic destructive rollback is allowed.

## Open Questions (Resolved)

✓ **Supported relations:** Confirmed as 11: transactions, product_prices, product_stocks, purchase_details, sale_details, dispatch_details, sale_return_details, purchase_return_details, product_bundles (parent_product_id), product_bundle_items (product_id), product_unit_conversions.

✓ **Retired product retention:** Soft-archived using `merged_into_id` without deletion; no separate deactivation status needed.

✓ **Product code conflicts:** Import-time discrepancies are logged per existing behavior; reconciliation does not re-evaluate codes.

✓ **Product prices collision handling:** Reject the entire group and report conflicting rows with values; no auto-deletion or "take survivor" merging.

✓ **Bundle item semantic conflicts:** Detect and reject groups where repointing would create duplicate bundle components.

✓ **Conflict handling pattern:** All conflicts → reject and report; no automatic destructive actions; future operator decisions handled separately.
