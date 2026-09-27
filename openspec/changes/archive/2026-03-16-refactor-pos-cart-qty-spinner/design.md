## Context

The POS cart interface currently renders quantity as a plain numeric input field. Delete actions are rendered inline within the Sub Total cell, creating unclear affordances. The approval system for both quantity reduction (QTY_REDUCE) and line deletion (LINE_REMOVE) is already mature and working, but the UI doesn't clearly surface the approval states to users.

**Current Structure:**
- Quantity input: `<input type="number">` in Qty column
- Delete button: rendered below price in Sub Total cell
- Serial items: complex flex layout with qty input + serial button + serial chips
- Approval flow: buttons change state (color, text, data attributes) when approvals are pending/approved

**Implementation location:** `Modules/Pos/Resources/views/sell.blade.php` JavaScript template rendering (`buildLineRow()` function, lines ~1770-1945)

## Goals / Non-Goals

**Goals:**
1. Replace quantity input with spinner UI ([-] [input] [+] buttons)
2. Move delete button to a new dedicated "Aksi" (Actions) column
3. Maintain full approval workflow for both QTY_REDUCE and LINE_REMOVE actions
4. Improve UX clarity: quantity controls and deletion are visually separated
5. Keep approval state machine behavior identical (pending → approved flow unchanged)

**Non-Goals:**
- Changing approval system logic or backend APIs
- Modifying approval request/response structure
- Renaming or restructuring approval states
- Changing how roles/permissions control quantity reduction
- Adding new approval action types

## Decisions

### Decision 1: Spinner Button Styling
**Choice:** Use Bootstrap utility classes (`btn btn-sm btn-outline-*`) for +/- buttons, matching existing POS button styles

**Rationale:**
- Consistent with current cart UI (reduce quantity buttons already use `btn-outline-warning`)
- No new CSS or icon libraries needed
- Bootstrap provides built-in responsive sizing

**Alternatives Considered:**
- Custom CSS with arrows: Requires new CSS rules, breaks consistency
- Icon-only buttons (no +/-): Less accessible, harder to understand

---

### Decision 2: Spinner Layout for Serial Items
**Choice:** Keep spinner in top row, serial button and chips below (vertical stacking within qty cell)

**Rationale:**
- Serial items already have complex nested layout
- Spinner is the primary interaction; serials are secondary management
- Maintains compact cell width while preserving full functionality

**Current structure for serial items:**
```
<td class="pos-cart-serial-cell">
  <div class="d-flex flex-column">
    <div>[-] [input] [+]</div>          ← spinner
    <small>Serial btn</small>
    <small>X/Y Serial</small>           ← assignment counter
    <div>[chip] [chip]</div>            ← serial chips
  </div>
</td>
```

---

### Decision 3: Approval Button Placement in Qty Column
**Choice:** Approval buttons (Periksa/Lanjutkan) appear below spinner in same cell

**Rationale:**
- Approval buttons directly control quantity behavior
- Keeps related UI elements spatially grouped
- Spinner visibility remains constant; buttons replace minus button only when approval is pending

**State transitions:**
```
No approval needed:
  [-] [input] [+]

Approval pending (QTY_REDUCE):
  [input]
  [Periksa] (yellow)

Approval granted:
  [input]
  [✓ approvedQty] (green)
```

---

### Decision 4: Actions Column Button Styling
**Choice:** Use standard Bootstrap button classes with color coding

**Rationale:**
- Red text link for initial state (matches current small btn-link design)
- Yellow warning when approval pending
- Green success when approval approved
- Same color scheme as quantity approval buttons (visual consistency)

---

### Decision 5: Table Header Changes
**Choice:** Add "Aksi" column header; keep existing headers intact

**Rationale:**
- Minimal DOM changes
- Clear labeling of the new column
- No reordering of existing columns (stability)

---

## Risks / Trade-offs

**Risk: Serial item cell height increases**
- Spinner + serial button + chips may exceed current cell height
- Mitigation: Use `align-middle` on table cell; allow natural flex wrapping

**Risk: Approval button flow confusion**
- User might not understand why spinner is hidden and replaced by Periksa button
- Mitigation: Toast notifications already explain state changes ("Permintaan dikirim. Klik 'Periksa Persetujuan'...")

**Risk: Horizontal space with new Actions column**
- Table may become wider on small screens
- Mitigation: Existing cart table is already responsive with scrolling; no new overflow behavior

**Trade-off: Approval buttons stay in Qty cell vs. moving to Actions column**
- Qty cell keeps them: preserves "approval is about quantity change" semantics
- Actions cell would house them: moves visual separation further right
- **Chosen: Qty cell** to keep quantity-related approval in quantity column

## Migration Plan

1. Modify `buildLineRow()` function in sell.blade.php to:
   - Refactor qtyCell generation for all three cases (privileged serial, non-privileged serial, non-serial)
   - Replace plain `<input type="number">` with spinner: `<button>[-]</button> <input> <button>[+]</button>`
   - Extract delete button from subtotal cell to new cell

2. Add new table column header "Aksi" in cart table `<thead>`

3. Update event handlers:
   - Wire up +/- button click handlers (already exist as `js-line-qty` and `js-reduce-qty`)
   - Ensure delete button handler (`js-line-remove`) still works in new location

4. CSS: Minimal changes—use existing Bootstrap utilities; no new stylesheets

5. Test:
   - Non-serial items with and without approval permissions
   - Serial items with and without reduction approval pending
   - Approval workflow: request → pending → approved → confirm → delete
   - Edge cases: rapid clicking, state flipping mid-approval

## Open Questions

None identified at proposal stage.
