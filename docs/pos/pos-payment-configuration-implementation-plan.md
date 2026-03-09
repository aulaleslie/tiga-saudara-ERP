# POS Payment Configuration Implementation Plan

Date: 2026-03-09  
Owner: POS Team

## Objective

Implement a new settings module **Konfigurasi Pembayaran POS** that mirrors the location-configuration pattern, but for payments:
1. Load all payment methods in the system (cross-business).
2. Configure per-setting payment availability via enable/disable.
3. Remove dependency on `payment_methods.is_available_in_pos`.

## Locked Decisions

Confirmed decisions for this plan:
1. Candidate list shows all payment methods; `is_available_in_pos` is no longer used.
2. Backfill default is disabled for all setting-payment assignments.
3. No ordering/priority feature in v1.
4. POS session open must be blocked when no enabled payment method exists.
5. Reuse existing permissions `paymentMethods.access` and `paymentMethods.edit` (no new permission codes).

## Scope

In scope:
1. New per-setting payment configuration data model.
2. New configuration UI/menu/routes under Setting module.
3. POS runtime filtering and checkout validation based on enabled payments.
4. Session-open guard for minimum one enabled method.
5. Removal of `is_available_in_pos` field usage and schema.
6. Feature/regression tests.

Out of scope:
1. Per-terminal payment configuration.
2. Multi-payment split checkout redesign.
3. Payment method ordering/prioritization.

## Current Baseline (Verified)

1. POS payment options are fetched from `/pos/sell/payment-methods/search` in `Modules/Pos/Resources/views/sell.blade.php`.
2. Search logic currently relies on `PosPaymentMethodSearchService`.
3. Checkout finalization validates payment method existence in `FinalizePosCheckoutService`.
4. Existing location configuration architecture (controller/view/pivot) is available as implementation reference.

## Phase Plan

### Phase 1 - Data Model and Migration

Deliverables:
1. Create pivot table `setting_pos_payment_methods` with:
1. `id`
2. `setting_id` (FK -> `settings`)
3. `payment_method_id` (FK -> `payment_methods`)
4. `is_enabled` (boolean, default false)
5. timestamps
2. Add unique constraint on (`setting_id`, `payment_method_id`).
3. Add index for resolver/query path (`setting_id`, `is_enabled`, `payment_method_id`).
4. Backfill full matrix of settings x payment methods with `is_enabled = false`.
5. Add model `SettingPosPaymentMethod` with relationships to `Setting` and `PaymentMethod`.
6. Update `Setting` and `PaymentMethod` models with assignment relationships.
7. Drop `is_available_in_pos` column from `payment_methods`.
8. Remove `is_available_in_pos` from validation, fillable, casts, and setting forms.

Acceptance criteria:
1. Every setting has explicit assignment rows for every payment method.
2. All assignments start disabled.
3. No code path references `is_available_in_pos`.

### Phase 2 - Configuration UI and Routes

Deliverables:
1. Add controller, e.g. `PosPaymentConfigurationController`, in Setting module.
2. Add routes in `Modules/Setting/Routes/web.php`:
1. `GET /pos-payment-configurations` -> index
2. `PATCH /pos-payment-configurations/{paymentMethod}/toggle` -> enable/disable
3. Add view `Konfigurasi Pembayaran POS` similar to sale location config table:
1. Payment name
2. COA
3. Cash/non-cash flag
4. Reference-required flag
5. Status (Enabled/Disabled)
6. Action toggle button
4. Add menu item in `resources/views/layouts/menu.blade.php`:
1. Label: `Konfigurasi Pembayaran POS`
2. Gate: reuse `@can('paymentMethods.access')`
5. Guard toggle action with `paymentMethods.edit`.

Acceptance criteria:
1. User can view all payment methods from all businesses in one table.
2. User can toggle per-setting enable/disable state.
3. Menu visibility and actions follow existing payment method permissions.

### Phase 3 - POS Runtime Integration

Deliverables:
1. Update `PosPaymentMethodSearchService` to return only methods enabled for current setting by joining `setting_pos_payment_methods`.
2. Keep optional text search (`q`) against payment method name.
3. Update checkout validation in `FinalizePosCheckoutService`:
1. Selected `payment_method_id` must be enabled for current setting.
2. Return `PAYMENT_INVALID` for disabled/non-assigned methods.
4. Update request/validation path as needed so enabled-state is enforced server-side (not only UI).
5. POS modal UX:
1. If no methods available, show explicit message.
2. Disable/guard checkout action accordingly.

Acceptance criteria:
1. `/pos/sell/payment-methods/search` only returns enabled methods for active setting.
2. Checkout cannot proceed with disabled methods, even if payload is spoofed.
3. POS cashier sees clear error state when no payment methods are enabled.

### Phase 4 - Session Open Guard

Deliverables:
1. Add enabled-payment existence check before session open in:
1. `PosSessionController::create`
2. `PosSessionLifecycleService::openSession`
2. Error message should instruct user to configure payments first.

Acceptance criteria:
1. Session open is blocked when setting has zero enabled payment methods.
2. Guard works for both UI path and service-level path.

### Phase 5 - Tests

Deliverables:
1. New feature tests for payment configuration module:
1. Index shows full payment list.
2. Toggle enable/disable persists per setting.
3. Cross-setting isolation.
2. Update/add POS tests:
1. Payment search returns enabled-only by setting.
2. Disabled method is rejected at checkout finalize.
3. Session open blocked when no enabled methods.
3. Remove/adjust old tests relying on `is_available_in_pos`.

Acceptance criteria:
1. Targeted Setting and POS feature suites pass.
2. No regression on checkout validation and session lifecycle.

### Phase 6 - Rollout Strategy

Because backfill is disabled-for-all, this is a behavior-breaking rollout by design.

Rollout steps:
1. Deploy migration + code.
2. For each active setting, enable required payment methods in `Konfigurasi Pembayaran POS`.
3. Verify cashier can open session and complete checkout.
4. Communicate cutover SOP to operations before production deploy.

Operational safeguards:
1. Add clear blocker message on session open page.
2. Consider temporary admin checklist for post-deploy activation.

## Risks and Mitigations

1. Risk: All settings lose payment availability immediately after migration.  
Mitigation: Planned SOP and staged activation checklist before cashier shift starts.
2. Risk: Users without `paymentMethods.edit` cannot self-remediate blocked sessions.  
Mitigation: Ensure at least one role per setting has edit permission before rollout.
3. Risk: Legacy code still referencing `is_available_in_pos` causes runtime errors.  
Mitigation: include grep-based cleanup and targeted regression tests in same release.

## Resolved Additional Decisions

1. Newly created settings will auto-create payment assignments as disabled.
2. Newly created payment methods will be assigned to all settings as disabled.
3. If methods are disabled during an active session, checkout is blocked immediately.
4. Configuration screen will include bulk actions: `Enable All` and `Disable All`.
5. Users with `paymentMethods.access` but without `paymentMethods.edit` will see a read-only configuration view.

## Remaining Questions

1. None at this time.
