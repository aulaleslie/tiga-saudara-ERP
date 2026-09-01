## Context

`ExpenseForm::render()` currently filters taxes with a condition on `$this->tax_id`. The component has no such top-level property: each selected tax is stored in `details.<index>.tax_id`. Livewire therefore throws `PropertyNotFoundException` whenever the component renders, including the initial Expense Create request.

Tax options were filtered as part of the transaction master-data lifecycle work. New selections must contain only active taxes, while an existing expense must continue showing an inactive tax already referenced by one of its detail rows. The fix must retain that lifecycle behavior, prevent client state manipulation from selecting unapproved inactive taxes, and enforce write-boundary validation in `ExpenseService`.

## Goals / Non-Goals

**Goals:**

- Restore successful rendering of Expense Create and Expense Edit.
- Derive retained tax identifiers from persisted expense detail rows during edit.
- Show active taxes plus any inactive taxes already selected by existing persisted rows.
- Enforce authoritative write-boundary tax validation in `ExpenseService::saveExpense()` inside `DB::transaction()` using row locks.
- Verify the regression with focused Livewire and service tests.

**Non-Goals:**

- Changing expense tax calculations or database schema.
- Changing global transaction master-data lifecycle rules.
- Introducing new routes, controllers, or external API contracts.
- Running or planning the full application test suite.

## Decisions

### Derive retained tax options from persisted detail rows

The form component will query tax IDs from persisted expense detail rows belonging to `$this->expenseId` rather than trusting unvalidated client payload state. For new expenses, this set is empty and only active taxes are returned.

### Group active and retained conditions in one query scope

The tax query will group `is_active = true OR id IN (<retained detail tax IDs>)` so outer constraints cannot be broadened. An empty selected set naturally returns only active taxes.

### Enforce write-boundary validation in ExpenseService with locks

`ExpenseService::saveExpense()` validates all selected taxes inside `DB::transaction()` with `lockForUpdate()`. Tax selections must be active, with an exception allowed only if the ID matches an unchanged, already-persisted detail row belonging to the expense being edited.

### Use focused regression verification

Coverage targets all critical paths: rendering create/edit forms, retaining persisted inactive taxes, rejecting crafted/new inactive tax submissions, and handling taxes deactivated prior to submit.

## Risks / Trade-offs

- [Detail state can contain null, empty-string, or duplicate tax values] → Filter empty values and deduplicate before querying.
- [An inactive tax could be exposed or saved as a new choice] → Include only inactive identifiers present in persisted detail rows; reject any new/replaced inactive assignment at the service write boundary.
- [Concurrency window between option render and form save] → Lock tax and detail rows inside `DB::transaction()` and re-verify `is_active` at submit time.
