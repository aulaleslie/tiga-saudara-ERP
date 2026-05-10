## 1. Planner Data Preparation

- [x] 1.1 Review current `PosReturnApprovalPreviewPlannerService` grouping and identify the smallest plan payload additions needed for parent rows, component rows, source POS item trace, and resolution-specific totals.
- [x] 1.2 Add bounded source-data loading for checkout sales, generated sales, sale details, sale bundle items, dispatch details, settings, locations, taxes, and serials used by the preview planner.
- [x] 1.3 Define a stable planned-detail shape that distinguishes parent return rows from bundle component target rows while preserving existing fields consumed by the Blade preview.

## 2. Split-Owner Bundle Component Expansion

- [x] 2.1 Implement preview-only expansion from actionable bundled POS Return lines to matching `sale_bundle_items` component allocation rows in generated source Sales documents.
- [x] 2.2 Group derived component target rows by generated source Sale, source owner, source location, and tax context alongside existing parent rows.
- [x] 2.3 Include source POS item, returned serial, component product, component quantity, component sale reference, selected resolution, and stock behavior on every component target row.
- [x] 2.4 Report a blocker when component allocation mapping is missing or ambiguous instead of silently omitting an implied component-owned Sales target.

## 3. Mixed Resolution Preview Behavior

- [x] 3.1 Remove the global mixed `cash_return` plus `product_replacement` preview blocker.
- [x] 3.2 Validate `cash_return` and `product_replacement` requirements per planned line so one invalid replacement target does not block solely because other lines use cash return.
- [x] 3.3 Update preview plan totals and labels so cash-return amounts and product-replacement intent are shown from line-level resolution rather than relying on the POS Return header option.

## 4. Approval Preview UI

- [x] 4.1 Update `approval-preview.blade.php` to clearly show target groups by generated Sale document, owner, location, and tax context.
- [x] 4.2 Render component target rows explicitly with source POS item/serial trace and a visual distinction from parent item rows.
- [x] 4.3 Show mixed-resolution summaries without presenting the return as a single uniform option.
- [x] 4.4 Preserve the preview-only messaging and ensure no final approval submission control is introduced.

## 5. Verification

- [x] 5.1 Add or update planner tests for a split-owner bundled POS return where one returned POS item maps to parent and component target groups across multiple generated Sales documents.
- [x] 5.2 Add or update route/view tests asserting the approval preview displays component-owned Sales targets explicitly.
- [x] 5.3 Add or update planner tests proving mixed `cash_return` and `product_replacement` lines no longer create a mixed-resolution blocker.
- [x] 5.4 Add blocker tests for missing or ambiguous bundle component target mapping.
- [x] 5.5 Run the focused POS Return approval preview test set with `php artisan test --filter=POSReturnApprovalPreview`.

## 6. Manual UAT Follow-up

- [x] 6.1 Remove the dispatch column from the approval preview target table and show the generated Sale document explicitly on each row.
- [x] 6.2 Stop presenting grouped return-option labels in the planned target header so resolution remains row-driven.
- [x] 6.3 Update approval preview route coverage for the per-row Sale document rendering and the absence of the legacy dispatch header.
- [x] 6.4 Stop surfacing legacy header `return_option` mismatch warnings in the approval preview.
- [x] 6.5 Scope bundle component target quantities to the selected bundled parent line so mixed bundled and non-bundled selections do not over-select component rows.
- [x] 6.6 Prefer POS transaction bundle lineage and checkout split metadata over generic product-only matching when mapping component-owned Sales targets.
