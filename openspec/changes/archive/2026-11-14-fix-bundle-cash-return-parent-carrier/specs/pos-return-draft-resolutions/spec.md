# Spec Delta

## ADDED Requirements

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
