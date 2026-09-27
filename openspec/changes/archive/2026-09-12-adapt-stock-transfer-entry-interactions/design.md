## Context

The archived stabilization delivery made transfer scan mutations authoritative and rapid delivery deterministic, but the entry UI remains split across `SearchProduct`, `TransferProductTable`, browser events, acknowledgements, and the parent `TransferStockForm`. Stock opname and breakage already provide a more coherent interaction model: an exact-scan resolver with explicit ambiguity, deliberate product search, row-level serial management, visible feedback, and reliable focus recovery.

Transfer cannot copy adjustment behavior literally. It has a selected origin, destination, one form-wide condition, whole-base-unit quantities, existing registered serials, stock-visibility restrictions, and transfer-specific eligibility that must be revalidated on every mutation. The existing `TransferScanResolverService` and stabilized mutation checks remain useful domain boundaries.

## Goals / Non-Goals

**Goals:**

- Give transfer create and edit the proven stock-opname/breakage interaction shape for scanning, ambiguity, search, feedback, focus, and serial management.
- Make exact scanning and product discovery visibly and behaviorally distinct.
- Resolve exact identifier collisions explicitly instead of relying on product/conversion/serial precedence or database `first()` ordering.
- Place queue completion and row mutation under one authoritative Livewire entry owner while retaining canonical server reload and transfer-specific validation.
- Preserve rapid captured-order, at-most-once behavior and permission-safe browser projections.

**Non-Goals:**

- Changing transfer persistence, movement, dispatch, receipt, approval, reservation, or inventory mutation timing.
- Changing routes, policies, permissions, database schema, or historical transfer records.
- Supporting camera scanners, offline entry, fractional base units, or raw/unregistered serial entry.
- Copying opname's dual good/broken counters into transfer, which has one explicit condition.
- Running the full application test suite as the delivery gate.

## Decisions

### 1. Use one authoritative transfer-entry coordinator

Scan capture, exact resolution, ambiguity state, product-search selection, row mutation, feedback, focus recovery, and queue completion will be owned by one mounted Livewire entry component. The parent remains responsible for form-wide origin, destination, and condition; its origin and condition continue to remount entry through the existing keyed lifecycle, while destination-only changes preserve rows.

The coordinator may reuse extracted table presentation and the existing `TransferScanResolverService`, but sibling browser-event mutation and acknowledgement are no longer the primary consistency protocol. A captured operation completes only after its authoritative mutation or rejection finishes in the owning component.

Alternative considered: keep `SearchProduct` and `TransferProductTable` as independent state owners and expand acknowledgement events for ambiguity and search. Rejected because it multiplies ordering paths and makes modal, queue, row, and focus state harder to keep coherent.

### 2. Separate exact scanner intent from deliberate product search

The always-ready scanner field accepts an exact product barcode, conversion barcode, or serial. An unknown exact scan remains unknown; it does not fall through to tokenized results or auto-select a sole fuzzy match. “Cari Produk” opens a separate search interaction whose debounced tokens match product name, code, barcode, category, and brand.

Selecting a search result sends only canonical product identity. The coordinator reloads tenant, origin, condition, stock-managed status, serialization, and current eligible stock before mutation. A first non-serialized selection adds one base unit; selecting an existing row focuses it without an implicit extra quantity. A serialized selection creates or focuses a zero-quantity row. Repeated scanner input, rather than repeated modal selection, is the fast increment path.

Alternative considered: retain one hybrid input. Rejected because a failed scan can become an unintended fuzzy product mutation and scanner focus competes with interactive search results.

### 3. Model exact collisions as explicit ambiguity

The resolver will collect all exact eligible candidates across product barcode, conversion barcode, and serial namespaces. Zero candidates returns not found, one returns resolved, and more than one returns ambiguity. Duplicate records within a namespace and cross-namespace collisions are both ambiguous; fixed type precedence and unordered `first()` selection are removed.

Before presentation, candidates are filtered authoritatively for global product identity (active and stock-managed), selected origin ownership (active location owned by current tenant setting), selected condition, conversion validity, and serial availability at origin. The ambiguity projection contains only minimal identity and permission-safe labels. Choosing a candidate re-resolves its canonical identifier at mutation time; cancellation changes no rows and restores scanner focus.

Alternative considered: keep product-over-conversion-over-serial precedence. Rejected because it silently applies a business effect the operator did not disambiguate.

### 4. Adapt serialized row interaction without weakening transfer rules

Scanning a serialized product barcode creates or focuses its row at quantity zero. Exact serial scans add a currently eligible serial and quantity remains the count of unique selected serials. A row-level dialog shows selected serials, permits removal, and offers a searchable fallback picker limited to existing eligible serials for that row, origin, and condition.

Every add, remove, or scan reloads canonical serial state and enforces global product validity, origin location tenant ownership, origin, condition, status, dispatch/return reservation, and cross-row uniqueness. Blind projections omit availability, location, condition, tax, and reservation provenance. Unregistered serial text is never accepted.

Alternative considered: allow free-form serial entry as adjustment does for counting. Rejected because a transfer moves known inventory identities.

### 5. Reuse the proven FIFO and focus lifecycle inside the coordinator

Scanner input is captured and cleared synchronously, assigned an operation token, and queued in FIFO order. The next scan starts only when the coordinator finishes the preceding resolution, explicit ambiguity choice, or rejection. When ambiguity is open, later captured scans remain queued; closing or resolving it resumes the queue. Product-search modal activity does not turn typed search into scanner input.

At-most-once claims remain server-side for the active form session with a TTL aligned to session lifetime. The shared-cache deployment assumption documented by the archived delivery remains: multi-host deployments require a genuinely shared cache store. Livewire initialization and morph-safe element lookup restore focus after outcomes and modal closure.

### 6. Keep visibility and validation authoritative at every boundary

Public component state, modal candidate data, dispatched events, and feedback are projections, never authority. Operators without `stockTransfers.view-system-stock` receive neutral Bahasa Indonesia feedback and no stock quantities, shortage, allocation, serial availability, location provenance, condition provenance, or tax provenance. Privileged operators may receive actionable operational detail.

Origin and condition gate all entry. Origin or creation-condition changes use the existing confirmation/remount behavior and clear rows; destination-only changes preserve them. Save, submit, approval, dispatch, and return boundaries continue their existing revalidation because entry does not reserve inventory.

## Risks / Trade-offs

- [Consolidating Livewire ownership can disturb existing parent/table event contracts] → Characterize create/edit hydration and emitted row payloads first, preserve the parent-facing contract, and migrate one entry path at a time behind focused tests.
- [An ambiguity modal pauses rapid scanning] → Keep later captures queued, provide clear choose/cancel actions, and resume deterministically with scanner focus restored.
- [Filtering candidates before display could become query-heavy] → Batch eager-load candidate relationships and share eligibility predicates with authoritative mutation validation; verify query behavior on focused collision fixtures.
- [Search selection semantics differ from scanner increments] → Make feedback explicit: modal selection adds once only when absent, while scanner repetitions increment.
- [Serial eligibility can change after entry] → Continue authoritative validation at later lifecycle boundaries; this delivery does not claim reservation.
- [At-most-once behavior depends on shared cache topology] → Retain the documented deployment requirement and cover atomic claims with focused tests.
- [Browser timing cannot be proven by PHP component tests alone] → Require a focused real-browser rapid-scan check, without making the full suite part of acceptance.

## Migration Plan

No schema or data migration is required. First characterize the parent/child contracts and reference adjustment interactions, then introduce the coordinator and resolver result shape, move exact scanning and row mutation, add ambiguity/search/serial dialogs, and remove obsolete sibling acknowledgements only after focused parity checks pass. Existing persisted drafts continue to hydrate their base quantities and selected serial identities.

Rollback is an application-code revert; persisted formats and inventory timing are unchanged.

## Open Questions

- Confirm the production deployment cache store is shared if the application runs on multiple hosts; otherwise the documented at-most-once scope is single-host.
- During implementation, confirm whether existing transfer table markup can remain a presentation partial or whether keeping it as a nested Livewire component would reintroduce split state ownership.
