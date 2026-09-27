## Why

Purchase and sales create/edit forms currently derive every business-sensitive decision from the active session business. Authorized staff need to create a document for another business without changing their active workspace, while preserving the target business's PKP or non-PKP tax behavior.

## What Changes

- Add a dedicated permission that enables cross-business selection on Purchase and Sales create and edit forms.
- Require authorized users to select an accessible business through a searchable single-select dropdown; users without the permission retain the active-business-only workflow.
- Create documents under the selected business rather than the active session business, including target-business document numbering and PKP tax behavior.
- Permit a draft Purchase or Sale to move to a different accessible business during edit; assign a new target-business document number when it moves.
- Rehydrate only tax context when a target business changes: preserve entered products, quantities, prices, discounts, and shipping while applying or removing taxes according to the selected business's PKP status.
- Keep the existing redirect to the active-business list and clearly notify the user when the saved document belongs to another business.

## Capabilities

### New Capabilities
- `cross-business-purchase-sale-documents`: Permission-governed target-business selection, validation, draft-only reassignment, tax-context rehydration, and numbering for Purchase and Sales documents.

### Modified Capabilities

None.

## Impact

- Affects `app/Config/Permissions.php`, the four Purchase/Sale Livewire create/edit forms and Blade views, their product/tax child components, and Purchase/Sale document-number generation.
- Requires server-side target-business authorization using the user's accessible settings, including Super Admin access.
- Requires focused Livewire and feature coverage for permissions, PKP/non-PKP changes, draft-only reassignment, numbering, and unchanged active-business redirects.
