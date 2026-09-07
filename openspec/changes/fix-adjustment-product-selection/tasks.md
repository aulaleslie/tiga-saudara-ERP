## 1. Correct selection routing

- [ ] 1.1 Add a locked `selectionTarget` property and optional mount argument to `App\Livewire\Purchase\SearchProduct`, defaulting to the purchase cart while preserving existing mount arguments and initialization; dispatch the unchanged product payload to that target.
- [ ] 1.2 Bind the adjustment table target in adjustment create/edit views and the breakage table target in breakage create/edit views.

## 2. Add focused regression coverage

- [ ] 2.1 Add focused Livewire checks for the default purchase recipient and both explicit table recipients, payload preservation including serial requirements, recipient persistence across requests, and rejection of client recipient updates.
- [ ] 2.2 Add parameterized page-render checks for the configured search recipient on all four affected forms using isolated fixtures and existing authorization conventions.
- [ ] 2.3 Verify adjustment and breakage selection events produce one correctly initialized row with a selected location, preserve existing rows, and retain no-location and duplicate feedback without adding rows in those cases.

## 3. Verify the fix

- [ ] 3.1 Run only the new focused tests and directly relevant existing selection tests using explicit `php artisan test` paths or filters against an isolated test database; record commands and results. Do not run the full suite or reset the user's local MySQL database.
- [ ] 3.2 Perform a local browser smoke check when browser access is available: select a location and the reported ACER product on `/adjustments/create`, confirm one visible row, and leave the form unsubmitted. Record the result or the browser-access limitation.
