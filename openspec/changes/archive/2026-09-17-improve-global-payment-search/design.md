## Context

The standalone and party-embedded global-payment workspaces render `PurchaseTable` and `SaleTable` in global mode. Their current search predicates apply one `%search%` value across document fields and transaction-detail product snapshots. The same components also render ordinary setting-scoped lists, so the new contract must be isolated to global mode unless separately adopted later.

Products already expose current names, codes, primary barcodes, conversion barcodes, and normalized serial records. Purchase serial provenance is represented through purchase detail → received-note detail → serial associations, including legacy receiving linkage. Sales retain dispatched serial provenance through sale-detail serial identifiers and dispatch records.

## Goals / Non-Goals

**Goals:**

- Give both global-payment workspaces identical tokenized text-search semantics.
- Require every whitespace-delimited text token to match at least one supported non-identity field.
- Search product names and codes from current linked product records.
- Treat the entire trimmed input as an exact, case-insensitive barcode or serial identity in parallel with tokenized text search.
- Preserve query composition with eligibility, party, business, date, summary-card, sorting, and pagination constraints.
- Keep behavior deterministic under production MySQL/MariaDB and focused SQLite tests.

**Non-Goals:**

- Changing ordinary non-global purchase and sales list search.
- Searching persisted detail product names or codes when the linked product is missing.
- Partial, tokenized, or fuzzy barcode and serial matching.
- Changing product, barcode, serial, transaction, or payment persistence.
- Introducing a general search engine, denormalized search index, or full-suite test requirement.

## Decisions

### 1. Use a shared global-payment search query abstraction

Extract the predicate construction into shared query support consumed by both table components in global mode. The abstraction accepts the complete trimmed input and applies transaction-specific relationship paths while sharing tokenization, text grouping, and exact-identity normalization rules.

This avoids allowing the purchase and sales implementations to drift. Duplicating predicates in each Livewire component was rejected because the required behavior and edge cases are intentionally symmetric.

### 2. Combine tokenized text and exact identity as parallel alternatives

Split trimmed input on one or more whitespace characters and discard empty tokens. The text branch uses AND between tokens and, for each token, OR across all supported partial fields:

```text
(token1 in any text field)
AND (token2 in any text field)
AND ...
OR exact-barcode(complete input)
OR exact-serial(complete input)
```

Tokens can match different fields of the same transaction; for example, one token can match the supplier and another the current product name. Barcode and serial columns are excluded from every `%token%` predicate.

Keeping exact identity as an alternative to the token branch preserves scanner-style lookup even when a barcode contains whitespace or punctuation. Treating each word as an independent global OR was rejected because it returns documents matching only part of a multi-token query.

### 3. Use current linked catalog fields without active-product filtering

Product-name and product-code predicates traverse `purchase_details.product` or `sale_details.product` and inspect `products.product_name` and `products.product_code`. They do not inspect the detail snapshot fields and do not apply the product list's `active()` scope: a payable historical transaction remains discoverable when its linked product is inactive or merged.

When `product_id` is null or its product was deleted, snapshot name/code values are not a fallback search source. This deliberately follows the requirement that catalog product fields are authoritative.

### 4. Resolve barcode identity through catalog relationships

The exact barcode branch compares the complete trimmed input case-insensitively against both `products.barcode` and barcodes belonging to that product's unit conversions. A matching product qualifies a transaction only through a detail row linked to that product.

Supporting conversion barcodes prevents the same physical product from becoming undiscoverable when it was selected or scanned using an alternate unit identity. Partial barcode matches remain prohibited.

### 5. Resolve serial identity through transaction provenance

The purchase predicate traverses purchase details and receiving-detail serial associations, retaining the established legacy receiving relationship where needed for historical rows. The sales predicate uses persisted sale/dispatch serial lineage rather than assuming that a serial's current inventory state still identifies its historical sale.

Serial comparison uses the complete trimmed input and the existing canonical serial normalization. The query must not return another transaction merely because it contains the same product as the matched serial.

### 6. Keep exact case-insensitive comparison explicit and index-aware

Centralize exact identity comparison so MySQL/MariaDB and SQLite have equivalent case-insensitive equality semantics. Prefer equality compatible with the configured case-insensitive collation and existing barcode/serial indexes; use a driver-appropriate collation expression where SQLite test semantics differ. Avoid `%LIKE%` and avoid wrapping indexed identity columns in functions when the connection's collation can provide the required equality.

Text fields retain case-insensitive partial matching consistent with existing application behavior. Parameter binding is required for every token and exact input.

### 7. Verify through focused component and query tests

Add focused tests for both global modes covering cross-field AND token behavior, renamed/current product data, rejection of snapshot-only product matches, exact case-insensitive primary/conversion barcode matches, exact serial lineage, partial identity rejection, and composition with party/business or eligibility constraints. Run only the directly affected test files or focused filters; a full-suite run is outside this change.

## Risks / Trade-offs

- [Multiple nested `EXISTS` predicates can be expensive on broad global datasets] → Resolve identity through indexed catalog/serial columns, retain server-side pagination, avoid eager-loading relationships solely for filtering, and inspect generated query behavior in focused verification.
- [Database collations differ between MySQL/MariaDB and SQLite] → Centralize exact comparison and cover mixed-case identity searches in SQLite-focused tests.
- [Historical rows with deleted products stop matching snapshot names] → Make this intentional in specifications and preserve snapshots for display/audit only.
- [Serial provenance has legacy and current representations] → Cover both supported lineage paths with focused fixtures and ensure matching is tied to the specific transaction.
- [Shared table components could accidentally change ordinary lists] → Gate the new abstraction behind `globalMode` and retain focused regression coverage for non-global search behavior.
