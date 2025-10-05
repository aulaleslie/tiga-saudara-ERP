<?php

namespace Modules\SalesReturn\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\SalesReturn\Entities\SaleReturn;

class QueueSaleReturnReplacementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $saleReturnId)
    {
    }

    public function handle(): void
    {
        $saleReturn = SaleReturn::with(['saleReturnGoods', 'sale', 'location'])->find($this->saleReturnId);

        if (! $saleReturn) {
            return;
        }

        Log::info('Queued sale return replacement follow-up', [
            'sale_return_id' => $saleReturn->id,
            'reference' => $saleReturn->reference,
            'goods' => $saleReturn->saleReturnGoods->map(function ($good) {
                return [
                    'product_id' => $good->product_id,
                    'quantity' => $good->quantity,
                    'product_name' => $good->product_name,
                ];
            })->values()->all(),
        ]);
    }
}
