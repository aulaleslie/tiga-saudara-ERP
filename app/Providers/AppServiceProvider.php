<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Model::preventLazyLoading(!app()->isProduction());

        // Register Livewire components from modules
        \Livewire\Livewire::component('modules.purchase.modals.payment-term-quick-add-modal', \Modules\Purchase\Livewire\Modals\PaymentTermQuickAddModal::class);
        \Livewire\Livewire::component('modules.people.modals.supplier-quick-add-modal', \App\Livewire\Modules\People\Modals\SupplierQuickAddModal::class);
        \Livewire\Livewire::component('modules.people.modals.customer-quick-add-modal', \App\Livewire\Modules\People\Modals\CustomerQuickAddModal::class);
        \Livewire\Livewire::component('modules.product.modals.product-quick-add-modal', \App\Livewire\Modules\Product\Modals\ProductQuickAddModal::class);
        \Livewire\Livewire::component('modules.setting.modals.tax-quick-add-modal', \Modules\Setting\Livewire\Modals\TaxQuickAddModal::class);
        \Livewire\Livewire::component('modules.setting.modals.unit-quick-add-modal', \Modules\Setting\Livewire\Modals\UnitQuickAddModal::class);
        \Livewire\Livewire::component('modules.product.modals.category-quick-add-modal', \Modules\Product\Livewire\Modals\CategoryQuickAddModal::class);
        \Livewire\Livewire::component('modules.product.modals.brand-quick-add-modal', \Modules\Product\Livewire\Modals\BrandQuickAddModal::class);
    }
}
