## MODIFIED Requirements

### Requirement: Blind protection SHALL cover every existing transfer browser surface
The system SHALL apply permission-aware projections to transfer create, edit, detail, dispatch, receipt, return dispatch, return receipt, rejection/correction, archive, allocation-drift, and browser-accessible audit or export surfaces that exist in this delivery. A blind detail or lifecycle projection SHALL omit exact requested and movement quantities, serial manifests, allocation buckets, expected values, return obligations, and differences while retaining non-stock document metadata and lifecycle context needed for authorized navigation and action. Transfer detail rendering MUST NOT load or access protected route-policy, legacy line-obligation, or movement-obligation relationships for a viewer without stock visibility, and it MUST explicitly eager-load every protected relationship used for a viewer with stock visibility so rendering remains compatible with disabled lazy loading.

#### Scenario: Blind user opens transfer detail
- **WHEN** a user with `stockTransfers.show` but without stock visibility opens a transfer containing distinctive quantities, bucket allocations, obligations, and serial manifests while Eloquent lazy loading is disabled
- **THEN** the detail renders successfully without loading or accessing protected obligation relationships, contains none of those protected values, and retains permitted document, product-identity, status, actor, and timestamp context

#### Scenario: Blind user encounters allocation drift
- **WHEN** dispatch validation detects allocation drift for a user without stock visibility
- **THEN** the browser response and session contain no planned allocation, actual allocation, difference, availability figure, or protected allocation payload and present only neutral guidance

#### Scenario: Privileged user reviews lifecycle detail
- **WHEN** an otherwise-authorized user with stock visibility opens an existing lifecycle or drift surface
- **THEN** the current detailed operational comparison remains available

#### Scenario: Privileged user opens transfer detail with strict lazy loading
- **WHEN** a user with `stockTransfers.show` and stock visibility opens a transfer containing legacy line obligations or version-2 movement obligations while Eloquent lazy loading is disabled
- **THEN** the controller explicitly eager-loads the protected relationships and the detail renders the authorized obligation information without lazy-loading queries
