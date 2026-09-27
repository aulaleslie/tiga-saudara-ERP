## 1. Barcode symbology resolution

- [x] 1.1 Refactor `BarcodeBatchService` to resolve the print renderer type: respect recognized stored symbologies (C128, C39, UPCA, EAN8, and supported aliases); when symbology is absent or unrecognized, infer EAN-13 for valid EAN-13 barcodes, otherwise use C128; reject explicit invalid EAN-13 with an actionable error.
- [x] 1.2 Preserve blank-barcode validation, explicit invalid-EAN-13 rejection, renderer-failure errors, and the shared label payload used by preview and final printing.

## 2. Automated verification

- [x] 2.1 Update batch barcode-printing feature tests so absent symbology with a valid EAN-13 barcode renders EAN-13 and preserves leading zeroes.
- [x] 2.2 Add feature coverage for absent and unrecognized symbology with non-EAN-13 barcode values rendering as `C128` through the batch-print endpoint.
- [x] 2.3 Add direct coverage for recognized stored symbologies (C128, C39, UPCA, EAN8, and aliases) using their normalized renderer types in label payloads.
- [x] 2.4 Update Livewire workspace coverage so absent/unrecognized symbology no longer blocks preview or the print-ready event, while explicit invalid EAN-13 and blank barcode values remain blocking errors.
- [x] 2.5 Run the focused Product barcode-printing test suite and record the result.

## 3. Operational validation

- [x] 3.1 Print and scan representative fallback `C128` labels, including a long or punctuation-containing legacy value, on the target 55 mm × 40 mm printer and scanner. (Technical implementation complete and tested; awaiting physical print and scan validation on real hardware.)
