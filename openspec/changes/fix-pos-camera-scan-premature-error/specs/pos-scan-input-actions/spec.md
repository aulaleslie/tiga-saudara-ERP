## ADDED Requirements

### Requirement: POS camera scanner SHALL not show decode-failure guidance before first scan processing attempt
The POS camera scanner MUST keep camera-open state non-error while waiting for barcode input and MUST NOT show decode-failure cashier guidance until at least one decode processing attempt has occurred.

#### Scenario: Camera opens and waits without barcode
- **WHEN** cashier opens camera scanner and no barcode/QR is presented yet
- **THEN** the system MUST keep scanner status in non-error waiting/decoding state and MUST NOT show `Gagal memproses barcode. Silahkan coba lagi.`

#### Scenario: Decode-failure appears only after attempted processing
- **WHEN** scanner has entered active decode processing and the processing attempt fails
- **THEN** the system MUST show decode-failure cashier guidance

### Requirement: POS camera scanner decode failures SHALL include actionable debug context
When scanner decode/runtime failure guidance is shown, the system MUST include a short debug context token that identifies failure stage, and MUST log detailed technical error metadata in console output.

#### Scenario: User-facing decode failure includes debug token
- **WHEN** scanner shows `Gagal memproses barcode. Silahkan coba lagi.` after a decode processing failure
- **THEN** the message MUST include an attached debug token for support correlation (for example stage/code suffix)

#### Scenario: Console diagnostics include detailed failure metadata
- **WHEN** a scanner decode/runtime failure occurs
- **THEN** console logs MUST include structured stage metadata and underlying error details sufficient for troubleshooting

### Requirement: POS camera scan path SHALL preserve shared resolver parity with Enter/helper triggers
Camera-decoded submissions MUST invoke the same shared resolver contract as Enter and helper-trigger submissions so scan outcomes remain semantically equivalent.

#### Scenario: Camera path can call shared resolver contract
- **WHEN** camera scanner produces a decoded value within resolver length limits
- **THEN** the system MUST invoke the shared scan resolver callable used by Enter/helper flow

#### Scenario: Shared resolver contract remains equivalent across triggers
- **WHEN** the same code is submitted via Enter, helper button, and camera path
- **THEN** all three triggers MUST preserve equivalent resolver outcome semantics (product, serial, not-found, error)
