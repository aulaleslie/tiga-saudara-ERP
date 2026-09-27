## Context

The `PosCheckoutPayment` model defines a `paymentMethod()` BelongsTo relationship that links to the `PaymentMethod` model via the `payment_method_id` foreign key. This relationship was correctly implemented during recent multi-payment work. However, `PosReceiptService::getReceiptData()` was written expecting a `method()` relationship, which doesn't exist on the model.

When receipt printing is triggered for a multi-payment checkout, Laravel's Eloquent attempts to eager-load `'payments.method'` and fails with a `RelationNotFoundException` because the relationship doesn't exist. Accessing `$payment->method->name` would also return null if it didn't throw an exception first.

## Goals / Non-Goals

**Goals:**
- Fix the relationship reference in `PosReceiptService` to match the actual model definition
- Ensure multi-payment transaction receipts can be printed and reprinted without errors
- Maintain backward compatibility with existing receipt data structure

**Non-Goals:**
- Rename or refactor the `paymentMethod()` relationship on the model
- Add relationship aliases or shortcuts
- Change receipt display logic or data presentation

## Decisions

**Decision: Use existing `paymentMethod()` relationship**

We'll update the receipt service to use the actual relationship name (`paymentMethod()`) instead of introducing aliases or shortcuts. This is the simplest fix and aligns with Laravel conventions.

_Why this over alternatives:_
- **vs. Adding a `method()` alias**: Aliases add unnecessary indirection. The relationship is already correctly named and used elsewhere.
- **vs. Lazy-loading in the view**: Eager-loading is correct; it prevents N+1 queries when rendering multiple payments.

**Decision: Fix three locations in one change**

All three references (`eager-load`, line 55, line 60) use the same incorrect name and should be fixed together in a single change.

## Risks / Trade-offs

**[Risk: Incomplete fix]** → Mitigated by searching the codebase to confirm only these three lines reference the broken relationship.

**[Risk: Unintended side effects]** → Low risk; this is a direct name replacement matching Laravel's relationship definition. Receipt data structure and display logic remain unchanged.

**[Trade-off: No design complexity needed]** → This is a straightforward bug fix with no architectural decisions or new patterns. The simplicity is a strength—minimal surface for error.
