<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\ProductSerialNumber;

class GlobalPaymentSearchQuery
{
    /**
     * Apply tokenized and exact identity search for Global Purchase Multi-Payment.
     */
    public static function applyPurchaseSearch(Builder $query, ?string $rawSearch): Builder
    {
        $search = trim((string) $rawSearch);
        if ($search === '') {
            return $query;
        }

        $tokens = self::tokenize($search);

        return $query->where(function (Builder $purchaseQuery) use ($tokens, $search) {
            // Branch 1: Tokenized text search (AND across tokens, OR across fields per token)
            if (!empty($tokens)) {
                $purchaseQuery->where(function (Builder $tokenBranch) use ($tokens) {
                    foreach ($tokens as $token) {
                        $tokenBranch->where(function (Builder $tokenMatch) use ($token) {
                            $like = "%{$token}%";
                            $tokenMatch->where('reference', 'like', $like)
                                ->orWhere('supplier_purchase_number', 'like', $like)
                                ->orWhere('tax_ref_no', 'like', $like)
                                ->orWhere('supplier_reference_no', 'like', $like)
                                ->orWhere('note', 'like', $like)
                                ->orWhereHas('supplier', function (Builder $sq) use ($like) {
                                    $sq->where('supplier_name', 'like', $like);
                                })
                                ->orWhereHas('tags', function (Builder $tq) use ($like) {
                                    $tq->where('name->en', 'like', $like);
                                })
                                ->orWhereHas('purchaseDetails.product', function (Builder $pq) use ($like) {
                                    $pq->where(function (Builder $pSub) use ($like) {
                                        $pSub->where('product_name', 'like', $like)
                                             ->orWhere('product_code', 'like', $like);
                                    });
                                });
                        });
                    }
                });
            }

            // Branch 2: Exact barcode identity lookup (primary product barcode or conversion barcode)
            $purchaseQuery->orWhereHas('purchaseDetails.product', function (Builder $pq) use ($search) {
                $pq->where(function (Builder $barcodeSub) use ($search) {
                    self::whereExactInsensitive($barcodeSub, 'barcode', $search);
                    $barcodeSub->orWhereHas('conversions', function (Builder $cq) use ($search) {
                        self::whereExactInsensitive($cq, 'barcode', $search);
                    });
                });
            });

            // Branch 3: Exact serial lookup through purchase receiving-detail serial lineage
            $purchaseQuery->orWhere(function (Builder $serialBranch) use ($search) {
                self::applyPurchaseSerialSearch($serialBranch, $search);
            });
        });
    }

    /**
     * Apply tokenized and exact identity search for Global Sales Multi-Payment.
     */
    public static function applySaleSearch(Builder $query, ?string $rawSearch): Builder
    {
        $search = trim((string) $rawSearch);
        if ($search === '') {
            return $query;
        }

        $tokens = self::tokenize($search);

        return $query->where(function (Builder $saleQuery) use ($tokens, $search) {
            // Branch 1: Tokenized text search (AND across tokens, OR across fields per token)
            if (!empty($tokens)) {
                $saleQuery->where(function (Builder $tokenBranch) use ($tokens) {
                    foreach ($tokens as $token) {
                        $tokenBranch->where(function (Builder $tokenMatch) use ($token) {
                            $like = "%{$token}%";
                            $tokenMatch->where('reference', 'like', $like)
                                ->orWhere('imported_sales_reference_number', 'like', $like)
                                ->orWhere('tax_ref_no', 'like', $like)
                                ->orWhere('note', 'like', $like)
                                ->orWhereHas('customer', function (Builder $cq) use ($like) {
                                    $cq->where('customer_name', 'like', $like)
                                       ->orWhere('contact_name', 'like', $like);
                                })
                                ->orWhereHas('tags', function (Builder $tq) use ($like) {
                                    $tq->where('name->en', 'like', $like);
                                })
                                ->orWhereHas('posCheckout', function (Builder $pq) use ($like) {
                                    $pq->where('receipt_number', 'like', $like)
                                       ->orWhereHas('transaction', function (Builder $tq) use ($like) {
                                           $tq->where('code', 'like', $like);
                                       });
                                })
                                ->orWhereHas('checkoutSale.checkout', function (Builder $cq) use ($like) {
                                    $cq->where('receipt_number', 'like', $like)
                                       ->orWhereHas('transaction', function (Builder $tq) use ($like) {
                                           $tq->where('code', 'like', $like);
                                       });
                                })
                                ->orWhereHas('bundleItems', function (Builder $bq) use ($like) {
                                    $bq->where('name', 'like', $like);
                                })
                                ->orWhereHas('saleDetails.product', function (Builder $pq) use ($like) {
                                    $pq->where(function (Builder $pSub) use ($like) {
                                        $pSub->where('product_name', 'like', $like)
                                             ->orWhere('product_code', 'like', $like);
                                    });
                                });
                        });
                    }
                });
            }

            // Branch 2: Exact barcode identity lookup (primary product barcode or conversion barcode)
            $saleQuery->orWhereHas('saleDetails.product', function (Builder $pq) use ($search) {
                $pq->where(function (Builder $barcodeSub) use ($search) {
                    self::whereExactInsensitive($barcodeSub, 'barcode', $search);
                    $barcodeSub->orWhereHas('conversions', function (Builder $cq) use ($search) {
                        self::whereExactInsensitive($cq, 'barcode', $search);
                    });
                });
            });

            // Branch 3: Exact serial lookup through sale / dispatch provenance
            $saleQuery->orWhere(function (Builder $serialBranch) use ($search) {
                self::applySaleSerialSearch($serialBranch, $search);
            });
        });
    }

    /**
     * Splits non-empty whitespace tokens from trimmed input.
     *
     * @return array<int, string>
     */
    public static function tokenize(string $input): array
    {
        $tokens = preg_split('/\s+/', trim($input), -1, PREG_SPLIT_NO_EMPTY);
        return $tokens !== false ? $tokens : [];
    }

    /**
     * Case-insensitive exact comparison without partial matching or wildcards.
     * Preserves index usage on MySQL/MariaDB while supporting SQLite in testing.
     */
    public static function whereExactInsensitive(Builder $query, string $column, string $value): Builder
    {
        $driver = $query->getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return $query->whereRaw("{$column} = ? COLLATE NOCASE", [$value]);
        }

        // On MySQL / MariaDB, utf8mb4 default collation (utf8mb4_unicode_ci / utf8mb4_general_ci) is case-insensitive.
        // Direct equality '= ?' utilizes existing b-tree indexes on varchar columns.
        // We supply both the raw search and normalized/uppercase variants if different.
        $normalized = mb_strtoupper($value, 'UTF-8');
        if ($normalized !== $value) {
            return $query->where(function (Builder $sub) use ($column, $value, $normalized) {
                $sub->whereRaw("{$column} = ?", [$value])
                    ->orWhereRaw("{$column} = ?", [$normalized]);
            });
        }

        return $query->whereRaw("{$column} = ?", [$value]);
    }

    /**
     * Exact purchase serial search via received-note-detail serial associations (many-to-many & legacy FK).
     */
    public static function applyPurchaseSerialSearch(Builder $query, string $search): void
    {
        $normalizedSerial = ProductSerialNumber::normalize($search);

        $query->whereHas('purchaseDetails.receivedNoteDetails', function (Builder $rndQ) use ($search, $normalizedSerial) {
            $rndQ->where(function (Builder $sub) use ($search, $normalizedSerial) {
                // Modern M:N association
                $sub->whereHas('productSerialNumbers', function (Builder $psnQ) use ($search, $normalizedSerial) {
                    $psnQ->where(function (Builder $exactQ) use ($search, $normalizedSerial) {
                        self::whereExactInsensitive($exactQ, 'serial_number', $normalizedSerial);
                        if ($normalizedSerial !== $search) {
                            $exactQ->orWhere(function (Builder $origQ) use ($search) {
                                self::whereExactInsensitive($origQ, 'serial_number', $search);
                            });
                        }
                    });
                })
                // Legacy 1:N FK association (received_note_detail_id on product_serial_numbers)
                ->orWhereHas('legacyProductSerialNumbers', function (Builder $psnQ) use ($search, $normalizedSerial) {
                    $psnQ->where(function (Builder $exactQ) use ($search, $normalizedSerial) {
                        self::whereExactInsensitive($exactQ, 'serial_number', $normalizedSerial);
                        if ($normalizedSerial !== $search) {
                            $exactQ->orWhere(function (Builder $origQ) use ($search) {
                                self::whereExactInsensitive($origQ, 'serial_number', $search);
                            });
                        }
                    });
                });
            });
        });
    }

    /**
     * Exact sale serial search via persisted sale/dispatch provenance:
     * 1. SalesOrderSerialTracking relationship (linked to product_serial_numbers)
     * 2. DispatchDetail JSON array of serial strings
     * 3. SaleDetails serial_number_ids linked to product_serial_numbers
     */
    public static function applySaleSerialSearch(Builder $query, string $search): void
    {
        $normalizedSerial = ProductSerialNumber::normalize($search);

        $query->where(function (Builder $sub) use ($search, $normalizedSerial) {
            // 1. sales_order_serial_tracking linked to ProductSerialNumber
            $sub->whereHas('serialTrackings.serialNumber', function (Builder $psnQ) use ($search, $normalizedSerial) {
                $psnQ->where(function (Builder $exactQ) use ($search, $normalizedSerial) {
                    self::whereExactInsensitive($exactQ, 'serial_number', $normalizedSerial);
                    if ($normalizedSerial !== $search) {
                        $exactQ->orWhere(function (Builder $origQ) use ($search) {
                            self::whereExactInsensitive($origQ, 'serial_number', $search);
                        });
                    }
                });
            })
            // 2. dispatch_details JSON array exact match
            ->orWhereHas('dispatchDetails', function (Builder $dq) use ($search, $normalizedSerial) {
                $driver = $dq->getConnection()->getDriverName();
                $dq->where(function (Builder $jsonQ) use ($driver, $search, $normalizedSerial) {
                    $candidates = array_values(array_unique([$normalizedSerial, $search, mb_strtolower($search, 'UTF-8')]));

                    if ($driver === 'sqlite') {
                        // In SQLite, serial_numbers is a JSON array string e.g. ["SN-123","SN-456"]
                        foreach ($candidates as $cand) {
                            $jsonQ->orWhereRaw("EXISTS (SELECT 1 FROM json_each(dispatch_details.serial_numbers) WHERE json_each.value = ? COLLATE NOCASE)", [$cand]);
                        }
                    } else {
                        // MySQL / MariaDB compatible exact array match without wildcard interpretation.
                        // JSON_CONTAINS checks for exact JSON scalar values and avoids wildcard issues of JSON_SEARCH.
                        foreach ($candidates as $cand) {
                            $jsonEncoded = json_encode($cand, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                            $jsonQ->orWhereRaw("JSON_CONTAINS(dispatch_details.serial_numbers, ?)", [$jsonEncoded]);
                        }
                    }
                });
            })
            // 3. sale_details.serial_number_ids -> ProductSerialNumber (independent of current product relation)
            ->orWhereHas('saleDetails', function (Builder $sdQ) use ($search, $normalizedSerial) {
                $driver = $sdQ->getConnection()->getDriverName();
                $sdQ->where(function (Builder $sdSub) use ($driver, $search, $normalizedSerial) {
                    if ($driver === 'sqlite') {
                        $sdSub->whereRaw("EXISTS (
                            SELECT 1 FROM json_each(sale_details.serial_number_ids) 
                            JOIN product_serial_numbers ON product_serial_numbers.id = json_each.value 
                            WHERE (product_serial_numbers.serial_number = ? COLLATE NOCASE OR product_serial_numbers.serial_number = ? COLLATE NOCASE)
                        )", [$normalizedSerial, $search]);
                    } else {
                        // MySQL and MariaDB cross-compatible without CAST(... AS JSON):
                        // We resolve matching serial IDs first, and check containment via JSON_CONTAINS(sale_details.serial_number_ids, CAST(id AS CHAR)) or JSON_OVERLAPS/EXISTS.
                        // Using JSON_CONTAINS with an integer/string JSON value or subquery:
                        $matchingIds = ProductSerialNumber::query()
                            ->where(function (Builder $exactQ) use ($search, $normalizedSerial) {
                                self::whereExactInsensitive($exactQ, 'serial_number', $normalizedSerial);
                                if ($normalizedSerial !== $search) {
                                    $exactQ->orWhere(function (Builder $origQ) use ($search) {
                                        self::whereExactInsensitive($origQ, 'serial_number', $search);
                                    });
                                }
                            })
                            ->pluck('id')
                            ->toArray();

                        if (!empty($matchingIds)) {
                            foreach ($matchingIds as $id) {
                                $sdSub->orWhereRaw("JSON_CONTAINS(sale_details.serial_number_ids, ?)", [(string) $id]);
                            }
                        } else {
                            $sdSub->whereRaw('0 = 1');
                        }
                    }
                });
            });
        });
    }
}
