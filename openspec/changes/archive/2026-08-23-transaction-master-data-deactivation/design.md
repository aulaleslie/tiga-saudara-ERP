## Context

The ERP currently handles lifecycle differently across master records. Customers, suppliers, taxes, payment methods, and products expose delete actions; some controllers delete immediately, others rely on partial relationship checks, and database foreign keys variously restrict, null, or cascade references. Products have a `merged_into_id` state used for duplicate reconciliation, while bundles, terminals, and users already demonstrate explicit active/inactive patterns.

Transaction creation is also distributed across controllers, form requests, Livewire components, autocomplete components, imports, POS staged checkouts, and service-layer posting. Historical workflows include document views, reports, payments, returns, reversals, and audits. A model-wide scope that hides inactive records would therefore protect creation at the cost of breaking history.

## Goals / Non-Goals

**Goals:**

- Establish one lifecycle meaning for covered transaction-linked master data.
- Prevent inactive records from being introduced into new transactions at both query and write boundaries.
- Preserve inactive references in existing drafts, posted documents, reports, payments, returns, and audits.
- Make deactivation reversible and visible to administrators.
- Protect historical data from destructive application actions and unsafe foreign-key cascades.
- Keep product deactivation independent from duplicate-product merging.
- Verify touched behavior using focused implementation and regression tests.

**Non-Goals:**

- Rewriting historical transaction rows or migrating them to replacement master records.
- Treating deactivation as anonymization or satisfying a personal-data erasure request.
- Changing transaction cancellation, rejection, archival, or deletion rules.
- Automatically merging duplicate customers, suppliers, products, or accounting records.
- Requiring the repository's full test suite as part of this change.

## Decisions

### 1. Use an explicit active flag rather than soft deletion

Covered tables will use a non-null boolean `is_active`, defaulting to true and indexed where it is used by operational selectors. Existing rows are active during migration. Models will expose explicit active/eligible query scopes, while unrestricted queries continue to resolve inactive rows.

Soft deletion was rejected because its global scope would hide inactive records from historical queries and create widespread dependence on `withTrashed()`. A multi-state enum was also considered, but active/inactive is sufficient for this capability; merge, archival, and legal-retention states remain separate domain concepts.

### 2. Separate administrative visibility, operational eligibility, and historical resolution

Queries will be classified by purpose:

```text
administrative list  -> active + inactive, status filter
new transaction      -> active only
existing draft       -> active choices + currently referenced inactive row
historical operation -> source-linked active or inactive row
report/audit          -> active + inactive
```

No blanket global scope will be applied. Shared scopes or small eligibility services may centralize the active predicate, but call sites remain explicit about intent.

### 3. Validate at selection time and again at the authoritative write boundary

Dropdowns, autocomplete endpoints, quick-add integrations, and import lookups will filter inactive options for usability. Controllers, form requests, Livewire submit handlers, and posting/finalization services will revalidate active eligibility immediately before creating new activity. POS and other staged flows must revalidate at finalization so deactivation after initial selection cannot bypass the rule.

UI-only filtering was rejected because stale tabs and crafted requests can submit inactive identifiers. Validation solely in each model event was rejected because eligibility depends on context: an inactive party is invalid for a new sale but valid when inherited from an existing receivable being settled.

### 4. Preserve the current inactive selection only for an existing document

Edit forms may explicitly union their current referenced row into an otherwise active-only option set. Submission may retain that unchanged inactive reference, but changing a field or adding a line requires an active selection. This avoids silently corrupting drafts while preventing them from becoming a route for fresh inactive usage.

### 5. Anchor historical exceptions to source records

Returns, refunds, reversals, payments, and other follow-up operations will resolve master data through their source document rather than through unrestricted general-purpose selectors. An inactive customer or supplier may be inherited to settle an existing balance. A newly chosen payment method, location, tax, unit, or account remains subject to active eligibility unless the source transaction itself dictates that exact historical reference.

### 6. Keep product `is_active` independent of `merged_into_id`

An inactive product remains the same product and retains all references. A merged product continues to point to its canonical survivor under existing reconciliation rules. Operational product eligibility requires the product to be active and not retired by merge, while historical resolution can load either state.

Reusing `merged_into_id` for discontinuation was rejected because it falsely asserts identity equivalence and can trigger reference migration behavior.

### 7. Replace delete UI while retaining defensive endpoint behavior

Administrative actions will become `Deactivate` and `Reactivate`, with status badges, filters, effect-oriented confirmation text, and suitable permissions. Existing destroy routes may temporarily map to deactivation for backward compatibility, but domain services/controllers must prevent destructive deletion. API behavior should be explicit where changing a route is safe.

### 8. Harden referential integrity without rewriting history

The implementation will inventory foreign keys from covered masters to transactional, inventory, payment, return, and accounting tables. Unsafe cascade or null-on-delete policies that can damage history will be replaced with restrictive behavior where migration risk is acceptable. Application protections remain mandatory because legacy schemas and SQLite/MySQL differences may prevent uniform foreign-key replacement in one deployment.

Lifecycle migrations only add/backfill status and indexes or adjust constraints; they do not rewrite transaction references. Deactivation itself only updates lifecycle metadata.

### 9. Handle defaults and required infrastructure explicitly

Deactivating a default tax, payment method, location, or other configured default must not allow it to flow into new transactions. Where a workflow requires an active default, deactivation will be blocked until an active replacement is configured or will atomically clear/reassign the default according to that module's established behavior. Required structural records, such as accounts with dependent configuration, may have stricter deactivation guards than ordinary selectable masters.

### 10. Use focused verification proportional to touched behavior

Tests will target each changed lifecycle action and the selectors/write boundaries actually modified. Regression coverage will include at least one new-transaction rejection, stale-selection rejection, historical display/report path, source-based return or settlement path, and product merge/deactivation separation. Verification commands will use focused PHPUnit/Pest filters; no full-suite task will be included.

## Risks / Trade-offs

- **Missed selector or write path permits inactive use** → Inventory all covered model lookups and protect authoritative posting/finalization boundaries in addition to UI filters.
- **Over-filtering hides historical records** → Avoid global scopes and add regression tests for reports, document views, returns, and settlements.
- **Inactive defaults produce empty or invalid forms** → Define per-master default replacement or deactivation guards and test required-default workflows.
- **Existing foreign keys have inconsistent delete semantics** → Audit constraints before migration, change them incrementally, and retain application-level deletion guards.
- **Large cross-module scope increases rollout risk** → Implement by master group and shared boundary patterns, with focused tests after each touched area.
- **Reactivation exposes stale configuration** → Validate required relationships and defaults before allowing reactivation.
- **Terminology conflicts with product merge retirement** → Use “inactive/deactivated” for availability and reserve “merged/retired duplicate” for reconciliation lineage.

## Migration Plan

1. Inventory covered tables, lifecycle columns, delete routes, foreign keys, operational selectors, defaults, imports, and authoritative write boundaries.
2. Add `is_active` fields and indexes to covered tables that do not already have an equivalent field, defaulting/backfilling existing rows to active.
3. Add model scopes and lifecycle operations, then replace administrative delete/reactivate UI and status filters.
4. Update new-transaction selectors and authoritative validators module by module, preserving current inactive references on existing drafts.
5. Update historical operations and reports only where active filtering or inner joins would prevent inactive resolution.
6. Harden unsafe delete paths and foreign-key behavior after verifying current production-compatible constraints.
7. Run focused implementation and regression tests for touched modules.

Rollback of code restores prior selection behavior. Schema rollback may remove newly added status columns only if no inactive states need preservation; otherwise the safe rollback leaves additive columns in place. Constraint rollback must never reintroduce a cascade capable of deleting historical transactions.

## Open Questions

- Which chart-of-account records are operationally deactivatable versus structurally required and therefore guarded?
- For each required default, should deactivation demand a replacement in the same interaction or simply block until another record is made default?
- Should legacy delete permissions be renamed in place to preserve deployed roles, or should distinct deactivate/reactivate permissions be introduced and mapped during migration?
