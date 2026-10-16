# Tasks

## 1. Receipt transaction note

- [x] 1.1 Add the existing resolved POS transaction note to current, historical and draft/loaded receipt data without Sales/checkout-note substitution; verify focused fixtures for a split checkout's canonical transaction, missing transaction and empty note.
- [x] 1.2 Render `Catatan` after payments and before return history or tail space with escaped multiline wrapping; verify literal HTML, newline preservation, whitespace omission and ordering through focused receipt rendering assertions.

## 2. Proven price adjustment information

- [x] 2.1 Build optional standard-item comparisons from original POS and current linked Sales unit prices using minor-unit precision and proven unit basis; verify one edited line, repeated products, distinct split prices/quantities, restored original price and missing explanatory lineage without changing financial mapping rules.
- [x] 2.2 Add packed-item generic notices only for proven blended unit-price changes, excluding fabricated box/loose comparisons and internal bundle allocations; verify packed change, non-price/header adjustments, return-only quantity reduction, catalog independence and unsupported allocation cases with focused parameterized cases.
- [x] 2.3 Add fully returned standard-item comparisons to return-history payloads using saved return-time price and proven original unit basis; verify fully returned items stay absent from main items, later Sales edits do not alter return endpoints, and unsupported packed/component provenance produces no invented numeric comparison.
- [x] 2.4 Render standard comparisons with distinct current quantities, packed notices and labeled return-time comparisons within the shared thermal template; verify affected-item placement, historical/preview suppression and unchanged existing totals, serials and return details with targeted rendering assertions.

## 3. Current debt and payment consistency

- [x] 3.1 Exercise an authorized Sales monetary adjustment on a debt POS transaction through persisted records and verify the current receipt shows 280,000 total, 100,000 paid and 180,000 debt after an original 300,000 transaction; narrowly fix receipt projection if checkout payment presentation is stale and assert printing leaves financial records and snapshots unchanged.
- [x] 3.2 Verify a later 50,000 payment yields total 280,000, paid 150,000 and debt 130,000 with reconciled method rows; cover a representative adjusted transaction with completed returns and retain existing contradiction refusal and unchanged-checkout tender/change behavior using focused receipt fixtures.

## 4. Focused integration verification

- [x] 4.1 Extend existing receipt test fixtures to verify equivalent note, price explanation and settlement output across ordinary checkout, transaction and global-payment Print/Reprint paths while preserving access checks and action-specific print logging; assert historical output retains original amounts with the transaction note and no adjustment notices.
- [x] 4.2 Run only affected named test files or precise `php artisan test --filter=...` selections for receipt generation, current money, return projection and endpoint behavior; record commands/results, with no full-suite or `composer test:fresh-sqlite` requirement.
- [x] 4.3 Inspect representative 72 mm output with a long multiline note, split-price annotations, packed notice and a fully returned item; verify wrapping, intact monetary values and preserved PPN footer/stamp space, then run `openspec validate add-pos-receipt-notes-and-price-adjustment-info --strict` and record the result.
