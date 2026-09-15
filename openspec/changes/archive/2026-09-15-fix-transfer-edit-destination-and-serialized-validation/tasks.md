## 1. Durable Destination Synchronization

- [x] 1.1 Bind the transfer form's modelable destination dropdown directly to `TransferStockForm::destinationLocation` so the accepted selection is present in the parent mutation snapshot.
- [x] 1.2 Route destination-bound updates through parent validation/error clearing and `locationsConfirmed` synchronization without remounting or clearing existing product and serial rows.
- [x] 1.3 Preserve authoritative active, distinct-origin, consignment-compatible, and tenant/origin mutation validation for selected destination IDs at save and submission.

## 2. Blind Serialized Intent Validation

- [x] 2.1 Refactor parent row preparation to recognize identity-only blind rows even when serial identities are present, without defaulting omitted provenance or stock fields to false or zero.
- [x] 2.2 Normalize positive serial IDs, reject duplicates or requested-quantity/count mismatches with neutral feedback, and pass only canonical identity intent to the draft service.
- [x] 2.3 Confirm `TransferDraftService` authoritatively reloads every submitted serial and derives tax/condition allocation while enforcing product, origin, condition, availability, custody/dispatch, and return-state invariants for both save and submission.
- [x] 2.4 Preserve privileged bucket-aware pre-validation and ensure crafted client provenance, stock, or allocation values cannot become mutation authority.

## 3. Focused Livewire Verification

- [x] 3.1 Add a regression reproducing a blind edit of a destination-less saved draft with taxed serialized intent, selecting the destination through the real nested dropdown contract and saving successfully.
- [x] 3.2 Assert the saved destination, requested quantity, serial identities, and authoritative taxed allocation are preserved while blind snapshots and events contain no protected stock, bucket, tax, or condition-provenance keys.
- [x] 3.3 Add focused failure cases for duplicate/mismatched serial counts, stale or ineligible authoritative serial state, and invalid destination selection, proving no partial draft mutation occurs.
- [x] 3.4 Add or retain the corresponding privileged edit regression to confirm existing destination, serialized allocation, and validation behavior remains intact.
- [x] 3.5 Run only the focused transfer Livewire interaction, visibility, and draft-save test files or filters and confirm they pass.
