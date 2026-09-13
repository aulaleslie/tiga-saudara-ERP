## 1. Extend Receipt Persistence and Eligibility

- [x] 1.1 Add additive movement fields for document-level physical-count confirmation, confirmer/timestamp, and any receipt-specific applied inventory references or snapshots not already represented on movement lines.
- [x] 1.2 Update movement entities, casts, relationships, and invariants for empty receipt confirmation and receipt application provenance.
- [x] 1.3 Add a centralized workflow version `2` eligibility resolver for same-business and non-PKP-to-non-PKP routes, retaining version `1` for every PKP-involved route.
- [x] 1.4 Integrate eligibility into new-transfer version assignment and every version `2` dispatch/receipt entry point without adding historical transfer backfill.

## 2. Build Universally Blind Receipt Preparation

- [x] 2.1 Add a destination-side preparation service that creates or resumes the one open `FORWARD_RECEIPT` attempt linked to the exact approved forward dispatch and seeds no lines.
- [x] 2.2 Adapt barcode, whole-unit conversion, serial, and tokenized search to destination-tenant receipt observations while allowing zero-stock non-serialized products and requiring individual scans for serialized quantities.
- [x] 2.3 Preserve unexpected products and substitute serial identities as operator observations without exposing source-manifest classification or claim provenance.
- [x] 2.4 Implement document-level empty-count confirmation with actor/timestamp and clear stale confirmation whenever receipt observations change.
- [x] 2.5 Submit explicitly completed empty, partial, excess, shortage, unexpected, or substitute observations as immutable pending attempts without inventory, serial, custody, claim, or header effects.
- [x] 2.6 Make rejected receipt corrections start as empty blind drafts while preserving immutable supersession history.

## 3. Enforce Destination Authorization and Projection Boundaries

- [x] 3.1 Add a universal receipt-preparation projection that is identical for preparers with and without stock visibility and contains only sanitized operator observations.
- [x] 3.2 Add blind and privileged receipt-approval projections, returning neutral match/failure guidance to blind approvers and exact source/count, stock, allocation, serial, custody, and difference detail only to privileged approvers.
- [x] 3.3 Add destination-side authorization wrappers for prepare, scan, quantity/serial mutation, empty confirmation, submit, cancel, correct, review, approve, and reject using receive create/approval permissions.
- [x] 3.4 Enforce scoped transfer, receipt movement, and source dispatch aggregate identity at both route and domain boundaries and neutralize blind browser errors.
- [x] 3.5 Add receipt preparation and review browser surfaces with escaped dynamic rendering, scanner-first interaction, explicit empty confirmation, and no hidden source-manifest state.

## 4. Compare and Approve Forward Receipt Atomically

- [x] 4.1 Implement canonical receipt comparison against the exact approved forward dispatch using product, condition, base quantity, and normalized distinct serial sets independent of ordering.
- [x] 4.2 Implement a dedicated receipt approval executor that locks transfer, source dispatch, receipt, lines, destination stocks, global products, live serials, and active claims in stable order and revalidates all aggregate, lifecycle, eligibility, provenance, and whole-unit invariants.
- [x] 4.3 Add the approved dispatch's immutable applied good/broken and tax/non-tax quantities to destination stock, update global totals, and persist receipt inventory transactions and before/after snapshots.
- [x] 4.4 For every exact serialized line, validate source-owned active custody, move the live serial to destination, record serial history, close movement custody, and remove the active claim atomically.
- [x] 4.5 Approve and history-stamp the receipt and project eligible no-return transfers from `DISPATCHED` to `COMPLETED` in the same transaction.
- [x] 4.6 Guarantee rollback without partial destination, serial, custody, claim, movement, history, transaction, or header effects and return the committed result on same-key idempotent replay.

## 5. Focused Verification

- [x] 5.1 Add focused migration/model tests for empty confirmation audit fields, receipt provenance, casts, relationships, and rollback-safe defaults.
- [x] 5.2 Add focused receipt preparation/scanner tests for empty starts, product/conversion/token search, zero-stock observation, exact and substitute serials, duplicates, tenant scope, serialized restrictions, and explicit empty confirmation.
- [x] 5.3 Add focused authorization and payload-leakage tests proving preparation is universally blind, approval is permission-aware, destination ownership is enforced, aggregate IDs are scoped, and crafted protected client values are ignored.
- [x] 5.4 Add focused comparison tests for exact, empty, partial, missing, excess, unexpected, condition, and serialized-set outcomes plus empty correction behavior.
- [x] 5.5 Add focused approval tests for good and broken provenance, tax/non-tax buckets, whole-unit constraints, global/destination underflow consistency, atomic rollback, header completion, and idempotent replay.
- [x] 5.6 Add focused serial custody and concurrency tests for exact claim ownership, location movement, custody closure, claim removal, competing operations, and failed/mismatched receipt preservation.
- [x] 5.7 Add focused activation tests for same-business, non-PKP-to-non-PKP, every PKP-involved route, crafted ineligible version `2` requests, and continued return-movement dormancy.
