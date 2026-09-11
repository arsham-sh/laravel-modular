<?php

namespace Arsham\LaravelModular\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeModuleCommand extends Command
{
    protected $signature = 'module:make
                            {name : The name of the module}';

    protected $description = 'Create a new Laravel module';

    public function handle(Filesystem $files): int
    {
        $name = Str::studly($this->argument('name'));

        $modulePath = base_path("Modules/{$name}");

        if ($files->exists($modulePath)) {
            $this->error("Module [{$name}] already exists.");

            return self::FAILURE;
        }

        $directories = [
            'App/Console',
            'App/Http/Controllers',
            'App/Http/Middleware',
            'App/Http/Requests',
            'App/Models',
            'App/Providers',
            'App/Services',
            'Database/Factories',
            'Database/Migrations',
            'Database/Seeders',
            'Routes',
            'Tests/Feature',
            'Tests/Unit',
        ];

        foreach ($directories as $directory) {
            $files->makeDirectory(
                "{$modulePath}/{$directory}",
                0755,
                true
            );
        }

        $files->put(
            "{$modulePath}/Routes/api.php",
            "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n"
        );

        $files->put(
            "{$modulePath}/module.json",
            json_encode([
                'name' => $name,
                'namespace' => "Modules\\{$name}",
                'version' => '1.0.0',
                'description' => "{$name} module",
                'enabled' => true,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );

        $this->info("Module [{$name}] created successfully.");

        $this->newLine();

        $this->line("Location:");
        $this->line("  Modules/{$name}");

        return self::SUCCESS;
    }
}