## MODIFIED Requirements

### Requirement: Serial Lines Have Independent Resolutions

The system SHALL represent each original sold serial for a serial-tracked product as an individually resolvable draft line. Each serial draft line MUST have one resolution value: `none`, `product_replacement`, or `cash_return`. The default resolution for serial draft lines MUST be `none`. When a user explicitly selects `none` for a serial line during draft create or edit, the system MUST preserve that explicit no-action selection and MUST NOT replace it with a header-level return option default.

#### Scenario: Serial defaults to no action
- **WHEN** a valid POS return draft is opened for a transaction with serial-tracked products
- **THEN** each source serial line defaults to the `none` resolution

#### Scenario: Different serials use different resolutions
- **WHEN** a user selects different resolutions for different sold serials of the same product in one POS Return document
- **THEN** the system persists each serial's selected resolution independently

#### Scenario: Explicit serial no-action remains no-action on edit
- **WHEN** an authorized user edits a draft POS Return
- **AND** changes one serialized source line from `cash_return` or `product_replacement` to `none`
- **AND** at least one other line remains actionable
- **THEN** the system saves the draft with that serialized source line as no-action
- **AND** the system does not convert that line back to the POS Return header `return_option`

### Requirement: Draft And Rejected Edit Rules

The system SHALL allow POS Returns in `draft` status and draft approval state to be edited. Editing MUST revalidate source snapshot freshness and replacement serial availability. Draft edit MUST rebuild the draft from the submitted line selections while treating explicit line-level `none` as authoritative. Header-level `return_option` defaults MUST NOT override an explicit `none` selection. This change does not add rejected return edit behavior; rejected returns MUST NOT use the draft edit action introduced by this change.

#### Scenario: Edit draft return
- **WHEN** an authorized user edits and saves a draft POS Return
- **THEN** the system updates the draft header and rebuilds draft lines from the submitted selections
- **AND** no execution-side mutation occurs

#### Scenario: Draft edit action rejects rejected return
- **WHEN** a user attempts to use the draft edit action for a rejected POS Return
- **THEN** the system blocks the draft edit action
- **AND** keeps the POS Return status and approval status unchanged

#### Scenario: Partial no-action edit remains valid
- **WHEN** an authorized user edits a draft POS Return that contains multiple serialized lines
- **AND** changes one returned serial line to `none`
- **AND** leaves at least one other line as `cash_return` or `product_replacement`
- **THEN** the system saves the edit successfully
- **AND** the `none` line no longer contributes expected cash amount, replacement serial requirement, bundle execution trace, or actionable line count
- **AND** no Sales Return, stock, dispatch, payment, or serial execution mutation occurs

#### Scenario: All no-action edit is rejected
- **WHEN** an authorized user edits a draft POS Return
- **AND** every submitted source line has `none` resolution or no positive actionable quantity
- **THEN** the system rejects the save with a clear validation message requiring at least one return action
- **AND** the existing draft lines remain unchanged
- **AND** no Sales Return, stock, dispatch, payment, or serial execution mutation occurs
