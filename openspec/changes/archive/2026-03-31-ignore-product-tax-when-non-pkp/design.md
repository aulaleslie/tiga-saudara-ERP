## Context

Currently, the ERP system distinguishes between PKP (Pengusaha Kena Pajak) and non-PKP businesses via the `is_pkp` setting. While the system strictly validates that PKP businesses must assign a tax to every purchased and sold product, it fails to defensively strip taxes when a business is non-PKP. As a result, product tax defaults or frontend cart anomalies can bleed into the backend, causing taxes to be calculated and saved for entities that should not be dealing with taxes.

## Goals / Non-Goals

**Goals:**
- Ensure zero tax is calculated and saved on Purchase records when `is_pkp` is false.
- Ensure zero tax is calculated and saved on Sale records when `is_pkp` is false.
- Do this robustly at the controller/service level (backend) to protect against any frontend state discrepancies.

**Non-Goals:**
- Redesigning the entire PKP toggle mechanics across other modules (e.g., POS), though POS sessions might need to be checked if they bypass `SaleService`.
- Altering the existing frontend Livewire/Alpine JS UI components in this specific change, as the backend fix is the primary ironclad solution. (Though frontend cleanups are generally good, this design focuses on backend enforcement).

## Decisions

**1. Intercept Cartesian Insertion in Controllers/Services**
We will intercept the Cartesian items immediately before they are persisted to `PurchaseDetail` or `SaleDetails`.
- *Rationale*: This is the narrowest, most effective chokepoint. 
- *Alternatives Considered*: Modifying the Cart libraries or Livewire options to never accept tax. This is fragile because `StorePurchaseRequest` also accepts Alpine arrays that bypass Livewire cart.

**2. Force `tax_id = null` and `product_tax_amount = 0`**
- *Rationale*: If `is_pkp` is false, it means the entire transaction context is non-taxable. We simply override `$cartItem['tax_id']` with `null`.

## Risks / Trade-offs

- [Risk] POS Modules might not use `SaleController` or `PurchaseController`.
  → Mitigation: The POS module uses `PosSessionController` and `FinalizePosCheckoutService`. We should review if `FinalizePosCheckoutService` correctly strips tax when non-PKP. (Actually, `pos-checkout-finalize-integration` probably covers POS, but we should add a quick check on POS finalize service).
