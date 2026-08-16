## Context

Normal Sales currently carries bundle composition as cart-row options, normalizes each parent row independently, and persists one `sale_details` parent with its linked `sale_bundle_items` inside a database transaction. Edit hydration reconstructs `quantity_per_bundle` from persisted quantities, while later quantity changes recalculate captured component quantities from that base. Dispatched Sales use a separate in-place monetary edit path so dispatch and component foreign-key identity is not destroyed.

Existing focused tests establish much of this behavior, but Sequence 5 still lacks a cohesive regression boundary for repeated quantity changes, removal isolation, cross-row component identity, and rollback on component-write failure. The change must preserve the established single-owner Normal Sales model and avoid absorbing POS owner-split or downstream dispatch-identity work.

## Goals / Non-Goals

**Goals:**

- Turn the Sequence 5 persistence invariants into focused, executable regression coverage.
- Prove that parent rows remain distinct across bundle identities and ordinary-versus-bundled usage.
- Prove that captured component quantities, parent linkage, and Sale linkage remain correct through create and editable-draft update paths.
- Prove atomic rollback at the component-persistence boundary.
- Preserve deliberate stock deferral from Sales persistence to dispatch.
- Limit production edits to the smallest correction demonstrated by a failing regression.

**Non-Goals:**

- Redesigning the Sales cart, normalizer, persistence service, or database schema.
- Changing normal Sales into an owner-split flow.
- Changing POS allocation, parent residual, tax, or component ownership behavior.
- Fixing downstream handling of standalone POS/legacy `sale_bundle_items` rows whose `sale_detail_id` is null.
- Reopening bundle pricing, lifecycle, snapshot, dispatch composite-key, serial, HPP, return, or reporting decisions owned by other hardening sequences.

## Decisions

### 1. Use characterization-first implementation

Add focused tests against the public Livewire/service behavior before changing production code. If the tests pass, the change remains test-only. If a test fails, adjust only the narrowest cart or persistence boundary responsible for that failed invariant.

Alternative considered: proactively refactor bundle persistence into a new coordinator. Rejected because the current transactional service and row-level persistence already satisfy the observed flow, making a refactor higher-risk than the hardening goal warrants.

### 2. Treat the cart snapshot as authoritative during editable Sales persistence

The persisted component quantity and captured composition come from the selected or hydrated cart row. Live product-bundle definitions may trigger lifecycle/drift warnings, but they do not silently replace captured quantities or component identity during an acknowledged draft update.

Alternative considered: reload current bundle composition during every save. Rejected because this would mutate an in-progress transaction and contradict the established snapshot contract.

### 3. Assert identity at both parent and Sale levels

For linked normal Sales components, tests will assert both `sale_detail_id` ownership and `sale_id === saleDetail.sale_id`. This covers the required invariant without introducing a redundant database constraint or schema migration.

Alternative considered: add a composite database constraint spanning `sale_bundle_items.sale_id` and `sale_details.sale_id`. Rejected for this boundary because the service assigns both values in one transaction, cross-table equality is not directly expressible as a conventional foreign key in the current schema, and no corruption has been demonstrated.

### 4. Exercise rollback by failing a component write inside the real service transaction

The regression test will introduce a controlled, test-local failure at component persistence after the Sale or parent detail has begun writing, then assert that no partial Sale, detail, or component data remains. Production failure hooks or application-only test seams will not be added unless the existing framework cannot provide a safe local failure mechanism.

Alternative considered: regard the presence of `DB::transaction` as sufficient proof. Rejected because the component boundary is central to Sequence 5 and deserves executable rollback evidence.

### 5. Keep deferred stock validation explicit

Creation and editable-draft update remain reservation-free and do not mutate inventory or reject insufficient component stock. Dispatch remains the enforcement point for location-specific availability. Tests will characterize both halves so future work cannot accidentally move only one side of the rule.

Alternative considered: validate or reserve stock during Sales creation. Rejected because it would change established workflow semantics and overlap dispatch/inventory hardening.

### 6. Defer standalone null-parent compatibility without discarding it

Current POS split posting normally creates a zero-quantity parent detail for component-only owner groups and links their component rows to it. Truly standalone rows are a valid historical cross-module representation, but successful downstream dispatch of such rows is not a normal Sales persistence concern. The observed dispatch inconsistency remains recorded for Sequence 6 or 7.

Alternative considered: fix standalone dispatch submission in this change. Rejected because it expands scope from normal Sales writing into POS-generated downstream identity behavior.

## Risks / Trade-offs

- [A characterization test may expose a real production defect] → Make only the smallest correction needed for that specific invariant and add the failing case as permanent coverage.
- [Mocking an Eloquent write too broadly could test framework behavior rather than rollback] → Prefer a test-local model event or database-validity failure that occurs inside the real `SaleService` transaction, and assert all affected tables afterward.
- [Old aggregator tests may imply production aggregation behavior that is not used by submission] → Test the actual Livewire/service path and avoid changing `SaleCartAggregator` unless a production caller is demonstrated.
- [Quantity tests may accidentally exercise only one recalculation] → Cover an increase, decrease, and subsequent increase before persistence, then repeat through hydrated draft editing.
- [Standalone POS/legacy dispatch remains inconsistent] → Keep it explicitly documented as a later-sequence dependency rather than presenting all bundle downstream behavior as safe.

