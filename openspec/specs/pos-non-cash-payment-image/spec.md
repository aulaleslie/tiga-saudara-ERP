# pos-non-cash-payment-image Specification

## Purpose
TBD - created by archiving change add-pos-checkout-note-payment-image. Update Purpose after archive.
## Requirements
### Requirement: Non-cash payment stages accept one optional image
The staged-payment interface SHALL display an optional image input only when the selected payment method is non-cash. The field SHALL accept at most one JPEG or PNG image up to 5 MB, and omission of an image SHALL never prevent staging or finalizing the payment.

#### Scenario: Non-cash payment without image
- **WHEN** a cashier stages a valid non-cash payment without selecting an image
- **THEN** the system accepts the payment subject to its existing amount and reference rules

#### Scenario: Non-cash payment with valid image
- **WHEN** a cashier selects one valid JPEG or PNG image no larger than 5 MB for a non-cash payment
- **THEN** the system uploads the image and associates its opaque token with that payment stage

#### Scenario: Cash payment selected
- **WHEN** a cashier selects a cash payment method
- **THEN** the staged-payment interface hides and clears the image input

#### Scenario: Invalid image is supplied
- **WHEN** a cashier uploads an unsupported file type, more than one file, or an image larger than 5 MB
- **THEN** the system rejects the upload with an actionable validation message without committing the payment stage

### Requirement: Image optionality is independent from payment reference rules
The presence or absence of a payment image SHALL NOT change whether an EDC or other payment reference is required. Existing `requires_reference` behavior SHALL continue to govern the reference independently.

#### Scenario: Reference-required method has no image
- **WHEN** a non-cash method requires a reference and the cashier provides the reference but no image
- **THEN** the payment stage is valid

#### Scenario: Optional-reference method has an image
- **WHEN** a non-cash method does not require a reference and the cashier provides an image without a reference
- **THEN** the payment stage is valid

#### Scenario: Required reference is missing despite image
- **WHEN** a non-cash method requires a reference and the cashier provides an image but no reference
- **THEN** the system rejects the payment under the existing reference requirement

### Requirement: Temporary image tokens are scoped and recoverable
Before checkout finalization, the system SHALL store an uploaded image as a temporary record scoped to the authenticated cashier, setting, active POS session, cart token, and expiry time. Payment-chain recovery SHALL preserve the image token and display metadata for its originating stage without storing binary data in the Laravel session.

#### Scenario: Page reload recovers staged image
- **WHEN** a cashier reloads the POS page after committing a non-cash stage with an image
- **THEN** the recovered payment chain retains that stage's image association

#### Scenario: Token belongs to another cart or cashier
- **WHEN** a staged-payment request supplies an image token outside its cashier, setting, session, or cart-token scope
- **THEN** the system rejects the token and does not commit it to the payment chain

#### Scenario: Optional token is expired
- **WHEN** a staged-payment request supplies an expired or already consumed image token
- **THEN** the system rejects that supplied token with an actionable error

#### Scenario: Cash stage supplies an image token
- **WHEN** a staged-payment request supplies an image token for a cash payment method
- **THEN** the system rejects the token and does not associate an image with the cash stage

### Requirement: Images attach to corresponding generated Sale Payments
During successful checkout finalization, the system SHALL attach the image from a non-cash payment stage to every `SalePayment` generated from that stage. The system SHALL use the existing single-file `attachments` media collection and SHALL NOT permanently attach the image to the POS checkout or POS checkout payment record.

#### Scenario: Inline checkout creates one payment
- **WHEN** a non-cash stage with an image produces one Sale Payment
- **THEN** that Sale Payment contains one copy of the image in its `attachments` collection

#### Scenario: Non-cash stage has no image
- **WHEN** a non-cash stage without an image produces one or more Sale Payments
- **THEN** those Sale Payments receive no attachment from that stage

### Requirement: Split posting duplicates an image across payments from the same stage
When one POS payment stage produces multiple Sale Payments because checkout posting creates multiple Sales, the system SHALL create an independent copy of that stage's image on every corresponding Sale Payment.

#### Scenario: One stage is split across two Sales
- **WHEN** one non-cash stage with an image produces Sale Payments for two owner-aligned Sales
- **THEN** both Sale Payments contain an independent copy of the same image

#### Scenario: One stage is split across several Sales
- **WHEN** one non-cash stage with an image produces more than two Sale Payments
- **THEN** every Sale Payment mapped to that stage contains exactly one image copy

### Requirement: Images remain isolated by payment stage
The system MUST correlate generated Sale Payments using the originating payment stage order and MUST NOT attach one stage's image to a Sale Payment generated from another stage, even when stages use the same payment method.

#### Scenario: Two different non-cash methods have different images
- **WHEN** a checkout contains a BRI stage with image A and a BNI stage with image B
- **THEN** BRI-originated Sale Payments receive only image A and BNI-originated Sale Payments receive only image B

#### Scenario: Repeated method uses separate stages
- **WHEN** two non-cash stages use the same payment method but provide different images
- **THEN** each generated Sale Payment receives only the image associated with its originating stage order

#### Scenario: Cash follows a non-cash stage
- **WHEN** a checkout contains a non-cash stage with an image followed by a cash stage
- **THEN** no Sale Payment generated from the cash stage receives the non-cash image

### Requirement: Finalization and cleanup are retry-safe
The system SHALL include image-stage association in checkout idempotency comparison, SHALL avoid adding duplicate attachments during a posted-checkout replay, and SHALL retain temporary sources long enough for a bounded retry after failed finalization. Reset and expiry cleanup SHALL remove unconsumed temporary images.

#### Scenario: Posted checkout is replayed
- **WHEN** an already posted checkout with attached payment images is replayed using the same idempotency key and payload
- **THEN** the system returns the existing checkout result without adding another attachment

#### Scenario: Idempotency key is reused with different image evidence
- **WHEN** a checkout idempotency key is reused with a different image token for any payment stage
- **THEN** the system rejects the conflicting payload

#### Scenario: Finalization fails before commit
- **WHEN** checkout finalization fails after payment images have been staged
- **THEN** the temporary image associations remain available for a bounded retry and no posted checkout is reported

#### Scenario: Payment chain is reset
- **WHEN** a cashier resets the complete staged-payment chain
- **THEN** the system removes unconsumed temporary images scoped to that cart token

#### Scenario: Temporary upload expires
- **WHEN** an unconsumed temporary image passes its expiry time
- **THEN** scheduled cleanup removes its temporary file and record

### Requirement: Committed stage images survive later-stage transitions
After the system commits a non-cash payment stage with an image, that image token SHALL be owned by the committed payment chain until finalization consumes it, the complete payment chain is reset, or expiry cleanup removes an unconsumed upload. Resetting the active stage form or selecting a later payment method MUST NOT delete it.

#### Scenario: Cash follows an attached transfer stage
- **WHEN** a cashier commits a transfer stage with image evidence and then selects Cash for the remaining balance
- **THEN** the transfer image remains available for checkout finalization
- **AND** the Cash stage has no image token

#### Scenario: Finalization attaches only the transfer image
- **WHEN** the transfer-with-image stage followed by Cash finalizes successfully
- **THEN** the Transfer Sale Payment receives the image in its `attachments` collection
- **AND** the Cash Sale Payment receives no attachment

#### Scenario: Active-form reset after successful stage
- **WHEN** a non-cash stage with an image is successfully committed and the UI prepares the next payment stage
- **THEN** the active form clears its local attachment selection
- **AND** the committed stage's temporary image is not deleted

