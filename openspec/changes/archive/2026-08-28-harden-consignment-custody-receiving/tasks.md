## 1. Receival lifecycle concurrency

- [x] 1.1 Add focused regression tests proving Receival update and deletion revalidate locked authoritative state and cannot mutate a concurrently submitted document.
- [x] 1.2 Add transactional lifecycle service operations for Receival draft/rejected update and draft-only deletion using `lockForUpdate()` and in-transaction state/dependency checks.
- [x] 1.3 Refactor Phase 1 Receival controller update and destroy actions to delegate decisive lifecycle validation and mutation to the locked service operations while retaining permission and active-setting lookup guards.

## 2. Active location admission and classification governance

- [x] 2.1 Add focused feature/service tests that exclude inactive consignment locations from selectors and reject forged or newly inactive locations during pending creation and approval without partial mutation.
- [x] 2.2 Apply active, setting-owned, consignment-location scopes to Phase 1 receiving lists and selectors and revalidate all three attributes under lock in receiving creation and approval.
- [x] 2.3 Implement a centralized, tenant-safe location classification dependency checker covering non-zero stock buckets, pending/approved consignment receiving documents, active consignment serials, immutable receiving provenance, sold sources, and Phase 2 allocation dependencies.
- [x] 2.4 Integrate the dependency checker into Location classification updates with actionable dependency-category validation messages and tests for zero-stock locations that still retain documents or provenance.

## 3. Tenant isolation and deterministic product lines

- [x] 3.1 Scope the Phase 1 Receival supplier filter collection to the active setting and add a feature test proving foreign-setting supplier names and IDs are absent.
- [x] 3.2 Add normalization and controller regression tests rejecting duplicate product IDs on Receival create and edit with no partial persistence.
- [x] 3.3 Enforce one product line per Receival in domain normalization with an actionable error before any document or line mutation.
- [x] 3.4 Add a read-only duplicate-data preflight for existing `consignment_receival_lines` and report affected Receival IDs without modifying historical rows.
- [x] 3.5 When the preflight is clean, add an SQLite/MySQL-compatible unique constraint on `(consignment_receival_id, product_id)` with a reversible migration and focused constraint test.
- [x] 3.6 Add a focused receiving approval/full-reversal regression proving the one-product-per-document invariant produces deterministic stock and cost snapshots.

## 4. Focused verification and release readiness

- [x] 4.1 Run the Consignment custody receiving unit tests and Phase 1 governance feature tests, recording the test and assertion totals.
- [x] 4.2 Run the Phase 2 Consignment allocation unit and feature tests to confirm location/provenance hardening does not regress sold-source discovery, confirmation lifecycle, or reconciliation.
- [x] 4.3 Run directly affected Purchase receiving and inventory/warehouse valuation tests to preserve ordinary-location and owned-versus-custody behavior.
- [x] 4.4 Run PHP syntax checks for touched files, `git diff --check`, and strict OpenSpec validation for this change; resolve only issues introduced by this implementation.
