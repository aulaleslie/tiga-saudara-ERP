# Proposal

## Why

In the POS, when a customer with a WHOLESALER or RESELLER tier is selected before any product is added, a newly added ordinary product line shows the base sale price instead of the customer's tier price. The tier price only appears if the customer is selected after the line exists, which reprices it. A line's initial price must already reflect the selected customer's tier.

## What Changes

- When an ordinary (non-bundle, non-packed) product line is first added to a cart that already has a tier customer selected, the line's initial unit price is the customer's tier price (WHOLESALER → tier 1 price, RESELLER → tier 2 price), falling back to the base sale price when the tier price is zero or unset.
- The line is tagged as tier-priced when the tier price was applied, consistent with lines repriced after a customer selection.
- A conversion unit chosen on an ordinary product keeps per-base-unit pricing: a tier customer pays the tier unit price with no conversion pricing.
- Unchanged: bundle pricing (no tier), packed/box pricing and its display, the product picker, draft/loaded-transaction hydration, and the existing fallback to the product's own price when no per-setting price row exists.

## Capabilities

### New Capabilities

### Modified Capabilities
- `pos-cart-management`: adds a requirement that the initial unit price of a newly added ordinary line reflects the selected customer's tier.

## Impact

- Code: ordinary-pricing branch of the add-line flow in `Modules/Pos/Services/PosCartService.php`.
- Tests: `Modules/Pos/Tests/Feature/POSCustomerTierRepricingTest.php` (customer-first ordering).
- No schema, API contract, or UI changes.
