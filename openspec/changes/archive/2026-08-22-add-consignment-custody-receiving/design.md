## Context

The ERP currently treats Purchase receiving approval as both physical receipt and acquisition: it updates aggregate and location stock, tax buckets, serial history, inventory transactions, last and average purchase prices, and Purchase completion. Consignment Phase 1 needs the custody pieces without creating an ordinary Purchase or payable.

Physical inventory is stored in aggregate `product_stocks` rows keyed operationally by product/location, serials carry location and history, and per-setting ProductPrice rows supply Sales/POS HPP. Location ownership and sale-location priority are already established cross-module seams. Inventory reports currently multiply physical quantities by average cost and do not distinguish supplier-owned custody.

The stakeholders are receiving operators, document and receiving approvers, inventory controllers, finance/report users, and administrators configuring locations and permissions. The urgent release favors a narrow inbound-only workflow and established Laravel/Livewire conventions.

## Goals / Non-Goals

**Goals:**

- Introduce a dedicated inbound consignment custody workflow independent from Purchase/payables.
- Reuse established document and receiving approval conventions without calling the coupled Purchase receiving controller.
- Preserve exact supplier, product, location, UOM, cost, tax, transaction, and serial provenance.
- Update physical stock and a setting-scoped operational weighted average atomically on receiving approval.
- Keep ordinary Purchase, location, stock, cost, and report behavior unchanged for non-consignment operations.
- Support a conservative full reversal before any downstream dependency exists.
- Establish data required by later sales allocation and supplier billing phases.

**Non-Goals:**

- Sales/POS consignment detection, HPP allocation, billing confirmation, Purchase generation, payment, customer/supplier returns beyond the narrow receipt reversal, transfers, adjustments, imports, bundles, partial/multiple receiving, agreements, commissions, outbound consignment, or tax-platform integration.
- Historical inventory conversion or backfill.
- Supplier/customer PKP classification; tax is setting-driven in this ERP.

## Decisions

### 1. Use a dedicated Consignment domain

Create separate headers, lines, receiving notes, and receiving details under a Consignment module/domain. Reuse status names, notification conventions, permission patterns, Blade/Livewire conventions, and shared low-level stock/serial helpers where safe.

Alternative: add a Purchase type at receipt time. Rejected because Purchase status, payment eligibility, reports, costing, and receiving services assume an acquisition/payable and would create pervasive exceptions before a supplier bill legally exists.

### 2. Use `locations.is_consignment` as a binary admission rule

Add a default-false indexed boolean. Consignment receiving requires true; ordinary Purchase receiving requires false. Location remains physical custody and never identifies supplier ownership.

Alternative: location-type enum. Deferred because no other mutually exclusive location classes are required.

### 3. One full receiving note per receival

The receival approves expected custody; its one receiving note verifies actual complete delivery. A rejected note may be replaced, but pending/approved uniqueness is enforced transactionally. Operators create a new receival for another delivery.

Alternative: reuse partial Purchase receiving. Rejected for Phase 1 because cumulative receipt quantities, shortfall completion, and cost normalization add substantial scope.

### 4. Approved receiving details are immutable supplier ownership lots

The approved detail stores supplier through its header and product/location/quantity/cost/tax through snapshots. Non-serialized supplier availability will later be derived from immutable receipt and allocation movements, not a mutable balance counter. Serial history references the consignment detail polymorphically; a direct current-source pointer may be added only as a search optimization.

Alternative: supplier columns on `product_stocks`. Rejected because multiple suppliers share one product/location physical bucket.

### 5. Add distinct inventory provenance and transaction types

Use `CONSIGNMENT_RECEIPT` and `CONSIGNMENT_RECEIPT_REVERSAL` transactions with durable source references to consignment receiving details. Prefer an additive polymorphic source (`source_type`, `source_id`) that can coexist with legacy `received_note_detail_id`.

Alternative: record `BUY`. Rejected because reports and replay interpret BUY as an owned purchase and attempt Purchase reference/cost resolution.

### 6. Update physical stock but only setting-scoped operational average cost

Approval locks the setting/location and affected stock/price rows, computes setting quantity before receipt, and applies weighted average using approved line unit DPP. A zero-quantity product seeds the average. Only the receival setting's ProductPrice average changes; neither the global synchronizer, other settings, nor last purchase price is updated.

This intentionally defines the field as an operational HPP estimate across physically sellable stock for the setting. Later supplier billing uses exact receipt allocation cost and does not need to equal weighted-average sale HPP.

Alternative: keep average unchanged. Rejected for the urgent path because existing Sales/POS HPP resolution would treat a new consignment-only product as missing/zero cost and require a larger Phase 2 provisional-cost system.

### 7. Snapshot setting-driven tax at receival approval

Validation uses the header's persisted setting. PKP requires applicable line tax; non-PKP persists null/zero tax. Receiving consumes the approved snapshot even if setting configuration later changes and increments the matching physical tax/non-tax stock bucket.

Alternative: defer tax until supplier billing. Rejected because Sales/POS source behavior already depends on physical stock tax buckets.

### 8. Receiving approval is one locked database transaction

Lock the receival, receiving note, location or other stable creation guard, affected products, ProductPrice rows, existing stocks, and serial candidates. Revalidate lifecycle, tenant, feature, location, quantities, snapshots, serial uniqueness, and absence of prior processing. Apply all lines, transactions, provenance, serial history, cost, notification resolution, and status in one commit.

Database uniqueness/indexes provide idempotency where possible; row locks protect mutable aggregate balances. Because product/location stock creation lacks a guaranteed historical unique constraint, locking the stable location row serializes missing-row creation until a safe uniqueness migration is validated.

### 9. Full reversal restores snapshots only under strict eligibility

Approval records before/after stock buckets, setting quantity, operational average, serial state, and transaction IDs. Full reversal is allowed only when every affected record still matches its post-approval snapshot and no later movement/cost/dependency exists. It creates reversal history and transactions rather than deleting approvals.

Alternative: algebraically reverse after later movements. Rejected because average-cost and fungible-stock consumption make exact ownership restoration ambiguous.

### 10. Valuation separates physical custody from owned value

Warehouse views label consignment locations and exclude their value from owned grand totals. Inventory valuation replay recognizes consignment transaction types separately and avoids treating operational average over consignment quantity as an owned asset. Standard-only report behavior remains unchanged.

### 11. Govern Phase 1 entry points with permissions

Expose the module whenever it is installed and enabled through the existing module system. Menus and actions remain protected by dedicated permissions, tenant boundaries, lifecycle validation, and domain-level inventory guards; no additional environment feature flag is used.

## Risks / Trade-offs

- [Operational average includes supplier-owned physical stock] → Separate owned versus custody valuation and document that supplier billing uses exact receipt cost.
- [Existing cost synchronizer propagates across settings] → Never invoke it from consignment receiving; assert other-setting prices remain unchanged.
- [Product/location stock row can race on first creation] → Lock a stable location guard, re-query under transaction, and add a unique key only after duplicate-data preflight proves safe.
- [Tax reality may be more nuanced than setting-only project rules] → Follow the confirmed ERP convention and snapshot inputs; require adviser review before later billing/tax release.
- [Full reversal is intentionally restrictive] → Give actionable blocking evidence and require a new receival after eligible full reversal; defer partial corrections.
- [Consignment location cannot accept owned Purchase stock] → Make classification and selector labels explicit; require separate standard and consignment locations.
- [Phase 1 creates custody without billing] → Restrict access through dedicated permissions and controlled role assignment.
- [Inventory reports currently infer cost from BUY/Purchase references] → Add explicit consignment movement classification and regression-test standard replay/export parity.

## Migration Plan

1. Add the location classification with default false.
2. Add consignment document, receiving, detail, audit/provenance, reversal, and necessary transaction/serial source schema with foreign keys and indexes.
3. Deploy models/services/permissions/routes/UI behind dedicated permissions and tenant guards.
4. Add ordinary Purchase receiving guards and valuation/report classification while all historical locations remain standard.
5. Run focused and fresh-SQLite tests, inspect for duplicate product/location stock rows before considering any uniqueness constraint, and verify no historical backfill occurs.
6. Configure dedicated empty consignment locations, roles, and a controlled UAT dataset.
7. Grant consignment permissions only to roles approved for the Phase 1 workflow.

Rollback first revokes consignment permissions. Additive tables/columns remain to preserve audit data; schema rollback is permitted only when no consignment records exist. Reclassifying locations or deleting custody history is not a rollback mechanism.

## Open Questions

- Confirm whether location classification may be disabled when historical records exist but all active balances are zero; the conservative default is to require explicit archive/governance rather than reinterpret history.
- Confirm whether the inventory report will show a separate consignment subtotal in Phase 1 or only exclude it from the owned total while warehouse reporting supplies the physical detail.
