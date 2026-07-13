## Context

The POS receipts and reprint receipts currently use a series of null coalescing operators (`??`) to resolve the customer's name from either `contact_name` or `customer_name`. 

Because empty strings (`""`) are considered non-null by the `??` operator, customers saved with an empty `contact_name` instead of `null` end up printing as a blank space on receipts. This happened when the `contact_name` and `company_name` fields were introduced in a later migration (`2024_09_09_224310_add_info_to_customer_table.php`).

Additionally, there are B2B cases where displaying both the company name and contact person provides better context. 

## Goals / Non-Goals

**Goals:**
- Provide a robust way to display customer identity that safely ignores empty strings.
- Present a combined identity (`Contact - Company`) if both are present, which matches the behavior in the Settings module.
- Fix POS printed receipts and reprint receipts so they no longer print blank customer names when empty strings exist.

**Non-Goals:**
- Modifying the underlying database columns or changing how empty fields are stored.
- Altering customer selection logic in POS.

## Decisions

**Decision 1: Add `getDisplayNameAttribute()` to Customer Model**
- *Rationale*: A central accessor using Laravel's `filled()` function ensures consistent handling of empty strings, nulls, and whitespaces. It handles the combination of `contact_name` and `company_name` (falling back to `customer_name` for the company part if `company_name` is blank).
- *Alternatives Considered*: We could just fix `PosReceiptService.php` directly, but that leaves the issue for other modules to potentially trip over, and misses the opportunity for code reuse.

**Decision 2: Simplify `PosReceiptService` logic**
- *Rationale*: By delegating the name formatting to `$customer->display_name`, `getReceiptData` and `getTransactionReceiptData` become much cleaner and less error-prone. We simply fallback to `'-'` if `$customer` itself is null.

## Risks / Trade-offs

- **Risk**: Existing receipts printed from `PosReceiptService` might subtly change format (e.g. from just "John" to "John - Acme Corp"). 
  - *Mitigation*: This is considered an improvement as it adds necessary context, aligning with what the user requested. If both are identical, the accessor should only print the name once.
