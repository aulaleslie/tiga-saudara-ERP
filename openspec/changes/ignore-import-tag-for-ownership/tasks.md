## 1. Sales Import Tests

- [x] 1.1 Add or update sales import tests proving Daizu rows still route to Daizu when `Tag` and product markers conflict.
- [x] 1.2 Add sales import tests proving `*`, ` TP`, and unmarked non-Daizu rows route to Tiga Nusa, TOP IT, and Perdana while preserving CSV tag metadata.
- [x] 1.3 Add sales import tests proving tag differences do not split rows that share an invoice number and product-name owner.
- [x] 1.4 Add sales import tests proving historical purchase-owner fallback is ignored for unmarked non-Daizu stock movement and transactions.
- [x] 1.5 Add sales import duplicate tests proving duplicate lookup uses product-name-resolved setting and ignores changed CSV tag values.

## 2. Purchase Import Tests

- [x] 2.1 Add or update purchase import tests proving Daizu rows still route to Daizu when `Tag` and product markers conflict.
- [x] 2.2 Add purchase import tests proving `*`, ` TP`, and unmarked non-Daizu rows route to Tiga Nusa, TOP IT, and Perdana while preserving CSV tag metadata.
- [x] 2.3 Add purchase import tests proving tag differences do not split rows that share an invoice number and product-name owner.
- [x] 2.4 Add purchase import tests proving historical purchase-owner fallback is ignored for unmarked non-Daizu stock movement, ProductPrice, and transactions.
- [x] 2.5 Add purchase import duplicate tests proving duplicate lookup uses product-name-resolved setting and ignores changed CSV tag values.

## 3. Sales Import Implementation

- [x] 3.1 Update sales import tenant resolution so it uses only product-name ownership priority: Daizu, `*`, ` TP`, then Perdana.
- [x] 3.2 Update sales import row grouping so tenant keys are derived from product-name ownership and never from CSV tag.
- [x] 3.3 Update sales import stock-owner resolution to remove tag and historical purchase-owner fallback for imported rows.
- [x] 3.4 Ensure sales ProductPrice, dispatch location, stock decrement, and inventory Transaction owners align with the product-name-resolved setting.
- [x] 3.5 Keep sales tag syncing as metadata after document creation.

## 4. Purchase Import Implementation

- [x] 4.1 Update purchase import tenant resolution so it uses only product-name ownership priority: Daizu, `*`, ` TP`, then Perdana.
- [x] 4.2 Update purchase import row grouping so tenant keys are derived from product-name ownership and never from CSV tag.
- [x] 4.3 Update purchase import stock-owner resolution to remove tag and historical purchase-owner fallback for imported rows.
- [x] 4.4 Ensure purchase ProductPrice, stock increment, stock location, and inventory Transaction owners align with the product-name-resolved setting.
- [x] 4.5 Keep purchase tag syncing as metadata after document creation.

## 5. Verification

- [x] 5.1 Run focused sales import ownership tests.
- [x] 5.2 Run focused purchase import ownership tests.
- [x] 5.3 Run broader import-related tests if focused changes touch shared import paths.
- [x] 5.4 Review import row error and duplicate messages to ensure they no longer imply tag-based ownership.
