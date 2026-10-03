# Tasks

## 1. Isolated upload and form

- [x] 1.1 Add a dedicated authenticated staging route and handler for individual purchase payment attachments; verify focused upload requests accept image, PDF, DOC/DOCX, XLS/XLSX, and TXT samples while rejecting disguised content.
- [x] 1.2 Implement one image compression attempt without cropping or changing aspect ratio, retaining the smaller valid result or the original; verify focused cases for under-1-MB output, over-1-MB fallback, and an unsupported compressor format.
- [x] 1.3 Change the individual payment form to stage multiple files, submit `attachments[]`, remove only the selected staged file, and wait for pending uploads; verify the rendered form and a focused browser/manual upload interaction.

## 2. Payment persistence

- [x] 2.1 Add individual multi-attachment payment storage without changing the existing single-attachment service contract used by other flows; verify focused payment creation with zero, one, and multiple attachments and unchanged balance reconciliation.
- [x] 2.2 Validate every staged filename, path, content type, and duplicate before payment creation, and clean up all created media on failure; verify focused invalid-file, path-traversal, duplicate, and mid-batch rollback cases.
- [x] 2.3 Expand the payment media collection's accepted types for this feature while preserving global payment behavior; verify a focused global payment attachment regression test.

## 3. Attachment display

- [x] 3.1 Render every attachment in the shared payment DataTable cell with escaped names and new-tab links, and eager-load media; verify focused table output for zero, one, and multiple attachments across the purchase detail and separate payment list contexts.
- [x] 3.2 Render every attachment on the payment detail page using the same safe label and link behavior; verify a focused view response containing each file link.

## 4. Focused completion checks

- [x] 4.1 Document deployment-level PHP/proxy upload limits and run only focused upload, payment storage, display, and global-payment regression tests; verify those checks pass and `openspec validate individual-purchase-payment-multiple-attachments --strict` succeeds.
