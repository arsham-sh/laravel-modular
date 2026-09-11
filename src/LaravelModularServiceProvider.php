<?php

namespace Arsham\LaravelModular;

use Arsham\LaravelModular\Console\MakeModuleCommand;
use Illuminate\Support\ServiceProvider;

class LaravelModularServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeModuleCommand::class,
            ]);
        }
    }
}