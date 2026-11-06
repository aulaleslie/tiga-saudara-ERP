<?php

namespace App\Http\Middleware;

use App\Support\SalesReturn\SalesReturnWorkflowResolver;
use Closure;
use Illuminate\Http\Request;
use Modules\SalesReturn\Entities\SaleReturn;

/**
 * Rejects standard Sales Return mutation routes for POS-owned or mixed-history
 * returns before any controller code runs. Read routes simply do not use it.
 */
class EnsureStandardSalesReturn
{
    public function __construct(
        private readonly SalesReturnWorkflowResolver $resolver,
        private readonly \App\Support\SalesReturn\StandardReturnGuard $guard,
    )
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $route = $request->route();

        foreach (['sale_return', 'saleReturn'] as $name) {
            $param = $route?->parameter($name);
            if ($param !== null) {
                $this->check($param instanceof SaleReturn ? $param : SaleReturn::withArchived()->find($param));
            }
        }

        foreach (['itemSettlement', 'saleReturnPayment'] as $name) {
            $param = $route?->parameter($name);
            if ($param !== null && is_object($param) && method_exists($param, 'saleReturn')) {
                $this->check($param->saleReturn()->withArchived()->first());
            }
        }

        return $next($request);
    }

    private function check(?SaleReturn $saleReturn): void
    {
        if ($saleReturn) {
            $this->guard->assertBusinessAccess($saleReturn);
            $this->resolver->assertStandardMutable($saleReturn);
        }
    }
}
