# Tasks

## 1. Version Boundary and Persistence

- [x] 1.1 Add v3 creation activation and explicit legacy/v3 routing guards; verify focused tests keep existing workflow versions unchanged through edits, rejection and deployment.
- [x] 1.2 Add version-compatible nullable route headers, creation-business context, immutable request revisions, approval allocations/serial assignments, execution allocations and cancellation evidence; verify isolated migrations preserve representative legacy rows, indexes and foreign keys.
- [x] 1.3 Implement location-independent v3 numbering with a locked sequence and unique reference key; verify focused duplicate/concurrent allocation and legacy-number preservation cases.
- [x] 1.4 Audit transfer consumers for required header locations, status interpretation and movement lineage, and adapt v3 readers; verify list/detail/history render with strict lazy loading and mixed legacy/v3 fixtures.

## 2. Authorization and Projections

- [x] 2.1 Register cancel-dispatch and view-history through existing permission synchronization and implement the design's active-business action matrix; verify separate permissions, self-approval and cross-business actions without route-business memberships.
- [x] 2.2 Implement global v3 discovery alongside existing legacy scope and version-aware list actions; verify focused query/endpoint tests deny unauthorized detail/actions and preserve legacy discovery.
- [x] 2.3 Implement separate goods, allocation, history and receipt projections; verify sentinel-based payload tests cover HTML, Livewire state, JSON, errors and exports, including approval without stock visibility, stock visibility without approval, and history without approval.
- [x] 2.4 Ensure configured operator roles can follow required detail redirects using explicit show permission, without implicit permission grants; verify success redirects and denial for crafted direct detail access.

## 3. Location-Free Goods Entry

- [x] 3.1 Adapt existing search, exact barcode/conversion/serial resolution and ambiguity handling to cross-business discovery without origin selection; verify focused resolver cases for collisions, whole conversions, duplicates, eligible conditions and unavailable serials.
- [x] 3.2 Implement v3 goods draft/save and atomic create-submit, retaining one condition and allowing incomplete serial drafts but exact serialized submission; verify focused service/Livewire cases for both create/edit, mismatch rejection, rollback and duplicate submission.
- [x] 3.3 Freeze submitted goods revisions and invalidate allocation approval context after material edits or rejection/resubmission; verify a stale plan cannot dispatch changed goods.
- [x] 3.4 Update creation/edit labels, remove locations and redirect save/submit to detail; verify targeted component/action checks, leaving scanner focus and interaction testing to the human browser checklist.

## 4. Approval Allocations

- [x] 4.1 Build approver-only serial grouping by product/source and searchable non-serialized source/destination selection; verify multi-business, repeated-source aggregate limits, condition filtering and identical-route rejection.
- [x] 4.2 Implement manual Simpan Progres with request/configuration revisions and incomplete destinations; verify resume, stale-save rejection, event recording and absence of reservations/inventory effects.
- [x] 4.3 Implement final server-derived summary and revision-bound confirmation, plus reasoned rejection without editing goods through allocation payloads; verify stale modal, missing routes, quantity totals and serial source drift prevent dispatch.
- [x] 4.4 Snapshot per-allocation business identities, cross-business marker and existing tax-classification rules independently of return policy; verify same-business preservation, both cross-business tax classifications and no applicable-tax rollback.

## 5. Atomic Dispatch

- [x] 5.1 Implement v3 approval/dispatch orchestration with stable locks and aggregate consumption validation; verify mixed-source non-serialized good/broken bucket deductions and global product totals.
- [x] 5.2 Persist immutable movement/allocation/transaction evidence and exact serial route snapshots with existing exclusive claims; verify shared serial availability remains enforced in competing inventory selection paths.
- [x] 5.3 Commit approval/dispatch events and DISPATCHED status with inventory changes; verify late-failure rollback, operation replay, stale revisions and serial-claim conflicts using focused tests.
- [x] 5.4 Guard separate legacy dispatch and return routes against v3 documents; verify no extra dispatch preparation is needed and existing v2 behavior still passes representative focused tests.

## 6. Confirmation Receipt and Cancellation

- [x] 6.1 Add Terima Barang and the agreed Bahasa Indonesia confirmation using receive permission only; verify the endpoint accepts whole-document confirmation without count entry or receive.approval, and rejects quantity/location overrides.
- [x] 6.2 Implement atomic posting of every frozen destination allocation, serial reclassification/movement, claim closure and completion evidence; verify same/cross-business routes, good/broken stock, replay and late-failure rollback without return obligations.
- [x] 6.3 Add reasoned Batalkan Pengiriman confirmation with dedicated permission and DISPATCHED-only guard; verify empty reasons, completed/cancelled states and unauthorized requests cannot reverse stock.
- [x] 6.4 Implement exact source-delta compensation, product-total restoration, matching serial-claim release and immutable reversal history; verify intervening source transactions and tax-setting changes remain intact, and mismatched custody rolls back all effects.
- [x] 6.5 Verify receipt/cancellation mutual exclusion and replay semantics through focused tests; use only a disposable MySQL test database for an actual locking race check when available, otherwise document the unverified MySQL concurrency limit.

## 7. Detail History and Navigation

- [x] 7.1 Record lifecycle events transactionally and render the permission-protected detail timeline; verify actor/time/revision/reason evidence, no duplicate successful events and no route metadata for history-only viewers.
- [x] 7.2 Hide Archive on Stock Transfer surfaces, show only valid new-workflow actions, and retain legacy history/return readability; verify focused view and direct-route assertions for terminal states and historical fixtures.

## 8. Focused Verification and Human Handoff

- [x] 8.1 Run only the changed Stock Transfer test files and selected affected regression filters in an isolated test database, and record exact commands/results; do not run the full application suite or destructive tests on the production replica.
- [x] 8.2 Update manual-verification.md with implemented route names and expected labels, and hand it to the human developer; verify the checklist covers all UI decisions, without executing automated or agent-driven browser tests.
- [x] 8.3 Document migration/activation and creation-disable rollback instructions, preserving populated v3 support; verify the instructions prohibit historical rewrites and destructive schema rollback.
- [x] 8.4 Run openspec validate redesign-stock-transfer-allocation-workflow --strict and resolve artifact inconsistencies; verification is a successful strict validation result.
