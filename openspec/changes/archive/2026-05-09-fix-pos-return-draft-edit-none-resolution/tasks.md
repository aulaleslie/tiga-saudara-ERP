## 1. Regression Coverage

- [x] 1.1 Add a focused service-level test for editing a draft with multiple serialized lines where one line changes to `none` and at least one other line remains actionable.
- [x] 1.2 Add a focused service-level test for editing a draft so every submitted line is `none`, asserting the save is rejected and the existing draft lines remain unchanged.
- [x] 1.3 Add or extend a Livewire edit-form test to verify selecting `Tidak` for one serialized line submits and persists no-action intent instead of reverting to the header return option.

## 2. Draft Edit Validation

- [x] 2.1 Update draft-line validation so an explicitly submitted `resolution = none` is preserved and is not replaced by `return_option` fallback.
- [x] 2.2 Keep any backward-compatible return-option fallback limited to missing or implicit line resolution, not explicit `none`.
- [x] 2.3 Ensure all-`none` or otherwise non-actionable edits still fail with the existing minimum-one-action validation message.

## 3. Persistence And Display Safety

- [x] 3.1 Verify partial `none` edits rebuild draft lines without changing POS Return lifecycle status away from `draft`.
- [x] 3.2 Verify partial `none` edits do not create Sales Return records, Sale Return Details, stock mutations, dispatch changes, payment mutations, replacement dispatches, or serial execution mutations.
- [x] 3.3 Confirm readonly/detail display remains consistent for drafts edited with a no-action serial line, with returned/actionable lines primary and non-returned source context derived from snapshot where applicable.

## 4. Verification

- [x] 4.1 Run the focused POS Return draft/edit regression tests.
- [x] 4.2 Run a focused POS Return test group that covers draft resolutions, shared form surface, and readonly detail behavior.
- [x] 4.3 Manually verify `/pos/returns/2/edit` can change one line to `Tidak` and save while rejecting the case where every line is `Tidak`.
