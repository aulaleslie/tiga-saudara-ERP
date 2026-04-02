## 1. Cart Tax Selection Handling

- [x] 1.1 Update the purchase cart tax selector to pass the selected tax value directly into `updateTax(...)` when the user changes the field.
- [x] 1.2 Update the sale cart tax selector to pass the selected tax value directly into `updateTax(...)` when the user changes the field.
- [x] 1.3 Change `App\\Livewire\\Purchase\\ProductCart::updateTax()` to accept the explicit selected value, normalize it, write it back to component state, and persist it to the cart row before recalculating totals.
- [x] 1.4 Change `App\\Livewire\\Sale\\ProductCart::updateTax()` to accept the explicit selected value, normalize it, write it back to component state, and persist it to the cart row before recalculating totals.

## 2. Regression Coverage

- [x] 2.1 Add a Livewire regression test proving a first explicit tax selection on a taxless purchase cart row is persisted immediately and used for recalculation.
- [x] 2.2 Add a Livewire regression test proving a first explicit tax selection on a taxless sale cart row is persisted immediately and used for recalculation.
- [x] 2.3 Run the focused purchase and sale Livewire tax-selection tests and confirm PKP validation no longer fails after a valid first selection.
