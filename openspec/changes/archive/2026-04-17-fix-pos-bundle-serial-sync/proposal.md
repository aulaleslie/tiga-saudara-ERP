## Why

When a user scans a product by serial number in the POS interface, and that product is a bundle parent, the system correctly prompts the user to select a bundle. However, the scanned serial number is lost during this transition. After selecting the bundle, the user must scan the serial number again to append it to the newly created cart line. 

This creates friction in the checkout process, especially for serialized bundle products where high-speed scanning is expected.

## What Changes

This change introduces a mechanism to preserve the scanned serial number across the bundle selection modal.
- Scanned serial numbers will be stored in a temporary state if a bundle selection is required.
- Upon bundle selection, the preserved serial number will be automatically appended to the resulting cart line.
- The `addProductToCart` JavaScript function will be updated to handle serial numbers atomically.
- Logic in the bundle selection modal and "Continue Normal" handlers will be updated to utilize the preserved serial state.

## Capabilities

### New Capabilities
- None

### Modified Capabilities
- `pos-cart-management`: Automatically append scanned serial numbers even when interrupted by bundle selection modals.
- `pos-scan-input-actions`: Propagate resolved serial numbers through the product addition flow.

## Impact

- `Modules/Pos/Resources/views/sell.blade.php`: JavaScript logic for product addition, bundle selection, and serial handling.
