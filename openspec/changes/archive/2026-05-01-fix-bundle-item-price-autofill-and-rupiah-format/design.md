## Context

Bundle item pricing in Product Bundle create/edit is managed by a Livewire table component that hydrates `informational_item_price` when a product is selected. In current behavior, unresolved pricing can collapse to `0`, and the row input is numeric-only (`type=number`), which conflicts with the desired Indonesian currency interaction (`Rp ...` on blur, raw number on focus).

This change touches both create and edit flows and crosses component/view boundaries: item selection, price resolution, input formatting, and payload normalization before controller validation.

## Goals / Non-Goals

**Goals:**
- Ensure bundle item autofill uses active-setting non-tier source (`product_prices.sale_price`) as the authoritative value.
- Prevent silent ambiguity when selected products have no sale price row for current setting.
- Deliver consistent focus/blur currency UX for `informational_item_price` in create and edit.
- Preserve server-side numeric contract so controller validation and persistence keep receiving clean numeric values.

**Non-Goals:**
- Changing POS/Sales runtime bundle pricing logic.
- Introducing tier-based pricing selection for bundle item informational prices.
- Altering database schema for bundle/header/item price fields.

## Decisions

### Decision 1: Use active-setting `sale_price` only for bundle item autofill
- Choice: Resolve item autofill from `product_prices.sale_price` by `(product_id, setting_id)`.
- Rationale: Requirement is explicitly non-tier and setting-scoped; this aligns with existing Product pricing model and avoids customer-tier coupling in bundle configuration UI.
- Alternative considered: fallback to tier prices when sale price missing.
- Rejected because it violates non-tier requirement and creates opaque behavior.

### Decision 2: Introduce explicit missing-price guard path
- Choice: when no price row / no `sale_price`, show clear row-level warning and keep value empty (or explicit zero only if legacy compatibility requires it).
- Rationale: distinguish true zero from missing data; reduce accidental incorrect saves.
- Alternative considered: silent fallback to `0`.
- Rejected because it hides data quality issues and caused the current confusion.

### Decision 3: Use text-based currency field with canonical numeric state
- Choice: switch informational price input interaction to text-display formatting, while retaining canonical numeric value for submit.
- Rationale: `type=number` cannot display `Rp` + grouped separators reliably; existing codebase already has a reusable focus/blur currency pattern.
- Alternative considered: keep `type=number` and format outside field.
- Rejected because it cannot satisfy in-field blur/focus behavior requested.

### Decision 4: Keep backend validation unchanged and normalize at UI boundary
- Choice: continue using `required|numeric|min:0` in controller, with hidden/raw value submission from Livewire state.
- Rationale: minimizes backend churn and preserves proven validation semantics.
- Alternative considered: accepting formatted currency strings server-side.
- Rejected because it broadens parsing responsibilities and regression surface.

## Risks / Trade-offs

- [Risk] Currency formatting logic diverges across screens if copied ad-hoc.
  → Mitigation: reuse existing shared pattern and align separators/prefix config (`Rp`, `.`, `,`).

- [Risk] Missing-price guard may block workflows where teams intentionally save zero.
  → Mitigation: clarify policy in spec/tests (empty + warning vs allowed explicit zero input).

- [Risk] Livewire hydration/hidden input drift could submit stale values.
  → Mitigation: feature tests for immediate-save after typing and focus/blur transitions in create/edit.

- [Risk] Setting-scoped price lookup may expose products without current-setting price.
  → Mitigation: optionally scope picker query to priced products in active setting; otherwise enforce clear warning path.
