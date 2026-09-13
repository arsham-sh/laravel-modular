<?php

namespace Arsham\LaravelModular\Console;

use Arsham\LaravelModular\Support\ModuleApplicationSupport;
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

    public function handle(Filesystem $files, ModuleApplicationSupport $support): int
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
        $namespace = "Modules\\{$module}";

        if ($files->exists($path)) {
            $this->error("Controller [{$name}Controller] already exists.");
            return self::FAILURE;
        }

        $files->makeDirectory($directory, 0755, true);
        $support->prepare();
        $this->writeController($files, $path, $namespace, $name);
        $support->updateModuleControllers($module);

        $this->info("Controller [{$name}Controller] created in module [{$module}].");
        return self::SUCCESS;
    }

    private function writeController(Filesystem $files, string $path, string $namespace, string $name): void
    {
        $methods = $this->option('resource') ? <<<'PHP'
    public function index(): JsonResponse
    {
        return $this->success([]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->success([], 'Created successfully.', 201);
    }

    public function show(mixed $id): JsonResponse
    {
        return $this->success(['id' => $id]);
    }

    public function update(Request $request, mixed $id): JsonResponse
    {
        return $this->success(['id' => $id], 'Updated successfully.');
    }

    public function destroy(mixed $id): JsonResponse
    {
        return $this->success(null, 'Deleted successfully.');
    }
PHP
            : <<<'PHP'
    public function index(): JsonResponse
    {
        return $this->success([]);
    }
PHP;

        $imports = $this->option('resource')
            ? "use Illuminate\\Http\\JsonResponse;\nuse Illuminate\\Http\\Request;\n"
            : "use Illuminate\\Http\\JsonResponse;\n";

        $content = strtr(<<<'PHP'
<?php

namespace __NS__\App\Http\Controllers;

__IMPORTS__use App\Traits\HttpResponses;

class __NAME__Controller
{
    use HttpResponses;

__METHODS__
}
PHP
        , [
            '__NS__' => $namespace,
            '__NAME__' => $name,
            '__IMPORTS__' => $imports,
            '__METHODS__' => $methods,
        ]);

        $files->put($path, $content);
    }
}
