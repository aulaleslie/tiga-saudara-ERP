## 1. Global Payment Note Presentation

- [x] 1.1 Update the shared sales table reference cell to render a non-blank escaped sale header note beneath the document number only in Global Payment mode.
- [x] 1.2 Update the shared purchase table reference cell to render a non-blank escaped purchase header note beneath the document number only in Global Payment mode.
- [x] 1.3 Update the sales and purchase allocation-table reference cells to render each candidate's non-blank escaped header note beneath its document number with wrapping secondary-text styling.

## 2. Allocation Candidate Ordering

- [x] 2.1 Replace the sales candidate ID ordering with bound query ordering that pins the starting sale first, then places dated candidates by due date ascending, null due dates last, and equal dates by ID ascending.
- [x] 2.2 Apply the equivalent purchase candidate ordering, pinning `purchase_id` when supplied and otherwise ordering every candidate by due date ascending with explicit ID tie handling.
- [x] 2.3 Confirm the existing starting-document default allocation, old allocation restoration, eligibility checks, and server-side allocation keys remain unchanged after reordering.

## 3. Focused Verification

- [x] 3.1 Extend the directly affected Global Sales Payment Livewire tests to verify visible escaped note rendering in global mode, note-only search results, and no added note presentation in the normal list context.
- [x] 3.2 Add or extend a focused Global Sales Payment create-form test to verify entry-sale pinning, remaining due-date order, null-last behavior, deterministic ID ties, visible candidate notes, and unchanged starting allocation default.
- [x] 3.3 Extend the directly affected Global Purchase Payment Livewire tests to verify visible escaped note rendering in global mode, note-only search results, and no added note presentation in the normal list context.
- [x] 3.4 Extend the focused Global Purchase Payment create-form tests to verify entry-purchase pinning, supplier-only due-date ordering, deterministic ID ties, visible candidate notes, and unchanged starting allocation default.
- [x] 3.5 Run only the touched Global Sales Payment and Global Purchase Payment test files or focused filters, and resolve regressions without invoking the full application suite.
