## Context

The POS multi-stage payment modal (`pos-staged-checkout-modal`) uses Bootstrap `.form-control` inputs but suffers from CSS rendering issues and lacks UX features for efficient payment entry. The staged payment JavaScript module (`window.PosStagedPayment`) manages the payment chain state and rendering but needs enhancement to support formatted display and quick-add workflows.

**Current issues:**
1. `#staged-method-search` input renders with transparent background despite inline `style="background-color: #fff;"` - likely due to missing `!important` flag or missing border styling
2. `#staged-amount-input` is a raw `type="number"` input showing unformatted values (e.g., 150000) without thousand separators
3. No quick-add affordance for common payment amounts
4. Payment chain display (`renderPaymentChain()`) shows all info inline in badge, making multiple payments hard to scan
5. No "fill remainder" functionality to quickly complete remaining payment

**Constraints:**
- Modal uses Bootstrap 4.x classes and styling
- Must not break existing approval workflow or payment chain state machine
- All formatting must be display-only; raw values must be sent to backend
- Must work with Indonesian locale (using `Intl.NumberFormat('id-ID')`)

## Goals / Non-Goals

**Goals:**
- Make payment method input visually opaque and accessible with proper contrast
- Display payment amounts with thousand separators in real-time as user types
- Provide quick-add buttons for common amounts (1K, 5K, 10K, 50K) and remainder fill
- Improve payment chain readability by separating method, amount, and reference into distinct visual sections
- Maintain full backward compatibility with existing payment submission flow and backend APIs
- Keep all numeric calculations accurate (no floating-point errors from formatting)

**Non-Goals:**
- Change payment method search/selection logic or dropdown behavior
- Modify backend payment processing or API contracts
- Add new validation rules (use existing validateAmountForMethod, validateBeforeSubmit)
- Implement currency selection or multi-currency support
- Add animation or advanced UX features (keep implementation simple and maintainable)

## Decisions

### Decision 1: Input Background Fix Using !important Overrides
**Choice:** Use inline `style` with `!important` flags to force white background and border visibility on payment method search input.

**Rationale:**
- Bootstrap's `.form-control` likely applies base styles without `!important`
- Modal context may introduce additional CSS resets
- Inline `!important` is a quick, surgical fix that doesn't require new CSS classes
- Alternative (adding new CSS class) would require touching the stylesheet and introducing more moving parts

**Alternative considered:**
- Add new `.form-control-opaque` class to stylesheet - rejected because inline style is simpler and more maintainable for a one-off fix

### Decision 2: Text Input with Client-Side Formatting for Amount Field
**Choice:** Change `type="number"` to `type="text"` with `inputmode="numeric"` and implement JavaScript formatter that:
- Captures input and strips non-digits
- Displays formatted value with thousand separators on input/change events
- Stores raw numeric value in `dataset.rawValue` for form submission
- Submits raw value to backend (no formatted strings sent to API)

**Rationale:**
- HTML5 `type="number"` cannot be styled to show thousand separators
- Client-side formatting gives us complete control over display without backend involvement
- Using `dataset` attribute keeps numeric value accessible without polluting the form
- Formatter updates both display and validation in single function
- `inputmode="numeric"` provides mobile keyboard hint without locking input type

**Alternatives considered:**
- Masked input library - rejected (adds dependency, more complexity)
- Custom input handler with cursor position tracking - rejected (overkill, too fragile)
- Post-submission formatting in backend - rejected (defeats the UX purpose of showing formatted values)

### Decision 3: Quick-Add Buttons with Additive Logic
**Choice:** Implement quick-add buttons that ADD to current amount (not set) with a separate "Sisa" button that FILLS to remainder:
- `[+1.000]`, `[+5.000]`, `[+10.000]`, `[+50.000]` - add specified amount to current input
- `[Sisa]` - fills input with exact remainder value

**Rationale:**
- Additive buttons let cashiers build up payments incrementally (e.g., +10K + 5K + 1K)
- "Sisa" (remainder) button provides quick-fill for final payment to complete transaction
- Separate behavior prevents accidental overfill when incrementally adding
- Matches common POS UI patterns (calculator-style buttons)
- No new validation needed - existing `validateAmountForMethod()` already validates final amount

**Alternatives considered:**
- Single "fill remainder" button - rejected (doesn't support multi-increment workflows)
- Pre-calculated suggested amounts based on remainder - rejected (too complex, less flexible)

### Decision 4: Payment Chain Display with Multi-Line Badges
**Choice:** Restructure `renderPaymentChain()` to display each payment in a multi-line badge layout:
```
┌─────────────────────┐
│ ✓ Cash              │
│   Rp100,000         │
│   Ref: ABC123       │
└─────────────────────┘
```

**Rationale:**
- Vertical stacking makes method, amount, and reference visually distinct
- Easier to scan when multiple payments are present
- Maintains badge visual style (bootstrap `.badge`) but with better information hierarchy
- Reference number is now clearly secondary (smaller font, lower opacity)

**Alternatives considered:**
- Horizontal table layout - rejected (too much HTML structure, harder to fit in modal)
- Inline format with stronger separators - rejected (still cramped, harder to scan)

### Decision 5: Format as Text in HTML, Render in JavaScript
**Choice:** Build quick-add button HTML directly in sell.blade.php with styling, but all behavior (event listeners, formatting) is handled in pos-staged-payment.js setupQuickAddButtons() function.

**Rationale:**
- Separation of concerns: HTML structure in blade template, behavior in JavaScript module
- Quick-add buttons are part of the payment form UI, naturally belong in blade template
- Initialization happens in `PosStagedPayment.initialize()` alongside other event setup
- Easy to disable/hide buttons based on payment chain state if needed later

## Risks / Trade-offs

**[Risk] Formatting causes de-sync between displayed value and form submission**
→ *Mitigation:* Always store raw numeric value in `dataset.rawValue` and submit that. Clear separator between display (formatted string) and submission (raw number).

**[Risk] Quick-add buttons add complexity to the modal form**
→ *Mitigation:* Buttons are optional convenience features; validation logic unchanged. Can be hidden via CSS if needed without breaking payment flow.

**[Risk] !important overrides are fragile and may need adjustment if Bootstrap version changes**
→ *Mitigation:* Document why !important is needed in code comment. If stylesheet is refactored in future, move to proper CSS class.

**[Risk] JavaScript formatter runs on every input event - performance concern**
→ *Mitigation:* Formatter is lightweight (regex + Intl.NumberFormat). No performance issue for input field. Acceptable trade-off for UX improvement.

**[Risk] Payment chain with 10+ payments could overflow the modal display area**
→ *Mitigation:* Current `renderPaymentChain()` uses `d-flex flex-wrap` which allows wrapping. Badges will wrap to multiple lines. Acceptable until we see real usage patterns.

## Migration Plan

**Deployment:**
1. Deploy blade template changes (HTML markup additions) - non-breaking
2. Deploy pos-staged-payment.js changes (new functions + initialize calls) - non-breaking
3. No database migrations or API changes needed
4. No feature flags required - changes are purely UI improvements

**Rollback:**
- Revert blade template to remove quick-add buttons and revert input styling
- Revert pos-staged-payment.js to remove formatter functions and their initialize() calls
- No data cleanup needed

**Testing strategy:**
- Test payment method search input renders with opaque background
- Test amount input displays formatted values (1000 → 1.000) while maintaining numeric calculations
- Test quick-add buttons increment amount correctly and respect remainder bounds
- Test "Sisa" button fills to exact remainder value
- Test payment submission sends raw numeric values, not formatted strings
- Test payment chain renders multiple payments with clear visual separation

## Open Questions

1. Should quick-add button amounts be configurable per terminal/store, or hardcoded as 1K, 5K, 10K, 50K?
   → Current proposal: hardcoded. Revisit if regional variations needed.

2. Should quick-add buttons be disabled/hidden when remainder is less than the button amount?
   → Current proposal: no - buttons always add, validation prevents overpayment. Revisit based on cashier feedback.

3. What locale should thousand separator use? Currently hardcoded to `'id-ID'`.
   → Correct for Indonesian market. Revisit if expanding to other locales.
