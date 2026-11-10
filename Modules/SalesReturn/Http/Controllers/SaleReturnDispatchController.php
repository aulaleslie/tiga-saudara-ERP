<?php

namespace Modules\SalesReturn\Http\Controllers;

use App\Support\SalesReturn\SaleReturnLifecycleSyncService;
use App\Support\SalesReturn\SalesReturnStateException;
use App\Support\SalesReturn\StandardDispatchRequestService;
use App\Support\SalesReturn\StandardReturnGuard;
use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement;

class SaleReturnDispatchController extends Controller
{
    public function showDispatchForm(SaleReturn $saleReturn)
    {
        abort_if(Gate::denies('saleReturnSettlements.dispatchRequest'), 403);

        $items = SaleReturnItemSettlement::where('sale_return_id', $saleReturn->id)
            ->whereIn('status', [
                SaleReturnItemSettlement::STATUS_APPROVED_AWAITING_DISPATCH,
                SaleReturnItemSettlement::STATUS_DISPATCH_REQUESTED,
            ])
            ->get();

        return view('salesreturn::dispatch', compact('saleReturn', 'items'));
    }

    public function requestDispatch(Request $request, SaleReturn $saleReturn)
    {
        abort_if(Gate::denies('saleReturnSettlements.dispatchRequest'), 403);

        $data = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|integer|exists:sale_return_item_settlements,id',
            'items.*.dispatched_serial_number' => 'nullable|string|max:255',
            'items.*.source_location_id' => 'nullable|integer|exists:locations,id',
            'items.*.dispatch_mode' => 'nullable|in:ORIGINAL,REPLACEMENT',
        ]);

        try {
            DB::transaction(function () use ($data, $saleReturn) {
                $guard = app(StandardReturnGuard::class);
                $service = app(StandardDispatchRequestService::class);
                // One lock sequence for the whole request: source sale -> header -> rows by id.
                $guard->lockForAction($saleReturn);

                $ids = collect($data['items'])->pluck('id')->map(fn ($id) => (int) $id);
                if ($ids->unique()->count() !== $ids->count()) {
                    throw new SalesReturnStateException('Permintaan pengiriman memuat baris ganda.');
                }

                foreach ($data['items'] as $it) {
                    $item = $guard->lockSettlement((int) $it['id']);

                    // A row from another return is never silently skipped: the WHOLE request fails.
                    if ((int) $item->sale_return_id !== (int) $saleReturn->id) {
                        throw new SalesReturnStateException('Baris penyelesaian tidak termasuk dalam retur ini.');
                    }

                    $service->request(
                        $item,
                        $it['dispatched_serial_number'] ?? null,
                        isset($it['source_location_id']) ? (int) $it['source_location_id'] : null,
                        null,
                        $it['dispatch_mode'] ?? null,
                    );
                }
            });

            \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->notifyApprovalNeeded(
                $saleReturn,
                'Pengiriman Retur Penjualan ' . $saleReturn->reference,
                $saleReturn->setting_id,
                null,
                'dispatch'
            ));

            return back()->with('success', 'Pengajuan pengiriman berhasil dikirim untuk persetujuan.');
        } catch (SalesReturnStateException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Failed to request dispatch for sale return', ['error' => $e->getMessage()]);
            return back()->with('error', 'Gagal mengajukan pengiriman.');
        }
    }

    public function approveDispatch(Request $request, SaleReturnItemSettlement $itemSettlement)
    {
        abort_if(Gate::denies('saleReturnSettlements.dispatchApproval'), 403);

        if ($itemSettlement->status !== SaleReturnItemSettlement::STATUS_DISPATCH_REQUESTED) {
            return back()->with('error', 'Item tidak dalam status pengajuan pengiriman.');
        }

        try {
            DB::transaction(function () use ($request, $itemSettlement) {
              // ONE transaction: dispatch effects, completion roll-up and archival commit or roll back
              // together. Notifications are deferred to the commit (afterCommit, below).
              $applyDispatch = function () use ($request, $itemSettlement) {
                // Consignment buyout protocol: coordination lock before the document/stock/serial locks below.
                $coordSale = \Modules\SalesReturn\Entities\SaleReturn::query()->whereKey($itemSettlement->sale_return_id)->value('setting_id');
                \Modules\Consignment\Services\ConsignmentBuyoutReservationGuard::lockCoordination((int) $coordSale, [(int) optional($itemSettlement->detail)->product_id]);

                // Source sale -> header -> row locks, then the status is re-read: a replayed or
                // concurrent approval finds the row no longer DISPATCH_REQUESTED and moves no stock.
                $itemSettlement = app(StandardReturnGuard::class)->lockSettlement($itemSettlement->id);
                if ($itemSettlement->status !== SaleReturnItemSettlement::STATUS_DISPATCH_REQUESTED) {
                    throw new SalesReturnStateException('Item tidak dalam status pengajuan pengiriman.');
                }

                // Held custody: dispatch consumes custody (original) or replacement stock, exactly once,
                // from the identity persisted at request time. Legacy returns keep the old path below.
                if ($itemSettlement->saleReturn->usesHeldCustody()) {
                    app(\App\Support\SalesReturn\StandardDispatchCustodyService::class)
                        ->execute($itemSettlement, app(\App\Support\SalesReturn\StandardReturnCustodyService::class));
                    $itemSettlement->update([
                        'status' => SaleReturnItemSettlement::STATUS_DISPATCHED,
                        'dispatch_approved_at' => now(),
                        'dispatch_approved_by' => Auth::id(),
                    ]);

                    return;
                }

                $detail = $itemSettlement->detail;
                $product = $detail->product;

                // If product has serials
                if ($product->serial_number_required) {
                    $outgoingSerial = $itemSettlement->dispatched_serial_number ?? $itemSettlement->serialNumber?->serial_number;

                    // If same as returned serial => repair (no stock movement)
                    if ($outgoingSerial && $outgoingSerial === ($itemSettlement->serialNumber?->serial_number ?? null)) {
                        // mark dispatched (no stock movement needed for repair)
                        $itemSettlement->update([
                            'status' => SaleReturnItemSettlement::STATUS_DISPATCHED,
                            'dispatch_approved_at' => now(),
                            'dispatch_approved_by' => Auth::id(),
                        ]);

                        // Also mark the serial as sold (out of our warehouse)
                        $itemSettlement->serialNumber?->update([
                            'status' => ProductSerialNumber::STATUS_SOLD,
                        ]);
                        return;
                    }

                    // Replacement: find the outgoing serial in stock and decrement it
                    if ($outgoingSerial) {
                        $sn = \Modules\Product\Entities\ProductSerialNumber::where('product_id', $product->id)
                            ->where('serial_number', $outgoingSerial)
                            ->lockForUpdate()
                            ->first();

                        if (!$sn) {
                            throw new \Exception("Serial {$outgoingSerial} tidak ditemukan untuk produk.");
                        }

                        $sourceLocationId = $sn->location_id;
                        $taxId = $sn->tax_id;

                        $sourceStock = \Modules\Product\Entities\ProductStock::where('product_id', $product->id)
                            ->where('location_id', $sourceLocationId)
                            ->lockForUpdate()
                            ->first();

                        if (!$sourceStock) {
                            throw new \Exception("Stok tidak ditemukan untuk produk {$product->product_name} di lokasi sumber.");
                        }

                        if ($sourceStock->quantity < 1) {
                            throw new \Exception("Stok tidak cukup untuk produk {$product->product_name} di lokasi sumber.");
                        }

                        // Reserved by an approved consignment buyout: neither the serial nor its bucket may be consumed.
                        \Modules\Consignment\Services\ConsignmentBuyoutReservationGuard::assertSerialsUnclaimed([(int) $sn->id], $product->product_name);
                        \Modules\Consignment\Services\ConsignmentBuyoutReservationGuard::assertQuantityAvailable(
                            (int) $product->id, (int) $sourceLocationId, ! empty($taxId),
                            (float) ($taxId ? $sourceStock->quantity_tax : $sourceStock->quantity_non_tax), 1.0, $product->product_name
                        );

                        // decrement
                        $previousQuantity = $product->product_quantity;
                        $previousQuantityAtLocation = $sourceStock->quantity;

                        $sourceStock->decrement('quantity', 1);
                        if ($taxId) {
                            $sourceStock->decrement('quantity_tax', 1);
                        } else {
                            $sourceStock->decrement('quantity_non_tax', 1);
                        }

                        $product->decrement('product_quantity', 1);

                        $afterQuantity = $product->product_quantity;
                        $afterQuantityAtLocation = $sourceStock->quantity;

                        \Modules\Product\Entities\Transaction::create([
                            'product_id' => $product->id,
                            'setting_id' => session('setting_id'),
                            'quantity' => -1,
                            'current_quantity' => $afterQuantity,
                            'broken_quantity' => 0,
                            'location_id' => $sourceLocationId,
                            'user_id' => Auth::id(),
                            'reason' => 'Dispatch replacement for Sale Return #' . $itemSettlement->saleReturn->reference,
                            'type' => 'DISPATCH_RETURN',
                            'previous_quantity' => $previousQuantity,
                            'after_quantity' => $afterQuantity,
                            'previous_quantity_at_location' => $previousQuantityAtLocation,
                            'after_quantity_at_location' => $afterQuantityAtLocation,
                            'quantity_non_tax' => $taxId ? 0 : 1,
                            'quantity_tax' => $taxId ? 1 : 0,
                            'broken_quantity_non_tax' => 0,
                            'broken_quantity_tax' => 0,
                        ]);

                        // mark the serial as dispatched (sold)
                        $sn->update([
                            'status' => ProductSerialNumber::STATUS_SOLD,
                        ]);

                        // finalize item
                        $itemSettlement->update([
                            'status' => SaleReturnItemSettlement::STATUS_DISPATCHED,
                            'dispatch_approved_at' => now(),
                            'dispatch_approved_by' => Auth::id(),
                        ]);
                        return;
                    }

                    throw new \Exception('Dispatch serial number diperlukan untuk produk serial.');
                }

                // Non-serial product: use stored location_id as source (set during request)
            $sourceLocationId = $itemSettlement->location_id;
            $quantity = $detail->quantity ?? 1;

            if (!$sourceLocationId) {
                // Fallback: search for any location with enough stock
                $availableStock = \Modules\Product\Entities\ProductStock::where('product_id', $product->id)
                    ->where('quantity', '>=', $quantity)
                    ->first();

                if (!$availableStock) {
                    throw new \Exception("Stok tidak tersedia untuk produk {$product->product_name}.");
                }
                $sourceLocationId = $availableStock->location_id;
            }

            $sourceStock = \Modules\Product\Entities\ProductStock::where('product_id', $product->id)
                ->where('location_id', $sourceLocationId)
                ->lockForUpdate()
                ->first();

                if (!$sourceStock) {
                    throw new \Exception("Stok tidak ditemukan untuk produk {$product->product_name} di lokasi sumber.");
                }

                if ($sourceStock->quantity < $quantity) {
                    throw new \Exception("Stok tidak cukup untuk produk {$product->product_name} di lokasi sumber.");
                }

                $taxId = $sourceStock->tax_id ?? null;
                \Modules\Consignment\Services\ConsignmentBuyoutReservationGuard::assertQuantityAvailable(
                    (int) $product->id, (int) $sourceLocationId, ! empty($taxId),
                    (float) ($taxId ? $sourceStock->quantity_tax : $sourceStock->quantity_non_tax), (float) $quantity, $product->product_name
                );
                $previousQuantity = $product->product_quantity;
                $previousQuantityAtLocation = $sourceStock->quantity;

                $sourceStock->decrement('quantity', $quantity);
                if ($taxId) {
                    $sourceStock->decrement('quantity_tax', $quantity);
                } else {
                    $sourceStock->decrement('quantity_non_tax', $quantity);
                }

                $product->decrement('product_quantity', $quantity);

                $afterQuantity = $product->product_quantity;
                $afterQuantityAtLocation = $sourceStock->quantity;

                \Modules\Product\Entities\Transaction::create([
                    'product_id' => $product->id,
                    'setting_id' => session('setting_id'),
                    'quantity' => -$quantity,
                    'current_quantity' => $afterQuantity,
                    'broken_quantity' => 0,
                    'location_id' => $sourceLocationId,
                    'user_id' => Auth::id(),
                    'reason' => 'Dispatch replacement for Sale Return #' . $itemSettlement->saleReturn->reference,
                    'type' => 'DISPATCH_RETURN',
                    'previous_quantity' => $previousQuantity,
                    'after_quantity' => $afterQuantity,
                    'previous_quantity_at_location' => $previousQuantityAtLocation,
                    'after_quantity_at_location' => $afterQuantityAtLocation,
                    'quantity_non_tax' => $taxId ? 0 : $quantity,
                    'quantity_tax' => $taxId ? $quantity : 0,
                    'broken_quantity_non_tax' => 0,
                    'broken_quantity_tax' => 0,
                ]);

                // finalize item
                $itemSettlement->update([
                    'status' => SaleReturnItemSettlement::STATUS_DISPATCHED,
                    'dispatch_approved_at' => now(),
                    'dispatch_approved_by' => Auth::id(),
                ]);
              };
              $applyDispatch();

              $saleReturn = SaleReturn::query()
                  ->whereKey($itemSettlement->sale_return_id)
                  ->lockForUpdate()
                  ->first();

              if ($saleReturn) {
                  $lifecycleSync = app(SaleReturnLifecycleSyncService::class);
                  $actorId = (int) Auth::id();

                  // Dispatched goods settle their share of the return's value (cash paid stays separate).
                  app(\App\Support\SalesReturn\StandardFinancialService::class)->refreshReturnHeader($saleReturn);
                  $lifecycleSync->syncSaleReturnCompletionRollup($saleReturn, $actorId);
                  $lifecycleSync->archiveSourceSaleIfFullyReturnedAndCompleted($saleReturn, $actorId);
              }
            });

            \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($itemSettlement->saleReturn, 'dispatch'));
            \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->resolveRevision($itemSettlement->saleReturn, 'dispatch'));

            return back()->with('success', 'Pengiriman disetujui.');
        } catch (SalesReturnStateException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Failed to approve dispatch', ['error' => $e->getMessage()]);
            return back()->with('error', 'Gagal menyetujui pengiriman.');
        }
    }

    /**
     * Direct dispatch item (from modal on detail page).
     * For items with STATUS_APPROVED_AWAITING_DISPATCH.
     */
    public function dispatchItem(Request $request, SaleReturnItemSettlement $itemSettlement)
    {
        abort_if(Gate::denies('saleReturnSettlements.dispatch'), 403);

        $request->validate([
            'dispatched_serial_number' => 'nullable|string|max:255',
            'dispatch_note' => 'nullable|string|max:500',
            'dispatch_mode' => 'nullable|in:ORIGINAL,REPLACEMENT',
        ]);

        try {
            DB::transaction(function () use ($request, $itemSettlement) {
                // Same prerequisites and lock order as the bulk request: no stock is deducted here,
                // the item only becomes DISPATCH_REQUESTED.
                $locked = app(StandardReturnGuard::class)->lockSettlement($itemSettlement->id);
                app(StandardDispatchRequestService::class)->request(
                    $locked,
                    $request->dispatched_serial_number,
                    null,
                    $request->dispatch_note,
                    $request->dispatch_mode,
                );
            });

            \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->notifyApprovalNeeded(
                $itemSettlement->saleReturn,
                'Pengiriman Retur Penjualan ' . $itemSettlement->saleReturn->reference,
                $itemSettlement->saleReturn->setting_id,
                null,
                'dispatch'
            ));

            return back()->with('success', 'Pengajuan pengiriman berhasil dikirim.');
        } catch (SalesReturnStateException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Failed to request dispatch for item', ['error' => $e->getMessage()]);
            return back()->with('error', 'Gagal mengajukan pengiriman: ' . $e->getMessage());
        }
    }

    public function rejectDispatch(Request $request, SaleReturnItemSettlement $itemSettlement)
    {
        abort_if(Gate::denies('saleReturnSettlements.dispatchApproval'), 403);

        $request->validate(['rejection_reason' => 'required|string|max:255']);

        if ($itemSettlement->status !== SaleReturnItemSettlement::STATUS_DISPATCH_REQUESTED) {
            return back()->with('error', 'Item tidak dalam status pengajuan pengiriman.');
        }

        try {
            DB::transaction(function () use ($request, $itemSettlement) {
                $itemSettlement = app(StandardReturnGuard::class)->lockSettlement($itemSettlement->id);
                if ($itemSettlement->status !== SaleReturnItemSettlement::STATUS_DISPATCH_REQUESTED) {
                    throw new SalesReturnStateException('Item tidak dalam status pengajuan pengiriman.');
                }

                $itemSettlement->update([
                    'status' => SaleReturnItemSettlement::STATUS_APPROVED_AWAITING_DISPATCH,
                    'dispatch_rejected_at' => now(),
                    'dispatch_rejected_by' => Auth::id(),
                    'dispatch_rejection_reason' => $request->rejection_reason,
                    // Nothing was consumed; the identity is re-derived on the next request.
                    'dispatch_kind' => null,
                    'dispatch_serial_number_id' => null,
                ]);
            });

            \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->resolveApproval($itemSettlement->saleReturn, 'dispatch'));
            \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Notification\DocumentNotificationService::class)->notifyRevisionNeeded(
                $itemSettlement->saleReturn,
                'Pengiriman Retur Penjualan ' . $itemSettlement->saleReturn->reference,
                $itemSettlement->saleReturn->setting_id,
                $request->rejection_reason,
                null,
                'dispatch'
            ));

            return back()->with('success', 'Pengajuan pengiriman ditolak.');
        } catch (SalesReturnStateException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Failed to reject dispatch', ['error' => $e->getMessage()]);
            return back()->with('error', 'Gagal menolak pengiriman.');
        }
    }
}
