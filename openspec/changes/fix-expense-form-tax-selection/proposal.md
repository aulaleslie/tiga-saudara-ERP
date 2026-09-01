## Why

Expense creation currently fails during Livewire rendering because the form queries a nonexistent top-level `tax_id` property even though tax selections belong to individual expense detail rows. The regression also needs to be corrected without losing the master-data lifecycle rule that an inactive tax already referenced by an editable expense remains visible and valid while unselected/new inactive taxes are authoritatively rejected at write time.

## What Changes

- Make the Expense Livewire form derive retained tax options from persisted detail rows instead of reading an undefined component property or client payload state.
- Limit tax choices to active taxes while retaining inactive taxes already selected on existing persisted expense detail rows.
- Enforce transactional write-boundary validation in `ExpenseService::saveExpense()` with row locks, requiring active taxes for new rows/expenses and permitting inactive taxes only for unchanged persisted detail references.
- Add focused regression coverage for rendering Expense Create, editing an expense referencing an inactive tax, and rejecting crafted/unauthorized inactive tax assignments at write time.

## Capabilities

### New Capabilities
- `expense-form-tax-selection`: Defines reliable Expense form rendering and lifecycle-aware tax option and write-boundary validation behavior for create and edit flows.

### Modified Capabilities

None.

## Impact

- Affected code: `App\Livewire\Expense\ExpenseForm`, `Modules\Expense\Services\ExpenseService`, and focused Expense Livewire tests.
- Existing tax and expense persistence models remain unchanged.
- No database migration, route, external API, or dependency changes are required.
- Existing transaction master-data lifecycle behavior is preserved and strengthened.
