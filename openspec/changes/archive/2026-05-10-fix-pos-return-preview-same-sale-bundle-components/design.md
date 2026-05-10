## Context

POS Return approval preview builds a read-only execution plan from persisted POS Return lines and current source checkout data. For bundled POS lines, the planner expands `line_meta.bundle_trace` into component target rows by matching the component product, bundle id, quantity, POS lineage, and persisted `sale_bundle_items`.

The current component candidate filter rejects every `sale_bundle_items` row whose `sale_id` equals the parent POS Return line `sale_id`. That protects one historical case where a parent-linked row existed only as context and the real component execution target lived in another generated Sale. It is too broad for valid same-owner bundle allocation cases, where a component is intentionally posted to the same generated Sale as the parent because the parent and component share source setting, source location, and tax bucket.

Observed POS Return 1 has this shape:

```text
POS checkout 1
├─ Sale 1: parent item and component product 2
└─ Sale 2: component product 3

POS Return lines 1 and 2
└─ bundle_trace
   ├─ product 2 -> sale_bundle_items.id=1, sale_id=1, line_group_key=pos-0-0
   └─ product 3 -> sale_bundle_items.id=2, sale_id=2, line_group_key=pos-0-1
```

Product 2 is uniquely mapped, but the preview blocks because Sale 1 candidates are discarded before lineage matching.

## Goals / Non-Goals

**Goals:**

- Allow approval preview to resolve same-sale bundle component targets when they are uniquely supported by persisted POS/Sales lineage.
- Preserve blockers for genuinely missing or ambiguous component target rows.
- Keep current split-owner component target support intact.
- Add regression coverage for a checkout where bundle components map to both the parent Sale and a separate generated Sale.

**Non-Goals:**

- Do not implement final approval execution from the preview page.
- Do not change POS checkout posting, Sales Return execution, stock, serial, payment, dispatch, or settlement behavior.
- Do not modify database schema or rewrite existing POS/Sales records.
- Do not relax unrelated dispatch, serial, replacement serial, snapshot drift, or source identity validations.

## Decisions

### Decision: Replace blanket same-sale exclusion with evidence-based target selection

The planner should not discard same-sale `sale_bundle_items` rows solely because they share the parent Sale. Instead, it should build candidate rows from all checkout-linked Sales and then use deterministic evidence to narrow them:

1. component product id
2. parent bundle id when available
3. full or apportioned component quantity
4. POS lineage from `line_group_key` suffix matching the bundle trace index
5. POS transaction line bundle metadata, including informational component price when available

Rationale: same-owner bundle allocations are valid in split posting, and the persisted `line_group_key`/bundle metadata is stronger evidence than `sale_id != parent_sale_id`.

Alternative considered: simply remove the same-sale exclusion. That is too loose because it could reintroduce false positives when both a parent-linked context row and a separate standalone execution row exist.

### Decision: Prefer POS lineage matches before broader fallback matches

When POS transaction line metadata is available, lineage-matched candidates should be preferred over broader product/bundle/quantity matches. If lineage reduces candidates to one row, that row is the planned component target even if it is in the same Sale as the parent.

Rationale: POS-created bundle rows use deterministic `line_group_key` values such as `pos-0-0` and `pos-0-1`, which identify the original bundle component order. That lets preview distinguish sibling components and same-sale allocations without relying only on owner grouping.

Alternative considered: choose same-sale candidates only when no off-sale candidates exist. That handles POS Return 1, but it is weaker than lineage-first matching and could choose the wrong row when multiple generated Sales contain the same component product.

### Decision: Keep blockers as safety boundaries

If candidate selection still produces zero rows in a multi-sale checkout, the planner should continue reporting `component_target_missing`. If it produces more than one row after all available lineage narrowing, it should continue reporting `component_target_ambiguous`.

Rationale: approval preview is a safety gate. The fix should remove false blockers, not infer unsafe execution targets.

Alternative considered: downgrade component target failures to warnings. That would make the preview look ready even when approval execution cannot be planned safely.

## Risks / Trade-offs

- Same-sale rows that were historically parent-context-only could become candidates if lineage evidence is absent or incomplete. Mitigation: prefer lineage matches and keep ambiguity blockers when multiple plausible candidates remain.
- Existing tests may encode the old broad same-sale exclusion. Mitigation: update or add focused tests that distinguish invalid context rows from valid same-owner component allocations.
- Floating quantity/amount comparisons can remain fragile. Mitigation: reuse the existing `quantitiesMatch()` tolerance and amount apportionment behavior rather than introducing new numeric rules.
- The source snapshot may not carry enough component owner data for every legacy return. Mitigation: rely on current persisted checkout sale and `sale_bundle_items` rows, while keeping snapshot drift validation unchanged.
