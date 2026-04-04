## Context

The purchase creation flow uses two Livewire components in parent-child relationship:
- **CreateForm** (parent): Owns the submit logic and persistence to database
- **ProductCart** (child): Manages cart items and tax inclusion toggle

Both components have their own `is_tax_included` property:
- `CreateForm.$is_tax_included` defaults to `false` (line 40)
- `ProductCart.$is_tax_included` defaults to `true` (line 40)

**Current Behavior:**
- On new purchase creation, `ProductCart.mount()` is called with `$data = null` (not duplicating)
- This skips the state initialization and event dispatch that only occur when `$data` exists
- Result: `CreateForm` never receives the initial `true` value from `ProductCart`
- When user submits, `CreateForm` persists its own `false` value to database

**Event Synchronization:**
- A Livewire event `taxIncludedUpdated` exists and is properly handled by `CreateForm.handleTaxIncludedUpdated()`
- The event is fired from `ProductCart.handleTaxIncluded()` when user toggles checkbox
- But this event is NOT fired on initial mount when `$data` is null

**Purchase Tax Policy Gap:**
- The purchase flow currently mixes two separate concerns: `is_tax_included` state and per-line purchase tax assignment
- Investigation showed conflicting expectations around whether PKP purchase lines without product tax should remain unassigned or should receive an automatic fallback tax
- The business rule is now explicit:
  - PKP purchase lines must always resolve to a tax if one can be derived
  - Resolution order is product purchase tax first, default tax second
  - Non-PKP purchase flows must not expose or persist tax state at all

**Quick-Add Product Tax Sync Gap:**
- The purchase create page includes a nested product quick-add modal, and that modal includes nested purchase and sale tax dropdowns
- The dropdown component has two distinct selection paths:
  - manual selection from the dropdown list
  - automatic selection after receiving a `taxCreated` event from the nested tax quick-add modal
- The manual path dispatches `taxDropdownSelected` back to `ProductQuickAddModal`, which updates the parent component properties used for validation and persistence
- The automatic path currently updates only the dropdown's local `selected` and `selectedLabel` state
- Result: the UI appears to have selected the new tax, but `ProductQuickAddModal.$purchase_tax_id` / `$sale_tax_id` can remain null
- When the user saves the product, the product creator persists null tax ids and the purchase cart receives a product payload whose tax configuration does not match the visible UI state

## Goals / Non-Goals

**Goals:**
- Ensure `CreateForm.$is_tax_included` and `ProductCart.$is_tax_included` are synchronized on component mount
- For PKP entities, default `is_tax_included = true` on new purchase creation
- Maintain event-driven synchronization when user toggles the checkbox
- Preserve existing behavior for non-PKP entities (defaults to false, checkbox hidden)
- Ensure PKP purchase product rows resolve tax deterministically using product purchase tax first, then default tax
- Ensure non-PKP purchase flows hide tax UI and normalize away any incoming tax state before persistence
- Ensure nested tax quick-add in the add-product modal uses the same persistence path as manual tax selection
- Ensure auto-selection after tax creation updates the parent product modal state, not just the dropdown label
- Ensure the emitted product payload after quick-add reflects the persisted tax ids the user saw selected

**Non-Goals:**
- Changing database schema or migration of existing records
- Changing sales tax behavior in this change
- Updating purchase editing or duplication flows (they already dispatch events)
- Redesigning the dropdown or modal architecture beyond the event contract needed for state synchronization

## Decisions

### Decision 1: Initialize `is_tax_included = true` in CreateForm.mount() for PKP users

**Approach:** Check `$this->isPkpEnabled()` after setting it, and if true AND not duplicating, set `is_tax_included = true`.

**Rationale:** 
- PKP entities should tax-include by default (business requirement)
- This matches the UI default state (checkbox appears checked)
- Non-PKP entities default to false (unchanged)
- Avoids introducing a new parameter or data passing mechanism

**Alternatives Considered:**
1. Pass `is_tax_included` as a parameter from view → Rejected (introduces view logic, tightly couples component initialization to view)
2. Use computed property based on `isPkp` → Rejected (state should not be computed, needs to be user-modifiable)

### Decision 2: Dispatch initial event from ProductCart.mount() even when $data is null

**Approach:** Move the `dispatch('taxIncludedUpdated')` outside the `if($data)` block in ProductCart.mount(), so it always fires regardless of whether mounting for new or duplicate purchase.

**Rationale:**
- Ensures parent always receives the initial state from child
- Follows the event-driven pattern already established
- Single source of truth: ProductCart owns the initial value (true), parent receives it via event
- Makes the flow explicit and testable

**Alternatives Considered:**
1. Have CreateForm pass initial value to ProductCart → Rejected (inverts component hierarchy, ProductCart should own tax state)
2. Use wire:model binding to sync state reactively → Rejected (Livewire only syncs on user action or explicit blur, not on mount)

### Decision 3: No changes to non-PKP flow or visibility logic

**Rationale:**
- Current implementation already hides the checkbox correctly with `@if($isPkp)` in view
- Non-PKP purchases should stay false (no tax considerations)
- Minimal change scope reduces risk

### Decision 4: PKP purchase tax assignment uses product tax first, then default tax

**Approach:** When a purchase line is added or reconciled under a PKP setting, resolve its tax in this order:
1. Use the product's configured purchase tax for the active setting when available
2. Otherwise use the tax row marked `is_default = true`
3. Otherwise leave the row unresolved and fail validation explicitly

**Rationale:**
- This matches the business rule the team clarified during exploration
- It removes the current ambiguity between "manual selection required" and "automatic fallback"
- It avoids the unstable "latest tax wins" behavior that is not a business rule

**Alternatives Considered:**
1. Keep tax null until the user chooses manually → Rejected (conflicts with the clarified PKP rule)
2. Use the latest-created tax when no product tax exists → Rejected (not deterministic from a business perspective)

### Decision 5: Non-PKP purchase flows must suppress tax UI and persistence entirely

**Approach:** When `is_pkp = false`, purchase forms must:
- hide tax selectors and tax-included UI
- ignore incoming product tax values from product payloads or restored cart state
- persist purchase details with `tax_id = null` and `product_tax_amount = 0`
- persist purchase headers with `tax_amount = 0` and `is_tax_included = false`

**Rationale:**
- The business rule is not merely "tax hidden"; it is "tax not applicable"
- Hidden tax-bearing cart state must not survive into persisted non-PKP purchases
- This aligns purchase behavior with existing normalization expectations elsewhere in the codebase

### Decision 6: Auto-selected quick-add taxes must dispatch the same parent sync event as manual selection

**Approach:** When `TaxSearchDropdown` receives `taxCreated` and auto-selects the new tax, it must immediately dispatch the same `taxDropdownSelected` event used by the manual `select()` path so `ProductQuickAddModal` receives the new tax id in its authoritative state.

**Rationale:**
- The parent modal owns the state that validation and persistence use
- A visual selection without parent synchronization is a false UI state
- Reusing the same event contract avoids splitting persistence behavior across two code paths

**Alternatives Considered:**
1. Update the parent modal directly from the tax quick-add modal → Rejected (couples nested modal to parent field semantics)
2. Read selected tax ids from the rendered hidden input during save → Rejected (persistence should rely on Livewire component state, not DOM scraping)

### Decision 7: Tax-created auto-selection must be scoped to the requesting dropdown

**Approach:** The quick-add tax event contract should carry enough context to distinguish which field requested the modal so only that dropdown auto-selects the newly created tax.

**Rationale:**
- The add-product modal can render both purchase and sale tax dropdowns at the same time
- A broadcast `taxCreated` event without field scoping can make both dropdowns appear selected even if only one initiated the tax creation
- The state contract should preserve field independence just like manual selection already does

**Alternatives Considered:**
1. Let all tax dropdowns react to `taxCreated` and rely on later user correction → Rejected (creates misleading multi-field UI state)
2. Disable one dropdown whenever the other is active → Rejected (unrelated UX constraint and does not solve event ambiguity)

## Risks / Trade-offs

**Risk: Existing non-PKP purchases with is_tax_included=1**
- If any non-PKP purchases were created with `is_tax_included = true`, they should be normalized
- **Mitigation:** Not addressed in this change. Separate repair command exists (`RepairPurchaseTaxInclusion`). This change only ensures future creates are correct.

**Risk: Timing of event dispatch**
- If ProductCart fires the event on mount but CreateForm hasn't mounted yet, event is lost
- **Mitigation:** Livewire components are rendered top-down (parent first), so CreateForm listens before ProductCart mounts. This is safe.

**Risk: User expectation on edit/duplicate**
- When editing or duplicating a purchase, the value comes from the database (via $data parameter)
- This change only affects NEW creates, not edits
- **Mitigation:** Existing flows already handle this correctly with the `if($data)` block

**Risk: Conflicting existing tests and assumptions**
- Some current tests encode "leave tax null" or "latest tax fallback" semantics that conflict with the clarified business rule
- **Mitigation:** Update specs and tests together so the codebase has a single contract

**Risk: Missing default tax in PKP setup**
- If a PKP setting has products without purchase tax and no default tax exists, purchase lines cannot be auto-resolved
- **Mitigation:** Keep explicit validation and surface a clear operational error rather than silently storing zero tax

**Risk: Broadcast tax-created events can update multiple dropdowns**
- Purchase and sale tax dropdowns both listen for `taxCreated`
- Without request scoping, a newly created tax can appear selected in more than one field
- **Mitigation:** Make the event contract field-aware and document that only the initiating dropdown may auto-select

**Risk: Product quick-add payload diverges from modal UI**
- The product payload emitted into the purchase flow is derived from persisted product prices, not the dropdown widget directly
- If parent state is stale, the payload carries null tax ids despite a visible selection
- **Mitigation:** Require parent-state synchronization before save and add tests that assert the emitted payload contains the new tax id

**Trade-off: Two places initializing is_tax_included**
- CreateForm has a default, ProductCart has a default, now both fire events
- **Rationale:** This is acceptable because:
  - CreateForm's default serves as fallback (defensive)
  - ProductCart's event is the source of truth (primary)
  - If ProductCart event doesn't fire for some reason, CreateForm still has a sensible default

**Trade-off: Automatic PKP purchase tax assignment vs explicit user choice**
- The clarified rule reduces manual work but removes some UI explicitness in PKP mode
- **Rationale:** This is acceptable because the business rule prefers deterministic correctness over manual assignment when a configured tax exists

**Trade-off: Reusing the manual-selection event contract**
- This keeps the behavior coherent and reduces branching, but it means the auto-select path inherits the same assumptions as manual selection
- **Rationale:** This is desirable because both interactions are semantically "the dropdown has selected a tax and the parent state must now reflect it"
