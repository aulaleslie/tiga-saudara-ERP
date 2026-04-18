## 1. Baseline and Guardrails

- [x] 1.1 Record the current `Modules/Pos/Resources/views/sell.blade.php` line count and current `php artisan test Modules/Pos/Tests/Feature/POSSellShellScanUiTest.php` result, including the known scanner-library expectation mismatch if still present.
- [x] 1.2 Capture or generate a rendered POS sell page baseline for an authenticated cashier with an active POS session.
- [x] 1.3 Define the render comparison tolerance for this refactor: allow insignificant whitespace changes only, and treat DOM IDs, classes, script includes, style content, route output, modal attributes, and permission-gated markup changes as failures.

## 2. CSS-Only Extraction

- [x] 2.1 Create `Modules/Pos/Resources/views/sell/css/styles.blade.php`.
- [x] 2.2 Move only the existing inline `<style>...</style>` block from `sell.blade.php` into the CSS partial without editing selector names, rule bodies, or CSS order.
- [x] 2.3 Replace the original CSS block inside `@push('page_css')` with an include of the CSS partial from the same stack location.
- [x] 2.4 Clear compiled views and render the POS sell page to verify there are no include, syntax, or missing-variable errors.
- [x] 2.5 Compare rendered output against the baseline and confirm no meaningful style, DOM, script, route, or permission-gated markup changes were introduced.
- [x] 2.6 Run the targeted POS sell shell test and confirm there are no new failures beyond documented baseline failures.

## 3. Static Modal Extraction

- [x] 3.1 Extract the checkout modal into `Modules/Pos/Resources/views/sell/modals/checkout.blade.php`, include it at the original location, and verify render equivalence.
- [x] 3.2 Extract the staged checkout modal into `Modules/Pos/Resources/views/sell/modals/staged-checkout.blade.php`, include it at the original location, and verify render equivalence.
- [x] 3.3 Extract success/gratitude/save-success modals into focused modal partials, include them in the original order, and verify render equivalence.
- [x] 3.4 Extract customer-create, search-results, serial, reduce-quantity, cash-pickup, camera-scanner, and bundle-selection modals one at a time, verifying render equivalence after each extraction.
- [x] 3.5 Run the targeted POS sell shell test after modal extraction and confirm no new failures beyond documented baseline failures.

## 4. Static Shell Component Extraction

- [x] 4.1 Extract the landscape lock screen into a shell/component partial and verify render equivalence.
- [x] 4.2 Extract the info strip and navigation areas into shell/component partials, preserving all variables and permission checks, then verify render equivalence.
- [x] 4.3 Extract product search, cart, customer, and payment shell areas one at a time, preserving all IDs/classes and verifying render equivalence after each extraction.
- [x] 4.4 Confirm `sell.blade.php` remains the orchestration file for layout order, page CSS stack, page scripts stack, and inline JavaScript.

## 5. Final Verification

- [x] 5.1 Run `php artisan view:clear`.
- [x] 5.2 Run the targeted POS sell shell test and document any remaining known baseline failures separately from this refactor.
- [x] 5.3 Run relevant POS critical-path feature tests or the smallest available POS feature test group that covers sell route rendering, scanner markup, session controls, and checkout entry points.
- [x] 5.4 Confirm final `sell.blade.php` line count is reduced and all new partials live under `Modules/Pos/Resources/views/sell/`.
- [x] 5.5 Manually review that no public JavaScript extraction, route/controller changes, DOM selector renames, or asset pipeline changes were introduced.
