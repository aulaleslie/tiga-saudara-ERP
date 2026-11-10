# Tasks

## 1. Shared inventory replay correction

- [x] 1.1 Remove nonexistent transfer_date selection and metadata from InventoryReplaySupport; use dispatch time for outgoing TRF, receipt time for incoming TRF, and transaction created_at directly when corresponding metadata is missing. Add persisted transfer regression coverage through inventory summary, detail, valuation, and warehouse valuation; verify focused tests complete without unknown-column errors and assert expected dates/balances.
- [x] 1.2 Add focused shared-replay scenarios for dispatch/receipt across a period boundary, each missing lifecycle timestamp while its opposite exists, and unavailable transfer metadata; verify period/opening/as-of expectations match the delta specs.

## 2. Warehouse quantity correction

- [x] 2.1 Apply the same select, metadata, and direction-specific fallback correction to WarehouseStockQuantityReportQueryService. Add persisted TRF regression cases for the boundary and missing-metadata scenarios; verify focused warehouse quantity tests assert correct as-of balances and complete against the migrated schema.

## 3. Focused integration verification

- [x] 3.1 Inspect every active report entry under reports?tab=produk for the same invalid selection or equivalent transfer-date lookup; record the inspected paths and any separately caused failures in the implementation summary. Verify no affected report query still selects transfers.transfer_date.
- [x] 3.2 Run only affected report tests using focused php artisan test paths/filters, including existing export coverage and a TRF-backed export parity case. Verify the five affected consumers complete and export values agree with report balances; record commands, results, and environment limitations. Use a focused MySQL check if the existing test environment is available; a broad suite is not required.

## Workflow follow-up

- Before archive, correct the existing main-spec structure for warehouse-stock-quantity-report and warehouse-stock-valuation-report while preserving requirement content; validation currently reports that archive would refuse these targets.
