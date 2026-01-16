# Purchase Return Settlement — Execution Plan

## Recommended Order
Phase 1: Foundations (Completed)
1) Ticket 1: Settlement Entry Page (Kelola Penyelesaian)
2) Ticket 2: Serial vs Non-serial Settlement Rules
3) Ticket 8: Validation Rules & Blocking Save

Phase 2: Settlement Methods (Completed)
4) Ticket 3: Perbaikan Produk
5) Ticket 4: Kembali Barang Rusak
6) Ticket 5: Ubah Nota Pembelian
7) Ticket 6: Simpan Sebagai Kredit
8) Ticket 7: Pengembalian Tunai

Phase 3: Approval & Effects (Completed)
9) Ticket 10: Approval & Locking
10) Ticket 11: Inventory & Financial Effects on Approval

Phase 4: Supporting
11) Ticket 9: Pricing & Amounts
12) Ticket 12: Permissions for Settlement Creation

## Parallelizable Tasks
- Ticket 9 (Pricing & Amounts) can run in parallel with Phase 1 after Ticket 1 UI is scaffolded.
- Ticket 12 (Permissions) can run in parallel with Phase 2 once endpoints are defined.
- Ticket 4 (Broken stock) and Ticket 7 (Cash return) can be built in parallel after validation rules are in place.

## Milestones
Milestone 1: Settlement entry + core validation complete (Tickets 1, 2, 8)
Milestone 2: All settlement methods selectable and saved (Tickets 3–7)
Milestone 3: Approval workflow + system effects complete (Tickets 10, 11)
Milestone 4: Pricing display + permissions locked down (Tickets 9, 12)

## Risks per Phase
Phase 1 risks
- Incomplete serial/receive data blocks settlement entry.
- Validation rules not aligned with existing data constraints.

Phase 2 risks
- Ubah Nota Pembelian depends on accurate outstanding balance and purchase linkage.
- Credit application may conflict with purchase payment logic.

Phase 3 risks
- Transaction safety across inventory and finance updates.
- Concurrency issues around approval and edits.

Phase 4 risks
- Last purchase price unavailable or inconsistent.
- Permission model mismatches existing roles.

## Testing Strategy
Unit tests
- Validation rules for serial vs non-serial settlement.
- Nominal value checks (positive, non-zero).
- Purchase balance checks for Ubah Nota Pembelian and Kredit.

Integration tests
- Settlement save flow with mixed methods.
- Approval flow applying inventory and financial updates.
- Permission enforcement for create/approve actions.

End-to-end tests
- Full settlement creation from return detail page.
- Approval locking behavior.
- Error handling when unpaid purchase is not found.

Manual testing
- Large returns with many serials for performance.
- Edge cases: missing serials, insufficient balance, invalid receive ID.
