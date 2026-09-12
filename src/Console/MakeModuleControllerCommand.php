<?php

namespace Arsham\LaravelModular\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeModuleControllerCommand extends Command
{
    protected $signature = 'module:make-controller
                            {module : The name of the module}
                            {name : The controller name}
                            {--resource : Generate resource-style CRUD methods}';

    protected $description = 'Create a controller inside an existing Laravel module';

    public function handle(Filesystem $files): int
    {
        $module = Str::studly($this->argument('module'));
        $name = Str::studly($this->argument('name'));
        $modulePath = base_path("Modules/{$module}");

        if (! $files->isDirectory($modulePath)) {
            $this->error("Module [{$module}] does not exist. Create it first with module:make {$module}.");
            return self::FAILURE;
        }

        $directory = "{$modulePath}/App/Http/Controllers";
        $path = "{$directory}/{$name}Controller.php";

        if ($files->exists($path)) {
            $this->error("Controller [{$name}Controller] already exists.");
            return self::FAILURE;
        }

        $files->makeDirectory($directory, 0755, true);

        $methods = $this->option('resource')
            ? "    public function index(): JsonResponse\n    {\n        return response()->json([]);\n    }\n\n    public function store(Request \\$request): JsonResponse\n    {\n        return response()->json([], 201);\n    }\n\n    public function show(mixed \\$id): JsonResponse\n    {\n        return response()->json(['id' => \\$id]);\n    }\n\n    public function update(Request \\$request, mixed \\$id): JsonResponse\n    {\n        return response()->json(['id' => \\$id]);\n    }\n\n    public function destroy(mixed \\$id): JsonResponse\n    {\n        return response()->json(null, 204);\n    }"
            : "    public function index(): JsonResponse\n    {\n        return response()->json([]);\n    }";

        $imports = $this->option('resource')
            ? "use Illuminate\\Http\\JsonResponse;\nuse Illuminate\\Http\\Request;\n"
            : "use Illuminate\\Http\\JsonResponse;\n";

        $content = "<?php\n\nnamespace Modules\\{$module}\\App\\Http\\Controllers;\n\n{$imports}\nclass {$name}Controller\n{\n{$methods}\n}\n";

        $files->put($path, $content);

        $this->info("Controller [{$name}Controller] created in module [{$module}].");
        $this->line("Location: Modules/{$module}/App/Http/Controllers/{$name}Controller.php");

        return self::SUCCESS;
    }
}
