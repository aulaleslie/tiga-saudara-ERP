## 1. Correct selection routing

- [x] 1.1 Add a locked `selectionTarget` property and optional mount argument to `App\Livewire\Purchase\SearchProduct`, defaulting to the purchase cart while preserving existing mount arguments and initialization; dispatch the unchanged product payload to that target.
- [x] 1.2 Bind the adjustment table target in adjustment create/edit views and the breakage table target in breakage create/edit views.

## 2. Add focused regression coverage

- [x] 2.1 Add focused Livewire checks for the default purchase recipient and both explicit table recipients, payload preservation including serial requirements, recipient persistence across requests, and rejection of client recipient updates.
- [x] 2.2 Add parameterized page-render checks for the configured search recipient on all four affected forms using isolated fixtures and existing authorization conventions.
- [x] 2.3 Verify adjustment and breakage selection events produce one correctly initialized row with a selected location, preserve existing rows, and retain no-location and duplicate feedback without adding rows in those cases.

## 3. Verify the fix

- [x] 3.1 Run only the new focused tests and directly relevant existing selection tests using explicit `php artisan test` paths or filters against an isolated test database; record commands and results. Do not run the full suite or reset the user's local MySQL database.
- [x] 3.2 Perform a local browser smoke check when browser access is available: select a location and the reported ACER product on `/adjustments/create`, confirm one visible row, and leave the form unsubmitted. Record the result or the browser-access limitation.

## Verification record

- Focused routing tests: 11 passed, 45 assertions after recipient assertions were added (reported by the implementation agent).
- Related purchase/search checks: 3 passed, 5 assertions during review.
- Browser smoke check: skipped at the user's request after browser initialization failed; the user will perform the local browser check. No browser pass is claimed.
