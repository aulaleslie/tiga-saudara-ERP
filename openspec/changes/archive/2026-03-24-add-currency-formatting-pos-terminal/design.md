## Context

The POS terminal configuration form (`Modules/Pos/Resources/views/terminals/_form.blade.php`) includes two currency input fields:
- `close_variance_approval_threshold`: Variance threshold for POS session closure
- `cash_threshold`: Cash amount trigger for pickup notifications

These fields currently use HTML `type="number"` with no visual formatting, making large amounts difficult to read. The codebase already includes jQuery maskMoney (at `public/js/jquery-mask-money.js`), a battle-tested library used throughout the application for currency formatting (Expense, Product, Quotation modules).

## Goals / Non-Goals

**Goals:**
- Apply currency formatting to the two financial fields in the POS terminal form
- Improve readability using Indonesian Rupiah locale (Rp X.XXX,XX format)
- Maintain seamless user experience: formatted on blur, plain number on focus
- Extract raw numeric values before form submission for database compatibility
- Reuse existing jQuery maskMoney implementation

**Non-Goals:**
- Extending this to other forms (scope limited to POS terminal form for now)
- Backend validation or transformation (form submission extracts client-side)
- Real-time calculation or dependent field updates
- Creating a new currency formatting library

## Decisions

### Decision 1: Use jQuery maskMoney instead of native HTML5 or other approach
**Choice:** Leverage existing jQuery maskMoney library

**Rationale:**
- Already present in the codebase at `public/js/jquery-mask-money.js`
- Actively used in other modules (Expense, Product) with proven pattern
- Handles both formatting display and unmasking for submission
- Well-tested across browsers and edge cases (negative values, empty fields)

**Alternatives considered:**
- HTML5 `type="number"` with Intl.NumberFormat: Limited control over display format (doesn't include currency symbol); would require separate display formatting logic
- Custom JavaScript formatter: More code to maintain; maskMoney is already debugged and used elsewhere
- Alpine.js directive: Adds dependency; maskMoney pattern already established in the codebase

### Decision 2: Initialize maskMoney in a page script block, not in a shared JS file
**Choice:** Add a `@push('page_scripts')` block with maskMoney initialization inline in the form

**Rationale:**
- Follows existing pattern in Expense and Product modules
- Keeps currency configuration localized to the form (easier to maintain)
- Accesses `settings()->currency` directly from Blade for symbol, thousand separator, decimal separator
- Simple, minimal overhead

**Alternatives considered:**
- Global JavaScript file: Would require passing currency settings via data attributes or JavaScript global; harder to maintain
- Livewire component: Terminal form is not currently a Livewire component; adds complexity

### Decision 3: Extract unmasked values on form submit, not on field change
**Choice:** Hook into form `submit` event, iterate fields, call `.maskMoney('unmasked')`, replace field values

**Rationale:**
- Clean separation: display formatting happens on blur/focus; data extraction happens at submission boundary
- Prevents partial form state issues (only converts when user is ready to submit)
- Matches pattern used in Expense and Product modules
- No risk of accidentally posting formatted strings to the database

**Alternatives considered:**
- Unmask on blur: Loses the formatted display immediately; poor UX
- Hidden field pattern: Requires maintaining two fields per input; unnecessary complexity

## Risks / Trade-offs

| Risk | Mitigation |
|------|-----------|
| **jQuery dependency**: maskMoney requires jQuery | Already a hard dependency in the application; no new risk |
| **Browser support**: maskMoney uses older JavaScript patterns | Library is widely used; tested across supported browser versions |
| **Validation**: No server-side validation that submitted value is numeric | Standard practice; server validates all input anyway. Form-level unmasking ensures numeric format |
| **Accessibility**: Formatted currency may confuse screen readers | maskMoney is transparent to accessibility tools (native value remains numeric in input.value) |
| **Negative values**: maskMoney can format negatives if configured | Current threshold fields don't expect negatives; masks initialized without `allowNegative` flag |

## Migration Plan

1. **Update terminal form file** (`_form.blade.php`): Add script block with maskMoney initialization
2. **Test on create/edit pages**: Verify formatting on both `/pos/terminals/create` and `/pos/terminals/edit`
3. **Deploy**: No database migration or backend changes needed; JavaScript enhancement only
4. **Rollback**: Remove the script block; formatting is purely client-side (no impact on saved data)

## Open Questions

- Should the form default to showing formatted values for pre-populated fields on page load, or only format on blur? (Recommend: format on load via maskMoney's native behavior — it applies mask on init)
- Are there other POS configuration forms beyond terminal create/edit that need this treatment? (Out of scope for this change, but good to identify for future work)
