## Context

The supplier detail template dereferences `paymentTerm.name` unconditionally. The `suppliers.payment_term_id` column and the supplier create/update forms intentionally allow null, while deletion of a referenced payment term uses `ON DELETE SET NULL`. Consequently, an absent relationship is a supported state rather than an exceptional one.

The customer detail template already renders an absent payment term with a placeholder, providing a nearby People-module precedent. The change should follow existing Laravel Blade, route-model binding, Gate authorization, Eloquent relationship, and module feature-test patterns.

## Goals / Non-Goals

**Goals:**

- Prevent the supplier show route from failing when the payment-term relationship is absent.
- Make the absence visible through a stable placeholder.
- Preserve the related payment-term name when present.
- Protect both states with focused HTTP-level regression tests.

**Non-Goals:**

- Making payment terms mandatory for suppliers.
- Assigning or backfilling a default payment term.
- Changing supplier or payment-term schemas, foreign keys, forms, or lifecycle behavior.
- Repairing production data that violates foreign-key integrity.

## Decisions

### Render the optional relationship defensively in Blade

Use PHP's null-safe relationship access with a fallback placeholder at the presentation boundary. This directly reflects the nullable domain contract and handles both a null foreign key and an unresolved related row.

Alternatives considered:

- Make `payment_term_id` required: rejected because it changes established creation, update, and deletion behavior.
- Supply a default Eloquent relation with `withDefault()`: rejected because it would globally introduce a synthetic payment term when only display fallback behavior is required.
- Eager-load the relationship in the controller: useful for query predictability but insufficient because eager loading does not turn an absent relationship into a model.

### Use the existing neutral placeholder

Render `-` when no payment term exists, matching the customer detail convention and avoiding a translated-label expansion for this focused fix.

### Verify behavior through the supplier show route

Feature tests shall request the actual supplier detail route under an authorized user. One case will use a null `payment_term_id`; another will associate a valid `PaymentTerm`. This verifies the Blade expression, controller rendering, relationship, and visible result together.

## Risks / Trade-offs

- [An orphaned non-null foreign key is hidden by the same placeholder] → Keep production integrity auditing operationally separate; verify the foreign-key constraint exists rather than turning a display request into a server error.
- [The supplier page includes a purchase-table Livewire component that can complicate an HTTP test] → Follow existing application test setup and assert the response/status and relevant payment-term text with the smallest required fixture set.
- [A generic dash does not explain why the term is absent] → Accept the established People-module convention for this bug fix; richer empty-state wording can be a separate localization/UI decision.

## Migration Plan

1. Deploy the null-safe view change and focused regression tests.
2. Clear compiled Laravel views during the normal production deployment (`php artisan view:clear`) so the updated Blade template is compiled.
3. Optionally audit suppliers with null or orphaned payment-term references.

Rollback consists of reverting the view and tests; there is no schema or data rollback.

## Open Questions

None. Existing schema and customer-detail behavior establish that a missing payment term is valid and should display as `-`.
