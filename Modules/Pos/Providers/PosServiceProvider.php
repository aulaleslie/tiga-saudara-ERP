<?php

namespace Modules\Pos\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Pos\Services\Adapters\InlinePosCheckoutPostingAdapter;
use Modules\Pos\Services\Adapters\LoggingPosCashDrawerAdapter;
use Modules\Pos\Services\Adapters\OwnerAwarePosCheckoutPostingAdapter;
use Modules\Pos\Services\Adapters\SplitPosCheckoutPostingAdapter;
use Modules\Pos\Services\Contracts\PosCashDrawerAdapter;
use Modules\Pos\Services\Contracts\PosCheckoutPostingAdapter;

class PosServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'Pos';

    protected string $moduleNameLower = 'pos';

    public function boot(): void
    {
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();

        $this->loadMigrationsFrom(module_path($this->moduleName, 'Database/Migrations'));

        \Livewire\Livewire::component('modules.pos.pos-terminal-search-dropdown', \Modules\Pos\Livewire\PosTerminalSearchDropdown::class);
        \Livewire\Livewire::component('pos-return.pos-return-create-form', \Modules\Pos\Livewire\PosReturn\PosReturnCreateForm::class);
        \Livewire\Livewire::component('pos-return.pos-return-edit-form', \Modules\Pos\Livewire\PosReturn\PosReturnEditForm::class);

        $this->commands([
            \Modules\Pos\Console\CleanupPosTemporaryImagesCommand::class,
        ]);
    }

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);

        // Re-entrance tracking lives on the lock instance, so every collaborator
        // guarding the same cart must share one instance: a coordinator holding
        // the lock calls into PosCartService, which must recognise the
        // ownership rather than block on it.
        //
        // `scoped`, not `singleton`: the instance is shared for one
        // request/job and rebuilt for the next. Under a long-lived worker
        // (Octane) a process-wide singleton would let one execution context
        // inherit another's re-entrant ownership and bypass the real lock.
        $this->app->scoped(\Modules\Pos\Services\PosCartMutationLock::class);

        $this->app->bind(PosCheckoutPostingAdapter::class, OwnerAwarePosCheckoutPostingAdapter::class);
        $this->app->bind(PosCashDrawerAdapter::class, LoggingPosCashDrawerAdapter::class);
    }

    protected function registerConfig(): void
    {
        $this->publishes([
            module_path($this->moduleName, 'Config/config.php') => config_path($this->moduleNameLower . '.php'),
        ], 'config');

        $this->mergeConfigFrom(module_path($this->moduleName, 'Config/config.php'), $this->moduleNameLower);
    }

    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/' . $this->moduleNameLower);
        $sourcePath = module_path($this->moduleName, 'Resources/views');

        $this->publishes([
            $sourcePath => $viewPath,
        ], ['views', $this->moduleNameLower . '-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->moduleNameLower);
    }

    public function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/' . $this->moduleNameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->moduleNameLower);

            return;
        }

        $this->loadTranslationsFrom(module_path($this->moduleName, 'Resources/lang'), $this->moduleNameLower);
    }

    public function provides(): array
    {
        return [];
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];

        foreach (config('view.paths') as $path) {
            if (is_dir($path . '/modules/' . $this->moduleNameLower)) {
                $paths[] = $path . '/modules/' . $this->moduleNameLower;
            }
        }

        return $paths;
    }
}
