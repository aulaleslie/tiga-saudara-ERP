<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Policies\ProductPolicy;
use App\Policies\PurchasePolicy;
use App\Policies\SalePolicy;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        Product::class => ProductPolicy::class,
        Purchase::class => PurchasePolicy::class,
        Sale::class => SalePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        Gate::before(function ($user, $ability) {
            $isSuperAdmin = $user->hasRole('Super Admin');
            
            return $isSuperAdmin ? true : null;
        });
    }
}
