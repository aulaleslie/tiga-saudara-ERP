## MODIFIED Requirements

### Requirement: Privileged users can edit fulfilled document monetary values in place
The system SHALL allow a user with ordinary edit authority plus `purchases.received.monetary.edit` to edit a Purchase in `RECEIVED` or `RECEIVED PARTIALLY` status, and a user with ordinary edit authority plus `sales.dispatched.monetary.edit` to edit a Sale in `DISPATCHED` or `DISPATCHED PARTIALLY` status. The edit SHALL persist supported monetary values directly to the existing document header and detail rows. Outside Global Payment, the document MUST belong to the active setting. In a dedicated Global Payment route, the user MUST additionally have the corresponding global-payment access permission and the system MUST use the document's actual setting without weakening the normal route guard.

#### Scenario: Authorized user changes a received purchase line price
- **WHEN** an authorized user saves a monetary edit for an eligible received Purchase
- **THEN** the system SHALL update the allowed monetary values on the existing Purchase and PurchaseDetail records
- **AND** the system SHALL retain every existing PurchaseDetail primary key

#### Scenario: Authorized user changes a dispatched sale monetary value
- **WHEN** an authorized user saves a monetary edit for an eligible dispatched Sale
- **THEN** the system SHALL update the allowed monetary values on the existing Sale and SaleDetails records
- **AND** the system SHALL retain every existing SaleDetails primary key

#### Scenario: Authorized global user edits a cross-setting fulfilled document
- **WHEN** a user has the applicable Global Payment access, ordinary edit, and lifecycle monetary-edit permissions and submits the dedicated global monetary form for an eligible document outside the active setting
- **THEN** the system SHALL apply the same monetary-only validation and persistence behavior using the document's actual setting
- **AND** it SHALL return the user to a safe destination in the originating Global Payment workspace

#### Scenario: Full edit mode is not exposed globally
- **WHEN** a Global Payment detail refers to an approved but unfulfilled document whose ordinary edit mode would be full
- **THEN** the global context SHALL NOT expose the full edit form
- **AND** the user SHALL use the normal setting-scoped workflow for any full document edit

#### Scenario: Unauthorized user is denied
- **WHEN** a user lacks ordinary edit authority, the applicable post-fulfillment monetary permission, or—on a global route—the applicable Global Payment access permission and attempts to open or save an eligible document
- **THEN** the system SHALL deny the action at the backend
- **AND** the system SHALL not change the document

#### Scenario: Normal cross-setting monetary edit remains denied
- **WHEN** a non–Super Admin user submits the ordinary monetary edit route for a document outside the active setting
- **THEN** the existing setting ownership guard SHALL deny the request
- **AND** possession of Global Payment access SHALL not alter that result
