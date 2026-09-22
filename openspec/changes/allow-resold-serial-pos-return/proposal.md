# Proposal

## Why

A serialized item that was returned, restored to sellable stock, and sold through a later dispatch cannot currently be returned again because POS Return submission treats the serial record as permanently claimed by the earlier completed return. Duplicate protection must follow the serial's sale/dispatch occurrence so legitimate later ownership cycles are supported without permitting duplicate returns against one dispatch.

## What Changes

- Scope POS Return serial-claim exclusivity to the returned serial and its source dispatch occurrence instead of the serial record alone.
- Continue blocking concurrent or completed duplicate claims for the same serial from the same source dispatch.
- Allow the same serial to be returned from a genuinely later dispatch after it was received back, became sellable, and was sold again.
- Apply the same rule to directly selected serialized lines and synthesized serialized bundle-component lines.
- Add focused regression coverage for same-dispatch rejection and later-dispatch acceptance; no full-suite verification is required for this corrective change.

## Capabilities

### New Capabilities

- `pos-return-serial-claim-eligibility`: Define how POS Returns distinguish duplicate serialized claims within one dispatch from valid claims after the serial is sold through a later dispatch.

### Modified Capabilities

None.

## Impact

- Affects POS Return draft construction and submission validation in `Modules/Pos/Services/PosReturnSubmissionService.php`.
- Affects focused POS Return feature tests, including serialized ordinary products and serialized bundle components.
- Does not change database schema, external APIs, serial availability rules, return execution effects, or cumulative quantity accounting by source dispatch.
