## 1. Expense Tax Option Loading

- [x] 1.1 Update `ExpenseForm` to derive retained tax options from persisted expense detail rows during edit and remove the invalid top-level `tax_id` access.
- [x] 1.2 Query active taxes plus only the inactive taxes already selected by current expense detail rows, using a grouped query condition.
- [x] 1.3 Update `ExpenseForm` render to derive retained inactive tax options from persisted expense detail rows during edit flows.

## 2. Write-Boundary Tax Validation

- [x] 2.1 Enforce transactional write-boundary tax validation in `ExpenseService::saveExpense()` inside `DB::transaction()` using `lockForUpdate()`.
- [x] 2.2 Require active taxes for new expenses and new detail rows while permitting unchanged persisted inactive tax references during edit.

## 3. Focused Regression Verification

- [x] 3.1 Add a focused Livewire regression test proving Expense Create renders without `PropertyNotFoundException` and excludes inactive unselected taxes.
- [x] 3.2 Add a focused Livewire regression test proving Expense Edit retains a selected inactive tax while excluding other inactive taxes.
- [x] 3.3 Add focused regression tests proving new expense creation rejects crafted inactive tax IDs and edit rejects assigning unapproved inactive taxes.
- [x] 3.4 Add a focused test proving a tax deactivated immediately before submission is rejected at write time.
- [x] 3.5 Run the focused `ExpenseFormTest` tests and confirm existing and new Expense form behavior in that test file remains green.
