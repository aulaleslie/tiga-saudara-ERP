<?php

namespace Modules\Product\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Factory;

class ProductServiceProvider extends ServiceProvider
{
    /**
     * @var string $moduleName
     */
    protected $moduleName = 'Product';

    /**
     * @var string $moduleNameLower
     */
    protected $moduleNameLower = 'product';

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->registerLivewireComponents();
        $this->loadMigrationsFrom(module_path($this->moduleName, 'Database/Migrations'));
        
        $this->commands([
            \Modules\Product\Console\NormalizeProductPurchasePricesCommand::class,
            \Modules\Product\Console\SeedAverageCostFromSalesHppCommand::class,
            \Modules\Product\Console\FillAverageCostFromLastPurchasePriceCommand::class,
            \Modules\Product\Console\PreflightBarcodesCommand::class,
            \Modules\Product\Console\ExportBarcodesCommand::class,
            \Modules\Product\Console\ImportBarcodesCommand::class,
            \Modules\Product\Console\ExportTigaNusaPricesCommand::class,
            \Modules\Product\Console\GenerateEan13BarcodesCommand::class,
            \Modules\Product\Console\PreflightIdentityCollisionsCommand::class,
            \Modules\Product\Console\ReconcileCatalogGroupCommand::class,
        ]);
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->register(RouteServiceProvider::class);
    }

    /**
     * Register config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->publishes([
            module_path($this->moduleName, 'Config/config.php') => config_path($this->moduleNameLower . '.php'),
        ], 'config');
        $this->mergeConfigFrom(
            module_path($this->moduleName, 'Config/config.php'), $this->moduleNameLower
        );
    }

    /**
     * Register views.
     *
     * @return void
     */
    public function registerViews()
    {
        $viewPath = resource_path('views/modules/' . $this->moduleNameLower);

        $sourcePath = module_path($this->moduleName, 'Resources/views');

        $this->publishes([
            $sourcePath => $viewPath
        ], ['views', $this->moduleNameLower . '-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->moduleNameLower);
    }

    /**
     * Register Livewire components.
     *
     * @return void
     */
    public function registerLivewireComponents()
    {
        \Livewire\Livewire::component('modules.product.modals.category-quick-add-modal', \Modules\Product\Livewire\Modals\CategoryQuickAddModal::class);
        \Livewire\Livewire::component('modules.product.modals.brand-quick-add-modal', \Modules\Product\Livewire\Modals\BrandQuickAddModal::class);
        \Livewire\Livewire::component('modules.product.category-search-dropdown', \Modules\Product\Livewire\CategorySearchDropdown::class);
        \Livewire\Livewire::component('modules.product.brand-search-dropdown', \Modules\Product\Livewire\BrandSearchDropdown::class);
        \Livewire\Livewire::component('modules.product.product-search-dropdown', \Modules\Product\Livewire\ProductSearchDropdown::class);
        \Livewire\Livewire::component('modules.product.tax-search-dropdown', \Modules\Product\Livewire\TaxSearchDropdown::class);
        \Livewire\Livewire::component('modules.product.unit-search-dropdown', \Modules\Product\Livewire\UnitSearchDropdown::class);
        \Livewire\Livewire::component('modules.product.product-price-setup', \Modules\Product\Livewire\ProductPriceSetup::class);
        \Livewire\Livewire::component('modules.product.sale-price-setup', \Modules\Product\Livewire\SalePriceSetup::class);
        \Livewire\Livewire::component('product::barcode-initialization', \Modules\Product\Livewire\BarcodeInitialization::class);
        \Livewire\Livewire::component('modules.product.barcode-batch-workspace', \Modules\Product\Livewire\BarcodeBatchWorkspace::class);
        \Livewire\Livewire::component('modules.product.barcode-product-search', \Modules\Product\Livewire\BarcodeProductSearch::class);
    }

    /**
     * Register translations.
     *
     * @return void
     */
    public function registerTranslations()
    {
        $langPath = resource_path('lang/modules/' . $this->moduleNameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->moduleNameLower);
        } else {
            $this->loadTranslationsFrom(module_path($this->moduleName, 'Resources/lang'), $this->moduleNameLower);
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach (\Config::get('view.paths') as $path) {
            if (is_dir($path . '/modules/' . $this->moduleNameLower)) {
                $paths[] = $path . '/modules/' . $this->moduleNameLower;
            }
        }
        return $paths;
    }
}
