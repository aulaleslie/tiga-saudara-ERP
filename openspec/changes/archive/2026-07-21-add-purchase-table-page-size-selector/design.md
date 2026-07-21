## Context

Currently, the `PurchaseTable` Livewire component defaults to paginating 10 items per page. Unlike the Yajra DataTables in this project, there is no user interface to change this number. Users browsing the Purchase Index or the Global Payments page must click through many pages if they wish to see more than 10 records at a time.

## Goals / Non-Goals

**Goals:**
- Provide a UI dropdown (10, 25, 50, 100) to select the number of records per page.
- Ensure the table correctly resets to page 1 when the page size changes to avoid `LengthAwarePaginator` out-of-bounds edge cases.
- Establish a straightforward pattern that can be replicated across other Livewire tables.

**Non-Goals:**
- We will not convert `PurchaseTable` to Yajra DataTables.
- We are not applying this to every single Livewire table in the project in this change, only `PurchaseTable`.
- We are not persisting the user's preferred page size to the database or session in this iteration.

## Decisions

- **UI Location**: We will place the dropdown in the pagination footer next to the "Menampilkan … data" count. This placement is intended and matches the implementation.
- **Livewire Integration**: We will use `wire:model.live="perPage"` on the select element. This automatically triggers a network request when the dropdown is changed.
- **Pagination Reset**: We will implement the `updatedPerPage()` lifecycle hook in the component to call `$this->resetPage()`. This is Livewire's idiomatic way to handle page resets when a bound property updates.

## Risks / Trade-offs

- **Risk: Performance hits on large page sizes.** → **Mitigation**: The maximum option provided is 100, which is well within acceptable limits for the queries executed by `PurchaseTable`.
- **Risk: Out of bounds pagination.** If a user is on page 5 and changes the limit to 100, they might end up on an empty page. → **Mitigation**: The `updatedPerPage` hook explicitly resets the page back to 1.
