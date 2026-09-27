<?php

namespace Modules\Pos\Services\Contracts;

interface PosCashDrawerAdapter
{
    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     success:bool,
     *     message:string
     * }
     */
    public function openDrawer(array $context): array;
}
