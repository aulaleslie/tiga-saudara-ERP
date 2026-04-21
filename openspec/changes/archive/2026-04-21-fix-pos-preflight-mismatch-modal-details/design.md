## Context

POS checkout preflight already returns structured validation failures (`code`, `message`, `details`) from backend when stock or serial fulfillability fails. The POS sell frontend currently throws a plain JavaScript `Error(message)` from a shared request helper, which discards `details` and prevents mismatch-modal routing from executing. As a result, cashiers see a generic validation alert instead of line-level mismatch diagnostics when clicking `Pilih Pembayaran`.

This is a cross-layer consistency issue: backend contract is correct, but frontend transport and rendering assumptions are inconsistent with that contract.

## Goals / Non-Goals

**Goals:**
- Preserve structured preflight error payload from backend through frontend request error handling.
- Guarantee preflight mismatch failures route to the dedicated mismatch modal and keep staged payment closed.
- Normalize mismatch modal row rendering to tolerate authoritative backend diagnostic fields and derive missing presentation fields deterministically.
- Add regression coverage for this failure path so future frontend refactors cannot silently downgrade to generic alerts.

**Non-Goals:**
- Changing checkout preflight endpoint URL, status code strategy, or base response envelope.
- Redesigning staged payment UX beyond mismatch failure handling.
- Altering stock allocation or serial validation business logic.

## Decisions

### 1. Use structured application errors in POS request helper
- **Decision:** Replace plain `Error(message)` throw path with an error object that preserves at least `message`, `code`, `details`, and HTTP status.
- **Rationale:** Preflight consumer logic already branches on `error.details`; preserving shape restores intended behavior without API changes.
- **Alternatives considered:**
  - Parse and branch inside checkout click handler only: rejected because it duplicates error handling and leaves other POS flows inconsistent.
  - Flatten `details` into `message`: rejected because structured diagnostics are needed for line-level modal rendering.

### 2. Define modal rendering fallback contract for mismatch lines
- **Decision:** Treat `requested_qty` and `allocated_qty` as canonical quantity fields and compute shortage as `max(requested_qty - allocated_qty, 0)` in UI if shortage is absent.
- **Rationale:** Backend currently guarantees requested/allocated but not a dedicated shortage field.
- **Alternatives considered:**
  - Require backend to send `shortage`: rejected for now to avoid unnecessary API expansion.

### 3. Provide deterministic product label fallback in mismatch modal
- **Decision:** Render product descriptor in priority order: `product_name` → `product_code` → `Product #<product_id>`.
- **Rationale:** Preflight failure payload may include identifiers without display name; cashier still needs actionable context.
- **Alternatives considered:**
  - Force backend to always include `product_name`: rejected as a separate concern; frontend can remain resilient with existing identifiers.

### 4. Add regression tests on preflight-failure UX path
- **Decision:** Extend POS test coverage to assert structured failure propagation and mismatch rendering path selection over generic alert fallback.
- **Rationale:** Existing tests validate backend payload contract but not frontend consumption behavior.
- **Alternatives considered:**
  - Rely only on manual QA: rejected due to high regression risk in large inline sell-page script.

## Risks / Trade-offs

- **[Risk]** Shared request helper change may affect other POS actions that rely on plain `Error` semantics.  
  **Mitigation:** Keep `message` behavior backward-compatible and only add non-breaking fields.
- **[Risk]** Modal fallback labels may be less friendly when `product_name` is absent.  
  **Mitigation:** Prefer `product_code` before ID and keep reason-aware guidance text.
- **[Risk]** UI regression tests for Blade+JS interactions can be brittle.  
  **Mitigation:** Focus assertions on branch behavior and payload shaping rather than brittle DOM cosmetics.

## Migration Plan

1. Update POS sell request helper to preserve structured error payload.
2. Normalize mismatch modal mapping for quantity/product fallback behavior.
3. Add regression tests for preflight mismatch handling and generic alert fallback guard.
4. Verify preflight success path still opens staged payment modal as before.

Rollback:
- Revert frontend helper/modal changes; backend contract remains compatible and untouched.

## Open Questions

- Should reason-code-specific copy be added per line (e.g., serial invalid vs insufficient stock), or remain at current generic modal guidance?
- Do we want to standardize structured error object usage across all POS pages beyond sell flow in a follow-up change?
