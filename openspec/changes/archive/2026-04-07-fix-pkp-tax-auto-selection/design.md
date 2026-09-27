## Context

Current implementation of purchase tax auto-selection for PKP (Pengusaha Kena Pajak) businesses only selects a tax if it is explicitly marked as "Default" in the system settings. If no default is set, the backend cart stores `null`, while the browser UI visually defaults to the first available option. This results in a validation failure during form submission.

## Goals / Non-Goals

**Goals:**
- Synchronize backend state with UI behavior by auto-selecting the first available tax as a fallback for PKP businesses.
- Ensure that every product added to a PKP purchase has a tax assigned immediately.
- Provide clearer error messages when tax requirements are not met.

**Non-Goals:**
- Automatically creating taxes if none exist.
- Changing tax selection behavior for non-PKP businesses.

## Decisions

### 1. Robust Fallback in `ProductCart`
Modify `ProductCart::resolveDefaultTaxId` to pick the first available tax in the system when the business is PKP and no explicit default is found.

- **Rationale**: This matches the visual behavior of HTML select elements where the first available option is shown if no value is explicitly selected, eliminating the "false selection" state.
- **Implementation**: Utilize the already-loaded `$this->taxes` collection, which is ordered by `is_default DESC, name ASC`.

### 2. Guard against Zero-Tax State in `CreateForm`
Update the validation logic in `CreateForm::ensureCartTaxesForPkp` to check if any taxes exist in the database before validating individual cart lines.

- **Rationale**: Provides specific instructions to the user to configure taxes if they are operating as a PKP business but have zero taxes set up.

## Risks / Trade-offs

- **Implicit Selection**: Users might inadvertently submit a purchase with the "wrong" tax if they don't review the auto-selected fallback. However, since PKP *requires* a tax, an auto-selected fallback is preferable to a broken form state, especially since the UI already suggests this selection.
