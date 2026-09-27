## Context

The `x-nominal-field` component (introduced in `fix-nominal-field-formatting-consistency`) manages currency formatting for all product pricing inputs using jQuery maskMoney. It uses a dual-input pattern:
- **Hidden input**: Stores raw numeric values for form submission
- **Visible input**: Displays formatted currency, managed by jQuery maskMoney

Current initialization happens in an IIFE (Immediately Invoked Function Expression) that configures maskMoney and handles focus/blur lifecycle. The bug occurs at two critical points:
1. **Line 251 initialization check**: Uses falsy check on empty string, skipping maskMoney activation
2. **Line 254 pre-formatting**: Uses `toFixed(2)` before passing to maskMoney, creating "50000.00" which maskMoney misinterprets in Indonesian locale (where "." is thousands separator)

## Goals / Non-Goals

**Goals:**
- Ensure empty fields show "0,00" placeholder on page load and stay formatted
- Fix blur event to correctly format any user input (empty, zero, or populated)
- Prevent maskMoney confusion by always passing raw numbers (not pre-formatted strings) to the mask function
- Maintain consistency across create, edit, and all uses of the component

**Non-Goals:**
- Change the dual-input architecture (it's working correctly for preventing Livewire conflicts)
- Add new features or functionality beyond fixing the bugs
- Refactor unrelated code or styling
- Modify the Livewire component initialization logic

## Decisions

### Decision 1: Always Initialize maskMoney, Even for Empty Values
**Approach**: Remove the falsy check at line 251. Always call `configureMask()` and apply initial mask, even for empty/zero values.

**Rationale**: maskMoney with `allowZero: true` can display "0,00" properly. The previous code assumed no initial value = no initialization needed, but that breaks the UX (blank field instead of showing currency format).

**Alternative Considered**: Only show placeholder text instead of masking. Rejected because placeholder doesn't provide currency context and users expect to see "0,00" for empty price fields.

### Decision 2: Never Pass Pre-Formatted Strings to maskMoney('mask')
**Approach**:
- On initialization: Pass raw number only, let maskMoney do ALL formatting
- On blur: Extract raw number, then immediately call maskMoney('mask') to format

**Rationale**: maskMoney's 'mask' command interprets the input string based on currency config. Passing "50000.00" (JavaScript's period decimal) confuses it because the component is configured for Indonesian locale (period as thousands separator). This causes "50000.00" to be parsed as "50,000" (thousands) incorrectly.

**Alternative Considered**: Pre-format values using the component's own formatCurrency function. Rejected because maskMoney already has this logic; duplicating it adds maintenance burden and potential for divergence.

### Decision 3: Keep toFixed(2) Only for Hidden Input
**Approach**:
- Hidden input: Keep the raw number, let form submission handle it
- Visible input: Never use toFixed(2) before masking; let maskMoney handle decimal places via its `precision: 2` config

**Rationale**: toFixed(2) is useful for ensuring consistent numeric precision in the hidden input for data submission, but it's harmful for the visible input before masking. The visible input should go directly from raw number → maskMoney formatting.

## Risks / Trade-offs

**Risk: maskMoney may not handle null/undefined gracefully**
→ Mitigation: Extract raw value defaults to 0 if parsing fails, so field always has a valid numeric state

**Risk: Rapid focus/blur events could cause race conditions**
→ Mitigation: The current code already handles this with try/catch around maskMoney operations. No additional fix needed.

**Risk: Locale-specific decimal separators could still cause issues in future**
→ Mitigation: All currency settings are read from `settings()->currency` at initialization. If locale changes, the data attributes are already set correctly. Component reacts to these, not hardcoded values.

**Trade-off: Component becomes slightly more JavaScript-heavy for initialization**
→ This is unavoidable given maskMoney's API. Alternative would be to use pure JavaScript formatting, but that's out of scope for this fix.

## Open Questions

None - the bugs and fixes are well-understood from the investigation phase.
