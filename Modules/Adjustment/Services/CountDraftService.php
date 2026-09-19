<?php

namespace Modules\Adjustment\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Adjustment\Entities\AdjustmentStatus;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;

class CountDraftService
{
    public const SCHEMA_VERSION = 1;
    public const SCHEMA_VERSION_2 = 2;

    /**
     * Compute a deterministic fingerprint for a canonical location-ID set.
     * Used to bind baseline tokens and signatures to a specific pool.
     *
     * @param array<int> $locationIds  Unique sorted ascending IDs
     */
    public static function locationSetFingerprint(array $locationIds): string
    {
        $sorted = array_values(array_unique(array_map('intval', $locationIds)));
        sort($sorted, SORT_NUMERIC);

        return hash('sha256', implode('|', $sorted));
    }

    /**
     * Consolidate validation for count draft creation/updating.
     * Validates header and draft rules using clear Indonesian messages.
     * Supports both schema version 1 (single location) and version 2 (multi-location).
     *
     * @param array $inputData
     * @param \Modules\Adjustment\Entities\Adjustment|null $existingAdjustment
     * @return array Validated and structured draft data with header fields
     * @throws ValidationException
     */
    public function validateDraftInput(array $inputData, ?\Modules\Adjustment\Entities\Adjustment $existingAdjustment = null): array
    {
        $rawDraft = $inputData['count_draft'] ?? [];
        if (is_string($rawDraft)) {
            $rawDraft = json_decode($rawDraft, true) ?? [];
        }
        if (!is_array($rawDraft)) {
            $rawDraft = [];
        }

        $hasLocationIds = !empty($inputData['location_ids']) && is_array($inputData['location_ids']);
        $hasLocationId = !empty($inputData['location_id']);

        $isV2 = $hasLocationIds
            || (isset($rawDraft['schema_version']) && (int) $rawDraft['schema_version'] === self::SCHEMA_VERSION_2)
            || !empty($rawDraft['location_ids'])
            || !empty($rawDraft['locations']);

        $rules = [
            'reference' => 'required|string|max:255',
            'date' => 'required|date',
            'note' => 'nullable|string|max:1000',
            'count_draft' => 'nullable',
        ];

        if ($isV2) {
            $rules['location_ids'] = 'required|array|min:1';
            $rules['location_ids.*'] = 'required|integer|exists:locations,id';
        } else {
            $rules['location_id'] = [
                'required',
                'exists:locations,id',
                function ($attribute, $value, $fail) {
                    $location = Location::find($value);
                    if ($location && $location->is_consignment) {
                        $fail('Penyesuaian stok tidak dapat dilakukan pada lokasi konsinyasi.');
                    }
                },
            ];
        }

        $messages = [
            'reference.required' => 'Keterangan/referensi wajib diisi.',
            'date.required' => 'Tanggal penyesuaian wajib diisi.',
            'date.date' => 'Format tanggal tidak valid.',
            'location_id.required' => 'Lokasi stok opname wajib dipilih.',
            'location_id.exists' => 'Lokasi yang dipilih tidak ditemukan.',
            'location_ids.required' => 'Minimal satu lokasi harus dipilih untuk stock opname.',
            'location_ids.min' => 'Minimal satu lokasi harus dipilih untuk stock opname.',
        ];

        // Fill location_ids from draft if present in payload but not top-level
        if ($isV2 && !$hasLocationIds) {
            $inferredLocIds = $rawDraft['location_ids'] ?? array_column($rawDraft['locations'] ?? [], 'location_id');
            if (empty($inferredLocIds) && $hasLocationId) {
                $inferredLocIds = [$inputData['location_id']];
            }
            $inputData['location_ids'] = $inferredLocIds;
        }

        $validator = Validator::make($inputData, $rules, $messages);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        try {
            if ($isV2) {
                $rawLocIds = $inputData['location_ids'] ?? [];
                $structuredDraft = $this->validateAndStructureDraftV2(
                    $rawDraft,
                    $rawLocIds,
                    $existingAdjustment
                );
            } else {
                $structuredDraft = $this->validateAndStructureDraft(
                    $rawDraft,
                    (int) $inputData['location_id'],
                    $existingAdjustment
                );
            }
        } catch (\InvalidArgumentException $e) {
            // Expected validation failure from domain rules (already in Indonesian)
            Log::info('[CountDraftService] Validation error in draft structure', [
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'count_draft' => [$e->getMessage()],
            ]);
        } catch (\Throwable $e) {
            // Unexpected technical exception: log stack trace and do not expose raw technical error
            Log::error('[CountDraftService] Unexpected system error during draft validation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw ValidationException::withMessages([
                'count_draft' => ['Terjadi kesalahan internal saat memproses draf perhitungan. Silakan periksa kembali data Anda.'],
            ]);
        }

        $result = [
            'reference' => $inputData['reference'],
            'date' => $inputData['date'],
            'note' => $inputData['note'] ?? null,
            'structured_draft' => $structuredDraft,
        ];

        if ($isV2 && isset($structuredDraft['locations'])) {
            $locIds = array_column($structuredDraft['locations'], 'location_id');
            $result['location_ids'] = $locIds;
            $result['location_id'] = $locIds[0] ?? null;
        } else {
            $result['location_id'] = (int) ($structuredDraft['location_id'] ?? $inputData['location_id']);
        }

        return $result;
    }

    /**
     * Validate and structure a count_draft payload before persistence.
     *
     * @param array $data
     * @return array
     */
    public function validateAndStructureDraft(
        array $data,
        ?int $authoritativeLocationId = null,
        ?\Modules\Adjustment\Entities\Adjustment $existingAdjustment = null
    ): array {
        // Enforce authoritative location ID if provided by caller (e.g. controller-validated location_id)
        $locationId = $authoritativeLocationId ?? (int) ($data['location_id'] ?? 0);
        if ($locationId <= 0) {
            throw new InvalidArgumentException('Lokasi stok opname wajib dipilih.');
        }

        $location = Location::with('setting')->find($locationId);
        if (!$location) {
            throw new InvalidArgumentException('Lokasi yang dipilih tidak ditemukan.');
        }

        try {
            app(AdjustmentOwnershipGuard::class)->assertLocationOwned($location);
        } catch (ValidationException $e) {
            throw new InvalidArgumentException((string) collect($e->errors())->flatten()->first());
        }

        // Build map of existing server-held product baselines if updating the same location
        $existingBaselinesByProduct = [];
        if ($existingAdjustment && (int) $existingAdjustment->location_id === $locationId) {
            $savedDraft = $existingAdjustment->count_draft;
            if (is_array($savedDraft) && !empty($savedDraft['rows'])) {
                foreach ($savedDraft['rows'] as $savedRow) {
                    $pId = (int) ($savedRow['product_id'] ?? 0);
                    if ($pId > 0 && !empty($savedRow['baseline'])) {
                        $existingBaselinesByProduct[$pId] = $savedRow['baseline'];
                    }
                }
            }
        }

        // Authoritatively derive setting ID and PKP status from database
        $setting = $location->setting;
        $settingId = (int) ($setting?->id ?? 0);
        $isPkp = (bool) ($setting?->is_pkp ?? false);
        $baselineCapturedAt = $data['baseline_captured_at'] ?? now()->toIso8601String();
        $draftSessionId = !empty($data['draft_session_id']) ? (string) $data['draft_session_id'] : null;

        // Atomic check: if session ID is already bound to a saved document, reject reuse for other documents
        if ($draftSessionId !== null) {
            $boundAdjId = \Illuminate\Support\Facades\Cache::get("opname_session_adj_{$draftSessionId}");
            if ($boundAdjId !== null) {
                if ($existingAdjustment === null || (int) $boundAdjId !== (int) $existingAdjustment->id) {
                    throw new InvalidArgumentException('Sesi perhitungan ini sudah digunakan untuk dokumen penyesuaian lain.');
                }
            }
        }

        $rawRows = $data['rows'] ?? [];
        if (!is_array($rawRows) || empty($rawRows)) {
            throw new InvalidArgumentException('Daftar produk tidak boleh kosong. Pindai atau pilih minimal satu produk.');
        }

        $validatedRows = [];
        $seenProductIds = [];
        $seenProductSerials = []; // [productId => [serialText => true]]

        // Eager load products for performance
        $productIds = array_map(fn($r) => (int) ($r['product_id'] ?? 0), $rawRows);
        $productsById = \Modules\Product\Entities\Product::with('baseUnit')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        foreach ($rawRows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0) {
                throw new InvalidArgumentException('Produk tidak valid.');
            }

            // Enforce single row per product across the entire draft
            if (isset($seenProductIds[$productId])) {
                throw new InvalidArgumentException("Produk ganda ditemukan dalam daftar opname (ID {$productId}).");
            }
            $seenProductIds[$productId] = true;

            // Load authoritative product record from DB
            $productEntity = $productsById->get($productId);
            if (!$productEntity) {
                throw new InvalidArgumentException("Produk dengan ID {$productId} tidak ditemukan.");
            }
            $productName = (string) $productEntity->product_name;
            $productCode = (string) $productEntity->product_code;
            $baseUnit = (string) ($productEntity->baseUnit?->unit_name ?? $productEntity->baseUnit?->name ?? $productEntity->baseUnit?->short_name ?? '');
            $isSerialized = (bool) $productEntity->serial_number_required;

            $rawSerials = $row['serials'] ?? [];
            $validatedSerials = [];

            if (!isset($seenProductSerials[$productId])) {
                $seenProductSerials[$productId] = [];
            }

            // Extract unique serial texts for authoritative database resolution
            $normalizedSerialTexts = [];
            foreach ($rawSerials as $serial) {
                $st = ProductSerialNumber::normalize((string) ($serial['serial_number'] ?? ''));
                if ($st !== '') {
                    $normalizedSerialTexts[] = $st;
                }
            }

            $dbSerialsByText = collect();
            if (!empty($normalizedSerialTexts)) {
                $dbSerialsByText = ProductSerialNumber::with('location')
                    ->where('product_id', $productId)
                    ->whereIn('serial_number', $normalizedSerialTexts)
                    ->get()
                    ->keyBy('serial_number');
            }

            foreach ($rawSerials as $serial) {
                $serialText = ProductSerialNumber::normalize((string) ($serial['serial_number'] ?? ''));
                if ($serialText === '') {
                    continue;
                }

                if (isset($seenProductSerials[$productId][$serialText])) {
                    throw new InvalidArgumentException("Nomor seri ganda '{$serialText}' untuk produk {$productName}.");
                }
                $seenProductSerials[$productId][$serialText] = true;

                $condition = strtolower(trim((string) ($serial['condition'] ?? 'good')));
                if (!in_array($condition, ['good', 'bad'], true)) {
                    $condition = 'good';
                }

                // Authoritatively resolve source metadata by product + serial text from database.
                // Do NOT trust client-supplied source serial ID, location, or status.
                // If serial is unknown in database, retain null source references.
                $matchedDbSerial = $dbSerialsByText->get($serialText);

                $validatedSerials[] = [
                    'serial_number' => $serialText,
                    'condition' => $condition,
                    'source_serial_id' => $matchedDbSerial ? (int) $matchedDbSerial->id : null,
                    'source_location_id' => $matchedDbSerial?->location_id ? (int) $matchedDbSerial->location_id : null,
                    'source_location_name' => $matchedDbSerial?->location?->name,
                    'source_status' => $matchedDbSerial?->status,
                    'source_tax_id' => $matchedDbSerial?->tax_id ? (int) $matchedDbSerial->tax_id : null,
                ];
            }

            if ($isSerialized) {
                // Serialized counts are strictly derived from accepted serial entries
                $goodCount = collect($validatedSerials)->where('condition', 'good')->count();
                $badCount = collect($validatedSerials)->where('condition', 'bad')->count();
            } else {
                $rawGood = $row['good_count'] ?? 0;
                $rawBad = $row['bad_count'] ?? 0;

                // Validate explicit integer numeric format (reject scientific notation, floats, negative values)
                $isValidIntegerFormat = function ($val) {
                    if (is_int($val)) {
                        return $val >= 0;
                    }
                    if (is_string($val) || is_numeric($val)) {
                        $str = trim((string) $val);
                        return preg_match('/^\d+$/', $str) === 1;
                    }
                    return false;
                };

                if (!$isValidIntegerFormat($rawGood)) {
                    throw new InvalidArgumentException("Jumlah fisik bagus untuk {$productName} harus berupa bilangan bulat positif atau nol.");
                }
                if (!$isValidIntegerFormat($rawBad)) {
                    throw new InvalidArgumentException("Jumlah fisik rusak untuk {$productName} harus berupa bilangan bulat positif atau nol.");
                }

                $goodCount = (int) $rawGood;
                $badCount = (int) $rawBad;
            }

            // Derive tax allocation strictly from location's setting PKP status
            if ($isPkp) {
                $goodTax = $goodCount;
                $goodNonTax = 0;
                $badTax = $badCount;
                $badNonTax = 0;
            } else {
                $goodTax = 0;
                $goodNonTax = $goodCount;
                $badTax = 0;
                $badNonTax = $badCount;
            }

            // Authoritatively resolve baseline:
            // 1. Preserve server-held baseline from existing adjustment (if editing same location).
            // 2. Or resolve server-held snapshot by opaque token if present, validating product, location, user, and session/document.
            //    If token is present but snapshot is expired, invalid, or mismatched, REJECT with clear error.
            // 3. Or retain the signed baseline snapshot captured when the row was added, verified via HMAC.
            // 4. Otherwise fall back to capturing fresh baseline from ProductStock at the target location ONLY for rows without baseline reference.
            $resolver = app(AdjustmentProductResolver::class);
            $hasToken = !empty($row['baseline']['token']);
            $token = $hasToken ? (string) $row['baseline']['token'] : null;
            $serverHeldSnapshot = $token ? $resolver->getBaselineSnapshot(
                $token,
                $productId,
                $locationId,
                auth()->id(),
                $draftSessionId,
                $existingAdjustment?->id
            ) : null;

            if (isset($existingBaselinesByProduct[$productId])) {
                $savedB = $existingBaselinesByProduct[$productId];
                $baseline = [
                    'existing_good_total' => (int) ($savedB['existing_good_total'] ?? 0),
                    'existing_good_tax' => (int) ($savedB['existing_good_tax'] ?? 0),
                    'existing_good_non_tax' => (int) ($savedB['existing_good_non_tax'] ?? 0),
                    'existing_bad_total' => (int) ($savedB['existing_bad_total'] ?? 0),
                    'existing_bad_tax' => (int) ($savedB['existing_bad_tax'] ?? 0),
                    'existing_bad_non_tax' => (int) ($savedB['existing_bad_non_tax'] ?? 0),
                    'captured_at' => $savedB['captured_at'] ?? $baselineCapturedAt,
                ];
                if (!empty($savedB['signature'])) {
                    $baseline['signature'] = $savedB['signature'];
                }
                if (!empty($savedB['token'])) {
                    $baseline['token'] = $savedB['token'];
                }
            } elseif ($hasToken) {
                // If client provided a baseline token, it MUST resolve to an active, valid server snapshot.
                // It must NOT silently capture fresh stock if expired, cleared from cache, or mismatched.
                if ($serverHeldSnapshot === null) {
                    throw new InvalidArgumentException("Referensi snapshot baseline untuk produk '{$productName}' tidak valid atau telah kedaluwarsa.");
                }

                $baseline = [
                    'existing_good_total' => (int) ($serverHeldSnapshot['existing_good_total'] ?? 0),
                    'existing_good_tax' => (int) ($serverHeldSnapshot['existing_good_tax'] ?? 0),
                    'existing_good_non_tax' => (int) ($serverHeldSnapshot['existing_good_non_tax'] ?? 0),
                    'existing_bad_total' => (int) ($serverHeldSnapshot['existing_bad_total'] ?? 0),
                    'existing_bad_tax' => (int) ($serverHeldSnapshot['existing_bad_tax'] ?? 0),
                    'existing_bad_non_tax' => (int) ($serverHeldSnapshot['existing_bad_non_tax'] ?? 0),
                    'captured_at' => $serverHeldSnapshot['captured_at'] ?? $baselineCapturedAt,
                    'signature' => $serverHeldSnapshot['signature'] ?? null,
                    'token' => $token,
                ];
            } elseif (
                !empty($row['baseline'])
                && is_array($row['baseline'])
                && $resolver->verifyBaselineSignature($row['baseline'], $productId, $locationId)
            ) {
                $signedB = $row['baseline'];
                $baseline = [
                    'existing_good_total' => (int) ($signedB['existing_good_total'] ?? 0),
                    'existing_good_tax' => (int) ($signedB['existing_good_tax'] ?? 0),
                    'existing_good_non_tax' => (int) ($signedB['existing_good_non_tax'] ?? 0),
                    'existing_bad_total' => (int) ($signedB['existing_bad_total'] ?? 0),
                    'existing_bad_tax' => (int) ($signedB['existing_bad_tax'] ?? 0),
                    'existing_bad_non_tax' => (int) ($signedB['existing_bad_non_tax'] ?? 0),
                    'captured_at' => $signedB['captured_at'] ?? $baselineCapturedAt,
                    'signature' => $signedB['signature'],
                ];
                if (!empty($signedB['token'])) {
                    $baseline['token'] = $signedB['token'];
                }
            } else {
                $captured = $resolver->captureBaseline(
                    $productId,
                    $locationId,
                    auth()->id(),
                    $draftSessionId,
                    $existingAdjustment?->id
                );
                $baseline = [
                    'existing_good_total' => (int) ($captured['existing_good_total'] ?? 0),
                    'existing_good_tax' => (int) ($captured['existing_good_tax'] ?? 0),
                    'existing_good_non_tax' => (int) ($captured['existing_good_non_tax'] ?? 0),
                    'existing_bad_total' => (int) ($captured['existing_bad_total'] ?? 0),
                    'existing_bad_tax' => (int) ($captured['existing_bad_tax'] ?? 0),
                    'existing_bad_non_tax' => (int) ($captured['existing_bad_non_tax'] ?? 0),
                    'captured_at' => $captured['captured_at'] ?? $baselineCapturedAt,
                    'signature' => $captured['signature'] ?? null,
                    'token' => $captured['token'] ?? null,
                ];
            }

            $validatedRows[] = [
                'product_id' => $productId,
                'product_name' => $productName,
                'product_code' => $productCode,
                'base_unit' => $baseUnit,
                'is_serialized' => $isSerialized,
                'good_count' => $goodCount,
                'bad_count' => $badCount,
                'tax_allocation' => [
                    'good_tax' => $goodTax,
                    'good_non_tax' => $goodNonTax,
                    'bad_tax' => $badTax,
                    'bad_non_tax' => $badNonTax,
                ],
                'serials' => $validatedSerials,
                'baseline' => $baseline,
            ];
        }

        $result = [
            'schema_version' => self::SCHEMA_VERSION,
            'location_id' => $locationId,
            'location_setting_id' => $settingId,
            'is_pkp' => $isPkp,
            'baseline_captured_at' => $baselineCapturedAt,
            'rows' => $validatedRows,
        ];

        if ($draftSessionId !== null) {
            $result['draft_session_id'] = $draftSessionId;
        }

        return $result;
    }

    /**
     * Validate and structure a count-draft payload for schema version 2
     * (multi-location pool). Produces the canonical v2 JSON shape.
     *
     * @param array       $data              Client-submitted draft payload
     * @param array<int>  $locationIds       Canonicalized location IDs (unique, sorted)
     * @param \Modules\Adjustment\Entities\Adjustment|null $existingAdjustment
     * @return array  Structured draft with schema_version=2
     * @throws InvalidArgumentException
     */
    public function validateAndStructureDraftV2(
        array $data,
        array $locationIds,
        ?\Modules\Adjustment\Entities\Adjustment $existingAdjustment = null
    ): array {
        // Canonicalize: unique integers in ascending order
        $locationIds = array_values(array_unique(array_map('intval', $locationIds)));
        sort($locationIds, SORT_NUMERIC);

        if (empty($locationIds)) {
            throw new InvalidArgumentException('Minimal satu lokasi harus dipilih untuk stock opname.');
        }

        $fingerprint = self::locationSetFingerprint($locationIds);

        // Load and validate all locations
        $locations = Location::with('setting')
            ->whereIn('id', $locationIds)
            ->get()
            ->keyBy('id');

        $locationMeta = [];
        foreach ($locationIds as $position => $locId) {
            $location = $locations->get($locId);
            if (!$location) {
                throw new InvalidArgumentException("Lokasi dengan ID {$locId} tidak ditemukan.");
            }
            if (!$location->is_active) {
                throw new InvalidArgumentException("Lokasi '{$location->name}' tidak aktif.");
            }
            if ($location->is_consignment) {
                throw new InvalidArgumentException("Lokasi '{$location->name}' adalah lokasi konsinyasi.");
            }

            $setting = $location->setting;
            $locationMeta[] = [
                'location_id' => (int) $location->id,
                'location_name' => (string) $location->name,
                'setting_id' => (int) ($setting->id ?? 0),
                'setting_name' => (string) ($setting->company_name ?? ''),
                'is_pkp' => (bool) ($setting->is_pkp ?? false),
                'position' => $position + 1,
            ];
        }

        // Build map of existing server-held baselines from a prior save of the same pool
        $existingBaselinesByProductAndLocation = [];
        if ($existingAdjustment && $existingAdjustment->isSchemaVersion2()) {
            $savedDraft = $existingAdjustment->count_draft;
            if (is_array($savedDraft) && !empty($savedDraft['rows'])) {
                foreach ($savedDraft['rows'] as $savedRow) {
                    $pId = (int) ($savedRow['product_id'] ?? 0);
                    foreach (($savedRow['location_baselines'] ?? []) as $lb) {
                        $lId = (int) ($lb['location_id'] ?? 0);
                        if ($pId > 0 && $lId > 0) {
                            $existingBaselinesByProductAndLocation["{$pId}_{$lId}"] = $lb;
                        }
                    }
                }
            }
        }

        $draftSessionId = !empty($data['draft_session_id']) ? (string) $data['draft_session_id'] : null;
        $baselineCapturedAt = $data['baseline_captured_at'] ?? now()->toIso8601String();

        // Atomic session-ID reuse check
        if ($draftSessionId !== null) {
            $boundAdjId = \Illuminate\Support\Facades\Cache::get("opname_session_adj_{$draftSessionId}");
            if ($boundAdjId !== null) {
                if ($existingAdjustment === null || (int) $boundAdjId !== (int) $existingAdjustment->id) {
                    throw new InvalidArgumentException('Sesi perhitungan ini sudah digunakan untuk dokumen penyesuaian lain.');
                }
            }
        }

        $rawRows = $data['rows'] ?? [];
        if (!is_array($rawRows) || empty($rawRows)) {
            throw new InvalidArgumentException('Daftar produk tidak boleh kosong. Pindai atau pilih minimal satu produk.');
        }

        // Eager load products
        $productIds = array_map(fn($r) => (int) ($r['product_id'] ?? 0), $rawRows);
        $productsById = \Modules\Product\Entities\Product::with('baseUnit')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $validatedRows = [];
        $seenProductIds = [];
        $seenProductSerials = [];

        foreach ($rawRows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0) {
                throw new InvalidArgumentException('Produk tidak valid.');
            }
            if (isset($seenProductIds[$productId])) {
                throw new InvalidArgumentException("Produk ganda ditemukan dalam daftar opname (ID {$productId}).");
            }
            $seenProductIds[$productId] = true;

            $productEntity = $productsById->get($productId);
            if (!$productEntity) {
                throw new InvalidArgumentException("Produk dengan ID {$productId} tidak ditemukan.");
            }

            $productName = (string) $productEntity->product_name;
            $productCode = (string) $productEntity->product_code;
            $baseUnit = (string) ($productEntity->baseUnit?->unit_name ?? $productEntity->baseUnit?->name ?? $productEntity->baseUnit?->short_name ?? '');
            $isSerialized = (bool) $productEntity->serial_number_required;

            // -- Serials (same logic as v1) --
            $rawSerials = $row['serials'] ?? [];
            $validatedSerials = [];
            if (!isset($seenProductSerials[$productId])) {
                $seenProductSerials[$productId] = [];
            }

            $normalizedSerialTexts = [];
            foreach ($rawSerials as $serial) {
                $st = ProductSerialNumber::normalize((string) ($serial['serial_number'] ?? ''));
                if ($st !== '') {
                    $normalizedSerialTexts[] = $st;
                }
            }

            $dbSerialsByText = collect();
            if (!empty($normalizedSerialTexts)) {
                $dbSerialsByText = ProductSerialNumber::with('location')
                    ->where('product_id', $productId)
                    ->whereIn('serial_number', $normalizedSerialTexts)
                    ->get()
                    ->keyBy('serial_number');
            }

            foreach ($rawSerials as $serial) {
                $serialText = ProductSerialNumber::normalize((string) ($serial['serial_number'] ?? ''));
                if ($serialText === '') {
                    continue;
                }
                if (isset($seenProductSerials[$productId][$serialText])) {
                    throw new InvalidArgumentException("Nomor seri ganda '{$serialText}' untuk produk {$productName}.");
                }
                $seenProductSerials[$productId][$serialText] = true;

                $condition = strtolower(trim((string) ($serial['condition'] ?? 'good')));
                if (!in_array($condition, ['good', 'bad'], true)) {
                    $condition = 'good';
                }

                $matchedDbSerial = $dbSerialsByText->get($serialText);
                $validatedSerials[] = [
                    'serial_number' => $serialText,
                    'condition' => $condition,
                    'source_serial_id' => $matchedDbSerial ? (int) $matchedDbSerial->id : null,
                    'source_location_id' => $matchedDbSerial?->location_id ? (int) $matchedDbSerial->location_id : null,
                    'source_location_name' => $matchedDbSerial?->location?->name,
                    'source_status' => $matchedDbSerial?->status,
                    'source_tax_id' => $matchedDbSerial?->tax_id ? (int) $matchedDbSerial->tax_id : null,
                ];
            }

            // -- Counts --
            if ($isSerialized) {
                $goodCount = collect($validatedSerials)->where('condition', 'good')->count();
                $badCount = collect($validatedSerials)->where('condition', 'bad')->count();
            } else {
                $rawGood = $row['good_count'] ?? 0;
                $rawBad = $row['bad_count'] ?? 0;

                $isValidIntegerFormat = function ($val) {
                    if (is_int($val)) {
                        return $val >= 0;
                    }
                    if (is_string($val) || is_numeric($val)) {
                        $str = trim((string) $val);
                        return preg_match('/^\d+$/', $str) === 1;
                    }
                    return false;
                };

                if (!$isValidIntegerFormat($rawGood)) {
                    throw new InvalidArgumentException("Jumlah fisik bagus untuk {$productName} harus berupa bilangan bulat positif atau nol.");
                }
                if (!$isValidIntegerFormat($rawBad)) {
                    throw new InvalidArgumentException("Jumlah fisik rusak untuk {$productName} harus berupa bilangan bulat positif atau nol.");
                }

                $goodCount = (int) $rawGood;
                $badCount = (int) $rawBad;
            }

            // -- Per-location baselines (v2 shape) --
            $resolver = app(AdjustmentProductResolver::class);
            $locationBaselines = [];

            $submittedLocationBaselines = [];
            foreach (($row['location_baselines'] ?? []) as $slb) {
                if (isset($slb['location_id'])) {
                    $submittedLocationBaselines[(int) $slb['location_id']] = $slb;
                }
            }

            foreach ($locationIds as $locId) {
                $locMeta = $locations->get($locId);
                $locSetting = $locMeta?->setting;
                $locIsPkp = (bool) ($locSetting?->is_pkp ?? false);

                $existingKey = "{$productId}_{$locId}";
                $submittedLb = $submittedLocationBaselines[$locId] ?? null;
                if ($submittedLb === null && count($locationIds) === 1 && !empty($row['baseline'])) {
                    $submittedLb = $row['baseline'];
                }

                $hasToken = !empty($submittedLb['token']);
                $token = $hasToken ? (string) $submittedLb['token'] : null;
                $serverHeldSnapshot = $token ? $resolver->getBaselineSnapshot(
                    $token,
                    $productId,
                    $locId,
                    auth()->id(),
                    $draftSessionId,
                    $existingAdjustment?->id
                ) : null;

                if (isset($existingBaselinesByProductAndLocation[$existingKey])) {
                    $saved = $existingBaselinesByProductAndLocation[$existingKey];
                    $lbItem = [
                        'location_id' => $locId,
                        'setting_id' => (int) ($locSetting?->id ?? 0),
                        'is_pkp' => $locIsPkp,
                        'existing_good_total' => (int) ($saved['existing_good_total'] ?? 0),
                        'existing_good_tax' => (int) ($saved['existing_good_tax'] ?? 0),
                        'existing_good_non_tax' => (int) ($saved['existing_good_non_tax'] ?? 0),
                        'existing_bad_total' => (int) ($saved['existing_bad_total'] ?? 0),
                        'existing_bad_tax' => (int) ($saved['existing_bad_tax'] ?? 0),
                        'existing_bad_non_tax' => (int) ($saved['existing_bad_non_tax'] ?? 0),
                        'captured_at' => $saved['captured_at'] ?? $baselineCapturedAt,
                    ];
                    if (!empty($saved['signature'])) {
                        $lbItem['signature'] = $saved['signature'];
                    }
                    if (!empty($saved['token'])) {
                        $lbItem['token'] = $saved['token'];
                    }
                    $locationBaselines[] = $lbItem;
                } elseif ($hasToken) {
                    if ($serverHeldSnapshot === null) {
                        throw new InvalidArgumentException("Referensi snapshot baseline untuk produk '{$productName}' tidak valid atau telah kedaluwarsa.");
                    }

                    $locationBaselines[] = [
                        'location_id' => $locId,
                        'setting_id' => (int) ($locSetting?->id ?? 0),
                        'is_pkp' => $locIsPkp,
                        'existing_good_total' => (int) ($serverHeldSnapshot['existing_good_total'] ?? 0),
                        'existing_good_tax' => (int) ($serverHeldSnapshot['existing_good_tax'] ?? 0),
                        'existing_good_non_tax' => (int) ($serverHeldSnapshot['existing_good_non_tax'] ?? 0),
                        'existing_bad_total' => (int) ($serverHeldSnapshot['existing_bad_total'] ?? 0),
                        'existing_bad_tax' => (int) ($serverHeldSnapshot['existing_bad_tax'] ?? 0),
                        'existing_bad_non_tax' => (int) ($serverHeldSnapshot['existing_bad_non_tax'] ?? 0),
                        'captured_at' => $serverHeldSnapshot['captured_at'] ?? $baselineCapturedAt,
                        'signature' => $serverHeldSnapshot['signature'] ?? null,
                        'token' => $token,
                    ];
                } elseif (
                    !empty($submittedLb)
                    && is_array($submittedLb)
                    && $resolver->verifyBaselineSignature($submittedLb, $productId, $locId)
                ) {
                    $locationBaselines[] = [
                        'location_id' => $locId,
                        'setting_id' => (int) ($locSetting?->id ?? 0),
                        'is_pkp' => $locIsPkp,
                        'existing_good_total' => (int) ($submittedLb['existing_good_total'] ?? 0),
                        'existing_good_tax' => (int) ($submittedLb['existing_good_tax'] ?? 0),
                        'existing_good_non_tax' => (int) ($submittedLb['existing_good_non_tax'] ?? 0),
                        'existing_bad_total' => (int) ($submittedLb['existing_bad_total'] ?? 0),
                        'existing_bad_tax' => (int) ($submittedLb['existing_bad_tax'] ?? 0),
                        'existing_bad_non_tax' => (int) ($submittedLb['existing_bad_non_tax'] ?? 0),
                        'captured_at' => $submittedLb['captured_at'] ?? $baselineCapturedAt,
                        'signature' => $submittedLb['signature'] ?? null,
                        'token' => $submittedLb['token'] ?? null,
                    ];
                } else {
                    // Fresh capture from ProductStock
                    $captured = $resolver->captureBaseline(
                        $productId,
                        $locId,
                        auth()->id(),
                        $draftSessionId,
                        $existingAdjustment?->id
                    );
                    $locationBaselines[] = [
                        'location_id' => $locId,
                        'setting_id' => (int) ($locSetting?->id ?? 0),
                        'is_pkp' => $locIsPkp,
                        'existing_good_total' => (int) ($captured['existing_good_total'] ?? 0),
                        'existing_good_tax' => (int) ($captured['existing_good_tax'] ?? 0),
                        'existing_good_non_tax' => (int) ($captured['existing_good_non_tax'] ?? 0),
                        'existing_bad_total' => (int) ($captured['existing_bad_total'] ?? 0),
                        'existing_bad_tax' => (int) ($captured['existing_bad_tax'] ?? 0),
                        'existing_bad_non_tax' => (int) ($captured['existing_bad_non_tax'] ?? 0),
                        'captured_at' => $captured['captured_at'] ?? $baselineCapturedAt,
                        'signature' => $captured['signature'] ?? null,
                        'token' => $captured['token'] ?? null,
                    ];
                }
            }

            $primaryLb = $locationBaselines[0] ?? [];
            $validatedRows[] = [
                'product_id' => $productId,
                'product_name' => $productName,
                'product_code' => $productCode,
                'base_unit' => $baseUnit,
                'is_serialized' => $isSerialized,
                'good_count' => $goodCount,
                'bad_count' => $badCount,
                'serials' => $validatedSerials,
                'location_baselines' => $locationBaselines,
                'baseline' => [
                    'existing_good_total' => array_sum(array_column($locationBaselines, 'existing_good_total')),
                    'existing_good_tax' => array_sum(array_column($locationBaselines, 'existing_good_tax')),
                    'existing_good_non_tax' => array_sum(array_column($locationBaselines, 'existing_good_non_tax')),
                    'existing_bad_total' => array_sum(array_column($locationBaselines, 'existing_bad_total')),
                    'existing_bad_tax' => array_sum(array_column($locationBaselines, 'existing_bad_tax')),
                    'existing_bad_non_tax' => array_sum(array_column($locationBaselines, 'existing_bad_non_tax')),
                    'token' => $primaryLb['token'] ?? null,
                    'signature' => $primaryLb['signature'] ?? null,
                    'captured_at' => $primaryLb['captured_at'] ?? $baselineCapturedAt,
                ],
            ];
        }

        $result = [
            'schema_version' => self::SCHEMA_VERSION_2,
            'location_set_fingerprint' => $fingerprint,
            'locations' => $locationMeta,
            'baseline_captured_at' => $baselineCapturedAt,
            'rows' => $validatedRows,
        ];

        if ($draftSessionId !== null) {
            $result['draft_session_id'] = $draftSessionId;
        }

        return $result;
    }

    /**
     * Check if an adjustment is eligible for editing count drafts.
     * Normal adjustments are editable while in draft or rejected status.
     * Legacy (non-versioned) normal documents remain editable while pending,
     * preserving prior behavior for documents not yet migrated.
     */
    public function canEditAdjustment(\Modules\Adjustment\Entities\Adjustment $adjustment): bool
    {
        $status = AdjustmentStatus::normalize($adjustment->status);
        $type = strtolower(trim((string) $adjustment->type));

        if ($type !== 'normal') {
            return false;
        }

        if (in_array($status, [AdjustmentStatus::Draft, AdjustmentStatus::Rejected], true)) {
            return true;
        }

        // Legacy non-versioned pending documents remain editable for backward compatibility.
        return $status === AdjustmentStatus::Pending && !$adjustment->isVersionedCountDraft();
    }

    /**
     * Adapt a legacy pending normal adjustment into the draft structure on read.
     * Does NOT persist or mutate the adjustment or inventory.
     */
    public function adaptLegacyAdjustmentToDraft(\Modules\Adjustment\Entities\Adjustment $adjustment): array
    {
        $locationId = (int) $adjustment->location_id;
        $location = $locationId > 0 ? Location::with('setting')->find($locationId) : null;
        $setting = $location?->setting;
        $isPkp = (bool) ($setting?->is_pkp ?? false);
        $capturedAt = now()->toIso8601String();

        $rows = [];
        $adjustment->loadMissing(['adjustedProducts.product.baseUnit']);

        foreach ($adjustment->adjustedProducts as $adjProduct) {
            $product = $adjProduct->product;
            if (!$product) {
                continue;
            }

            $rawSerials = is_string($adjProduct->serial_numbers)
                ? (json_decode($adjProduct->serial_numbers, true) ?? [])
                : ($adjProduct->serial_numbers ?? []);

            $serialIds = collect($rawSerials)->map(function ($item) {
                return is_array($item) ? ($item['id'] ?? null) : $item;
            })->filter()->values()->all();

            $serialsInDb = !empty($serialIds)
                ? ProductSerialNumber::with('location')->whereIn('id', $serialIds)->get()->keyBy('id')
                : collect();

            $validatedSerials = [];
            foreach ($rawSerials as $item) {
                $sId = is_array($item) ? ($item['id'] ?? null) : $item;
                $serialModel = $serialsInDb[$sId] ?? null;
                $sText = $serialModel ? $serialModel->serial_number : '';
                if ($sText === '') {
                    continue;
                }

                $validatedSerials[] = [
                    'serial_number' => ProductSerialNumber::normalize($sText),
                    'condition' => 'good', // Legacy records are preserved as proposed good counts
                    'source_serial_id' => $serialModel->id,
                    'source_location_id' => $serialModel->location_id,
                    'source_location_name' => $serialModel->location?->name,
                    'source_status' => $serialModel->status,
                    'source_tax_id' => $serialModel->tax_id,
                ];
            }

            $isSerialized = (bool) $product->serial_number_required;
            if ($isSerialized) {
                $goodCount = count($validatedSerials);
                $badCount = 0;
            } else {
                $goodCount = (int) ($adjProduct->quantity ?? (($adjProduct->quantity_tax ?? 0) + ($adjProduct->quantity_non_tax ?? 0)));
                $badCount = 0;
            }

            // Capture baseline from current location stock using resolver, bound to this legacy adjustment
            $resolver = app(AdjustmentProductResolver::class);
            $legacyDraftSessionId = "adj-{$adjustment->id}";
            $baseline = $resolver->captureBaseline(
                $product->id,
                $locationId,
                auth()->id(),
                $legacyDraftSessionId,
                (int) $adjustment->id
            );

            $rows[] = [
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'base_unit' => $product->baseUnit->unit_name ?? $product->baseUnit->name ?? '',
                'is_serialized' => $isSerialized,
                'good_count' => $goodCount,
                'bad_count' => $badCount,
                'tax_allocation' => [
                    'good_tax' => $isPkp ? $goodCount : 0,
                    'good_non_tax' => $isPkp ? 0 : $goodCount,
                    'bad_tax' => 0,
                    'bad_non_tax' => 0,
                ],
                'serials' => $validatedSerials,
                'baseline' => $baseline,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'location_id' => $locationId,
            'location_setting_id' => $setting?->id ?? 0,
            'is_pkp' => $isPkp,
            'baseline_captured_at' => $capturedAt,
            'draft_session_id' => "adj-{$adjustment->id}",
            'rows' => $rows,
        ];
    }

    /**
     * Atomically save a count draft (create or update) without mutating inventory,
     * serial records, transactions, or stock histories.
     *
     * @param array $inputData Form input data (reference, date, note, location_id/location_ids, count_draft_json/array)
     * @param \Modules\Adjustment\Entities\Adjustment|null $adjustment Existing adjustment for update, null for create
     * @return \Modules\Adjustment\Entities\Adjustment
     */
    public function saveDraft(array $inputData, ?\Modules\Adjustment\Entities\Adjustment $adjustment = null): \Modules\Adjustment\Entities\Adjustment
    {
        if ($adjustment) {
            app(AdjustmentOwnershipGuard::class)->assertOwned($adjustment);
        }

        // Reject saving over an existing document unless it is currently
        // editable (draft or rejected). This guards direct service usage,
        // not just the controller's canEditAdjustment() pre-check, so a
        // waiting_approval or approved document can never be silently reset
        // to draft.
        if ($adjustment && $adjustment->isNormalVersioned() && !$this->canEditAdjustment($adjustment)) {
            throw new InvalidArgumentException(
                'Dokumen penyesuaian ini tidak dapat diubah karena statusnya bukan draf atau ditolak.'
            );
        }

        if (isset($inputData['structured_draft']) && is_array($inputData['structured_draft'])) {
            $structuredDraft = $inputData['structured_draft'];
        } else {
            $rawDraft = is_string($inputData['count_draft'] ?? null)
                ? json_decode($inputData['count_draft'], true)
                : ($inputData['count_draft'] ?? []);

            $hasLocationIds = !empty($inputData['location_ids']) && is_array($inputData['location_ids']);
            $isV2 = $hasLocationIds
                || (isset($rawDraft['schema_version']) && (int) $rawDraft['schema_version'] === self::SCHEMA_VERSION_2)
                || !empty($rawDraft['location_ids'])
                || !empty($rawDraft['locations']);

            if ($isV2) {
                $rawLocIds = $inputData['location_ids'] ?? ($rawDraft['location_ids'] ?? array_column($rawDraft['locations'] ?? [], 'location_id'));
                if (empty($rawLocIds) && !empty($inputData['location_id'])) {
                    $rawLocIds = [$inputData['location_id']];
                }
                $structuredDraft = $this->validateAndStructureDraftV2($rawDraft, $rawLocIds, $adjustment);
            } else {
                $authoritativeLocationId = !empty($inputData['location_id']) ? (int) $inputData['location_id'] : null;
                $structuredDraft = $this->validateAndStructureDraft($rawDraft, $authoritativeLocationId, $adjustment);
            }
        }

        $isV2 = (isset($structuredDraft['schema_version']) && (int) $structuredDraft['schema_version'] === self::SCHEMA_VERSION_2)
            || isset($structuredDraft['locations']);

        if ($adjustment && !$isV2 && !$adjustment->isSchemaVersion2()) {
            app(AdjustmentOwnershipGuard::class)->assertOwned($adjustment);
        }

        $canonicalLocationIds = [];
        if ($isV2) {
            $canonicalLocationIds = array_column($structuredDraft['locations'] ?? [], 'location_id');
        } elseif (!empty($structuredDraft['location_id'])) {
            $canonicalLocationIds = [(int) $structuredDraft['location_id']];
        }

        $headerLocationId = $canonicalLocationIds[0] ?? ($inputData['location_id'] ?? null);

        $resolver = app(AdjustmentProductResolver::class);
        $newlyBoundTokens = [];
        $sessionId = $structuredDraft['draft_session_id'] ?? null;
        $newlyBoundSession = false;

        // Check if session was already owned by this adjustment prior to this save attempt
        $existingAdjId = $adjustment?->id ? (int) $adjustment->id : null;
        $sessionWasAlreadyOwned = false;
        if ($sessionId !== null && $existingAdjId !== null) {
            $currentSessionOwner = \Illuminate\Support\Facades\Cache::get("opname_session_adj_{$sessionId}");
            if ($currentSessionOwner !== null && (int) $currentSessionOwner === $existingAdjId) {
                $sessionWasAlreadyOwned = true;
            }
        }

        // Map persisted baselines by product ID if updating an existing adjustment
        $existingBaselines = [];
        if ($adjustment) {
            $savedDraft = $adjustment->count_draft;
            if (is_array($savedDraft) && !empty($savedDraft['rows'])) {
                foreach ($savedDraft['rows'] as $savedRow) {
                    $pId = (int) ($savedRow['product_id'] ?? 0);
                    if ($pId > 0 && !empty($savedRow['baseline'])) {
                        $existingBaselines[$pId] = $savedRow['baseline'];
                        $existingBaselines[$pId]['product_id'] = $pId;
                        $existingBaselines[$pId]['location_id'] = (int) $adjustment->location_id;
                    }
                }
            }
        }

        try {
            return \Illuminate\Support\Facades\DB::transaction(function () use (
                $inputData,
                $structuredDraft,
                $adjustment,
                $resolver,
                $sessionId,
                $existingBaselines,
                $sessionWasAlreadyOwned,
                $isV2,
                $canonicalLocationIds,
                $headerLocationId,
                &$newlyBoundTokens,
                &$newlyBoundSession
            ) {
                $headerData = [
                    'reference' => $inputData['reference'],
                    'date' => $inputData['date'],
                    'location_id' => $headerLocationId,
                    'note' => $inputData['note'] ?? null,
                    'count_draft' => $structuredDraft,
                    'type' => 'normal',
                    'status' => AdjustmentStatus::Draft,
                ];

                if ($adjustment) {
                    /** @var \Modules\Adjustment\Entities\Adjustment $lockedAdjustment */
                    $lockedAdjustment = \Modules\Adjustment\Entities\Adjustment::where('id', $adjustment->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($lockedAdjustment->isNormalVersioned() && !$this->canEditAdjustment($lockedAdjustment)) {
                        throw new InvalidArgumentException(
                            'Dokumen penyesuaian ini tidak dapat diubah karena statusnya bukan draf atau ditolak.'
                        );
                    }

                    if (!$isV2 && !$lockedAdjustment->isSchemaVersion2()) {
                        app(AdjustmentOwnershipGuard::class)->assertOwned($lockedAdjustment);
                    }

                    // Revising a rejected document returns it to draft.
                    $wasRejected = AdjustmentStatus::normalize($lockedAdjustment->status) === AdjustmentStatus::Rejected;
                    if ($wasRejected) {
                        $headerData['submitted_by'] = null;
                        $headerData['submitted_at'] = null;
                        $headerData['approved_by'] = null;
                        $headerData['approved_at'] = null;
                        $headerData['approval_result'] = null;
                    }

                    $lockedAdjustment->update($headerData);
                    $adjustment = $lockedAdjustment;

                    if ($wasRejected) {
                        app(\App\Services\Notification\DocumentNotificationService::class)->resolveRevision($adjustment);
                    }
                } else {
                    $adjustment = \Modules\Adjustment\Entities\Adjustment::create($headerData);
                }

                $targetAdjId = (int) $adjustment->id;

                // Synchronize adjustment_locations relation rows
                if ($isV2) {
                    \Modules\Adjustment\Entities\AdjustmentLocation::where('adjustment_id', $targetAdjId)->delete();
                    foreach ($canonicalLocationIds as $pos => $locId) {
                        \Modules\Adjustment\Entities\AdjustmentLocation::create([
                            'adjustment_id' => $targetAdjId,
                            'location_id' => (int) $locId,
                            'position' => $pos + 1,
                        ]);
                    }
                }

                // Atomic session claiming
                if ($sessionId !== null) {
                    $sessionLock = \Illuminate\Support\Facades\Cache::lock("lock_opname_session_{$sessionId}", 5);
                    $sessionLockAcquired = $sessionLock->get(function () use ($sessionId, $targetAdjId) {
                        $existingAdj = \Illuminate\Support\Facades\Cache::get("opname_session_adj_{$sessionId}");
                        if ($existingAdj !== null && (int) $existingAdj !== $targetAdjId) {
                            return false;
                        }
                        \Illuminate\Support\Facades\Cache::put("opname_session_adj_{$sessionId}", $targetAdjId, now()->addDays(7));
                        return true;
                    });

                    if (!$sessionLockAcquired) {
                        throw new InvalidArgumentException('Sesi perhitungan ini sudah terikat dengan dokumen lain.');
                    }
                    if (!$sessionWasAlreadyOwned) {
                        $newlyBoundSession = true;
                    }
                }

                // Atomic token claiming: claim ownership and reject if concurrently claimed by another adjustment
                foreach ($structuredDraft['rows'] as $row) {
                    // Multi-location: location_baselines
                    if (!empty($row['location_baselines']) && is_array($row['location_baselines'])) {
                        foreach ($row['location_baselines'] as $lb) {
                            if (!empty($lb['token'])) {
                                $token = (string) $lb['token'];
                                $claimStatus = $resolver->bindSnapshotToAdjustment($token, $targetAdjId, $lb);
                                if ($claimStatus === false) {
                                    $productName = $row['product_name'] ?? 'produk';
                                    throw new InvalidArgumentException("Snapshot baseline untuk {$productName} sudah digunakan oleh dokumen lain.");
                                }

                                if ($claimStatus === 'claimed') {
                                    $newlyBoundTokens[] = $token;
                                }
                            }
                        }
                    }

                    // Single-location v1: baseline.token
                    if (!empty($row['baseline']['token'])) {
                        $token = (string) $row['baseline']['token'];
                        $productId = (int) ($row['product_id'] ?? 0);
                        $persistedFallback = $existingBaselines[$productId] ?? null;

                        $claimStatus = $resolver->bindSnapshotToAdjustment($token, $targetAdjId, $persistedFallback);
                        if ($claimStatus === false) {
                            $productName = $row['product_name'] ?? 'produk';
                            throw new InvalidArgumentException("Snapshot baseline untuk {$productName} sudah digunakan oleh dokumen lain.");
                        }

                        if ($claimStatus === 'claimed') {
                            $newlyBoundTokens[] = $token;
                        }
                    }
                }

                return $adjustment;
            });
        } catch (\Throwable $e) {
            $rollbackAdjId = $adjustment?->id ? (int) $adjustment->id : null;
            if ($rollbackAdjId !== null) {
                foreach ($newlyBoundTokens as $token) {
                    $resolver->releaseSnapshotBinding($token, $rollbackAdjId);
                }
            } else {
                foreach ($newlyBoundTokens as $token) {
                    $key = "opname_baseline_{$token}";
                    $snapshot = \Illuminate\Support\Facades\Cache::get($key);
                    if (is_array($snapshot)) {
                        $snapshot['adjustment_id'] = null;
                        \Illuminate\Support\Facades\Cache::put($key, $snapshot, now()->addDays(7));
                    }
                }
            }

            if ($newlyBoundSession && $sessionId !== null) {
                \Illuminate\Support\Facades\Cache::forget("opname_session_adj_{$sessionId}");
            }

            throw $e;
        }
    }
}


