# pos-product-search-lifecycle

## Purpose

Defines the lifetime of POS product-search state so repeated searches remain efficient within one transaction and do not leak into a newly established transaction context.

## Requirements

### Requirement: Product search state SHALL persist within the current POS transaction
The POS `Cari Produk` modal SHALL preserve its current search query and rendered result state while the user continues working in the same POS transaction.

#### Scenario: Reopen search after selecting a product
- **WHEN** a user searches for products, selects a result, and reopens `Cari Produk` without crossing a transaction boundary
- **THEN** the modal SHALL display the previous query and result state

#### Scenario: Reopen search without selecting a product
- **WHEN** a user closes and reopens `Cari Produk` during the same transaction
- **THEN** the modal SHALL display the previous query and result state

#### Scenario: Non-boundary cart action preserves search
- **WHEN** a user removes a product, changes the customer, or cancels or closes a checkout flow without completing it
- **THEN** the current product-search query and result state SHALL remain unchanged

### Requirement: Successful transaction boundaries SHALL reset product search state
The POS SHALL clear the product-search query and rendered result state when a successful operation establishes a new transaction context. The reset SHALL occur for completed regular checkout, completed staged or multi-payment checkout, successful save-as-draft-and-new, successful cart clearing (`Kosongkan Keranjang`), and successful draft loading.

#### Scenario: Regular checkout completes
- **WHEN** a regular POS checkout completes successfully
- **THEN** the next opening of `Cari Produk` SHALL show an empty query and initial empty-search state

#### Scenario: Staged checkout completes
- **WHEN** a staged or multi-payment POS checkout completes successfully
- **THEN** the next opening of `Cari Produk` SHALL show an empty query and initial empty-search state

#### Scenario: Transaction is saved as draft and a new transaction is opened
- **WHEN** save-as-draft-and-new completes successfully
- **THEN** the next opening of `Cari Produk` SHALL show an empty query and initial empty-search state

#### Scenario: Cart is successfully cleared
- **WHEN** `Kosongkan Keranjang` completes successfully
- **THEN** the next opening of `Cari Produk` SHALL show an empty query and initial empty-search state

#### Scenario: Draft transaction is loaded
- **WHEN** a drafted POS transaction is loaded successfully into the sell page
- **THEN** `Cari Produk` SHALL start with an empty query and initial empty-search state for the loaded transaction

### Requirement: Unsuccessful boundaries SHALL preserve product search state
The POS SHALL reset product-search state only after confirmed success and SHALL preserve it when checkout, save-as-draft-and-new, or draft loading fails or is cancelled.

#### Scenario: Checkout fails
- **WHEN** regular or staged checkout fails before completion
- **THEN** the product-search query and result state SHALL remain unchanged

#### Scenario: Save as draft fails
- **WHEN** save-as-draft-and-new fails
- **THEN** the product-search query and result state SHALL remain unchanged

#### Scenario: Draft loading fails
- **WHEN** loading a drafted transaction fails
- **THEN** the existing transaction's product-search query and result state SHALL remain unchanged

#### Scenario: Cart clearing fails, is cancelled, or awaits supervisor approval
- **WHEN** `Kosongkan Keranjang` fails, is cancelled, or is still pending supervisor approval
- **THEN** the product-search query and result state SHALL remain unchanged

### Requirement: Reset SHALL reject stale search responses
Resetting product-search state at a successful transaction boundary SHALL prevent any search request started in the previous transaction context from rendering results afterward.

#### Scenario: Previous search response arrives after reset
- **WHEN** a pending product-search request from the previous transaction returns after a successful transaction-boundary reset
- **THEN** the response SHALL be ignored
- **AND** the new transaction's product-search state SHALL remain empty
