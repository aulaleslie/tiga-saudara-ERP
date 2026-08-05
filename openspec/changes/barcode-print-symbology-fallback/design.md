## Context

`BarcodeBatchService` reads a product barcode and `product_barcode_symbology`, validates both, renders an SVG with `milon/barcode`, and returns the same label payload to the Livewire preview and final batch-print endpoint. The current service accepts only a fixed set of stored types and rejects an absent or unsupported type, even when the barcode can be represented in Code 128.

The installed `milon/barcode` v10 renderer uses `EAN13` for EAN-13 and `C128` for automatic/general Code 128. EAN-13 accepts only decimal values with a valid EAN-13 check digit; `C128` is the appropriate broad fallback for normal catalog barcode values.

## Goals / Non-Goals

**Goals:**

- Respect recognized stored symbologies (C128, C39, UPCA, EAN8, and supported aliases) by rendering with their normalized type.
- Print a nonblank legacy barcode when stored symbology is absent or unrecognized.
- Infer EAN-13 only for a valid 13-digit EAN-13 value, preserving leading zeroes.
- Use renderer type `C128` as the fallback for absent or unrecognized symbology with non-EAN-13 barcodes.
- Preserve one shared decision path for preview and final printing.

**Non-Goals:**

- Mutating existing barcode values or symbology data.
- Changing barcode assignment, barcode normalization, scanner configuration, label dimensions, or selected-business price behavior.

## Decisions

### Respect recognized stored symbologies and fall back intelligently to C128

A stored symbology recognized by the installed renderer (`C128`, `C39`, `UPCA`, `EAN8`, and supported aliases like `CODE128`, `EAN-13`) SHALL be normalized and rendered using its stored type. When stored symbology is absent or unrecognized, first test whether the barcode itself is valid EAN-13; select `EAN13` only when that test succeeds. Every other nonblank value with absent or unrecognized symbology selects `C128`.

This respects intentional symbology declarations while gracefully handling incomplete legacy metadata. It avoids falsely representing a non-EAN barcode as EAN-13 when the stored symbology is missing or corrupted.

### Preserve strict handling for explicit EAN-13 data

An explicitly EAN-13 product (stored symbology `EAN13` or `EAN-13`) remains an EAN-13 rendering request. If the barcode is not valid EAN-13, rendering remains a blocking error rather than silently changing its symbology to Code 128. This makes bad declared EAN data visible and avoids a label whose printed format contradicts its stored classification.

### Keep renderer failure as the final validation boundary

The service continues to call the installed renderer and handles a renderer failure as a product-specific error. `C128` is selected by the application as the general fallback, but successful SVG generation remains required before a label can be emitted.

## Risks / Trade-offs

- [Long C128 values create dense symbols that may be hard to scan on 55 mm labels] → Maintain renderer-failure handling and physically test representative long fallback values with the target printer and scanner.
- [Stored symbologies beyond C128, C39, UPCA, EAN8 and their aliases are not explicitly recognized] → These are treated as unrecognized; the system falls back to EAN-13 inference or C128 based on barcode content, which is safe for legacy systems without modern symbology support.
- [Incorrect explicit EAN-13 records remain unprintable] → Return the existing actionable product-specific rendering error; operators can correct the data rather than receiving a format-changing fallback.

## Migration Plan

Deploy the service and test change with no migration or data backfill. Rollback restores the former stored-symbology validation behavior; no persisted data is affected.

## Open Questions

None.
