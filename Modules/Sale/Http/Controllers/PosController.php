<?php

namespace Modules\Sale\Http\Controllers;

use App\Events\PrintJobEvent;
use App\Support\PosLocationResolver;
use App\Support\PosSessionManager;
use App\Models\PosReceipt;
use Exception;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalePayment;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Sale\Http\Requests\StorePosSaleRequest;
use Modules\Setting\Entities\PaymentMethod;
use Throwable;

class PosController extends Controller
{
    private static ?bool $salesHasPaymentMethodIdColumn = null;

    private static ?bool $saleDetailsHasSerialNumbersColumn = null;

    private static ?bool $saleBundleItemsHasTaxIdColumn = null;

    private array $posLocationSettingMap = [];

    public function session()
    {
        abort_if(Gate::denies('pos.access'), 403);

        return view('sale::pos.session');
    }

    public function monitor()
    {
        abort_if(Gate::denies('reports.access'), 403);

        return view('sale::pos.supervisor');
    }

    public function index() {
        abort_if(Gate::denies('pos.access'), 403);
        Cart::instance('sale')->destroy();

        $customers = Customer::all();
        $product_categories = Category::all();

        return view('sale::pos.index', compact('product_categories', 'customers'));
    }


    public function store(StorePosSaleRequest $request) {
        abort_if(Gate::denies('pos.access'), 403);

        $cart = Cart::instance('sale');

        if ($cart->count() === 0) {
            return back()->withErrors(['cart' => 'Cart is empty'])->withInput();
        }

        $customer = Customer::findOrFail($request->customer_id);

        $validated = $request->validated();
        PosLocationResolver::setActiveAssignment((int) data_get($validated, 'pos_location_assignment_id'));
        $paymentsInput = collect(data_get($validated, 'payments', []));
        $shippingAmount = round((float) data_get($validated, 'shipping_amount', 0), 2);
        $tenantPartitions = $this->partitionCartByTenant($cart->content(), $shippingAmount);
        $tenantGroups = $tenantPartitions['groups'];
        $total_amount = $tenantPartitions['totals']['grand'];

        $paymentMethodIds = $paymentsInput
            ->pluck('method_id')
            ->filter()
            ->unique()
            ->values();

        $availableMethods = PaymentMethod::query()
            ->where('is_available_in_pos', true)
            ->whereIn('id', $paymentMethodIds)
            ->get()
            ->keyBy('id');

        $processedPayments = [];
        $runningBalance = $total_amount;
        $overallPaid = 0.0;

        foreach ($paymentsInput as $index => $payment) {
            $methodId = (int) data_get($payment, 'method_id');
            $amount = round((float) data_get($payment, 'amount', 0), 2);

            if ($amount <= 0) {
                continue;
            }

            /** @var PaymentMethod|null $method */
            $method = $availableMethods->get($methodId);

            if (! $method) {
                return back()->withErrors([
                    "payments.$index.method_id" => 'Selected payment method is not available for POS.',
                ])->withInput();
            }

            if (! $method->is_cash && $amount > $runningBalance + 0.00001) {
                return back()->withErrors([
                    "payments.$index.amount" => 'Non-cash payments cannot exceed the remaining balance.',
                ])->withInput();
            }

            $runningBalance = round($runningBalance - $amount, 2);

            if ($runningBalance < -0.01 && ! $method->is_cash) {
                return back()->withErrors([
                    "payments.$index.amount" => 'Overpayment is only allowed for cash payments.',
                ])->withInput();
            }

            if ($runningBalance < 0) {
                $runningBalance = 0.0;
            }

            $processedPayments[] = [
                'method' => $method,
                'amount' => $amount,
            ];

            $overallPaid += $amount;
        }

        $overallPaid = round($overallPaid, 2);
        $allocatablePaid = min($overallPaid, $total_amount);
        $due_amount = max(round($total_amount - $allocatablePaid, 2), 0);

        $uniqueMethodIds = collect($processedPayments)
            ->pluck('method.id')
            ->filter()
            ->unique();

        $hasCashPayment = collect($processedPayments)
            ->contains(function ($payment) {
                /** @var PaymentMethod|null $method */
                $method = $payment['method'] ?? null;

                return $method?->is_cash && ($payment['amount'] ?? 0) > 0;
            });

        $changeDue = $hasCashPayment
            ? max(round($overallPaid - $total_amount, 2), 0.0)
            : 0.0;

        $hasCashOverpayment = $hasCashPayment && $changeDue > 0;

        $primaryPayment = collect($processedPayments)
            ->firstWhere('amount', '>', 0) ?? collect($processedPayments)->first();

        $primaryMethodId = $primaryPayment['method']->id ?? null;
        $primaryMethodName = $primaryPayment['method']->name ?? '';

        if ($uniqueMethodIds->count() > 1) {
            $displayMethodName = 'Multiple';
        } else {
            $displayMethodName = $primaryMethodName;
        }

        if (round($due_amount, 2) >= $total_amount) {
            $payment_status = 'Unpaid';
        } elseif ($due_amount > 0) {
            $payment_status = 'Partial';
        } else {
            $payment_status = 'Paid';
        }

        $posLocationId = PosLocationResolver::resolveId();
        $posSession = $request->attributes->get('pos_session') ?? app(PosSessionManager::class)->ensureActive();

        DB::beginTransaction();

        try {
            $posReceipt = PosReceipt::create([
                'customer_id' => $customer->id,
                'customer_name' => $customer->customer_name,
                'total_amount' => $total_amount,
                'paid_amount' => $overallPaid,
                'due_amount' => $due_amount,
                'change_due' => $changeDue,
                'payment_status' => $payment_status,
                'payment_method' => $displayMethodName,
                'payment_breakdown' => collect($processedPayments)->map(function ($payment) {
                    /** @var PaymentMethod|null $method */
                    $method = $payment['method'] ?? null;

                    return [
                        'method_id' => $method?->id,
                        'method_name' => $method?->name,
                        'amount' => $payment['amount'] ?? 0,
                    ];
                })->values()->all(),
                'note' => $request->note,
                'pos_session_id' => $posSession->id ?? null,
            ]);

            $sales = [];

            foreach ($tenantGroups as $tenantGroup) {
                $saleData = [
                    'date' => now()->format('Y-m-d'),
                    'reference' => 'PSL',
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->customer_name,
                    'tax_percentage' => $request->tax_percentage,
                    'discount_percentage' => $request->discount_percentage,
                    'shipping_amount' => $tenantGroup['shipping'],
                    'paid_amount' => 0,
                    'total_amount' => $tenantGroup['total'],
                    'due_amount' => $tenantGroup['total'],
                    'status' => 'Completed',
                    'payment_status' => 'Unpaid',
                    'payment_method' => $displayMethodName,
                    'note' => $request->note,
                    'tax_amount' => round((float) $tenantGroup['tax_total'], 2),
                    'discount_amount' => round((float) $tenantGroup['discount_total'], 2),
                    'setting_id' => $tenantGroup['tenant_id'],
                    'pos_receipt_id' => $posReceipt->id,
                ];

                if ($this->salesHasPaymentMethodIdColumn()) {
                    $saleData['payment_method_id'] = $primaryMethodId;
                }

                $sale = Sale::create(array_merge($saleData, [
                    'pos_session_id' => $posSession->id ?? null,
                ]));

                $dispatchPlans = $this->persistSaleDetailsFromCart($sale, $tenantGroup['items'], true, $posLocationId);

                $calculatedItemTotal = round((float) $sale->saleDetails()->sum('sub_total'), 2);
                $calculatedTax = round((float) $sale->saleDetails()->sum('product_tax_amount'), 2);
                $calculatedDiscount = round((float) $sale->saleDetails()->sum('product_discount_amount'), 2);
                $saleTotal = round($calculatedItemTotal + $tenantGroup['shipping'], 2);

                $sale->update([
                    'total_amount' => $saleTotal,
                    'tax_amount' => $calculatedTax,
                    'discount_amount' => $calculatedDiscount,
                    'due_amount' => $saleTotal,
                ]);

                if (! empty($dispatchPlans)) {
                    $this->createDispatchesForSale($sale, $dispatchPlans);
                }

                $sales[] = $sale;
            }

            $remainingPerSale = [];
            $allocatedPerSale = [];

            foreach ($sales as $sale) {
                $remainingPerSale[$sale->id] = round((float) $sale->total_amount, 2);
                $allocatedPerSale[$sale->id] = 0.0;
            }

            $allocatableTotal = min($overallPaid, array_sum($remainingPerSale));
            $distributedTotal = 0.0;

            foreach ($processedPayments as $payment) {
                /** @var PaymentMethod $method */
                $method = $payment['method'];

                $available = min($payment['amount'], max(0, $allocatableTotal - $distributedTotal));
                $pending = $available;

                foreach ($sales as $sale) {
                    $needed = $remainingPerSale[$sale->id] ?? 0;

                    if ($needed <= 0 || $pending <= 0) {
                        continue;
                    }

                    $allocation = min($pending, $needed);

                    SalePayment::create([
                        'date' => now()->format('Y-m-d'),
                        'reference' => 'INV/' . $sale->reference,
                        'amount' => $allocation,
                        'sale_id' => $sale->id,
                        'pos_session_id' => $posSession->id ?? null,
                        'payment_method_id' => $method->id,
                        'payment_method' => $method->name,
                        'pos_receipt_id' => $posReceipt->id,
                    ]);

                    $remainingPerSale[$sale->id] = round($remainingPerSale[$sale->id] - $allocation, 2);
                    $allocatedPerSale[$sale->id] = round($allocatedPerSale[$sale->id] + $allocation, 2);
                    $pending = round($pending - $allocation, 2);
                    $distributedTotal = round($distributedTotal + $allocation, 2);
                }
            }

            foreach ($sales as $sale) {
                $paid = min($allocatedPerSale[$sale->id] ?? 0.0, (float) $sale->total_amount);
                $due = max(round((float) $sale->total_amount - $paid, 2), 0);

                if ($due >= $sale->total_amount) {
                    $status = 'Unpaid';
                } elseif ($due > 0) {
                    $status = 'Partial';
                } else {
                    $status = 'Paid';
                }

                $sale->update([
                    'paid_amount' => $paid,
                    'due_amount' => $due,
                    'payment_status' => $status,
                    'payment_method' => $displayMethodName,
                ]);
            }

            $posReceipt->update([
                'total_amount' => $total_amount,
                'paid_amount' => $overallPaid,
                'due_amount' => $due_amount,
                'change_due' => $changeDue,
                'payment_status' => $payment_status,
                'payment_method' => $displayMethodName,
            ]);

            DB::commit();

            $posReceipt->loadMissing(['sales.saleDetails.product', 'sales.tenantSetting']);

            $this->triggerReceiptPrint($posReceipt);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to create POS sale', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->withErrors(['error' => 'Failed to create POS sale.'])->withInput();
        }

        $cart->destroy();

        session()->flash('pos_change_due', $changeDue);
        session()->flash('pos_cash_overpayment', $hasCashOverpayment);
        session()->flash('pos_sale_completed', true);

        toast('POS Sale Created!', 'success');

        return redirect()->route('app.pos.index');
    }

    public function storeAsQuotation(Request $request)
    {
        abort_if(Gate::denies('pos.access'), 403);
        $cart = Cart::instance('sale');

        if ($cart->count() == 0) {
            return back()->withErrors(['cart' => 'Cart is empty'])->withInput();
        }

        $posLocationId = PosLocationResolver::resolveId();

        DB::beginTransaction();
        try {
            $customer = Customer::findOrFail($request->customer_id);
            $sale = Sale::create([
                'date' => now(),
                'due_date' => now()->addDays(7), // or null if N/A
                'customer_id' => $customer->id,
                'customer_name' => $customer->customer_name,
                'tax_percentage' => $request->tax_percentage ?? 0,
                'discount_percentage' => $request->discount_percentage ?? 0,
                'shipping_amount' => round((float) $request->input('shipping_amount', 0), 2),
                'total_amount' => round((float) $request->total_amount, 2),
                'paid_amount' => 0,
                'due_amount' => round((float) $request->total_amount, 2),
                'status' => Sale::STATUS_DRAFTED,
                'payment_status' => 'Unpaid',
                'payment_method' => '', // leave blank if unknown
                'note' => $request->note ?? '',
                'setting_id' => session('setting_id'),
                'is_tax_included' => false,
                'tax_amount' => round((float) $cart->tax(), 2),
                'discount_amount' => round((float) $cart->discount(), 2),
                'pos_session_id' => $posSession->id ?? null,
            ]);

            $this->persistSaleDetailsFromCart($sale, $cart->content(), false, $posLocationId);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to create quotation from POS', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->withErrors(['error' => 'Failed to save quotation.'])->withInput();
        }

        $cart->destroy();

        toast('Dokumen penjualan disimpan sebagai draft!', 'success');

        return redirect()->route('app.pos.index');
    }

    private function partitionCartByTenant($cartItems, float $shippingAmount): array
    {
        $items = collect($this->expandCartItemsBySetting($cartItems));

        $productIds = $items
            ->map(function ($item) {
                $options = $this->normalizeCartOptions($item->options ?? []);

                return (int) ($options['product_id'] ?? 0);
            })
            ->filter()
            ->unique();

        $productSettings = $productIds->isNotEmpty()
            ? Product::query()->whereIn('id', $productIds)->pluck('setting_id', 'id')->toArray()
            : [];

        $groups = [];
        $overallItemTotal = 0.0;
        $overallTax = 0.0;
        $overallDiscount = 0.0;

        foreach ($items as $item) {
            $options = $this->normalizeCartOptions($item->options ?? []);
            $tenantId = $this->resolveTenantIdForCartItem($options, $productSettings);

            $lineSubTotal = round((float) ($options['sub_total'] ?? ($item->price * $item->qty)), 2);
            $lineTax = round((float) ($options['product_tax_amount'] ?? ($options['tax_amount'] ?? 0)), 2);
            $lineDiscount = round((float) ($options['product_discount'] ?? ($options['product_discount_amount'] ?? 0)), 2);

            $overallItemTotal = round($overallItemTotal + $lineSubTotal, 2);
            $overallTax = round($overallTax + $lineTax, 2);
            $overallDiscount = round($overallDiscount + $lineDiscount, 2);

            if (! isset($groups[$tenantId])) {
                $groups[$tenantId] = [
                    'tenant_id' => $tenantId,
                    'items' => [],
                    'item_total' => 0.0,
                    'tax_total' => 0.0,
                    'discount_total' => 0.0,
                    'shipping' => 0.0,
                    'total' => 0.0,
                ];
            }

            $groups[$tenantId]['items'][] = $item;
            $groups[$tenantId]['item_total'] = round($groups[$tenantId]['item_total'] + $lineSubTotal, 2);
            $groups[$tenantId]['tax_total'] = round($groups[$tenantId]['tax_total'] + $lineTax, 2);
            $groups[$tenantId]['discount_total'] = round($groups[$tenantId]['discount_total'] + $lineDiscount, 2);
        }

        $groups = array_values($groups);
        $groupCount = count($groups);
        $remainingShipping = $shippingAmount;

        foreach ($groups as $index => &$group) {
            $share = 0.0;

            if ($groupCount > 0) {
                if ($overallItemTotal > 0) {
                    $share = round($shippingAmount * ($group['item_total'] / $overallItemTotal), 2);
                } else {
                    $share = round($shippingAmount / $groupCount, 2);
                }

                if ($index === $groupCount - 1) {
                    $share = round($remainingShipping, 2);
                } else {
                    $remainingShipping = round($remainingShipping - $share, 2);
                }
            }

            $group['shipping'] = $share;
            $group['total'] = round($group['item_total'] + $share, 2);
        }

        unset($group);

        $grandTotal = array_sum(array_map(fn ($group) => $group['total'], $groups));

        return [
            'groups' => $groups,
            'totals' => [
                'items' => round($overallItemTotal, 2),
                'tax' => round($overallTax, 2),
                'discount' => round($overallDiscount, 2),
                'shipping' => $shippingAmount,
                'grand' => round($grandTotal, 2),
            ],
        ];
    }

    private function expandCartItemsBySetting($cartItems): array
    {
        $items = [];
        $locationSettingMap = $this->loadPosLocationSettingMap();

        foreach ($cartItems as $item) {
            $options = $this->normalizeCartOptions($item->options ?? []);
            $allocations = $this->normalizeAllocations($options['pos_location_allocations'] ?? []);
            $serials = $this->resolveSerialNumbers($options) ?? [];
            $serialsByLocation = [];

            foreach ($serials as $serial) {
                $locationId = isset($serial['location_id']) ? (int) $serial['location_id'] : null;
                if ($locationId) {
                    $serialsByLocation[$locationId][] = $serial;
                }
            }

            if (empty($allocations)) {
                $items[] = $item;
                continue;
            }

            $qty = (int) $item->qty;
            $totalAllocQty = 0;
            $bySetting = [];

            foreach ($allocations as $allocation) {
                $locationId = (int) ($allocation['location_id'] ?? 0);
                $settingId = $locationId > 0 ? ($locationSettingMap[$locationId] ?? null) : null;

                if (! $settingId) {
                    $settingId = $this->resolveTenantIdForCartItem($options, []);
                }

                $allocationQty = max(0, (int) ($allocation['allocated_non_tax'] ?? 0) + (int) ($allocation['allocated_tax'] ?? 0));

                if ($allocationQty <= 0) {
                    continue;
                }

                $totalAllocQty += $allocationQty;

                if (! isset($bySetting[$settingId])) {
                    $bySetting[$settingId] = [
                        'setting_id' => $settingId,
                        'allocations' => [],
                        'quantity' => 0,
                    ];
                }

                $bySetting[$settingId]['allocations'][] = $allocation + ['setting_id' => $settingId];
                $bySetting[$settingId]['quantity'] += $allocationQty;
            }

            if ($totalAllocQty <= 0 || empty($bySetting)) {
                $items[] = $item;
                continue;
            }

            foreach ($bySetting as $settingId => $group) {
                $portionQty = $group['quantity'];

                if ($portionQty <= 0) {
                    continue;
                }

                $ratio = $qty > 0 ? ($portionQty / $qty) : 1.0;

                $newOptions = $options;
                $newOptions['setting_id'] = $settingId;
                $newOptions['allocated_non_tax'] = $this->sumAllocationField($group['allocations'], 'allocated_non_tax');
                $newOptions['allocated_tax'] = $this->sumAllocationField($group['allocations'], 'allocated_tax');
                $newOptions['pos_location_allocations'] = array_values($group['allocations']);

                if (! empty($serialsByLocation)) {
                    $filteredSerials = [];
                    foreach ($group['allocations'] as $allocation) {
                        $locationId = (int) ($allocation['location_id'] ?? 0);
                        if ($locationId && isset($serialsByLocation[$locationId])) {
                            $filteredSerials = array_merge($filteredSerials, $serialsByLocation[$locationId]);
                        }
                    }

                    if (! empty($filteredSerials)) {
                        $newOptions['serial_numbers'] = $filteredSerials;
                    }
                }

                $items[] = $this->cloneCartItemWithQuantity($item, $portionQty, $ratio, $newOptions);
            }
        }

        return $items;
    }

    private function loadPosLocationSettingMap(): array
    {
        if (! empty($this->posLocationSettingMap)) {
            return $this->posLocationSettingMap;
        }

        $map = SettingSaleLocation::query()
            ->select(['location_id', 'setting_id'])
            ->where('is_pos', true)
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->location_id => (int) $row->setting_id])
            ->toArray();

        $this->posLocationSettingMap = $map;

        return $this->posLocationSettingMap;
    }

    private function normalizeAllocations($allocations): array
    {
        if ($allocations instanceof \Illuminate\Support\Collection) {
            $allocations = $allocations->toArray();
        } elseif (is_object($allocations) && method_exists($allocations, 'toArray')) {
            $allocations = $allocations->toArray();
        }

        if (! is_array($allocations)) {
            $allocations = (array) $allocations;
        }

        $result = [];

        foreach ($allocations as $allocation) {
            if ($allocation instanceof \Illuminate\Support\Collection) {
                $allocation = $allocation->toArray();
            } elseif (is_object($allocation) && method_exists($allocation, 'toArray')) {
                $allocation = $allocation->toArray();
            } elseif (is_object($allocation)) {
                $allocation = (array) $allocation;
            }

            if (! is_array($allocation)) {
                continue;
            }

            $result[] = $allocation;
        }

        return $result;
    }

    private function sumAllocationField(array $allocations, string $field): int
    {
        $total = 0;

        foreach ($allocations as $allocation) {
            $total += max(0, (int) ($allocation[$field] ?? 0));
        }

        return $total;
    }

    private function cloneCartItemWithQuantity($item, int $quantity, float $ratio, array $options)
    {
        $subTotal = $options['sub_total'] ?? ($item->price * $item->qty);
        $discount = $options['product_discount'] ?? ($options['product_discount_amount'] ?? 0);
        $taxAmount = $options['tax_amount'] ?? ($options['product_tax_amount'] ?? 0);

        $options['sub_total'] = round($subTotal * $ratio, 2);
        $options['product_discount'] = round($discount * $ratio, 2);
        $options['product_tax_amount'] = round($taxAmount * $ratio, 2);

        return (object) [
            'name' => $item->name,
            'price' => $item->price,
            'qty' => $quantity,
            'options' => $options,
        ];
    }

    private function resolveTenantIdForCartItem(array $options, array $productSettings): int
    {
        $tenantId = (int) ($options['setting_id'] ?? 0);
        $productId = (int) ($options['product_id'] ?? 0);

        if ($tenantId <= 0 && $productId > 0) {
            $tenantId = (int) ($productSettings[$productId] ?? 0);
        }

        if ($tenantId <= 0) {
            $tenantId = (int) session('setting_id');
        }

        return $tenantId;
    }

    private function persistSaleDetailsFromCart(Sale $sale, $cartItems, bool $adjustInventory, ?int $posLocationId = null): array
    {
        $dispatchPlans = [];

        foreach ($cartItems as $cartItem) {
            $detailData = $this->mapCartItemToSaleDetailData($sale, $cartItem);
            $saleDetail = SaleDetails::create($detailData);

            $this->createBundleItemsFromCartItem($sale, $saleDetail, $cartItem);

            if ($adjustInventory) {
                $productId = $saleDetail->product_id;
                if (! $productId) {
                    continue;
                }

                $allocations = $this->applyInventoryAdjustments($sale, $saleDetail, $cartItem, $posLocationId);

                foreach ($allocations as $allocation) {
                    $quantity = max(0, (int) ($allocation['allocated_non_tax'] ?? 0) + (int) ($allocation['allocated_tax'] ?? 0));
                    if ($quantity <= 0) {
                        continue;
                    }

                    $dispatchPlans[] = [
                        'sale_detail_id' => $saleDetail->id,
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'location_id' => (int) ($allocation['location_id'] ?? 0),
                        'tax_id' => $quantity > 0 && ($allocation['allocated_tax'] ?? 0) > 0 ? ($saleDetail->tax_id ?: null) : null,
                        'serial_numbers' => $saleDetail->serial_numbers ?? null,
                    ];
                }
            }
        }

        return $dispatchPlans;
    }

    private function applyInventoryAdjustments(Sale $sale, SaleDetails $saleDetail, $cartItem, ?int $posLocationId): array
    {
        $options = $this->normalizeCartOptions($cartItem->options ?? []);
        $productId = (int) ($options['product_id'] ?? 0);

        $rawAllocations = $options['pos_location_allocations'] ?? [];

        if ($rawAllocations instanceof \Illuminate\Support\Collection) {
            $rawAllocations = $rawAllocations->toArray();
        } elseif (is_object($rawAllocations) && method_exists($rawAllocations, 'toArray')) {
            $rawAllocations = $rawAllocations->toArray();
        } elseif (is_string($rawAllocations)) {
            $decoded = json_decode($rawAllocations, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $rawAllocations = $decoded;
            }
        }

        if (! is_array($rawAllocations)) {
            $rawAllocations = (array) $rawAllocations;
        }

        $byLocation = [];

        foreach ($rawAllocations as $allocation) {
            if ($allocation instanceof \Illuminate\Support\Collection) {
                $allocation = $allocation->toArray();
            } elseif (is_object($allocation) && method_exists($allocation, 'toArray')) {
                $allocation = $allocation->toArray();
            } elseif (is_object($allocation)) {
                $allocation = (array) $allocation;
            }

            if (! is_array($allocation)) {
                continue;
            }

            $locationId = (int) ($allocation['location_id'] ?? 0);
            if ($locationId <= 0) {
                continue;
            }

            $nonTax = max(0, (int) ($allocation['allocated_non_tax'] ?? 0));
            $tax = max(0, (int) ($allocation['allocated_tax'] ?? 0));

            if ($nonTax === 0 && $tax === 0) {
                continue;
            }

            if (! isset($byLocation[$locationId])) {
                $byLocation[$locationId] = [
                    'location_id' => $locationId,
                    'allocated_non_tax' => 0,
                    'allocated_tax' => 0,
                ];
            }

            $byLocation[$locationId]['allocated_non_tax'] += $nonTax;
            $byLocation[$locationId]['allocated_tax'] += $tax;
        }

        $normalizedAllocations = array_values($byLocation);

        $requestedQuantity = max(0, (int) $saleDetail->quantity);

        if (empty($normalizedAllocations) && $productId) {
            $fallbackLocationId = (int) ($options['pos_location_id'] ?? $posLocationId ?? 0);
            if ($fallbackLocationId > 0 && $requestedQuantity > 0) {
                $fallbackNonTax = max(0, (int) ($options['allocated_non_tax'] ?? 0));
                $fallbackTax = max(0, (int) ($options['allocated_tax'] ?? 0));

                $nonTax = min($fallbackNonTax, $requestedQuantity);
                $tax = max(0, min($fallbackTax, $requestedQuantity - $nonTax));

                $normalizedAllocations[] = [
                    'location_id' => $fallbackLocationId,
                    'allocated_non_tax' => $nonTax,
                    'allocated_tax' => $tax,
                ];
            }
        }

        $totalAllocated = 0;
        foreach ($normalizedAllocations as $entry) {
            $totalAllocated += (int) $entry['allocated_non_tax'] + (int) $entry['allocated_tax'];
        }

        if ($requestedQuantity > 0 && $totalAllocated < $requestedQuantity && ! empty($normalizedAllocations)) {
            $remaining = $requestedQuantity - $totalAllocated;
            $normalizedAllocations[0]['allocated_non_tax'] += $remaining;

            Log::warning('POS sale allocations underspecified; padding first location', [
                'product_id' => $productId,
                'sale_id' => $sale->id,
                'sale_detail_id' => $saleDetail->id,
                'remaining' => $remaining,
            ]);
        }

        if ($productId) {
            foreach ($normalizedAllocations as $allocation) {
                $locationId = (int) ($allocation['location_id'] ?? 0);
                if ($locationId <= 0) {
                    continue;
                }

                $deductNonTax = max(0, (int) ($allocation['allocated_non_tax'] ?? 0));
                $deductTax = max(0, (int) ($allocation['allocated_tax'] ?? 0));

                if ($deductNonTax === 0 && $deductTax === 0) {
                    continue;
                }

                $this->deductProductStock($productId, $locationId, $deductNonTax, $deductTax, $sale);
            }
        }

        if ($productId) {
            $product = Product::query()->lockForUpdate()->find($productId);

            if ($product) {
                $newQuantity = max(0, (int) $product->product_quantity - (int) $saleDetail->quantity);
                $product->update(['product_quantity' => $newQuantity]);
            } else {
                Log::warning('Product not found when adjusting POS stock summary', [
                    'product_id' => $productId,
                    'sale_id' => $sale->id,
                ]);
            }
        }

        $this->markSerialNumbersAsSold($saleDetail, $options);

        return $normalizedAllocations;
    }

    private function createDispatchesForSale(Sale $sale, array $dispatchPlans): void
    {
        if (empty($dispatchPlans)) {
            return;
        }

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->format('Y-m-d'),
        ]);

        foreach ($dispatchPlans as $plan) {
            if (($plan['quantity'] ?? 0) <= 0) {
                continue;
            }

            DispatchDetail::create([
                'dispatch_id' => $dispatch->id,
                'sale_id' => $sale->id,
                'product_id' => $plan['product_id'] ?? null,
                'dispatched_quantity' => $plan['quantity'] ?? 0,
                'location_id' => $plan['location_id'] ?? null,
                'serial_numbers' => $plan['serial_numbers'] ?? null,
                'tax_id' => $plan['tax_id'] ?? null,
            ]);
        }
    }
    private function deductProductStock(int $productId, int $locationId, int $deductNonTax, int $deductTax, Sale $sale): void
    {
        if ($deductNonTax <= 0 && $deductTax <= 0) {
            return;
        }

        $stock = ProductStock::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            Log::warning('POS sale could not locate stock row for deduction', [
                'product_id' => $productId,
                'location_id' => $locationId,
                'sale_id' => $sale->id,
            ]);
            return;
        }

        $availableNonTax = max(0, (int) $stock->quantity_non_tax);
        $availableTax = max(0, (int) $stock->quantity_tax);

        $nonTaxToDeduct = min($deductNonTax, $availableNonTax);
        $taxToDeduct = min($deductTax, $availableTax);

        if ($deductNonTax > $availableNonTax || $deductTax > $availableTax) {
            Log::warning('POS sale deduction exceeded available stock for location', [
                'product_id' => $productId,
                'location_id' => $locationId,
                'sale_id' => $sale->id,
                'requested_non_tax' => $deductNonTax,
                'requested_tax' => $deductTax,
                'available_non_tax' => $availableNonTax,
                'available_tax' => $availableTax,
            ]);
        }

        $stock->quantity_non_tax = max(0, $availableNonTax - $nonTaxToDeduct);
        $stock->quantity_tax = max(0, $availableTax - $taxToDeduct);
        $stock->quantity = max(0, (int) $stock->quantity_non_tax + (int) $stock->quantity_tax);
        $stock->broken_quantity = max(0, (int) $stock->broken_quantity_non_tax + (int) $stock->broken_quantity_tax);
        $stock->save();
    }
    private function markSerialNumbersAsSold(SaleDetails $saleDetail, array $options): void
    {
        $serials = $this->resolveSerialNumbers($options);
        if (! $serials) {
            return;
        }

        $serialIds = collect($serials)
            ->map(fn ($serial) => (int) ($serial['id'] ?? 0))
            ->filter()
            ->values();

        if ($serialIds->isEmpty()) {
            return;
        }

        $records = ProductSerialNumber::query()
            ->whereIn('id', $serialIds)
            ->lockForUpdate()
            ->get();

        foreach ($records as $record) {
            $record->dispatch_detail_id = $saleDetail->id;
            $record->save();
        }

        if (! $saleDetail->tax_id) {
            $taxIds = $records->pluck('tax_id')->filter()->unique()->values();
            if ($taxIds->count() === 1) {
                $saleDetail->tax_id = $taxIds->first();
                $saleDetail->save();
            }
        }
    }

    private function mapCartItemToSaleDetailData(Sale $sale, $cartItem): array
    {
        $options = $this->normalizeCartOptions($cartItem->options ?? []);

        $unitPrice = $options['unit_price'] ?? $cartItem->price;
        $subTotal = $options['sub_total'] ?? ($cartItem->price * $cartItem->qty);
        $discountAmount = $options['product_discount'] ?? 0;
        $discountType = $options['product_discount_type'] ?? 'fixed';
        $taxAmount = $options['tax_amount'] ?? ($options['product_tax_amount'] ?? 0);
        $taxId = $options['resolved_tax_id'] ?? ($options['product_tax'] ?? ($options['tax_id'] ?? null));

        if ($taxId === '' || $taxId === 0 || $taxId === '0') {
            $taxId = null;
        }

        if ($taxId === null && ! empty($options['serial_tax_ids'])) {
            $uniqueTaxIds = array_values(array_unique(array_filter(array_map('intval', (array) $options['serial_tax_ids']))));
            if (count($uniqueTaxIds) === 1) {
                $taxId = $uniqueTaxIds[0];
            }
        }

        $data = [
            'sale_id' => $sale->id,
            'product_id' => $options['product_id'] ?? null,
            'tax_id' => $taxId !== null ? (int) $taxId : null,
            'product_name' => $cartItem->name,
            'product_code' => $options['code'] ?? '',
            'quantity' => (int) $cartItem->qty,
            'price' => round((float) $cartItem->price, 2),
            'unit_price' => round((float) $unitPrice, 2),
            'sub_total' => round((float) $subTotal, 2),
            'product_discount_amount' => round((float) $discountAmount, 2),
            'product_discount_type' => $discountType,
            'product_tax_amount' => round((float) $taxAmount, 2),
        ];

        if ($this->saleDetailsHasSerialNumbersColumn()) {
            $serials = $this->resolveSerialNumbers($options);
            $data['serial_numbers'] = $serials ?: null;
        }

        return $data;
    }

    private function createBundleItemsFromCartItem(Sale $sale, SaleDetails $saleDetail, $cartItem): void
    {
        $options = $this->normalizeCartOptions($cartItem->options ?? []);
        $bundleItems = $options['bundle_items'] ?? [];

        if ($bundleItems instanceof \Illuminate\Support\Collection) {
            $bundleItems = $bundleItems->toArray();
        } elseif (is_object($bundleItems)) {
            $bundleItems = (array) $bundleItems;
        }

        if (! is_array($bundleItems) || empty($bundleItems)) {
            return;
        }

        foreach ($bundleItems as $bundleItem) {
            if ($bundleItem instanceof \Illuminate\Support\Collection) {
                $bundleItem = $bundleItem->toArray();
            } elseif (is_object($bundleItem)) {
                $bundleItem = (array) $bundleItem;
            }

            if (empty($bundleItem)) {
                continue;
            }

            $bundleId = $bundleItem['bundle_id'] ?? ($options['bundle_id'] ?? null);
            $bundleItemId = $bundleItem['bundle_item_id'] ?? null;

            if ($bundleId === null || $bundleItemId === null) {
                Log::warning('Skipping POS sale bundle item due to missing identifiers', [
                    'sale_id' => $sale->id,
                    'sale_detail_id' => $saleDetail->id,
                    'bundle_item' => $bundleItem,
                ]);
                continue;
            }

            $bundlePayload = [
                'sale_detail_id' => $saleDetail->id,
                'sale_id' => $sale->id,
                'bundle_id' => $bundleId,
                'bundle_item_id' => $bundleItemId,
                'product_id' => $bundleItem['product_id'] ?? null,
                'name' => $bundleItem['name'] ?? '',
                'price' => round((float) ($bundleItem['price'] ?? 0), 2),
                'quantity' => (int) ($bundleItem['quantity'] ?? 0),
                'sub_total' => round((float) ($bundleItem['sub_total'] ?? 0), 2),
            ];

            if ($this->saleBundleItemsHasTaxIdColumn()) {
                $bundlePayload['tax_id'] = $saleDetail->tax_id;
            }

            SaleBundleItem::create($bundlePayload);
        }
    }

    private function triggerReceiptPrint(?PosReceipt $receipt): void
    {
        $userId = auth()->id();

        if (! $userId || ! $receipt) {
            return;
        }

        try {
            $receipt->loadMissing(['sales.saleDetails.product', 'sales.tenantSetting']);
            $htmlContent = view('sale::print-pos', [
                'receipt' => $receipt,
            ])->render();
        } catch (Throwable $throwable) {
            Log::error('Failed to render POS sale receipt for printing', [
                'receipt_id' => $receipt->id,
                'error' => $throwable->getMessage(),
            ]);

            return;
        }

        event(new PrintJobEvent($htmlContent, 'pos-sale', (int) $userId));
    }

    private function normalizeCartOptions($options): array
    {
        if ($options instanceof \Illuminate\Support\Collection) {
            return $options->toArray();
        }

        if (is_object($options) && method_exists($options, 'toArray')) {
            return $options->toArray();
        }

        if (is_array($options)) {
            return $options;
        }

        return (array) $options;
    }

    private function resolveSerialNumbers(array $options): ?array
    {
        $serials = $options['serial_numbers'] ?? null;

        if ($serials instanceof \Illuminate\Support\Collection) {
            $serials = $serials->toArray();
        } elseif (is_object($serials) && method_exists($serials, 'toArray')) {
            $serials = $serials->toArray();
        } elseif (is_string($serials)) {
            $decoded = json_decode($serials, true);
            $serials = json_last_error() === JSON_ERROR_NONE ? $decoded : [$serials];
        } elseif ($serials !== null && ! is_array($serials)) {
            $serials = (array) $serials;
        }

        if (! is_array($serials) || empty($serials)) {
            return null;
        }

        $normalized = [];
        $missingTaxLookup = [];

        foreach ($serials as $serial) {
            if ($serial instanceof \Illuminate\Support\Collection) {
                $serial = $serial->toArray();
            } elseif (is_object($serial) && method_exists($serial, 'toArray')) {
                $serial = $serial->toArray();
            } elseif (is_object($serial)) {
                $serial = (array) $serial;
            }

            if ($serial === null || $serial === []) {
                continue;
            }

            $serial['id'] = isset($serial['id']) ? (int) $serial['id'] : null;
            if (! isset($serial['tax_id']) || $serial['tax_id'] === '' || $serial['tax_id'] === null) {
                if ($serial['id']) {
                    $missingTaxLookup[] = $serial['id'];
                }
                $serial['tax_id'] = null;
            } else {
                $serial['tax_id'] = (int) $serial['tax_id'];
            }

            $normalized[] = $serial;
        }

        if (! empty($missingTaxLookup)) {
            $taxMap = ProductSerialNumber::query()
                ->whereIn('id', $missingTaxLookup)
                ->pluck('tax_id', 'id');

            foreach ($normalized as &$entry) {
                $id = $entry['id'] ?? null;
                if ($id && ($entry['tax_id'] === null || $entry['tax_id'] === '')) {
                    $taxId = $taxMap[$id] ?? null;
                    $entry['tax_id'] = $taxId !== null ? (int) $taxId : null;
                }
            }
            unset($entry);
        }

        return $normalized ?: null;
    }

    private function salesHasPaymentMethodIdColumn(): bool
    {
        if (self::$salesHasPaymentMethodIdColumn === null) {
            self::$salesHasPaymentMethodIdColumn = Schema::hasColumn('sales', 'payment_method_id');
        }

        return self::$salesHasPaymentMethodIdColumn;
    }

    private function saleDetailsHasSerialNumbersColumn(): bool
    {
        if (self::$saleDetailsHasSerialNumbersColumn === null) {
            self::$saleDetailsHasSerialNumbersColumn = Schema::hasColumn('sale_details', 'serial_numbers');
        }

        return self::$saleDetailsHasSerialNumbersColumn;
    }

    private function saleBundleItemsHasTaxIdColumn(): bool
    {
        if (self::$saleBundleItemsHasTaxIdColumn === null) {
            self::$saleBundleItemsHasTaxIdColumn = Schema::hasColumn('sale_bundle_items', 'tax_id');
        }

        return self::$saleBundleItemsHasTaxIdColumn;
    }
}
