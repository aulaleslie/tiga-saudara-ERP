## Context

The `payment_methods` table contains a boolean flag `is_cash` that indicates if a payment method behaves like physical cash (crucial for POS logic). Currently, the `PaymentMethodController` performs simple validation:

```php
'is_cash' => 'nullable|boolean',
```

This lacks coordination with existing records, allowing multiple "cash" methods.

## Goals / Non-Goals

**Goals:**
- Enforce strict uniqueness for `is_cash = true` records at the validation layer.
- Move validation from `PaymentMethodController` into dedicated `FormRequest` classes.
- Ensure both `create` and `edit` flows are covered.

**Non-Goals:**
- **Automatic Swapping**: We will not automatically disable the old cash method when a new one is set. This was explicitly requested to maintain data safety.
- **Database Unique Constraint**: We will focus on the application layer validation for now to provide better user feedback.

## Decisions

### 1. Dedicated Form Requests
Introduce `StorePaymentMethodRequest` and `UpdatePaymentMethodRequest` at `Modules/Setting/Http/Requests/`.

### 2. Validation Logic
Use a closure-based rule within each request's `rules()` method:
- **On Create**: Check if ANY existing method has `is_cash = true`.
- **On Edit**: Check if ANY OTHER method (excluding the current ID) has `is_cash = true`.

### 3. Error Message
Display a clear error: `"A cash payment method already exists. You must disable the existing one before designating another."`

## Risks / Trade-offs

- **UX Friction**: Users must perform two separate edits to "swap" a cash method. This is considered acceptable for data integrity.
