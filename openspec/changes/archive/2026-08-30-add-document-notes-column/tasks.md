## 1. Confirm Active List Paths

- [x] 1.1 Trace the normal and Global Payment Purchase and Sales index routes/controllers to identify which shared Livewire and legacy Yajra list renderers are user-visible.
- [x] 1.2 Record the directly affected focused test files and avoid changing inactive legacy renderers solely for cleanup.

## 2. Implement the Shared Note Column

- [x] 2.1 Add a `Catatan` header immediately after `Ref` in the active Purchase and Sales list templates for both normal and Global Payment modes.
- [x] 2.2 Move each document header note out of the reference cell into its matching `Catatan` cell and render the shared note component unconditionally for normal and Global Payment rows.
- [x] 2.3 Update the shared document-note component to render `-` for null, empty, or whitespace-only list notes while retaining escaped short-note and row-local long-note expand/collapse behavior.
- [x] 2.4 Bound and wrap the note cell/component so line breaks and long unbroken content remain readable without widening the surrounding table.
- [x] 2.5 Align any confirmed active legacy Purchase or Sales list renderer with the same `Ref | Catatan` contract without changing unrelated DataTable behavior.

## 3. Focused Verification

- [x] 3.1 Extend the directly affected Purchase list tests to cover column order, normal/global visibility, removal from the reference cell, blank placeholder, and short-note output.
- [x] 3.2 Extend the directly affected Sales list tests with the equivalent column and presentation coverage.
- [x] 3.3 Cover long or multiline expansion markup, escaped HTML-like content, and bounded wrapping through the shared component or the narrowest existing presentation test.
- [x] 3.4 Run only the changed Purchase/Sales list tests and existing Global Payment note-search tests that can regress from the markup change, then resolve any failures.
