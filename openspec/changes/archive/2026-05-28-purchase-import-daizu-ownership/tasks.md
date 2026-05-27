## 1. Ownership Resolution

- [x] 1.1 Add a purchase import helper that normalizes product names and detects Daizu products containing `KEDELE`, `KEDELAI`, or `RAGI`.
- [x] 1.2 Add a Daizu setting resolver that finds the seeded `Daizu Kedelai` setting and returns a clear failure when missing.
- [x] 1.3 Update purchase document owner resolution so Daizu products override tag, marker, and default tenant fallback.
- [x] 1.4 Update stock owner resolution so Daizu products override marker and historical `BUY` transaction fallback.

## 2. Import Processing

- [x] 2.1 Carry each row's raw product name in the prepared detail payload.
- [x] 2.2 Use the detail-level raw product name when resolving stock ownership in the purchase detail stock loop.
- [x] 2.3 Resolve Daizu stock locations explicitly and mark affected rows invalid when no usable Daizu location exists.
- [x] 2.4 Keep ProductPrice updates aligned with the resolved purchase document owner for Daizu rows.
- [x] 2.5 Include skipped duplicate rows in `processed_rows` while leaving `success_count` unchanged.

## 3. Verification

- [x] 3.1 Add focused tests for untagged `KEDELE IMPORT` purchase rows creating Daizu-owned purchases and stock movements.
- [x] 3.2 Add focused tests proving tag, marker, and historical stock owner fallback do not override Daizu products.
- [x] 3.3 Add focused tests for missing Daizu setting and missing Daizu location invalid-row errors.
- [x] 3.4 Add focused tests for mixed-product invoice rows resolving stock ownership from each row's own product name.
- [x] 3.5 Add focused tests for skipped duplicate rows contributing to processed progress without incrementing success count.
- [x] 3.6 Run the focused purchase import test set with `php artisan test --filter=PurchaseImport` or the nearest stable focused filter.
