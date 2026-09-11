## 1. Focused Characterization

- [x] 1.1 Add focused lifecycle/service tests that characterize material and no-op saves for `DRAFT` and `PENDING`, including revision, status, lines, and action-history assertions.
- [x] 1.2 Add focused authorization tests for active-origin-business ownership and immutable `APPROVED` or later states across direct controller and Livewire mutation paths.
- [x] 1.3 Add focused view tests for edit-action visibility on `DRAFT` and `PENDING` records and its absence without `stockTransfers.edit`.

## 2. Authoritative Edit Semantics

- [x] 2.1 Implement canonical comparison of persisted and submitted transfer state, including destination, normalized product quantities and bucket intent, and sorted serial selections while excluding presentation-only state.
- [x] 2.2 Update the locked transfer-save transaction so a material `DRAFT` edit remains draft, advances revision, synchronizes lines, and records `DRAFT` to `DRAFT` history.
- [x] 2.3 Update the same transaction so a material `PENDING` edit synchronizes lines, advances revision, transitions to `DRAFT`, and records `PENDING` to `DRAFT` history before requiring explicit resubmission.
- [x] 2.4 Ensure a no-op save leaves status, revision, lines, and history unchanged and that stale concurrent revisions fail without partial persistence.
- [x] 2.5 Enforce persisted stock-condition and origin immutability for existing transfers at Livewire, controller, and service boundaries, including crafted payloads.
- [x] 2.6 Route both the shared Livewire form and legacy controller update path through the corrected authoritative semantics and provide clear pending-to-draft feedback.

## 3. Entry and List Presentation

- [x] 3.1 Replace the create-form condition dropdown with real radio controls styled as a segmented `Barang Baik` / `Barang Rusak` choice with labels, visible focus, non-color-only selection, disabled, and validation states.
- [x] 3.2 Add creation-time confirmation when switching condition with entered rows so confirm clears incompatible rows and cancel restores the prior condition and rows.
- [x] 3.3 Render persisted stock condition as a read-only label or badge on edit forms, remove writable edit-time condition controls, and prevent historical mixed-condition transfers from entering ordinary edit.
- [x] 3.4 Expose the list edit action for both `DRAFT` and `PENDING` records while retaining permission checks and server-side lifecycle and ownership authorization.

## 4. Focused Verification and Human Browser Check

- [x] 4.1 Run focused PHPUnit/Livewire tests covering segmented-control rendering, condition confirmation state, list actions, edit authorization, material/no-op lifecycle behavior, history, revision locking, and explicit resubmission.
- [x] 4.2 Perform static inspection of rendered Blade and Livewire public state to confirm existing transfers cannot submit a writable condition through the ordinary edit UI.
- [x] 4.3 Prepare a concise human browser checklist covering keyboard/focus behavior, responsive segmented styling, creation-time condition confirmation and cancellation, read-only edit condition, draft edit discovery, and pending-to-draft success feedback.
