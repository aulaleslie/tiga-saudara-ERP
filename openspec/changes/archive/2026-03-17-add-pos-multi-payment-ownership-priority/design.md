## Context

Current POS checkout on `/pos/sell` is single-method by design: one selected payment method, one amount, one reference, and one `payment_method_id` persisted on `pos_checkouts`. The modal uses a searchable dropdown plus separate selected-value field, which causes visual/state collision during selection and does not scale to mixed-method tender.

At the same time, split posting already groups checkout results by ownership/tax context and proportionally allocates one aggregate paid amount into group `paid_total`. This means ownership split exists, but payment composition does not. Cash accounting, supervisor finalization, and reporting also infer cash vs non-cash from one top-level checkout payment method, which becomes invalid once a single checkout can contain both cash and non-cash lines.

Stakeholders:
- Cashiers: need fast, collision-free payment selection with mixed tender support.
- Supervisors: need accurate expected cash and variance in finalization.
- Finance/operations: need payment-method reports and reconciliation that match posted sale payments.
- Integrations: legacy consumers still rely on existing finalize compatibility fields.

## Goals / Non-Goals

**Goals:**
- Support multi-payment composition in checkout UI without selector/selected-value collision.
- Accept and validate multi-payment payloads with deterministic totals and replay-safe ordering.
- Allocate payment across ownership split groups with explicit non-cash priority to terminal-owner share, then deterministic proportional fallback.
- Keep finalize backward compatibility fields stable while exposing richer payment structures.
- Keep cash accounting, reconciliation, receipts, and reports consistent with mixed-method tender.

**Non-Goals:**
- Adding external payment gateway integrations, async capture, or settlement APIs.
- Changing stock split key logic (`source_setting_id + source_location_id + tax_bucket`).
- Redesigning receipt print layout beyond payment-breakdown readability.
- Replacing existing idempotency architecture; only extend it for multi-payment payloads.

## Decisions

### 1) Use a payment-composer UI instead of single searchable selector
**Choice:** Replace single selected-method flow with a composer list: cashier searches method, adds payment row, edits amount/reference inline, and sees `remaining`/`overpaid` summary.

**Rationale:** Prevents stacking/collision and maps directly to multi-tender mental model.

**Alternatives considered:**
- Keep single selector and add "split" toggle modal.
- Rejected because it preserves collision point and adds mode complexity.

### 2) Introduce multi-payment finalize contract with backward-compatible response projection
**Choice:** Add `payments[]` request contract (`payment_method_id`, `amount_paid`, optional `reference`) while retaining legacy response fields (`sale_id`, `sale_payment_id`, `dispatch_ids`) mapped from deterministic first split group.

**Rationale:** Enables mixed tender without breaking existing consumers.

**Alternatives considered:**
- Replace `payment` object in-place and drop legacy compatibility.
- Rejected due to high integration risk.

### 3) Persist checkout payment rows separately from top-level checkout method
**Choice:** Store payment entries as checkout child records (one row per payment line) and persist split-group allocation metadata linking each payment line to each split group allocation amount.

**Rationale:** Needed for replay, receipts, reporting, and reconciliation with mixed methods.

**Alternatives considered:**
- Store payment breakdown only in `response_payload` JSON.
- Rejected because relational reporting/reconciliation would become fragile and expensive.

### 4) Apply ownership-priority allocation in two stages
**Choice:** Allocation pipeline:
1. Build split ownership groups from existing planner.
2. Allocate non-cash payment amount to terminal-owner groups first, capped by their outstanding total.
3. Distribute non-cash overflow proportionally across remaining group balances.
4. Allocate cash across remaining balances proportionally.
5. Validate exact minor-unit reconciliation across payments and groups.

**Rationale:** Matches business rule "prioritize non-cash to POS setting owner" while preserving deterministic math.

**Alternatives considered:**
- Pure proportional allocation for all methods.
- Rejected because it ignores stated owner-priority policy.

### 5) Treat cash-session effects from cash component only
**Choice:** Session cash events and expected cash updates use summed cash payment component from checkout, not checkout grand total or single top-level payment method.

**Rationale:** Mixed-method checkout must increase drawer cash only by actual cash tender component.

**Alternatives considered:**
- Keep existing single-method inference from `pos_checkouts.payment_method_id`.
- Rejected because mixed tender would over/understate expected cash.

### 6) Rebuild payment reporting/reconciliation from payment entries
**Choice:** Payment-method summaries and cash/non-cash totals aggregate from persisted checkout payment rows (or posted sale-payments linked to those rows), not one payment method per checkout.

**Rationale:** A checkout can now contribute to multiple methods.

**Alternatives considered:**
- Continue grouping by `pos_checkouts.payment_method_id` and tag dominant method.
- Rejected due to loss of financial accuracy.

### 7) Extend idempotency hash and replay payload to include canonicalized payment lines
**Choice:** Canonicalize payment lines by deterministic order and include them in payload hash and stored replay payload, including per-group allocation outputs.

**Rationale:** Retries must reproduce identical multi-payment outcome and prevent payload mismatch ambiguity.

**Alternatives considered:**
- Hash only aggregate paid total.
- Rejected because two different payment compositions could collide semantically.

## Risks / Trade-offs

- [Allocation policy misunderstood by operators] -> Show explicit "allocation preview" in response payload/receipt metadata and add operator notes in release docs.
- [Rounding mismatch across payment x group matrix] -> Use minor-unit arithmetic with deterministic tie-breakers and strict reconciliation assertions.
- [Legacy paths accidentally use deprecated single-method fields] -> Keep compatibility fields but migrate internal reports/reconciliation to payment-entry source of truth.
- [Higher query complexity for reports] -> Add targeted indexes and precomputed summary helpers where required.
- [Receipt readability degradation with many payment lines] -> Limit printed payment breakdown to concise lines with totals and clear change display.

## Migration Plan

1. Add additive schema for checkout payment rows and payment-to-group allocation mappings.
2. Deploy server support for dual contract acceptance:
   - Legacy `payment` object (single-line compatibility path).
   - New `payments[]` contract (preferred path).
3. Update checkout UI to composer flow and submit `payments[]`.
4. Switch reporting/reconciliation/cash calculations to payment-entry source of truth.
5. Validate in staging with mixed-owner + mixed-method carts and idempotent retries.
6. Roll out progressively by setting/feature flag.
7. After stable period, deprecate write-path reliance on single-method checkout fields (keep read compatibility until explicitly removed).

Rollback:
- Toggle feature flag to force single-payment UI/API path.
- Keep additive schema in place; no destructive rollback required.

## Open Questions

- Should non-cash priority be configurable per terminal/setting, or fixed globally for this rollout?
- For payment method summary tab, should transaction count represent payment rows or distinct checkouts containing that method?
- Should receipt print full payment breakdown for all methods or only first N lines with "+n lainnya" compaction?
- Do we need explicit API versioning for `payments[]`, or is dual contract + capability rollout sufficient?
