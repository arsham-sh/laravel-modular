<?php

namespace Arsham\LaravelModular;

use Arsham\LaravelModular\Console\MakeModuleCommand;
use Arsham\LaravelModular\Console\MakeModuleControllerCommand;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

class LaravelModularServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            MakeModuleCommand::class,
            MakeModuleControllerCommand::class,
        ]);
    }

    public function boot(Filesystem $files): void
    {
        $modulesPath = base_path('Modules');

        if (! $files->isDirectory($modulesPath)) {
            return;
        }

        foreach ($files->glob("{$modulesPath}/*/module.json") as $moduleConfig) {
            $module = json_decode($files->get($moduleConfig), true);

            if (! is_array($module) || ($module['enabled'] ?? true) !== true) {
                continue;
            }

            $moduleDirectory = dirname($moduleConfig);
            $namespace = $module['namespace'] ?? null;

            if (is_string($namespace)) {
                /** @var \Composer\Autoload\ClassLoader $loader */
                $loader = require base_path('vendor/autoload.php');
                $loader->addPsr4(rtrim($namespace, '\\') . '\\', $moduleDirectory . DIRECTORY_SEPARATOR);
            }

            $provider = $module['provider'] ?? null;

            if (is_string($provider) && class_exists($provider)) {
                $this->app->register($provider);
            }
        }
    }
}
