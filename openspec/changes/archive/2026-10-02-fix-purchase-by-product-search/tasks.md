# Tasks

## 1. Product search

- [x] 1.1 Remove the `setting_id` predicate from product suggestions while retaining the search threshold and limit; verify a focused Livewire test finds a product with a different legacy setting ID.
- [x] 1.2 Keep the product search term and suggestions after selection, and control suggestion visibility so the list collapses on selection and reappears on focus; verify the select/refocus interaction in a focused component or browser test.

## 2. Selection integrity

- [x] 2.1 Keep selected products disabled in suggestions and enforce one selected entry per product ID; verify repeated selection leaves one ID and one visible selected entry.
- [x] 2.2 Run only focused purchase by product report tests covering suggestion scope, select/refocus, duplicate selection, and report setting scope; resolve any failures.
