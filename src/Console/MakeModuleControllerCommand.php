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
        $namespace = "Modules\\{$module}";

        if ($files->exists($path)) {
            $this->error("Controller [{$name}Controller] already exists.");
            return self::FAILURE;
        }

        $files->makeDirectory($directory, 0755, true);
        $this->writeController($files, $path, $namespace, $name);
        $this->writeResponsesTrait($files);

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

__IMPORTS__use Modules\Shared\App\Traits\HttpResponses;

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

    private function writeResponsesTrait(Filesystem $files): void
    {
        $root = base_path('Modules/Shared');
        $path = "{$root}/App/Traits/HttpResponses.php";

        if ($files->exists($path)) {
            return;
        }

        $files->makeDirectory(dirname($path), 0755, true);
        $files->put($path, <<<'PHP'
<?php

namespace Modules\Shared\App\Traits;

use Illuminate\Http\JsonResponse;

trait HttpResponses
{
    protected function success(mixed $data = null, ?string $message = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    protected function error(mixed $data = null, ?string $message = null, int $code = 500): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
            'data' => $data,
        ], $code);
    }
}
PHP);

        $moduleJson = "{$root}/module.json";
        if (! $files->exists($moduleJson)) {
            $files->put($moduleJson, json_encode([
                'name' => 'Shared',
                'namespace' => 'Modules\\Shared',
                'provider' => null,
                'version' => '1.0.0',
                'description' => 'Shared module support',
                'enabled' => true,
                'components' => ['traits'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        }
    }
}
