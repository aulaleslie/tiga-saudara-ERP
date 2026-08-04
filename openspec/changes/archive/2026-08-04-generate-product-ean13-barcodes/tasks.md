## 1. EAN-13 normalization support

- [x] 1.1 Add a focused Product-module helper/service that validates EAN-13 format and checksum, creates a random `200`–`299` EAN-13 candidate, and calculates its check digit without numeric coercion.
- [x] 1.2 Add focused unit tests for valid and invalid checksum cases, prefix boundaries, randomized payload shape, and generated check digits.

## 2. Console command implementation

- [x] 2.1 Add and register the `product:generate-ean13-barcodes` Product-module Artisan command with a `--dry-run` option.
- [x] 2.2 Process all products in bounded ID-ordered chunks, preserving valid EAN-13 values while normalizing their `product_barcode_symbology` to `EAN13`.
- [x] 2.3 For invalid product barcodes, atomically replace the product's old barcode identity with a unique generated EAN-13 identity, update the product barcode and `EAN13` symbology, and retry collisions against the shared product/conversion namespace.
- [x] 2.4 Reconcile the barcode identity row for preserved valid EAN-13 values, report conflicting legacy identities without overwriting either owner, and continue after a per-product failure.
- [x] 2.5 Implement no-write dry-run evaluation and completion reporting for preserved values, generated replacements, symbology updates, registry repairs, and conflicts/errors.

## 3. Verification

- [x] 3.1 Add feature tests covering complete-catalog processing, valid EAN-13 preservation, invalid/missing barcode replacement, symbology assignment, and idempotent reruns.
- [x] 3.2 Add feature tests covering cross-namespace collision retry, identity replacement/repair, per-product atomic rollback, and continuation after an error.
- [x] 3.3 Add feature tests proving `--dry-run` reports outcomes without changing product barcode, symbology, or identity registry data.
- [x] 3.4 Run the focused Product module test suite and the applicable project test command; record any unrelated failures separately.
