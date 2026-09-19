# Tasks

## 1. Search State Lifecycle

- [x] 1.1 Remove the unconditional query/result cleanup from the `Cari Produk` open handler and verify closing, selecting a product, and reopening the modal preserves the previous query and rendered results.
- [x] 1.2 Add a shared product-search reset operation that clears the modal query, restores the initial empty-search presentation, and invalidates older pending search requests; verify a simulated late response cannot repopulate cleared state.

## 2. Transaction Boundary Integration

- [x] 2.1 Invoke the shared reset only after successful regular checkout and successful staged/multi-payment checkout, and verify failed or cancelled checkout preserves search state in focused POS sell UI coverage.
- [x] 2.2 Invoke the shared reset only after successful save-as-draft-and-new, retain the existing fresh-page reset after successful draft load, and verify failed save/load and non-boundary cart actions do not trigger search cleanup.
- [x] 2.3 Invoke the shared reset only after a successful `Kosongkan Keranjang` cart-clear response, and verify failed, cancelled, or pending-approval cart clears preserve search state; confirm removing an individual product, changing the customer, and cancelling checkout still preserve search state.

## 3. Focused Verification

- [x] 3.1 Add or update focused POS sell-page tests for same-transaction preservation, all successful reset boundaries, failure preservation, and stale-response rejection; run only the directly affected test file(s) with `php artisan test --filter=<focused-test-class-or-method>` and confirm they pass.
- [x] 3.2 Add focused structural coverage proving the shared reset call sits inside the successful cart-clear response branch and is absent from failure/cancelled/pending-approval paths; run only the focused POS product-search lifecycle tests and confirm they pass.
- [x] 3.3 Perform a focused browser smoke check covering search-select-reopen, regular or staged checkout completion, save-as-draft-and-new, successful cart clear, and draft load; record that each transaction boundary starts with an empty search and do not run the full test suite.
