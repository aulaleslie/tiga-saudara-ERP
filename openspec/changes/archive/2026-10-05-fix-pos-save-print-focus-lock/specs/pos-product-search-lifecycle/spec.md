# Spec Delta

## ADDED Requirements

### Requirement: Opening Cari Produk SHALL focus the keyword input
When the **Cari Produk** modal finishes opening, the system SHALL place focus in its keyword input, whether or not results from an earlier search are still displayed, so the cashier can type immediately.

#### Scenario: First open in a fresh POS page
- **WHEN** the cashier opens **Cari Produk** on a freshly loaded POS page
- **THEN** the keyword input has focus and accepts typing without a click

#### Scenario: Reopen with preserved results
- **WHEN** the cashier reopens **Cari Produk** while results from an earlier search in the same transaction are still displayed
- **THEN** the keyword input has focus, and the preserved result cards remain reachable with keyboard navigation

#### Scenario: Enter on a preserved result card
- **WHEN** the cashier focuses a preserved result card after reopening **Cari Produk** and presses Enter once
- **THEN** the product is added exactly once
