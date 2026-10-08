# Proposal

## Why

The Stock Transfers list currently searches transfer header columns, so staff cannot locate a document using a product or serial they know. This slows down retrieval when the transfer number is unavailable.

## What Changes

- Extend the existing Stock Transfers list search to match partial or full current product names and primary product barcodes for products on a transfer.
- Match partial or full serial numbers associated with the transfer at any persisted stage, including requested, allocated, and movement records.
- Keep existing document search, visible-transfer scope, sorting, pagination, and row actions. Return each matching transfer once.
- Exclude unit-conversion barcodes from the new list search.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `document-list-search`: Add Stock Transfers list lookup by linked current product identity and transfer-associated serials.

## Impact

- `Modules/Adjustment/DataTables/StockTransfersDataTable.php` and focused Adjustment feature tests.
- Read-only lookup across existing transfer, product, serial, and movement data. No schema, API, or permission changes are expected.
