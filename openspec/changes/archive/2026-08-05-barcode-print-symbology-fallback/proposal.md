## Why

The batch barcode-printing screen currently rejects products that have a barcode value but no stored, supported barcode symbology. Legacy catalog data therefore cannot be printed even when its barcode can be safely rendered as EAN-13 or general Code 128.

## What Changes

- Resolve the print symbology from the stored value when it identifies EAN-13.
- For absent or unrecognized symbology, detect a valid EAN-13 barcode value and render it as EAN-13.
- Fall back to the renderer's general Code 128 type (`C128`) for every other nonblank barcode value.
- Keep blank barcode values and explicitly EAN-13 values that fail EAN-13 validation as blocking print errors.
- Replace unsupported-symbology rejection coverage with fallback-resolution coverage for workspace preview and print submission.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `browser-batch-barcode-printing`: Change print-time barcode symbology resolution so legacy barcode values without a usable stored symbology can be printed safely.

## Impact

- Affects `Modules/Product/Services/BarcodeBatchService.php`, which supplies labels to both the Livewire preview and batch-print endpoint.
- Affects Product module feature tests for batch barcode printing.
- Reuses the installed `milon/barcode` renderer; no schema change, API change, or new dependency is required.
