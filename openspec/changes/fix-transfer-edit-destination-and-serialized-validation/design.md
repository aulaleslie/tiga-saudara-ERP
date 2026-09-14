## Context

The transfer form uses a parent `TransferStockForm`, a nested modelable `LocationSearchDropdown`, and a nested `TransferProductTable`. A saved blind draft is intentionally projected with requested quantities and serial identity only; protected stock buckets and serial tax/condition provenance are absent.

Two defects intersect during edit. First, `TransferStockForm::validateAndPrepareRows` handles a blind serialized row as if omitted provenance meant non-tax and omitted stock meant zero, producing false allocation mismatch and insufficient-stock errors before `TransferDraftService` can perform its authoritative checks. Second, destination selection currently travels from the child dropdown to the parent through a targeted event request. The destination can be accepted by the parent but still be absent from the later save snapshot, leaving two independently serialized sources of state around a mutation boundary.

The existing `TransferDraftService::buildProductsData` already reloads selected serial IDs, validates product, location, condition, availability, dispatch/return state, and derives tax allocation from live records. The change should make that service boundary effective without returning protected provenance to blind clients.

## Goals / Non-Goals

**Goals:**

- Make an accepted destination selection durable through the nested Livewire edit-and-save interaction.
- Allow blind editors to save or submit valid existing serialized intent using identity-only serial payloads.
- Keep all protected serial provenance, stock, and allocation data out of blind Livewire state and events.
- Retain authoritative server validation against current database state for every mutation.
- Preserve privileged editing behavior and lifecycle/tenant rules.

**Non-Goals:**

- Exposing tax IDs, condition provenance, stock buckets, maximums, or availability to blind users.
- Changing transfer route policy, approval, dispatch, receipt, inventory, or return behavior.
- Migrating or repairing existing transfer rows; transfer 4 demonstrates valid persisted data.
- Replacing the shared location dropdown for unrelated modules.

## Decisions

### Use one model-bound source of truth for the destination

Bind the modelable destination dropdown directly to the parent form's `destinationLocation` property. Parent destination-update handling will clear its location error and notify the product table after the bound value changes. The nested dropdown will remain responsible for its scoped selection UI, while the parent and draft service remain responsible for mutation authorization and authoritative destination validation.

Keeping both child-owned selection and a second targeted parent-event request was rejected because their snapshots can advance independently. Adding destination to the product-table component key was rejected because destination does not change origin inventory eligibility and should not destroy existing rows or serial selections.

The existing event contract may remain available for callers that cannot use model binding, but the transfer form must not depend on two competing mechanisms for the same destination state.

### Separate identity-only validation from provenance-aware validation

The parent form will classify a row by whether protected bucket/stock state is present, not by whether serial identities are present. For a blind serialized row it will:

- normalize and deduplicate positive serial IDs;
- require at least one serial for a serial-required positive row;
- require the distinct serial count to equal the requested base quantity;
- construct canonical operator intent without calculating tax/condition buckets or comparing against client stock.

It will not default omitted `tax_id`, `taxable`, `is_broken`, bucket, or stock values. Treating absence as `false` or zero was rejected because absence is the confidentiality contract, not a business value.

### Keep `TransferDraftService` authoritative for serialized provenance and availability

Save and submit will continue through `TransferDraftService::buildProductsData`, which reloads current serial rows by ID and validates exact count, product ownership, origin location, transfer condition, operational availability, dispatch state, return state, and current tax identity before deriving persisted allocation and full serial snapshots.

Moving full serial records into public Livewire state was rejected because it would violate system-stock visibility. Trusting a server-derived allocation cached from an earlier request was rejected because stock and serial state may change before mutation.

### Verify the real interaction boundary and both visibility modes

Focused tests will cover the parent form's identity-only validation and the nested destination selection contract. At least one regression will reproduce a destination-less saved draft containing taxed serialized intent, select a destination through the same dropdown-to-parent interaction used by the browser, save, and verify the destination and original taxed allocation persist.

Blind assertions will inspect Livewire snapshots/events for absence of protected keys. Privileged coverage will ensure existing bucket-aware validation remains operational. Tests that only call the parent handler directly are insufficient for the destination regression.

## Risks / Trade-offs

- [Livewire model binding changes shared dropdown behavior] -> Scope the binding change to the transfer destination invocation and retain the dropdown's existing selection-scope validation.
- [A crafted client supplies serial IDs or destination IDs] -> Treat all client values as intent and re-query locations, products, stock, and serials in the existing draft service before persistence.
- [Blind pre-validation becomes less detailed] -> Keep neutral identity/count validation in the form and rely on the authoritative domain boundary for provenance and availability failures.
- [Destination updates remount or clear product rows] -> Keep destination out of the product-table key and synchronize only its destination property.
- [Duplicate serial identities distort quantity] -> Normalize to positive IDs, deduplicate, and compare distinct count with requested quantity before invoking the service.

## Migration Plan

Deploy the Livewire binding, form-validation adjustment, and focused tests together. No schema or data migration is required. Rollback is a code-only revert; persisted transfers are unchanged.

## Open Questions

None. The database evidence and existing visibility specification establish the intended behavior.
