Created At: 2026-05-27T01:45:45Z
Completed At: 2026-05-27T09:48:00Z
File Path: `file:///home/aulaleslie/Workspace/Rahmat/tiga-saudara-ERP/openspec/changes/fix-pos-bundled-replacement-valuation/tasks.md`

## 1. Split Posting Valuation

- [x] 1.1 Add focused coverage for split bundled POS checkout where two serialized parent units split across owners and component allocation belongs to one owner group.
- [x] 1.2 Update split planning grouped line payloads so parent `unit_price` is derived from parent residual share per parent quantity, while `line_subtotal` retains the full owner group amount.
- [x] 1.3 Verify inline posting persists `sale_details.price` and `sale_details.unit_price` from the parent residual unit amount and `sale_details.sub_total` from the owner group subtotal.
- [x] 1.4 Assert the component-owning sale keeps `sales.total_amount`, `pos_checkout_sales.grand_total`, payment allocation, and `sale_bundle_items.sub_total` including component value.

## 2. Return Approval Preview Valuation

- [x] 2.1 Add preview planner coverage for a bundled product replacement whose source sale detail commercial amount differs from the POS return snapshot line total.
- [x] 2.2 Introduce a canonical replacement commercial amount resolver that prefers the source sale detail owner-specific commercial amount for bundled replacement lines.
- [x] 2.3 Use the canonical replacement commercial amount for preview parent detail `amount`, original sale correction amount, and generated replacement sale effects.
- [x] 2.4 Ensure same-owner and cross-owner bundled product replacement preview paths use the same valuation rule.

## 3. Approval Persistence And Execution

- [x] 3.1 Update approval plan persistence expectations so Sales Return detail `sub_total`, `unit_price`, and execution context carry the canonical replacement commercial amount.
- [x] 3.2 Update cross-owner replacement lifecycle execution to create replacement-owner Sale header, Sale detail, and SalePayment using the persisted canonical amount.
- [x] 3.3 Verify original sale correction and generated replacement-owner Sale use matching commercial amounts during cross-owner bundled replacement approval.
- [x] 3.4 Confirm atomic rollback behavior remains covered for failures after valuation is resolved.

## 4. Regression Verification

- [x] 4.1 Add or update feature tests asserting expected observed values: sale 1 parent unit price 6,085,000, sale 2 parent unit price 6,085,000, sale 2 total 6,115,000, sale 3 replacement total 6,085,000.
- [x] 4.2 Run focused POS split bundle checkout tests.
- [x] 4.3 Run focused POS return approval preview, plan persistence, and cross-owner replacement tests.
- [x] 4.4 Run a broader focused POS return suite or `php artisan test` filter covering bundled split-owner product replacement.
