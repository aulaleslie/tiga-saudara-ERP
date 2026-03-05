# Current POS Supported Brainstorm

Context:
- Based on current POS module capabilities in this codebase.
- Aligned with current UI direction: theme-adaptive colors, no ecommerce integration, no on-screen number pad.

## Core Cashier Capabilities We Can Support

1. Cashier shell with product list table, totals, and payment CTA.
2. Product search/scan by barcode, SKU, and name.
3. PLU-style product list from existing product search endpoint.
4. Customer/member search by name/phone.
5. Customer fallback to configured walk-in customer.
6. Cart line operations:
   - quantity update
   - line discount
   - remove line
7. Bill-level discount (fixed or percentage).
8. Checkout methods:
   - cash
   - transfer
   - qris
9. Payment validation:
   - non-cash requires reference
   - cash must be fully paid
10. Cash change calculation.
11. Receipt flow:
   - receipt preview/view
   - print
   - reprint

## Session and Cash Control We Can Support

1. Open POS session with opening float.
2. Enforce active session before entering sell flow.
3. Close session with counted cash and variance capture.
4. Safe drop workflow in active session.
5. Threshold-based cash monitoring per terminal policy.
6. Supervisor approval flows for sensitive actions (e.g., price override / variance close depending policy).

## Monitoring, Reporting, and Back Office We Can Support

1. Active session monitor page.
2. POS reports:
   - daily sales
   - cashier summary
   - payment method summary
   - item sales summary
   - supervisor approval log
3. POS reconciliation page (POS totals vs posted sales/payments).
4. Terminal registry and policy management.

## UI Ideas That Fit Current Backend

1. Theme-adaptive POS UI using existing theme tokens/classes.
2. Item rows with `- / +` quantity controls mapped to current line update endpoint.
3. Payment slide with receipt preview panel.
4. Quick-cash preset chips (e.g., 50k/100k) that fill amount paid.
5. No numeric keypad in payment slide (intentionally omitted).
6. Bottom shortcuts wired to existing routes:
   - Reprint
   - Lap. Sales
   - Retur (deep-link to existing returns module)
   - Lainnya (sessions/monitor/reconciliation/terminals)

## Things Still Not Supported in Current POS Flow

1. Ecommerce order integration/polling.
2. Promo sponsor engine panels and calculations.
3. Pending/parked/suspended cart workflow.
4. Clerk handover flow from cashier UI.
5. Non-commerce transaction flow in POS cashier screen.
6. Split payment in one transaction.
7. Dedicated "Load Data" flow from cashier screen.
