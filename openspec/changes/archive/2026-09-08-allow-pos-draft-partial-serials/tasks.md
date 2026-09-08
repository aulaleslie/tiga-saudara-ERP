## 1. Client-side button gating

- [x] 1.1 In `Modules/Pos/Resources/views/sell.blade.php`, remove `allSerialsValid` from the `canSaveDraft` expression (~line 1593) so Save Draft is no longer gated by serial-quantity match.
- [x] 1.2 Confirm `canCheckout` (~line 1594) still requires `allSerialsValid` (directly or via its own condition) so Checkout continues to be blocked on serial mismatch.
- [x] 1.3 Adjust the mismatch status message block (~lines 1604-1611) so a serial mismatch is shown as informational styling when Save Draft is still available, and only rendered as a blocking/danger message when it is the reason Checkout is disabled.

## 2. Focused verification

- [x] 2.1 Grep `sell.blade.php` for any other usage of `allSerialsValid` or duplicated save/checkout gating logic to confirm the single edit point covers all cases.
- [x] 2.2 Manually trace the updated `canSaveDraft`/`canCheckout` logic against the scenarios in `openspec/changes/allow-pos-draft-partial-serials/specs/pos-serial-qty-mismatch-validation/spec.md` (under-assigned, over-assigned, exact match) to confirm expected button states on paper.
- [x] 2.3 Human will verify in-browser: partial serial scan → Save Draft enabled/Checkout disabled; exact match → both enabled; reopening a partially-scanned draft still blocks Checkout until completed.

## 3. Spec sync

- [x] 3.1 Confirm `openspec/changes/allow-pos-draft-partial-serials/specs/pos-serial-qty-mismatch-validation/spec.md` validates cleanly (`openspec status`) and is ready to archive into `openspec/specs/pos-serial-qty-mismatch-validation/spec.md` once implementation is verified.
</content>
