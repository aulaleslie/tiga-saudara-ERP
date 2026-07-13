## Context

Stock transfers are implemented in `Modules/Adjustment` with Livewire components under `app/Livewire/Transfer`. The visible create page uses `TransferStockForm` and directly persists a header and detailed tax/non-tax, broken, and serial line data in a transaction. The resource controller retains a separate, weaker `store()` contract, while edit combines an HTTP form with Livewire components that cannot hydrate the supplied `existingProducts` shape and posts only legacy product/quantity arrays. Approval and rejection update status without a transaction or transfer-row lock; edit/update do not enforce origin tenancy or editable status at the server boundary.

Dispatch and receipt already wrap inventory movement in database transactions and lock serial and stock records, but the transfer header is not locked before lifecycle validation. Movement uses planned `transfer_products` bucket quantities rather than recalculating non-tax-first allocation from locked live stock. Cross-tenant return is header-wide through `requiresReturn()` and returns every line rather than only actual taxed provenance.

The product domain now has canonical barcode identities and conversion barcodes. POS supplies mature scanner/search examples, but its resolver also imposes sale-specific location, price, bundle, and dispatch-reservation rules and therefore cannot be reused directly. Settings expose `is_pkp`, which predicts normal purchase/sale tax behavior but does not reclassify historical `ProductStock` tax and non-tax buckets. Existing inventory quantities and transfer quantity columns are integer based.

Stakeholders are origin warehouse creators, approvers, dispatchers, destination receivers, return dispatchers/receivers, tenant administrators assigning permissions, and auditors reviewing provenance. The change must remain compatible with Laravel 10, Livewire 3, nwidart module conventions, MySQL/MariaDB production, SQLite-focused tests, existing document numbers, and unrelated inventory flows.

## Goals / Non-Goals

**Goals:**

- Replace incompatible create/edit write paths with one transfer draft state, authoritative validation boundary, and atomic persistence service.
- Support keyboard-wedge scanner input for products, conversions, and serials while retaining text search.
- Normalize scan intent into whole base-unit quantities and preserve scan context for review.
- Preview and execute deterministic non-tax-first allocation without reserving inventory at approval.
- Establish explicit, permission-governed and concurrency-safe transfer lifecycle transitions, including rejection acknowledgement, revisions, and pre-dispatch archival.
- Persist actual dispatch provenance separately from previewed/requested quantities and mirror it exactly during receiving.
- Return only actually dispatched taxed provenance for cross-tenant transfers and complete non-tax-only transfers after destination receipt.
- Preserve historical records and expose complete append-only audit history for new actions.

**Non-Goals:**

- Changing `Setting::is_pkp` semantics or reclassifying existing tax/non-tax inventory when the flag changes.
- Reserving stock at approval or guaranteeing that an approved transfer can be dispatched later.
- Supporting fractional transfer inventory while the stock and transfer schemas remain integer based; conversions that do not resolve to whole base units are rejected rather than truncated.
- Automatically consuming broken stock for normal barcode scans.
- Adding camera scanning; keyboard-wedge scanners are the required input mechanism, with existing camera capabilities left unchanged.
- Rewriting historical transfer statuses, quantities, transactions, or document numbers.
- Replacing the global inventory transaction model or redesigning unrelated adjustment, purchase, sale, POS, or return flows.

## Decisions

### 1. Use one shared Livewire form and one server-side draft service

Create and edit will share a transfer form state that contains locations, normalized line intent, scan context, selected serial IDs, and allocation preview. `TransferDraftService` (name may follow established project conventions) will own create/update/resubmit transactions and reload authoritative tenant, location, product, conversion, serial, and stock data before persistence. Route authorization remains defense in depth, but the service will also receive the actor/active-setting context so a second entry point cannot bypass invariants.

The current controller `store()` path will either delegate to this same service or be removed if confirmed unused. It must not remain an independent reduced contract. Edit will hydrate a purpose-built DTO/array rather than passing raw nested Eloquent `toArray()` output to `TransferProductTable`.

Alternative considered: patch only `existingProducts` and keep controller update. Rejected because it would continue losing tax/broken/serial data and leave create/update validation inconsistent.

### 2. Separate requested intent, allocation preview, and actual movement

Each line has three semantic layers:

1. Requested intent: product, normal/broken mode, requested base quantity, selected serial IDs, and optional scan context.
2. Approved preview: non-tax/tax allocation calculated from then-current stock for reviewer visibility; it does not reserve stock.
3. Actual dispatch provenance: immutable dispatched bucket quantities and normalized serial snapshot derived under locks.

Existing `quantity_*` fields can remain the persisted requested/preview breakdown for compatibility, while existing `dispatched_quantity_*` fields become explicitly authoritative actual provenance. Additive columns or JSON are used only where existing fields cannot express requested total, transfer mode, conversion scan context, preview version/hash, or dispatch acknowledgement.

Receiving and return flows must use actual dispatched/return-dispatched provenance, never recompute from editable requested fields.

Alternative considered: freeze bucket allocation at approval. Rejected because approval does not reserve stock and dispatch would fail unnecessarily when total stock remains sufficient in a different tax bucket.

### 3. Implement transfer-specific lookup above shared barcode identity

A transfer lookup service will resolve canonical exact input in this order: product barcode, conversion barcode, then active serial number at the chosen origin. It will use product barcode identity/canonicalization and product conversion records but apply transfer-specific stock, origin-location, serial, and stock-managed rules. Text search will use one stock-aware query with eager loading/aggregation rather than per-product stock queries.

Exact scanner submission is triggered by Enter and immediately restores focus. Ambiguous text results require user selection. Conversion scans add the conversion factor to requested base quantity and retain unit/factor/scan-count context. Product scans add one. Serial scans select the exact serial and derive its tax/broken provenance.

Alternative considered: inject `PosScanResolverService`. Rejected because POS requires sale prices, sales-location resolution, and POS-specific reservation/bundle behavior that do not belong to stock transfers.

### 4. Keep transfer quantities integral and reject fractional conversions

The current stock buckets, transfer line quantities, transaction quantities, and serial counts are integer-oriented. Scanner conversion factors must therefore produce positive whole base-unit quantities for this change. Validation uses decimal-safe comparison before converting and never silently truncates or rounds. Supporting weight/fractional transfer inventory requires a separate schema-wide inventory decision.

Alternative considered: migrate transfer columns alone to decimals. Rejected because `ProductStock`, transaction projections, serial rules, and other inventory writers would remain inconsistent.

### 5. Allocate non-serialized quantities non-tax first at preview and dispatch

For normal mode:

```
non_tax = min(requested, available_quantity_non_tax)
tax     = requested - non_tax
```

Dispatch fails if `tax` exceeds available normal taxed stock. Broken mode uses the same rule over `broken_quantity_non_tax` then `broken_quantity_tax`. Normal mode never falls through to broken buckets. `is_pkp` is displayed as context if useful but does not alter allocation; live stock buckets are authoritative.

Serialized lines bypass priority allocation because each selected serial supplies exact tax/broken provenance. Their authoritative models are reloaded and locked at dispatch.

Alternative considered: use PKP status to force a tax bucket. Rejected because flag changes can leave valid mixed historical stock and must not rewrite provenance.

### 6. Use hash-bound acknowledgement for material dispatch drift

Approval stores an allocation preview and a stable representation/hash of approved intent. Dispatch locks the transfer and stock and recalculates actual allocation. If actual taxed quantity or mandatory cross-tenant return increases, the first request returns/presents a line-level dispatch review and a short-lived acknowledgement bound to transfer ID, approved revision, and calculated allocation hash. Confirmation locks and recalculates; it proceeds only if the hash still matches.

If allocation is unchanged or tax exposure decreases, normal authorized dispatch may proceed without a second acknowledgement. Every dispatch still records actual provenance.

Alternative considered: always show a separate review step. Viable but adds friction to the normal PKP or single-bucket path; hash-bound conditional review addresses the material surprise while preserving fast dispatch.

### 7. Centralize lifecycle policy and lock the transfer before transitions

A lifecycle service/state policy defines allowed actions:

```
PENDING  --approve--> APPROVED
PENDING  --reject-->  REJECTED
REJECTED --acknowledge--> DRAFT
DRAFT    --resubmit--> PENDING (revision + 1)
APPROVED --archive--> ARCHIVED
APPROVED --dispatch--> DISPATCHED
DISPATCHED --receive--> COMPLETED | AWAITING_RETURN
AWAITING_RETURN --return dispatch--> RETURN_DISPATCHED
RETURN_DISPATCHED --return receive--> COMPLETED
```

Initial successful creation remains `PENDING` to preserve current user behavior; `DRAFT` is introduced for an acknowledged rejection. Approval requires only `stockTransfers.approval`, and self-approval is allowed. `stockTransfers.edit` does not grant decision authority. Archive requires an additive permission (provisionally `stockTransfers.archive`) and reason and is allowed only for approved, undispatched transfers. Acknowledge/resubmit use edit authority under the origin tenant unless permission conventions discovered during implementation warrant a distinct acknowledgement permission.

Every action transaction begins by selecting the transfer row `FOR UPDATE`, checks active tenant, permission, status, and revision, then writes state and history. Existing route middleware and Gates remain for early denial.

Alternative considered: scatter state checks across controllers. Rejected because current races and inconsistent guards come from that pattern.

### 8. Add append-only action history and retain header projections

Add `transfer_action_histories` with transfer ID, revision, action, from/to status, actor ID, reason, metadata JSON, idempotency key where applicable, and timestamp. Header actor/timestamp columns remain as current-state projections for compatibility and list/detail performance. Earlier rejection/approval cycles are never overwritten in history.

Use an integer transfer revision incremented on resubmission and included in approve/reject and drift acknowledgement checks. Unique constraints on a suitable action/idempotency identity prevent duplicate history for retried actions.

Alternative considered: add more single-value columns to `transfers`. Rejected because repeated reject/acknowledge/resubmit cycles cannot be represented faithfully.

### 9. Model cross-tenant return obligations from actual taxed provenance

At successful dispatch, actual taxed normal/broken quantities and exact taxed serial IDs determine obligation data for cross-tenant transfers. This may use a line-level `transfer_return_obligations` table or additive line columns plus normalized serial linkage; the implementation should prefer normalized rows if partial fulfillment or future operational reporting requires them.

Destination receipt adds all actual dispatched provenance. It then transitions:

- same tenant: `COMPLETED`;
- cross-tenant with zero taxed obligation: `COMPLETED`;
- cross-tenant with taxed obligation: `AWAITING_RETURN`.

Return dispatch removes only outstanding obligated taxed quantities and exact taxed serials from destination stock. Return receipt restores only those quantities/serials to the origin and completes the transfer after every obligation is fulfilled. Non-tax quantities remain at destination.

Alternative considered: keep `requiresReturn()` as the sole rule and return whole lines. Rejected because it contradicts the tax-provenance policy and wrongly reverses non-tax quantities.

### 10. Preserve historical terminal records without destructive backfill

New state interpretation applies prospectively. Existing `RECEIVED` and `RETURN_RECEIVED` records remain stored and render as historical terminal states unless they already have reliable new obligation records. They are not silently reopened or assigned work. New transfers use explicit `COMPLETED`/`AWAITING_RETURN` behavior; model helpers and display mapping treat legacy terminal statuses compatibly.

MySQL status ENUM changes must be mirrored safely for SQLite, avoiding the existing divergence where return statuses are skipped. Prefer a string status column with application validation if changing the enum can be done safely; otherwise use driver-aware additive migration and tests.

Alternative considered: bulk rewrite historical statuses and infer obligations from old planned lines. Rejected because old lines do not reliably distinguish actual return obligation under the new rule.

### 11. Keep all inventory movements atomic and idempotent

Create/update, lifecycle decisions, initial dispatch, destination receipt, return dispatch, and return receipt each use a database transaction. Movement actions lock the transfer first, then stock/serial rows in deterministic product/ID order to reduce deadlocks. Stock, product aggregate quantities, serial locations, serial history, inventory transactions, line provenance, obligation state, transfer state, and action history commit or roll back together.

Idempotency middleware/service is extended to state-changing endpoints where a repeated browser or network request could duplicate work. State and revision checks remain the final protection even if an idempotency token is absent.

## Risks / Trade-offs

- [Risk] Existing data may contain lines whose total differs from bucket quantities or incomplete serial payloads. → Preserve historical terminal records, validate only when an old editable record is mutated, and provide a deterministic hydration/repair error rather than silently changing it.
- [Risk] Allocation can change between review and execution. → Bind acknowledgement to revision and allocation hash, recalculate under locks, and refuse stale confirmation.
- [Risk] More row locks can increase deadlock probability. → Lock transfer first and stock/serial rows in stable order, keep transactions small, and add bounded retry only for recognized deadlocks.
- [Risk] New status values can diverge between MySQL ENUM and SQLite. → Include migration tests on both supported drivers where available and avoid production-only status assumptions in model logic.
- [Risk] Barcode registry backfill may be incomplete in some environments. → Resolver may use canonical registry as primary and report an actionable data-integrity error; do not silently select ambiguous direct matches.
- [Risk] A conversion scan can create a large unintended quantity. → Display scan unit, factor, count, resulting base quantity, and live availability prominently; reject unsupported factors.
- [Risk] Conditional drift acknowledgement increases dispatch UX complexity. → Require it only when taxed/return exposure increases and present a concise line-level difference.
- [Risk] Tax-only return changes existing operational expectations. → Preserve historical transfers, make outstanding return quantities explicit on detail/list views, and cover mixed, non-tax-only, and tax-only UAT paths.
- [Risk] Controller extraction may touch a large legacy class. → Move behavior incrementally behind services while preserving route names and response conventions, then remove duplicated private logic only after focused tests pass.

## Migration Plan

1. Add status/revision/archive fields, append-only action history, and the chosen return-obligation storage with foreign keys/indexes and SQLite-compatible migrations.
2. Add new permissions through the centralized permission configuration and role synchronization without removing existing access/create/edit/show/dispatch/receive permissions.
3. Deploy model relationships, state helpers, history recording, allocation/lookup services, and compatibility mapping before switching UI actions.
4. Route create/edit and lifecycle actions through the new services while preserving existing route names; ensure the legacy controller store/update paths no longer have independent semantics.
5. Switch dispatch/receive/return movement to locked actual provenance and obligation processing, then enable the scanner-first shared Livewire form.
6. Treat pre-change `RECEIVED` and `RETURN_RECEIVED` rows as historical terminal records with no inferred new obligations. No destructive data rewrite is performed.
7. Verify focused feature/Livewire tests, migration behavior, and `composer test:fresh-sqlite`; perform UAT for same-tenant, cross-tenant non-tax, mixed-tax, serial, broken, rejection revision, archive, and dispatch-drift cases.

Rollback before new movement use can remove additive structures after reverting code. After new lifecycle or obligation records exist, rollback must first disable new writes and retain additive tables/columns until data is exported or a forward fix is deployed; historical movement provenance must never be discarded automatically.

## Open Questions

- Confirm the final permission name and role bundles for archival; the design assumes `stockTransfers.archive` and uses existing edit authority for rejection acknowledgement/resubmission.
- Confirm whether future operations need partial mandatory return dispatch/receipt. The initial UI can require full outstanding return, while normalized obligation storage should avoid blocking later partial support.
- Confirm whether an allocation whose taxed quantity decreases should be shown informationally at dispatch even though it does not require acknowledgement.
