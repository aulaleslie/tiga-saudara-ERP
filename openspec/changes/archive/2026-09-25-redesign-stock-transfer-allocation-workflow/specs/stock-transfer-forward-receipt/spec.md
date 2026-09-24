# Spec Delta

## ADDED Requirements

### Requirement: Version 3 receiving uses a single explicit confirmation
For version 3 only, receiving SHALL display the dispatched product list and quantities without route configuration and offer Terima Barang followed by a Bahasa Indonesia confirmation modal. Confirmation SHALL state that all listed goods must have been counted and received in full. The receiver SHALL not enter quantities, scan goods, submit a count, or seek a second receipt approval. The acting user SHALL hold receiving permission in the active business; self-receiving SHALL be allowed.

#### Scenario: Open receipt confirmation
- **WHEN** an authorized receiver clicks Terima Barang for a dispatched document
- **THEN** the modal says "Pastikan seluruh barang telah dihitung dan jumlahnya sesuai dengan daftar pada dokumen ini. Dengan mengonfirmasi, Anda menyatakan seluruh barang telah diterima lengkap." and offers Batal and Konfirmasi Penerimaan

#### Scenario: Confirm full receipt
- **WHEN** the receiver confirms a still-dispatched document
- **THEN** all approved destination effects commit atomically, receiver and time are recorded, and status becomes COMPLETED

#### Scenario: Dismiss confirmation or goods are incomplete
- **WHEN** the receiver dismisses the modal or does not confirm complete delivery
- **THEN** the document stays DISPATCHED and no receiving stock is posted

#### Scenario: Legacy receipt remains operational
- **WHEN** a version 1 or 2 document requires receipt after this change
- **THEN** its existing receiving rules remain authoritative

### Requirement: Version 3 receipt has no partial or mismatch workflow
Version 3 SHALL support one complete receipt only and SHALL not create blind counts, recount attempts, mismatch flags, partial receipts, or discrepancy-acceptance paths. A confirmation SHALL represent the actor's declaration of complete delivery, not independently recorded count evidence.

#### Scenario: Attempt partial receipt
- **WHEN** a client tries to receive a subset of a version 3 dispatch
- **THEN** no partial destination movement or partially received status is permitted
