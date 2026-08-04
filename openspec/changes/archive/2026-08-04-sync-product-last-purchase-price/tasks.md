## 1. Candidate Resolution

- [x] 1.1 Extend the HPP seeding command to resolve the Perdana setting explicitly alongside Tiga Nusa and Top IT settings.
- [x] 1.2 Load eligible received purchase details in product chunks and group deterministic literal-purchase candidates by product and setting.
- [x] 1.3 Calculate each selected literal purchase candidate's tax-inclusive, discount-excluded unit price from subtotal, line discount, and quantity.
- [x] 1.4 Resolve each target setting's last-purchase-price candidate by preferring its own latest purchase and then Perdana's latest purchase.
- [x] 1.5 Restrict HPP fallback resolution to the named Tiga Nusa, Top IT, and Perdana source settings without changing snapshot value selection.

## 2. Product Price Reconciliation

- [x] 2.1 Update existing `product_prices` rows with the independently resolved average HPP value and literal last purchase price while preserving selling, tier, and tax fields.
- [x] 2.2 Create missing `product_prices` rows only when both required source values resolve, retaining the existing same-product metadata-template behavior.
- [x] 2.3 Preserve an existing last purchase price and avoid missing-row creation when no own or Perdana literal-purchase fallback exists.
- [x] 2.4 Keep dry-run mode non-mutating and update command output as needed to distinguish created, updated, unchanged, and skipped reconciliation results.

## 3. Verification

- [x] 3.1 Update focused command tests to assert that existing rows receive the literal received-purchase last price while average price remains sourced from the latest imported HPP snapshot.
- [x] 3.2 Add tests for tax-inclusive, discount-excluded literal purchase calculation and deterministic latest-receipt ordering.
- [x] 3.3 Add tests proving own purchase precedence and Perdana fallback for Tiga Nusa, Top IT, Perdana, and other businesses.
- [x] 3.4 Add tests proving arbitrary non-special businesses cannot become HPP or last-price defaults, and missing literal purchase sources do not write zero or create incomplete rows.
- [x] 3.5 Run the focused Product-module command test suite and the relevant full test command if practical.
