# POS Return Draft Resolutions Specification

## Purpose

The POS Return draft workflow provides users with authoring permission a way to construct, validate, and submit return intent before approval. This specification defines requirements for draft creation, submission for approval, editing, and deletion of draft and rejected POS Returns, including line-level independent resolution handling for serial-tracked products, preservation of explicit no-action selections, and authorization boundaries between authoring and approval roles.
## Requirements
### Requirement: Draft Submit Uses Authoring Permission And Locks Pending Approval
The system SHALL allow users with POS Return draft authoring permission to submit a valid draft POS Return for approval. Submit-for-approval MUST require either `pos.returns.create` or `pos.returns.edit`; approver decision endpoints MUST still require `pos.returns.approve`. After submission, the POS Return MUST enter `pending_approval` status and `pending` approval status and MUST NOT be editable or deletable.

#### Scenario: Draft author submits return for approval
- **WHEN** a user with `pos.returns.edit` or `pos.returns.create` submits a valid draft POS Return for approval
- **THEN** the system changes the POS Return to `pending_approval` status and `pending` approval status
- **AND** no Sales Return, stock, dispatch, payment, replacement dispatch, serial execution, or inventory transaction mutation occurs

#### Scenario: Pending approval return is locked from revision
- **WHEN** a POS Return is in `pending_approval` status and `pending` approval status
- **THEN** the system blocks edit and delete actions for the POS Return
- **AND** the list and detail pages do not show edit or delete actions for that POS Return

#### Scenario: Approver permission remains required for approval decision
- **WHEN** a user without `pos.returns.approve` attempts to approve or reject a pending POS Return
- **THEN** the system denies the approval or rejection action
- **AND** keeps the POS Return status and approval status unchanged

### Requirement: Bundle Draft Resolutions SHALL Separate Refund And Replacement Eligibility
The system SHALL support whole-bundle and explicitly selected parent-only cash returns while preserving existing independent product-replacement eligibility. Whole-bundle cash return MUST include every original component proportionally. All identities MUST derive from the original persisted transaction.

#### Scenario: Whole bundle quantity is selected for cash return
- **WHEN** a user selects whole-bundle cash return for one or more units of a bundled POS line
- **THEN** the draft SHALL include the selected parent quantity and the proportional quantity of every originally fulfilled component
- **AND** the expected refund SHALL equal the original captured customer bundle amount for that quantity

#### Scenario: Bundle parent alone can be explicitly refunded
- **WHEN** a user explicitly selects parent-only cash return for a bundle parent
- **THEN** the draft SHALL include only the selected parent quantity at its captured allocated value
- **AND** retained components and their allocated charges SHALL remain unchanged

#### Scenario: Bundle parent alone cannot be refunded
- **WHEN** whole-bundle scope cannot resolve every original component for the selected quantity
- **THEN** the draft SHALL reject the selection with an actionable error

#### Scenario: Bundle component alone cannot be refunded
- **WHEN** a user attempts cash return for an individual bundle component
- **THEN** the draft SHALL reject the selection
- **AND** the component's internal allocation SHALL NOT become a customer refund amount

#### Scenario: Bundle parent can be replaced independently
- **WHEN** a user selects product replacement for a bundle parent
- **THEN** the draft SHALL allow the parent replacement without selecting its components
- **AND** the remaining bundle composition SHALL have no action

#### Scenario: Bundle component can be replaced independently
- **WHEN** a user selects product replacement for a persisted bundle component
- **THEN** the draft SHALL retain the exact SaleBundleItem, dispatch, owner, location, product, and serial lineage for that component
- **AND** the parent and other components SHALL have no action

### Requirement: Bundle cash-return scope is explicit and durable
New bundle cash-return selections SHALL default to `whole_bundle`. Users SHALL be able to explicitly choose `parent_only` per selected parent and quantity. The system MUST preserve scope through create, edit, submit, approval, and detail display, and MUST reject unknown scope values. Existing returns without scope MUST NOT be inferred as intentional parent-only selections.

#### Scenario: Whole bundle is the default
- **WHEN** a user selects cash return for an original bundle
- **THEN** whole bundle is selected and its included components are visible

#### Scenario: Parent-only choice survives editing
- **WHEN** a user saves and reopens an explicitly parent-only draft
- **THEN** parent-only remains selected and retained component charges are visible

#### Scenario: Partial selected quantity
- **WHEN** a user returns one of three bundles with two components per bundle
- **THEN** whole bundle selects one parent and two components, while parent only selects one parent

#### Scenario: Legacy pending draft lacks scope
- **WHEN** a pending legacy bundle return has no explicit scope
- **THEN** final approval is blocked until an authorized user reviews and resubmits explicit scope
- **AND** completed historical returns remain unchanged

#### Scenario: Whole-bundle vs parent-only charge consequences
- **GIVEN** a bundled sale with customer bundle price Rp890,000 comprising parent camera (allocated Rp630,000) and component microSD (allocated Rp260,000)
- **WHEN** `whole_bundle` scope is selected for cash return
- **THEN** the expected reduction/refund equals the full Rp890,000 customer bundle amount and all components are reversed
- **WHEN** `parent_only` scope is selected for cash return
- **THEN** the expected reduction/refund equals the parent allocated amount of Rp630,000
- **AND** the component microSD charge of Rp260,000 is retained without reversal

### Requirement: Replacement Draft SHALL Declare Serial Or Note-Only Execution
Each product-replacement draft line SHALL derive execution mode from the persisted product's serial-tracking classification. Serial-tracked lines MUST capture serial replacement identity; non-serial lines MUST be recorded as note-only replacement intent without promising automatic inventory movement.

#### Scenario: Serial replacement selects two serial identities
- **WHEN** a user selects replacement for a serial-tracked ordinary product, bundle parent, or bundle component
- **THEN** the draft SHALL require the exact originally sold serial and an eligible replacement serial of the same product
- **AND** it SHALL identify the action as a serial inventory replacement

#### Scenario: Non-serial replacement records note intent
- **WHEN** a user selects replacement for a non-serial ordinary product, bundle parent, or bundle component
- **THEN** the draft SHALL require product, quantity, reason, and origin identity without a replacement serial
- **AND** it SHALL identify the action as note-only with physical exchange and breakage handled manually

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

The system SHALL allow POS Returns in `draft` status with draft approval state and POS Returns in `rejected` status with rejected approval state to be edited by authorized users. Editing MUST revalidate source snapshot freshness and replacement serial availability. Edit save MUST rebuild the draft from the submitted line selections while treating explicit line-level `none` as authoritative. Header-level `return_option` defaults MUST NOT override an explicit `none` selection. A successful rejected edit save MUST reset the POS Return to `draft` status and draft approval state while preserving rejection audit fields. POS Returns in `pending_approval`, approved, execution, completed, archived, cancelled, or manual-correction states MUST NOT be editable through this action.

#### Scenario: Edit draft return
- **WHEN** an authorized user edits and saves a draft POS Return
- **THEN** the system updates the draft header and rebuilds draft lines from the submitted selections
- **AND** keeps the POS Return in `draft` status and draft approval state
- **AND** no execution-side mutation occurs

#### Scenario: Edit rejected return resets to draft
- **WHEN** an authorized user edits and saves a rejected POS Return
- **THEN** the system updates the header and rebuilds draft lines from the submitted selections
- **AND** changes the POS Return to `draft` status and draft approval state
- **AND** preserves existing rejection audit fields for traceability
- **AND** no Sales Return, stock, dispatch, payment, replacement dispatch, serial execution, or inventory transaction mutation occurs

#### Scenario: Pending return cannot use edit action
- **WHEN** a user attempts to edit a POS Return in `pending_approval` status and `pending` approval status
- **THEN** the system blocks the edit action
- **AND** keeps the POS Return status and approval status unchanged

#### Scenario: Partial no-action edit remains valid
- **WHEN** an authorized user edits a draft or rejected POS Return that contains multiple serialized lines
- **AND** changes one returned serial line to `none`
- **AND** leaves at least one other line as `cash_return` or `product_replacement`
- **THEN** the system saves the edit successfully
- **AND** the `none` line no longer contributes expected cash amount, replacement serial requirement, bundle execution trace, or actionable line count
- **AND** no Sales Return, stock, dispatch, payment, replacement dispatch, serial execution, or inventory transaction mutation occurs

#### Scenario: All no-action edit is rejected
- **WHEN** an authorized user edits a draft or rejected POS Return
- **AND** every submitted source line has `none` resolution or no positive actionable quantity
- **THEN** the system rejects the save with a clear validation message requiring at least one return action
- **AND** the existing POS Return lines remain unchanged
- **AND** the existing POS Return status and approval status remain unchanged
- **AND** no Sales Return, stock, dispatch, payment, replacement dispatch, serial execution, or inventory transaction mutation occurs

### Requirement: Draft And Rejected Delete Rules
The system SHALL allow authorized users to hard-delete POS Returns in `draft` status with draft approval state because those documents have no approval history. The system SHALL allow authorized users to delete POS Returns in `rejected` status with rejected approval state only through audited soft delete. Rejected soft delete MUST record the deleting actor and MAY record a delete reason. POS Returns in `pending_approval`, approved, execution, completed, archived, cancelled, or manual-correction states MUST NOT be deleted through draft or rejected delete actions.

#### Scenario: Draft return hard delete
- **WHEN** an authorized user deletes a POS Return in `draft` status and draft approval state
- **THEN** the system permanently removes the POS Return draft and its draft lines
- **AND** no Sales Return, stock, dispatch, payment, replacement dispatch, serial execution, or inventory transaction mutation occurs

#### Scenario: Rejected return audited soft delete
- **WHEN** an authorized user deletes a POS Return in `rejected` status and rejected approval state
- **THEN** the system soft-deletes the POS Return
- **AND** records the deleting actor
- **AND** records the delete reason when one is provided
- **AND** preserves rejection audit fields and draft line history in the soft-deleted record
- **AND** no Sales Return, stock, dispatch, payment, replacement dispatch, serial execution, or inventory transaction mutation occurs

#### Scenario: Pending return cannot be deleted
- **WHEN** a user attempts to delete a POS Return in `pending_approval` status and `pending` approval status
- **THEN** the system blocks the delete action
- **AND** keeps the POS Return status and approval status unchanged

#### Scenario: Approved or terminal return cannot use rejected delete
- **WHEN** a user attempts to delete an approved, execution, completed, archived, cancelled, or manual-correction POS Return through the draft/rejected delete action
- **THEN** the system blocks the delete action
- **AND** keeps the POS Return status and approval status unchanged

### Requirement: Draft valuation uses the persisted resolution intent
POS draft create and edit SHALL use the same normalized line resolutions, quantities, bundle scope, and serial identities for valuation and persistence. A supported header default applied to an omitted resolution MUST also govern valuation. Explicit line-level none MUST remain authoritative and contribute no return value or execution effects.

#### Scenario: Omitted line resolution uses cash default
- **WHEN** create or edit submits a supported cash-return header default and omits a positive line's resolution
- **THEN** the normalized cash-return choice governs line valuation, expected amount, and header total consistently
- **AND** the line does not receive zero value merely because its original payload omitted the resolution

#### Scenario: Explicit none survives header default
- **WHEN** a line explicitly selects none while another line remains actionable under a cash-return header
- **THEN** the none line stays unselected with zero expected refund and no execution effects

#### Scenario: Valuation evidence cannot be established
- **WHEN** normalized intent has no reliable source valuation
- **THEN** save or approval reports an actionable error rather than silently using an undefined or inconsistent fallback amount

#### Scenario: Draft edit preserves established POS rules
- **WHEN** an authorized user changes a valid draft quantity
- **THEN** totals follow the existing cumulative rounding, residual caps, and whole-bundle or parent-only rules
- **AND** the edit makes no stock, dispatch, payment, or completed-return mutation

### Requirement: Whole-bundle cash return includes components carried on the parent sale row
A `whole_bundle` cash return SHALL include every component captured at checkout as its own return line. This holds whether the component's sale allocation is a separate sibling row or the bundle parent's own sale row. The return MUST save without a bundle-completeness error when every captured component resolves.

#### Scenario: Components carried only on the parent sale row
- **GIVEN** a POS sale where a serial-tracked laptop bundle has one sale row and three non-serial components (mouse, coolingpad, mousepad, 1 per bundle) recorded only as bundle items on that row, each with its own dispatch
- **WHEN** a user saves a cash return with scope "Paket Lengkap" for the laptop serial
- **THEN** the draft saves successfully
- **AND** it contains one parent line and one component line per component, each linked to that component's dispatch

#### Scenario: Legacy sibling-row bundle still works
- **GIVEN** a bundle sale whose components have zero-price sibling sale rows
- **WHEN** a user saves a whole-bundle cash return
- **THEN** the draft saves with one component line per component, as before

#### Scenario: Unresolvable component still rejected
- **WHEN** a captured component's sale allocation cannot be resolved
- **THEN** the save is rejected, naming the unresolved component

### Requirement: Whole-bundle cash refund is carried by the parent line
In a `whole_bundle` cash return, the parent line SHALL carry the full customer bundle refund. Component lines MUST carry a refund, unit price and line total of zero, and MUST keep the component's allocated price as reference information.

#### Scenario: Refund total equals bundle price
- **GIVEN** a bundle sold for Rp7,600,000 with component allocations Rp20,000, Rp30,000 and Rp100,000
- **WHEN** a whole-bundle cash return is saved
- **THEN** the return total is Rp7,600,000
- **AND** each component line shows a zero refund and a zero line total

### Requirement: Component lines do not consume the parent's returnable quantity
Refundable and returnable quantities SHALL be tracked per sale row and product. Component return lines sharing the parent's sale row MUST NOT reduce the parent's remaining quantity, and the parent line MUST NOT reduce a component's.

#### Scenario: Shared carrier row
- **GIVEN** a bundle of quantity 1 whose components are carried on the parent's sale row
- **WHEN** a whole-bundle cash return for 1 is saved and submitted
- **THEN** the parent and each component are each counted as 1 of 1 returned
- **AND** a second return for the same bundle is rejected as exceeding the remaining quantity

#### Scenario: Draft resubmit keeps component identity
- **WHEN** a whole-bundle cash return draft is submitted for approval
- **THEN** each component line remains a component line for its own product
- **AND** no extra component lines are created

### Requirement: Whole-bundle cash return approval restocks components
Approving a `whole_bundle` cash return SHALL return each component's quantity to stock using that component's own dispatch, alongside the parent.

#### Scenario: Components restocked on approval
- **GIVEN** an approved whole-bundle cash return for a bundle whose components are carried on the parent's sale row
- **THEN** the parent serial and each component's quantity are returned to stock at the originating location
