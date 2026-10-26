# sales-purchase-print-documents Specification

## Purpose
Provide accurate, authorized sales and purchase commercial PDFs and approved-dispatch delivery notes, with consistent business identity, tax presentation, lifecycle labels, and settlement information.

## Requirements

### Requirement: Commercial document variants remain available throughout the lifecycle
The system SHALL provide Faktur Penjualan and Nota Penawaran for sales and Pembelian and Pesanan Pembelian for purchases in every transaction status, including archived records. Variant availability SHALL NOT depend on approval or fulfillment status.

#### Scenario: Offer remains available after approval
- **WHEN** an authorized user opens a sale before or after approval, dispatch, return, rejection, or archival
- **THEN** both commercial print actions remain available and their endpoints permit printing

#### Scenario: Purchase order remains available after receiving
- **WHEN** an authorized user views a purchase in draft, pending, approved, rejected, partially received, received, returned, or archived state
- **THEN** both Pembelian and Pesanan Pembelian remain printable

### Requirement: Titles and status distinguish commercial copies
The system SHALL title the variants FAKTUR PENJUALAN, NOTA PENAWARAN, PEMBELIAN, and PESANAN PEMBELIAN respectively. Each copy SHALL identify its reference and current Indonesian transaction status, prominently marking draft, pending approval, rejected, and archived records. Archived labeling SHALL supplement the underlying status.

#### Scenario: Pending offer is printed
- **WHEN** a pending-approval sale is printed as Nota Penawaran
- **THEN** its title is NOTA PENAWARAN and Menunggu Persetujuan is prominently visible

#### Scenario: Archived copy is printed
- **WHEN** an archived approved purchase is printed
- **THEN** its reference, approved status, and archived label are visible

### Requirement: Current owning business controls printed identity
Every document SHALL use its transaction’s owning business’s current name, address, contact details, and applicable tax identity. Printing SHALL NOT substitute the active session business or hardcoded company identity, or change the session business.

#### Scenario: Global copy belongs to a different business
- **WHEN** a user authorized for business B prints its document while business A is active
- **THEN** the PDF uses B’s current identity and the session remains on A

### Requirement: Current PKP state controls tax disclosure
Commercial documents SHALL use the owning business’s current is_pkp. PKP copies SHALL disclose recorded DPP, tax amounts, applicable rates, inclusion mode, and available tax reference. Non-PKP copies SHALL hide tax identity, tax references, DPP, rates, tax amounts, and tax-mode labels while retaining recorded tax within commercial item amounts and preserving the saved grand total.

#### Scenario: Formerly taxed document printed after PKP is disabled
- **WHEN** a transaction has recorded tax but its owning business currently has is_pkp false
- **THEN** its commercial variants hide tax details and retain tax-inclusive item amounts and the saved grand total
- **AND** no stored transaction or tax data is changed

#### Scenario: PKP document has multiple rates
- **WHEN** a PKP transaction includes rows with different recorded tax rates
- **THEN** printing identifies their applicable rates and amounts without inventing a single header rate

#### Scenario: PKP document has no recorded tax
- **WHEN** the owning business is PKP but the historical document records zero tax
- **THEN** the tax summary shows zero without inventing tax or a tax reference

### Requirement: Commercial breakdown preserves monetary authority
Commercial PDFs SHALL show saved commercial rows, discounts, shipping, and a reconciled grand total. They SHALL preserve manual price and line-total authority, allocated global discounts, saved tax, and supported precision. Tax-inclusive amounts SHALL NOT have tax added twice. Historical discrepancies SHALL be identified as reconciliation differences rather than silently repricing rows or changing totals.

#### Scenario: Global discount and shipping reconcile
- **WHEN** a saved transaction contains row discounts, an overall percentage or fixed discount, and shipping
- **THEN** the PDF explains the overall discount once and adds shipping once to reconcile to the saved grand total

#### Scenario: Historical values do not reconcile
- **WHEN** recorded rows and adjustments differ from the saved grand total
- **THEN** the PDF retains saved amounts and explicitly identifies the reconciliation difference
- **AND** printing does not repair the transaction

### Requirement: Printed lines preserve product and unit semantics
Commercial documents SHALL preserve existing current-product-name display with persisted-name fallback, snapshotted purchase entered units and quantities with legacy fallback, and saved sales bundle commercial rows without duplicating their value. Product-name resolution SHALL avoid per-line queries.

#### Scenario: Converted purchase line is printed
- **WHEN** a purchase was entered as 2.500 BOX with an entered-unit discount
- **THEN** both purchase variants show 2.500 BOX, its entered-unit pricing and correctly typed discount

#### Scenario: Standalone sales bundle is printed
- **WHEN** a sale contains a standalone bundle and ordinary details
- **THEN** each commercial row appears once with its authoritative amount and current-name fallback behavior

### Requirement: Settlement appears only on invoice and purchase copies
Faktur Penjualan and Pembelian SHALL show active monetary payments, applied credits separately where supported, and the canonical live outstanding balance. Invalidated payments and their associated credits SHALL be excluded. Nota Penawaran and Pesanan Pembelian SHALL omit payment, credit-settlement, and outstanding-balance sections.

#### Scenario: Sales invoice includes active credit settlement
- **WHEN** a sale has active payments, active applied credits, and invalidated payment records
- **THEN** Faktur shows active payments and credits separately and the canonical live due amount
- **AND** Nota Penawaran omits settlement information

#### Scenario: Purchase balance is refreshed
- **WHEN** the stored purchase balance differs from its canonical active-payment balance
- **THEN** Pembelian shows the live balance and Pesanan Pembelian omits settlement

### Requirement: Printing is authorized from ordinary and Global Payments views
Print actions SHALL appear on ordinary and Global Payments detail pages for authorized users. Every endpoint SHALL enforce document-view permission and access to the owning business; cross-business Global Payments printing SHALL additionally require the applicable Global Payments access. Direct URLs SHALL enforce the same checks and archived records SHALL use authorized archived lookup.

#### Scenario: Authorized Global Payments user prints
- **WHEN** a user with Global Payments access, document-view permission, and access to the owning business opens its global detail
- **THEN** its applicable print actions work without switching the active business

#### Scenario: Forged cross-business URL is rejected
- **WHEN** a user requests a PDF for an inaccessible business or lacks document-view permission
- **THEN** the endpoint denies access without exposing document content

### Requirement: Delivery notes print approved dispatches only
Surat Jalan SHALL contain one approved dispatch’s product quantities, units, serial numbers, recipient and delivery information, and signatures without prices or settlement. The header shortcut SHALL select the approved dispatch with the greatest ID. Each approved history row SHALL offer its own print action. Pending, rejected, missing, and unrelated dispatches SHALL NOT be printable.

#### Scenario: Newer pending dispatch exists
- **WHEN** a sale has an approved dispatch followed by a newer pending or rejected dispatch
- **THEN** the header shortcut prints the latest approved dispatch only

#### Scenario: Individual dispatch is requested
- **WHEN** an authorized user prints an approved dispatch from history
- **THEN** only that dispatch’s quantities and serials appear

#### Scenario: No approved dispatch exists
- **WHEN** a sale has no approved dispatch
- **THEN** the latest-approved shortcut is disabled or absent and direct printing returns no document

#### Scenario: Dispatch does not belong to sale
- **WHEN** a URL combines a sale with another sale’s dispatch or an unapproved dispatch
- **THEN** the endpoint rejects the request

### Requirement: PDFs remain readable across pages
The system SHALL render commercial PDFs with consistent monetary precision, repeated item-table headings across pages, and intact signature blocks. Variant filenames SHALL distinguish document type and reference.

#### Scenario: Long document is printed
- **WHEN** enough rows exist to span several pages
- **THEN** headings repeat, amounts remain readable, and signature blocks are not split across pages
