# Manual Verification Checklist

Run on a **staging copy** only. Use the normal Stock Opname screens (Buat → Simpan → Ajukan → Tinjau → Setujui/Tolak). No direct database edits.

## A. Explicit physical-zero confirmation

- [ ] Create a Stock Opname for one location, add a product with positive system stock, leave its count at 0. The row shows “Saya sudah menghitung: stok fisik kosong” (unchecked).
- [ ] Save the draft without ticking it → saving succeeds.
- [ ] Ajukan → rejected with a product-specific message; document stays **Draf**; no approval notification.
- [ ] Edit, tick the checkbox, save, reopen → checkbox still ticked; the detail page shows “Stok fisik dikonfirmasi kosong” with confirmer and time.
- [ ] Change the count to 2 and back to 0 → checkbox is cleared and must be ticked again.
- [ ] Change only the note as another user → confirmer/time unchanged.
- [ ] As a counter without `adjustments.view-system-stock`, the checkbox is visible on zero rows and no system stock, unmatched quantity, or projected effect is shown.

## B. Pending document without confirmation

- [ ] For a document submitted with an unconfirmed zero (stock was 0 at submission) whose stock later became positive, Setujui → blocked with guidance to reject and resubmit; stock unchanged.
- [ ] Tolak with a reason → edit, tick confirmation (or correct the count), save, Ajukan, Setujui → succeeds.

## C. Unmatched serialized stock — confirmed empty (Perdana / White Knight)

- [ ] Product with Perdana quantity 1 (no available serial) and White Knight quantity 1 (one serial). Select only Perdana, tick confirmation, submit.
- [ ] Reviewer page shows unmatched quantity 1 and the planned Perdana −1.
- [ ] Approve → Perdana 0, White Knight 1, global quantity 1; White Knight serial unchanged; approval history shows confirmation and unmatched evidence.

## D. Unmatched serialized stock — physical serial registers existing unit

- [ ] Separate fixture: location with quantity 1 and no serial. Count by entering one new physical serial, submit, approve.
- [ ] Serial is registered at that location; total stays 1 (net 0); history marks it as accounting for an existing unit.

## E. Regression

- [ ] Positive partial reductions and condition-only changes need no confirmation.
- [ ] POS returns and sales returns behave as before (no changes in this release).
