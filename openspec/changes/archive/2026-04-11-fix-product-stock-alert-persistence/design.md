## Context

The `ProductCreator` service is responsible for creating new products in a multi-tenant/multi-setting environment. To ensure data consistency, it explicitly resets several fields on the `Product` entity to default values (0 or null) before saving. This logic is intended to clear "legacy" columns that are no longer used or are handled by separate services (e.g., pricing). However, `product_stock_alert` was incorrectly categorized as a legacy field and included in this "zero-out" list, causing user input for this field to be discarded during product creation.

## Goals / Non-Goals

**Goals:**
- Restore persistence for the `product_stock_alert` field during the product creation flow.
- Ensure the field defaults to `0` if not provided, to satisfy database constraints.

**Non-Goals:**
- Modifying the product editing flow (already works correctly).
- Altering the database schema or the notification logic for low stock.

## Decisions

### 1. Remove `product_stock_alert` from the blunt overwrite list
We will remove `'product_stock_alert' => 0` from the `$fieldsWithDefaults` array in `ProductCreator::create`. This prevents the service from unconditionally overwriting the validated user input.

### 2. Apply a safe fallback for the required DB column
Since the `product_stock_alert` column is non-nullable in the database, we will ensure the value is set to `0` if it is null or missing from the `$validatedData` array. This will be done before the `Product::create()` call.

## Risks / Trade-offs

- **Risk**: Database exception if the value is missing and no fallback is applied.
  - **Mitigation**: We will verify and cast the value to an integer with a `?? 0` fallback in the creator service.
