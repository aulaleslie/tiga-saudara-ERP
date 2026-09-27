## Context

The operational purchase index at `/purchases` is rendered by `Modules/Purchase/Resources/views/index.blade.php` and `App\Livewire\Purchase\PurchaseTable`. `PurchaseTable` always applies the active `session('setting_id')`, and its rows link to the standard purchase detail and reuse the broad purchase action partial. The standard `PurchaseController::show()` and purchase-payment controller methods also enforce the active-setting guard.

The existing `Laporan -> Pembelian Global` is a reporting screen backed by `PurchaseReport`; it is not an operational purchase workspace and must remain separate. Existing purchase payment creation accepts one purchase, creates one `PurchasePayment`, stores at most one attachment, and recalculates the purchase header from active payments. `PurchasePayment::amount` is persisted using the existing cents-like mutator/accessor convention.

The requested workflow adds a centralized payment-only view across settings. It must not relax tenant guards on existing routes, must preserve current report and single-payment behavior, and must reuse existing purchase-payment records so current payment history and financial reports continue to work.

## Goals / Non-Goals

**Goals:**

- Add `Pembayaran Pembelian Global` beside `Semua Pembelian` under the operational `Pembelian` navigation.
- Reuse the operational purchase list layout while explicitly supporting a global, received-only, outstanding-only payment context.
- Provide authorized read-only detail and payment-history access for purchases outside the active setting.
- Let a user allocate one supplier payment across multiple fully received purchases sharing the exact `supplier_id`.
- Create ordinary purchase payment rows atomically and replicate one shared attachment to each created payment.
- Preserve server-side status, balance, permission, and cross-setting integrity under stale or concurrent submissions.

**Non-Goals:**

- Replacing or renaming the global purchase report under `Laporan`.
- Introducing a canonical supplier identity across different supplier records; v1 groups only by exact `supplier_id`.
- Creating a payment batch ledger/header model or changing downstream reporting to consume one.
- Paying purchases in `APPROVED`, `RECEIVED PARTIALLY`, or any state other than exact `RECEIVED`.
- Adding tags, withholding, a separate payment due date, multiple attachments, supplier credits, return credits, or unapplied supplier balances.
- Enabling global purchase editing, receiving, approval, deletion, archiving, duplication, or attachment management.

## Decisions

### Decision 1: Add an explicit global-payment mode to the operational purchase UI

Create dedicated list/controller routes and pass an explicit global-payment context into a reusable operational index/table presentation. In this mode the purchase query omits `setting_id`, requires exact `RECEIVED`, excludes archived rows, and requires a positive current outstanding balance. The view removes create/import controls and uses a payment-only row action partial.

An explicit boolean or dedicated component input is required because passing a null setting ID is unsafe: `PurchaseTable::mount()` currently treats null as a request to fall back to the active session setting. A separate context also prevents ordinary receiving tables or supplier-filtered embeds from accidentally becoming global.

Alternative considered: reuse the global report. Rejected because its detail/header report modes, filters, export contract, and permission domain do not match the operational list or payment actions.

### Decision 2: Use dedicated global routes rather than weakening existing setting guards

Add global list, read-only detail, payment-history data, payment-form, and submit routes before parameterized resource routes. Protect viewing routes with `purchasePayments.global.access`; protect payment creation/submission with both global access and `purchasePayments.create`.

Extract or share purchase-detail data loading where useful, but render it with an explicit global read-only context. Standard `purchases.show` and existing single-purchase payment endpoints continue calling their setting guard. The global view must use dedicated global URLs for payment history and payment creation and must not accept a client query parameter that disables tenant scoping.

Alternative considered: conditionally bypass `ensurePurchaseBelongsToCurrentSetting()` when the user has a global permission. Rejected because it would silently broaden every standard purchase route and make unsafe mutating actions easier to expose.

### Decision 3: Reuse `PurchasePayment` without a batch schema

The multi-payment submission creates one current `PurchasePayment` for each positive invoice allocation. Shared date, reference, `payment_method_id`, memo, and attachment content are copied to each row. Zero allocations create no row. Existing purchase histories, invalidation behavior, payment reports, cash-flow reports, and balance calculations therefore continue to operate without new joins or backfills.

The common payment reference is the user-visible association between rows created in one submission. V1 does not require whole-group invalidation or a separately addressable payment batch, so a batch header and batch-item table would add lifecycle and migration cost without serving a current requirement.

Alternative considered: add `purchase_payment_batches` and items. Deferred until the product requires batch history, group invalidation, batch approval, or batch-level reporting.

### Decision 4: Build the candidate set from exact supplier and live payable state

Resolve the starting purchase on the server, require exact status `RECEIVED`, non-archived state, and positive live outstanding balance, then load all other purchases with the same exact `supplier_id` under those same rules without `setting_id` filtering. Do not match suppliers by name, tax number, or other mutable attributes.

Outstanding balance is derived using the established effective active-payment semantics rather than trusting only the denormalized purchase header. The starting row defaults to its live outstanding balance and other rows default to zero.

Alternative considered: group setting-specific supplier records by name. Rejected because the data model has no canonical cross-setting supplier identity and name matching can settle the wrong legal counterparty.

### Decision 5: Put orchestration in a transactional service

Use a dedicated multi-purchase payment service called by the form/controller or Livewire component. It validates shared fields and normalized allocation amounts, begins a database transaction, locks candidate purchases in a stable ID order, reloads active payment totals, verifies exact supplier/status/archive/balance eligibility, creates positive payment rows, and recalculates each affected purchase before commit.

Use the existing purchase-payment amount accessor/mutator consistently; validation and calculation operate in domain currency values while persistence continues through the model. The form must submit an idempotency token or otherwise prevent a repeated browser request from duplicating all allocations.

Alternative considered: invoke the current controller `store()` repeatedly. Rejected because controller-to-controller calls cannot make the whole allocation atomic and repeat attachment handling, authorization, and redirects.

### Decision 6: Replicate the one attachment after preparing a reusable source

Validate and stage one supported upload once, then give every generated payment an independent media copy in its existing single-file `attachments` collection. The implementation must not allow the first `addMedia` call to move the only source before later payments copy it. It can preserve a staged original or make prepared copies before media attachment.

Database rollback does not automatically undo filesystem writes. The orchestration therefore tracks created media/files and removes them on any exception; a failure to attach any copy causes the full database transaction to roll back. Temporary uploads are cleaned after success or failure.

Alternative considered: attach the file only to the first payment and infer it for siblings by shared reference. Rejected because each current payment history expects its own attachment and references are not a relational grouping key.

### Decision 7: Mirror the sample structure using supported ERP fields

Build the page in existing Bootstrap/CoreUI conventions while following the sample's information hierarchy: breadcrumb/title, read-only supplier and shared payment controls, invoice allocation table, memo/attachment area, running subtotal/total, and cancel/save actions. Rows show transaction number, description, due date, total, live outstanding balance, and amount.

Payment method remains the supported domain control. Where useful, its related chart-of-account can be displayed as contextual account information, but v1 does not introduce a separate deposit-account value. Unsupported tags, withholding, payment due date, and multi-file upload are omitted rather than displayed as nonfunctional controls.

## Risks / Trade-offs

- [Global read routes accidentally expose mutating actions] → Use dedicated routes, an explicit view context, a payment-only action partial, backend permission checks, and tests that assert forbidden controls/endpoints remain unavailable.
- [Denormalized `due_amount` is stale] → Build and revalidate eligibility from current active payments and recalculate purchase headers after creation.
- [Two users pay the same invoice concurrently] → Lock affected purchases in stable order and revalidate all balances inside one transaction.
- [Multi-row request is retried or double-clicked] → Use the application's idempotency mechanism or a submission token covering the entire allocation.
- [Media copies survive a database rollback] → Track created media and staged files explicitly and clean them on failure.
- [Global query becomes expensive] → Filter by indexed lifecycle/archive/payment columns where available, eager-load only table relations, paginate, and add focused indexes only if query evidence shows a need.
- [Same real supplier exists as multiple setting-owned records] → V1 intentionally groups only exact `supplier_id`; canonical supplier grouping remains out of scope.
- [Shared reference is insufficient for later group operations] → Accept this v1 limitation; add a batch entity only when group lifecycle/reporting becomes a requirement.

## Migration Plan

1. Add the canonical `purchasePayments.global.access` permission through the existing permission synchronization/migration approach and assign it only to intended centralized payment roles.
2. Deploy dedicated global list/detail/payment routes, components/services, and views without changing existing purchase or report routes.
3. Add the navigation item after the permission exists so unauthorized users never see a dead link.
4. Verify focused authorization, cross-setting visibility, exact-status eligibility, atomicity, concurrency, amount scaling, and media-replication tests before enabling the permission in production roles.
5. Roll back by removing role assignments/menu exposure and reverting the additive routes/components. Existing `PurchasePayment` rows remain valid ordinary payments and require no data migration.

## Open Questions

None. The product decisions are resolved for v1: exact supplier identity, exact `RECEIVED` eligibility, payment-only global operations, one replicated attachment, and reuse of existing payment records.
