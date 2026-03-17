# pos-multi-payment-checkout-persistence Specification

## Purpose

Defines how multi-payment checkouts are persisted to the ledger with proper primary payment method assignment and individual payment records for accurate split group allocation.

## ADDED Requirements

### Requirement: Primary Payment Method Extraction for Multi-Payment Checkouts

When a checkout has multiple payment methods, the system SHALL extract the first payment method from the normalized payments sequence and store it as the "primary" payment method on the `pos_checkouts` record. This primary method serves as the main reference for the checkout while detailed payment information is stored separately in `pos_checkout_payments` records.

#### Scenario: First payment method is extracted as primary
- **WHEN** user submits a multi-payment checkout with payments: [Card 100,000, Cash 50,000]
- **THEN** the `pos_checkouts` record stores `payment_method_id` referencing the Card payment method (first in sequence)

#### Scenario: Primary payment reference is captured
- **WHEN** user submits a multi-payment checkout with Card (reference: "TXN123") and Cash (no reference)
- **THEN** the `pos_checkouts.payment_reference` stores "TXN123" from the primary (first) Card payment

#### Scenario: Multi-payment checkout ledger is created successfully
- **WHEN** the finalization service processes a multi-payment payload with valid payments and primary method
- **THEN** no "Undefined array key 'payment_method_id'" error occurs and the checkout ledger is persisted

### Requirement: Individual Payment Records Preservation

When a multi-payment checkout is finalized, the system SHALL persist each individual payment as a separate record in the `pos_checkout_payments` table with complete metadata (method ID, amount, reference, sequence order).

#### Scenario: Multiple payments are stored in pos_checkout_payments
- **WHEN** a multi-payment checkout (3 payments) is finalized
- **THEN** three separate records exist in `pos_checkout_payments`, each with payment_method_id, amount_minor_units, reference, and sequence_order

#### Scenario: Payment sequence order is preserved
- **WHEN** a multi-payment checkout has payments submitted in order: BRI (1M), BNI (1M), Cash (50k)
- **THEN** `pos_checkout_payments` records have sequence_order 0, 1, 2 respectively

### Requirement: Consistency with Posting Adapter Pattern

The finalization service extraction logic SHALL match the pattern already implemented in `InlinePosCheckoutPostingAdapter` to ensure consistent "first payment as primary" semantics across the entire finalization flow.

#### Scenario: First payment method used consistently
- **WHEN** `normalizeMultiPayment()` returns normalized structure with first payment as primary
- **THEN** `resolveCheckoutLedger()` uses the same first payment method without re-extracting or applying different logic

#### Scenario: No divergence between finalization and posting
- **WHEN** a multi-payment checkout is finalized and posted
- **THEN** the primary payment method stored on `pos_checkouts` matches the method used by the posting adapter for the sale_payment record
