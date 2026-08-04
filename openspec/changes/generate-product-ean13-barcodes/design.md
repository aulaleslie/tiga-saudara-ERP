## Context

Products currently store a free-form base-unit barcode and a separate `product_barcode_symbology` value. The Product module already has unique constraints for base product and conversion barcode columns plus a `barcode_identities` registry that enforces one canonical barcode identity across both namespaces. Existing console commands use chunked product queries and dry-run reporting patterns.

This change introduces a data-normalization Artisan command only. Barcode label generation continues to be handled separately; the current print screen remains untouched.

## Goals / Non-Goals

**Goals:**

- Identify valid EAN-13 values by requiring exactly 13 ASCII digits and a correct GS1 modulo-10 check digit.
- Preserve valid EAN-13 barcode values while marking their product symbology as `EAN13`.
- Replace every other product barcode with a unique EAN-13 whose first three digits are randomly selected from `200` through `299`.
- Atomically maintain `products.barcode`, `products.product_barcode_symbology`, and the barcode identity registry for each changed product.
- Provide a safe no-write preview and concise, actionable command summary.

**Non-Goals:**

- Rendering, printing, previewing, or exporting EAN-13 labels.
- Modifying product unit-conversion barcodes.
- Changing POS or scanner resolution behavior.
- Assigning a globally licensed GS1 company prefix; the requested `200`–`299` restricted-distribution range is used for internal product identifiers.

## Decisions

### A valid EAN-13 is validated by format and checksum

The command treats a value as valid only when it is exactly 13 digits and the final digit matches the EAN-13 modulo-10 check digit calculated from the preceding 12 digits. A 13-digit value with an invalid check digit is replaced.

This avoids mistaking arbitrary numeric identifiers for EAN-13 codes. Checking only length and numeric content was considered but rejected because it permits invalid scan values.

### Generate twelve data digits, then derive the check digit

The command selects a three-digit prefix from `200`–`299`, appends nine cryptographically random digits, calculates the thirteenth digit, and stores the resulting string without numeric coercion. The random space is therefore one billion base values across the requested prefix range.

Sequential allocation was considered but rejected because the requested behavior explicitly calls for randomized remaining digits and would require a persistent allocation cursor or a full scan to avoid race conditions.

### Reserve identity before committing the product update, with bounded retries

For each generated candidate, the command uses the existing barcode identity registry as the cross-namespace source of truth. Within a database transaction it locks the target product, replaces that product's old identity with a reserved generated identity, then updates the product barcode and symbology. It retries random candidates when reservation detects a collision. Any registry or database uniqueness failure rolls back that product's update and is reported while later products continue.

Relying only on a pre-check against `products.barcode` was rejected because it misses conversion barcodes and is race-prone. A single transaction for the entire catalog was rejected because it would create excessive lock duration and make a single bad row roll back all valid work.

### Preserve valid EAN-13 values and reconcile their registry identity

Valid existing EAN-13 values are not regenerated. The command sets their symbology to `EAN13` and verifies that the corresponding product-owned registry identity exists. A missing identity is created in the same per-product transaction; an identity owned elsewhere is reported as a conflict and does not alter the barcode or symbology.

This protects the cross-namespace uniqueness guarantee even for legacy data whose column value predates the registry. Blindly skipping valid values was rejected because it could leave a later barcode assignment able to reuse the same code.

### Command interface and reporting prioritize safe operation

The command will be registered as `product:generate-ean13-barcodes` and support `--dry-run`. It processes products by ID in bounded chunks, emits no database writes in dry-run mode, and reports counts for preserved valid values, generated replacements, symbology-only updates, registry repairs, conflicts/errors, and dry-run status.

The command need not print every generated value by default, keeping output suitable for large catalogs. The existing import/export command patterns inform the summary format.

## Risks / Trade-offs

- [A random candidate collides with a product or conversion barcode] → Retry generation through the registry with a bounded attempt limit; report and skip only the affected product if exhausted.
- [Legacy data has a valid EAN-13 whose registry identity belongs to another record] → Treat as a conflict and do not silently overwrite either owner.
- [A user edits a product during normalization] → Lock the product in its per-product transaction and atomically reserve/update the identity and fields.
- [A dry run becomes stale before the real run] → Clearly label it as a preview; the real run repeats all validation and collision handling.
- [Internal `200`–`299` values are mistaken for GS1-issued retail identifiers] → Document the range as an internal/restricted-distribution choice and leave external GS1 allocation out of scope.

## Migration Plan

No schema migration or deployment data backfill runs automatically. Deploy the command and tests, run it first with `--dry-run`, review its summary, then run it without the option during an appropriate maintenance window. Re-running is safe: valid normalized rows are preserved and only registry/symbology consistency is reconciled.

Rollback consists of rolling back the application release. The command intentionally changes barcode data, so restoring previous arbitrary values, if needed, uses the existing barcode export/backup process rather than an automatic database rollback.

## Open Questions

None.
