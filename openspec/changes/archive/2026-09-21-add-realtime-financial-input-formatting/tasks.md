# Tasks

## 1. Shared Real-Time Formatter

- [x] 1.1 Extend the focused JavaScript test harness with editable selection, `beforeinput`/`input`, paste, and caret assertions, and verify the new tests fail against the current blur-based helper for the expected reasons.
- [x] 1.2 Refactor or generalize `public/js/payment-amount-input.js` into an idempotent real-time financial formatter that retains existing payment API/marker compatibility, and verify focused tests cover initial canonical rendering plus programmatic get/set behavior.
- [x] 1.3 Implement progressive integer grouping and exact fractional rendering, including trailing zeros and the incomplete decimal state, and verify focused tests map `120000.23` to display `120.000,23` with canonical `120000.23`.
- [x] 1.4 Implement the digits-plus-one-dot operation gate with silent whole-operation rejection for invalid typing and paste, and verify focused tests preserve the prior value, selection, and caret without invalid styling or events that imply acceptance.
- [x] 1.5 Implement logical selection/caret mapping for insertion, Backspace, Delete, navigation, and selection replacement across regrouping boundaries, and verify focused tests exercise edits before, within, and after inserted thousands separators.

## 2. Nominal Field Integration

- [x] 2.1 Adapt `resources/views/components/nominal-field.blade.php` to use the shared real-time formatter without an embedded currency symbol while retaining its hidden canonical input and disabled-state contract, and verify focused component/view tests assert the marker, initial display, and hidden value.
- [x] 2.2 Wire nominal-field canonical changes through the existing hidden-input event contract and idempotent dynamic initialization, and verify focused Livewire/product tests cover rerendered fields and independent conversion-price rows.
- [x] 2.3 Add focused product create/edit coverage showing identical real-time price behavior and canonical old-input/submission values, and run only the relevant nominal/product test files or filters.

## 3. Payment Integration

- [x] 3.1 Update single Sales and Purchase payment forms to retain localized display during editing and submit canonical amounts, and verify their focused rendering/submission tests pass.
- [x] 3.2 Update global Sales and POS allocation integrations so totals, maximum checks, preview payloads, and submission use canonical values during real-time formatting, and verify the focused global Sales/POS payment tests pass.
- [x] 3.3 Update the DataTables-backed global Purchase allocation integration so visible and detached rows synchronize canonical hidden values after each accepted edit, and verify the focused global Purchase payment tests pass across multiple table pages.

## 4. Focused Verification

- [x] 4.1 Run the focused JavaScript formatter test file and resolve failures without invoking the full JavaScript test suite.
- [x] 4.2 Run only the affected nominal/product and Sales, Purchase, and POS payment Laravel test files or filters and record the passing focused commands; do not run the full Laravel test suite.
- [x] 4.3 Run `openspec validate add-realtime-financial-input-formatting --strict` and verify the completed change artifacts and implementation remain consistent.
