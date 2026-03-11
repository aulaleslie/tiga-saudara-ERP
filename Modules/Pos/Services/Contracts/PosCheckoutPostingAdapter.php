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
     *     receipt_number:string,
     *     actual_tax_total?:float,
     *     actual_grand_total?:float,
     *     split_groups?:array<int, array<string, mixed>>,
     *     sales?:array<int, array<string, mixed>>,
     *     sale_payments?:array<int, array<string, mixed>>,
     *     split_summary?:array<string, mixed>
     * }
     */
    public function post(array $context): array;
}
