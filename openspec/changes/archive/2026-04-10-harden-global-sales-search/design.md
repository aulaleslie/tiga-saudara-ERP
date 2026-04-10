## Context

The Global Sales Search menu is designed to locate sales using a wide variety of references. Under the new requirement, the search must locate both formal `Sale` records AND informal/in-progress `PosTransaction` records simultaneously, merging them into a unified paginated list. Clicking a result must dynamically route to either the Sales detail view or the POS Transaction detail view. Furthermore, tenant isolation blocks (`ensureSaleBelongsToCurrentSetting()`) need to be gracefully bypassed for authorized global search users.

## Goals / Non-Goals

**Goals:**
- Execute searches across both `Sale` and `PosTransaction` models simultaneously.
- Map the resulting records into a unified polymorphic format for Livewire.
- Enable searching by barcode, POS transaction code, POS receipt number, POS cashier, and denormalized customer name on both models where relevant.
- Route clicks from the UI to `/sales/{id}` or `/pos/transactions/{id}` based on the record type.
- Bypass tenant isolation blocks for `globalSalesSearch.access` users.

**Non-Goals:**
- Using SQL `UNION` queries. Eloquent makes relationships with Unions extremely complicated, so we will use concurrent/parallel Eloquent queries and application-level merging for the unified datatable.

## Decisions

- **Collection Merging & Pagination:** Rather than a DB union, `SerialNumberSearchService` will run two distinct queries (one for Sales, one for PosTransactions), merge the collections, sort them by date in PHP, slice the exact page chunk, and return a `LengthAwarePaginator`.
  - *Rationale:* Since `limit` is small (e.g. 20-50 per page), fetching `limit` from both queries = 100 items max loaded into memory, sorting them natively, and paginating provides the exact behavior visually without Eloquent union headaches.
  - *Unified Structure:* The resulting items will be mapped to simple objects with fields like `id`, `type` (`sale` | `pos`), `reference`, `customer_name`, `date`, `status`, `total_amount`, etc.

- **Option C Routing Bypass:** We will bypass `ensureSaleBelongsToCurrentSetting` natively in `SaleController::show` for the global scope to prevent 404s. We will implement equivalent logic for POS transactions (e.g., in `PosTransactionController` or wherever it loads records).

- **UI Updates:** The Livewire table will render differing icons/badges to distinguish Sale vs POS records and map click actions respectively.
