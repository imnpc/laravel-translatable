<?php

namespace SolutionForest\Translatable;

use Illuminate\Support\ServiceProvider;

class SFTranslationServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }
}
