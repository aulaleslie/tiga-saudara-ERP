<?php

namespace Modules\Adjustment\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Setting\Entities\Location;

class CountDraftService
{
    public const SCHEMA_VERSION = 1;

    /**
     * Consolidate validation for count draft creation/updating.
     * Validates header and draft rules using clear Indonesian messages.
     *
     * @param array $inputData
     * @param \Modules\Adjustment\Entities\Adjustment|null $existingAdjustment
     * @return array Validated and structured draft data with header fields
     * @throws ValidationException
     */
    public function validateDraftInput(array $inputData, ?\Modules\Adjustment\Entities\Adjustment $existingAdjustment = null): array
    {
        $rules = [
            'reference' => 'required|string|max:255',
            'date' => 'required|date',
            'location_id' => [
                'required',
                'exists:locations,id',
                function ($attribute, $value, $fail) {
                    $location = Location::find($value);
                    if ($location && $location->is_consignment) {
                        $fail('Penyesuaian stok tidak dapat dilakukan pada lokasi konsinyasi.');
                    }
                },
            ],
            'note' => 'nullable|string|max:1000',
            'count_draft' => 'nullable',
        ];

        $messages = [
            'reference.required' => 'Keterangan/referensi wajib diisi.',
            'date.required' => 'Tanggal penyesuaian wajib diisi.',
            'date.date' => 'Format tanggal tidak valid.',
            'location_id.required' => 'Lokasi stok opname wajib dipilih.',
            'location_id.exists' => 'Lokasi yang dipilih tidak ditemukan.',
        ];

        $validator = Validator::make($inputData, $rules, $messages);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $rawDraft = $inputData['count_draft'] ?? [];
        if (is_string($rawDraft)) {
            $rawDraft = json_decode($rawDraft, true) ?? [];
        }

        if (!is_array($rawDraft)) {
            $rawDraft = [];
        }

        try {
            $structuredDraft = $this->validateAndStructureDraft(
                $rawDraft,
                (int) $inputData['location_id'],
                $existingAdjustment
            );
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

        return [
            'reference' => $inputData['reference'],
            'date' => $inputData['date'],
            'location_id' => (int) $inputData['location_id'],
            'note' => $inputData['note'] ?? null,
            'structured_draft' => $structuredDraft,
        ];
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
        if ($location->is_consignment) {
            throw new InvalidArgumentException('Penyesuaian stok tidak dapat dilakukan pada lokasi konsinyasi.');
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
     * Check if an adjustment is eligible for editing count drafts.
     * Only pending normal adjustments are eligible.
     */
    public function canEditAdjustment(\Modules\Adjustment\Entities\Adjustment $adjustment): bool
    {
        $status = strtolower(trim((string) $adjustment->status));
        $type = strtolower(trim((string) $adjustment->type));

        return $status === 'pending' && $type === 'normal';
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
     * @param array $inputData Form input data (reference, date, note, location_id, count_draft_json/array)
     * @param \Modules\Adjustment\Entities\Adjustment|null $adjustment Existing adjustment for update, null for create
     * @return \Modules\Adjustment\Entities\Adjustment
     */
    public function saveDraft(array $inputData, ?\Modules\Adjustment\Entities\Adjustment $adjustment = null): \Modules\Adjustment\Entities\Adjustment
    {
        if (isset($inputData['structured_draft']) && is_array($inputData['structured_draft'])) {
            $structuredDraft = $inputData['structured_draft'];
        } else {
            $rawDraft = is_string($inputData['count_draft'] ?? null)
                ? json_decode($inputData['count_draft'], true)
                : ($inputData['count_draft'] ?? []);

            $authoritativeLocationId = !empty($inputData['location_id']) ? (int) $inputData['location_id'] : null;
            $structuredDraft = $this->validateAndStructureDraft($rawDraft, $authoritativeLocationId, $adjustment);
        }

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
        if ($adjustment && (int) $adjustment->location_id === (int) $structuredDraft['location_id']) {
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
                &$newlyBoundTokens,
                &$newlyBoundSession
            ) {
                $headerData = [
                    'reference' => $inputData['reference'],
                    'date' => $inputData['date'],
                    'location_id' => $structuredDraft['location_id'],
                    'note' => $inputData['note'] ?? null,
                    'count_draft' => $structuredDraft,
                    'type' => 'normal',
                    'status' => 'pending',
                ];

                if ($adjustment) {
                    $adjustment->update($headerData);
                } else {
                    $adjustment = \Modules\Adjustment\Entities\Adjustment::create($headerData);
                }

                $targetAdjId = (int) $adjustment->id;

                // Atomic session claiming: ensure session is not claimed by another adjustment
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
                    if (!empty($row['baseline']['token'])) {
                        $token = (string) $row['baseline']['token'];
                        $productId = (int) ($row['product_id'] ?? 0);
                        $persistedFallback = $existingBaselines[$productId] ?? null;

                        $claimStatus = $resolver->bindSnapshotToAdjustment($token, $targetAdjId, $persistedFallback);
                        if ($claimStatus === false) {
                            $productName = $row['product_name'] ?? 'produk';
                            throw new InvalidArgumentException("Snapshot baseline untuk {$productName} sudah digunakan oleh dokumen lain.");
                        }

                        // Track newly acquired claims only; never release pre-existing ownership on failure
                        if ($claimStatus === 'claimed') {
                            $newlyBoundTokens[] = $token;
                        }
                    }
                }

                // Trigger notification convention
                app(\App\Services\Notification\DocumentNotificationService::class)
                    ->notifyApprovalNeeded(
                        $adjustment,
                        $adjustment->reference,
                        $structuredDraft['location_setting_id'] ?? session('setting_id'),
                        $adjustment->location_id
                    );

                return $adjustment;
            });
        } catch (\Throwable $e) {
            // Rollback coordination: If the transaction or any later operation failed,
            // release ONLY newly acquired claims made during this attempt so the user can retry.
            // NEVER release pre-existing ownership belonging to an already-saved document.
            $rollbackAdjId = $adjustment?->id ? (int) $adjustment->id : null;
            if ($rollbackAdjId !== null) {
                foreach ($newlyBoundTokens as $token) {
                    $resolver->releaseSnapshotBinding($token, $rollbackAdjId);
                }
            } else {
                // If it was a new adjustment creation that failed, release without requiring matched id
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


