<?php

namespace Modules\Pos\Services\Contracts;

interface PosCheckoutPostingAdapter
{
    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     sale_id:int,
     *     dispatch_ids:array<int, int>,
     *     sale_payment_id:int,
     *     receipt_number:string
     * }
     */
    public function post(array $context): array;
}
