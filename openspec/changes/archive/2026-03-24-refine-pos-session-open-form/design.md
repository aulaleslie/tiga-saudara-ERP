## Context

The POS Session Open form (`/pos/sessions/open`) displays terminal selection and opening balance (Saldo Awal) fields. Currently:
- The Saldo field shows an "Rp" currency prefix that's inconsistent with other terminal forms
- The Terminal dropdown lacks a clear/reset affordance
- The Saldo field is always visible and always required (when `requiresTerminalSelection` is true), even though backend validation already makes it optional when no terminal is selected
- Users have no quick way to change a terminal selection without form reload

The Terminal Create form (`/pos/terminals/create`) uses a cleaner currency formatting approach without hardcoded prefixes, providing a pattern worth matching.

## Goals / Non-Goals

**Goals:**
- Remove hardcoded "Rp" prefix from the Saldo input field
- Add a clear button (×) integrated into the Terminal dropdown to allow quick deselection
- Make the Saldo field visibility reactive — hide/show based on whether a terminal is selected
- Update visual requirement indicators (asterisk and help text) to reflect conditional requirement state
- Maintain backward compatibility with existing validation and role-based access control

**Non-Goals:**
- Changing backend validation logic (already correct as-is)
- Refactoring currency formatting across the entire POS module
- Adding denomination input UI for opening float (out of scope)
- Changing the Terminal dropdown search/filter behavior

## Decisions

### 1. Integrate clear button into dropdown button (not separate)
**Decision**: The clear button will be an icon inside the dropdown toggle button, revealed only when a terminal is selected.

**Rationale**:
- Keeps related controls together (selection and clearing)
- Reduces visual clutter when nothing is selected
- Matches common dropdown patterns (e.g., searchable select libraries)
- Easier mobile/touch interaction than separate button

**Implementation**:
- Add a `clear()` method to `PosTerminalSearchDropdown` component
- Conditionally render the clear icon in the dropdown view using Alpine `x-show="$selectedLabel"`
- Wire the icon click to prevent dropdown toggle and call the clear action instead

### 2. Use Alpine.js for Saldo field visibility (client-side reactivity)
**Decision**: Toggle Saldo field visibility using Alpine.js reactivity bound to the Terminal dropdown's selected state.

**Rationale**:
- No extra server round-trip required
- Instant visual feedback
- Alpine is already in use (terminal dropdown uses Alpine)
- Livewire `@entangle` binding makes sharing state easy between components

**Implementation**:
- Wrap the Saldo field in an Alpine `x-show` directive that watches the hidden terminal input value
- Update the hidden input synchronously when terminal selection changes
- On change, toggle required attribute and update label/help text

### 3. Remove "Rp" prefix; keep number formatting JavaScript as-is
**Decision**: Remove the input-group with the "Rp" span. Keep the existing JavaScript formatting logic that handles number localization.

**Rationale**:
- Number formatting JS already handles display (comma-separated thousands, locale-aware)
- Removes hardcoded currency symbol (better for i18n if currency changes)
- Simpler HTML structure
- Aligns with terminal form's approach (no prefix in field, currency context in label or helper text)

**Implementation**:
- Remove `<span class="input-group-text">Rp</span>` wrapper
- Keep the existing `display input → hidden field` pattern and number formatting script
- Update label text if needed (no additional prefix required)

### 4. Make Saldo field requirement conditional via required attribute + validation
**Decision**: Dynamically set/unset the `required` HTML attribute based on terminal selection. Rely on backend validation as the source of truth.

**Rationale**:
- Backend validation (`StorePosSessionOpenRequest`) already checks: `'opening_float_total' => [$hasTerminal ? 'required' : 'nullable', ...]`
- HTML `required` attribute provides UX affordance and browser validation
- Combining both ensures consistency
- No server call needed to toggle requirement

**Implementation**:
- Alpine watches terminal selection state
- Sets `required` attribute on Saldo input when terminal is selected
- Removes it when terminal is cleared
- JavaScript runs after any terminal selection change

## Risks / Trade-offs

| Risk | Mitigation |
|------|-----------|
| Client-side visibility toggle could desync with server state | Browser validation + server-side validation ensures correctness. Client state is non-authoritative. |
| Alpine reactivity adds JavaScript dependency for core UX | Alpine is already required (used in Terminal dropdown). Graceful degradation: form still works without JS, validation happens server-side. |
| Testing visibility changes requires browser/Livewire tests | Add browser test cases for terminal selection → Saldo visibility toggle. |
| The clear button might be missed if not made obvious | Use a clear icon (×) with tooltip. Test with users if unclear. |

## Migration Plan

**Deployment Steps:**
1. Deploy updated view templates and Livewire component
2. No database migrations or config changes needed
3. Form behavior changes take effect immediately upon page reload

**Rollback:**
- Revert the template and component files to previous version
- No data loss or state corruption possible (UI-only change)

## Open Questions

- Should the clear button have a tooltip (e.g., "Batal pilihan terminal")? Or is the × icon obvious enough?
- Should clearing the terminal also clear the Saldo input value, or just hide the field?
