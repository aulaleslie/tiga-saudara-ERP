# Plan: Investigate Sales Return reference lookup failure

## Context
Users report that entering a sales reference number in the **Penjualan Return** workflow does not trigger any response—no matching results, alerts, or logs. The Livewire component `App\\Livewire\\SalesReturn\\SaleReferenceSearch` drives this lookup and should update the parent `SaleReturnCreateForm` component when a reference is selected.

## Objectives
1. Reproduce the problem and capture the exact UX (no results list, no toast, etc.).
2. Identify why the Livewire search component is silent (e.g., failure to query, JavaScript errors, or event wiring issues).
3. Deliver a verified fix that restores responsive feedback when a sales reference is entered.

## Investigation & Fix Tasks
1. **Reproduce & Instrument**
   - Launch the sales return create screen and reproduce the "no response" scenario.
   - Open browser dev tools to confirm there are no console or network errors.
   - Temporarily enable debug logging around `updatedQuery` and `selectSale` in `SaleReferenceSearch` to verify they fire as the user types/selects.

2. **Back-end Query Diagnostics**
   - Inspect the `Sale` dataset to ensure references exist that should match user input (check potential whitespace, casing, or status filters).
   - Add a feature/Livewire test that seeds a sale, types its reference, and expects `saleReferenceSelected` to fire, guarding against regressions.

3. **Front-end / Livewire Wiring Review**
   - Validate that the Livewire view `resources/views/livewire/sales-return/sale-reference-search.blade.php` renders search results (`wire:if` logic, z-index overlays, etc.).
   - Confirm the parent component listens for `saleReferenceSelected` and that the event payload updates form state (review `handleSaleSelected`).
   - If wiring is correct, trace whether UI blocking overlays (e.g., `wire:loading`) remain stuck, preventing interaction.

4. **Implement Fix**
   - Based on findings, adjust Livewire logic (e.g., fix debounce timing, broaden query filters, ensure event dispatch uses `$this->dispatch` vs `$this->emit`, etc.).
   - Ensure the user sees clear feedback when no matches are found or when lookups fail (toast/alert or inline message).

5. **Regression Coverage & Cleanup**
   - Finalize automated coverage (Livewire/feature test) proving the lookup returns results and updates the form.
   - Remove temporary logging/instrumentation.
   - Document behaviour (e.g., README snippet or inline comments) if necessary for future maintenance.

## Deliverables
- Working sales reference lookup on the return form with appropriate user feedback.
- Automated test validating the lookup workflow.
- QA notes describing reproduction steps and validation results.
