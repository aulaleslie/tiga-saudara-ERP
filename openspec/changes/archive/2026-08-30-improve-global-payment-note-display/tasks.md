## 1. Shared Note Presentation

- [x] 1.1 Add a shared anonymous Blade component (`resources/views/components/document-note.blade.php`) that accepts a document note and row-unique identifier, renders short notes directly, and renders deterministic 120-character/three-line previews with escaped full content and accessible Alpine expand/collapse controls.
- [x] 1.2 Add narrowly scoped component styles to `resources/views/includes/main-css.blade.php` that preserve authored line breaks, wrap unbroken text, bound note width, and override the table's nowrap rule only for document notes.

## 2. Global Payment List Integration

- [x] 2.1 Replace the sale Global Payment list's inline note markup in `resources/views/livewire/sale/sale-table.blade.php` with the shared component while preserving global-mode visibility, blank-note behavior, and row-local identity.
- [x] 2.2 Replace the purchase Global Payment list's inline note markup in `resources/views/livewire/purchase/purchase-table.blade.php` with the shared component while preserving global-mode visibility, blank-note behavior, and row-local identity.

## 3. Focused Verification

- [x] 3.1 Update `Modules/Sale/Tests/Feature/GlobalSalePaymentTableTest.php` with focused assertions for short notes, long previews, multiline expansion markup, escaped content, row-local controls, note search, normal-mode isolation, blank notes, and the string `0`.
- [x] 3.2 Update `Modules/Purchase/Tests/Feature/GlobalPurchasePaymentTableTest.php` with the equivalent focused purchase-note assertions.
- [x] 3.3 Run only `php artisan test Modules/Sale/Tests/Feature/GlobalSalePaymentTableTest.php Modules/Purchase/Tests/Feature/GlobalPurchasePaymentTableTest.php` and resolve failures caused by the touched presentation files.

## 4. Review Follow-ups

- [x] 4.1 Trim note content before checking `$hasNote` in `resources/views/components/document-note.blade.php` to prevent whitespace-only notes from rendering an empty container, while preserving untrimmed text output and "0" rendering.
- [x] 4.2 Wire up `$rowId` in `resources/views/components/document-note.blade.php` by deriving unique container element `id` attributes and linking `aria-controls` on expand/collapse buttons.
- [x] 4.3 Bind `:aria-expanded` dynamically in `resources/views/components/document-note.blade.php` to reflect the active Alpine `expanded` state truthful to screen readers.
- [x] 4.4 Consolidate inline element layout styles into `.document-note-container` in `resources/views/includes/main-css.blade.php` to unify max-width and text wrapping authority.
