## 1. Breakage Domain Planning

- [x] 1.1 Add a breakage movement planner that reloads the selected location and setting, derives the PKP bucket, and returns per-product current, movement, projected, drift, and conflict data.
- [x] 1.2 Add strict breakage serial classification using canonical sellable availability plus selected product, selected location, uniqueness, and PKP-consistency checks.
- [x] 1.3 Define and cover the versioned `approval_result` payload for location/PKP context, actual product before/movement/after values, serial transitions, actor, time, warnings, and transaction references.

## 2. Searchable and Scannable Entry

- [ ] 2.1 Replace breakage create and edit location inputs with the searchable standard-location dropdown scoped to the active setting and wired to the breakage editor.
- [ ] 2.2 Adapt or extract product search and scan resolution for breakage product barcodes, supported conversion barcodes, existing serials, ambiguous matches, and active-setting stock-managed product scope.
- [ ] 2.3 Redesign the breakage Livewire editor around one base-unit quantity, with product search, main scan input, scanner-focus feedback, duplicate-row handling, and safe confirmed location reset.
- [ ] 2.4 Implement non-serialized scan increments, conversion-factor increments, available-good limits, and serialized product barcode focus without quantity increment.
- [ ] 2.5 Implement serialized row entry where only eligible good serials at the selected location are accepted and displayed quantity is derived read-only from selected serial count.
- [ ] 2.6 Preserve valid breakage rows and serial selections across create/edit validation failures and adapt existing pending breakage rows to the redesigned editor.

## 3. Persistence and Lifecycle Enforcement

- [x] 3.1 Refactor breakage store and update validation to reload active-setting location/product state, reject consignment/cross-setting inputs, derive the PKP bucket server-side, and persist intent without inventory mutation.
- [x] 3.2 Reject zero or excessive non-serialized movement and tampered serialized identities/counts with product- or serial-specific Bahasa Indonesia validation messages.
- [x] 3.3 Refactor breakage approval into an atomic service that locks the document, location/setting context, stock rows, and serials in deterministic order and recomputes the complete plan.
- [x] 3.4 Apply same-bucket good-to-broken movements, mark selected serials broken without changing location/tax/lifecycle state, maintain physical totals and product aggregates, and persist transactions and notifications atomically.
- [x] 3.5 Block the entire approval on shortage, unexpected PKP bucket data, invalid serial state, drift conflict, or persistence failure and retain the pending document without partial effects.
- [x] 3.6 Persist approver metadata and the immutable versioned approval result on success while retaining compatible behavior for legacy breakage records.

## 4. Informed Review Presentation

- [ ] 4.1 Add a breakage-specific detail view that separates document metadata from the live pending approval preview and uses Bahasa Indonesia throughout.
- [ ] 4.2 Show review summary and per-product current good/broken stock, requested movement, projected result, PKP bucket, physical-total explanation, drift, and blocking conflicts according to stock-visibility permission.
- [ ] 4.3 Show serialized `Bagus → Rusak` transitions with product and selected-location evidence and explicit reasons for wrong-location, unavailable, broken, duplicate, or PKP-inconsistent conflicts.
- [ ] 4.4 Gate approval in the interface when preview conflicts exist, retain authoritative endpoint enforcement, and add an approval confirmation explaining the condition-only inventory effect.
- [ ] 4.5 Render approved breakage from immutable `approval_result` evidence and clearly label the limited fallback for legacy approved records without a stored result.

## 5. Focused Verification and Human Handoff

- [ ] 5.1 Add focused Livewire tests for searchable location selection/reset, product search, ordinary and conversion scans, ambiguity handling, focus restoration events, and create/edit state retention.
- [ ] 5.2 Add focused request/service tests for PKP and Non-PKP single-bucket persistence, excessive quantity rejection, cross-setting/consignment rejection, and no inventory mutation before approval.
- [ ] 5.3 Add focused serial tests for valid selected-location good serials and rejection of unknown, duplicate, wrong-product, wrong-location, inactive, dispatched, returning, broken, and PKP-inconsistent serials.
- [ ] 5.4 Add focused approval tests for locked revalidation, atomic rollback, same-bucket movement, unchanged serial location/tax/status, invariant physical totals, aggregates, transactions, notifications, and immutable audit results.
- [ ] 5.5 Add focused view/permission tests for pending projections, blocking conflicts, approval controls, approved immutable evidence, and legacy fallback without disclosing protected stock data.
- [ ] 5.6 Run only the affected Adjustment/Livewire test filters and provide a manual browser checklist for human verification of breakage create, edit, scan, review, approve, reject, and conflict flows.
