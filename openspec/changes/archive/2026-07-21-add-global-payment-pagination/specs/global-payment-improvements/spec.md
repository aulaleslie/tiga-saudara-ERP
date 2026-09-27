## ADDED Requirements

### Requirement: Global Payment Invoice External Number
The system SHALL display the external supplier purchase number (`supplier_purchase_number`) alongside the internal transaction number in the global payment invoice list.

#### Scenario: Viewing unpaid invoices for a supplier
- **WHEN** user views the global purchase payment creation page for a supplier
- **THEN** the table displays a new column showing the `supplier_purchase_number` for each invoice

### Requirement: Global Payment Invoice Pagination
The system SHALL use client-side pagination to divide the list of unpaid invoices into pages.

#### Scenario: Paginating through invoices
- **WHEN** user views a supplier with more than 10 unpaid invoices
- **THEN** the invoice table displays only 10 rows per page by default
- **THEN** the user can navigate to the next page to see the remaining invoices

#### Scenario: Changing rows per page
- **WHEN** user interacts with the rows-per-page dropdown on the invoice table
- **THEN** the user can select 10, 25, 50, 100, or "All" rows to display per page

### Requirement: Global Payment Cross-Page Submission
The system SHALL correctly serialize and submit all invoice allocation inputs across all pagination pages.

#### Scenario: Submitting allocations from multiple pages
- **WHEN** user inputs an allocation amount on page 1, navigates to page 2, inputs an allocation amount on page 2, and submits the form
- **THEN** the form successfully submits both allocation amounts from page 1 and page 2 to the server
