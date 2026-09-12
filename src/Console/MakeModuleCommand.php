<?php

namespace Arsham\LaravelModular\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeModuleCommand extends Command
{
    protected $signature = 'module:make
        {name : The name of the module}
        {--components=* : Components to generate}
        {--minimal : Generate only the core module components}
        {--no-prompts : Skip interactive component selection}';

    protected $description = 'Create a Laravel module with only the components you need';

    /** @var array<string, string> */
    private array $paths = [
        'controllers' => 'App/Http/Controllers',
        'requests' => 'App/Http/Requests',
        'models' => 'App/Models',
        'services' => 'App/Services',
        'resources' => 'App/Http/Resources',
        'factories' => 'Database/Factories',
        'seeders' => 'Database/Seeders',
        'routes' => 'Routes',
        'middleware' => 'App/Http/Middleware',
        'console' => 'App/Console',
        'feature-tests' => 'Tests/Feature',
        'unit-tests' => 'Tests/Unit',
    ];

    /** @var list<string> */
    private array $coreComponents = ['controllers', 'requests', 'models', 'services', 'routes'];

    /** @var array<string, string> */
    private array $componentLabels = [
        'controllers' => 'API controller',
        'requests' => 'Form request',
        'models' => 'Eloquent model',
        'services' => 'Service class',
        'resources' => 'API resource',
        'factories' => 'Model factory',
        'seeders' => 'Seeder',
        'routes' => 'API routes',
        'middleware' => 'Middleware',
        'console' => 'Console command',
        'feature-tests' => 'Feature tests',
        'unit-tests' => 'Unit tests',
    ];

    public function handle(Filesystem $files): int
    {
        $name = Str::studly($this->argument('name'));
        $modulePath = base_path("Modules/{$name}");

        if ($files->exists($modulePath)) {
            $this->error("Module [{$name}] already exists.");
            return self::FAILURE;
        }

        $components = $this->components();
        if ($components === null) {
            return self::FAILURE;
        }

        $files->makeDirectory("{$modulePath}/Config", 0755, true);
        $files->makeDirectory("{$modulePath}/App/Providers", 0755, true);

        foreach ($components as $component) {
            $files->makeDirectory("{$modulePath}/{$this->paths[$component]}", 0755, true);
        }

        $this->putTemplate($files, "{$modulePath}/Config/config.php", <<<'PHP'
<?php

return [
    'enabled' => true,
];
PHP
        );

        $this->createProvider($files, $modulePath, $name, $components);
        $this->createComponents($files, $modulePath, $name, $components);
        $this->createManifest($files, $modulePath, $name, $components);

        $this->info("Module [{$name}] created successfully.");

        return self::SUCCESS;
    }

    /** @return list<string>|null */
    private function components(): ?array
    {
        $requested = $this->option('components');

        if ($requested !== []) {
            $components = array_values(array_unique(array_filter(array_map(
                static fn (string $value): string => Str::kebab(trim($value)),
                $requested
            ))));

            return $this->validateComponents($components);
        }

        if ($this->option('minimal') || $this->option('no-prompts') || ! $this->input->isInteractive()) {
            return $this->coreComponents;
        }

        $choices = array_values($this->componentLabels);
        $selected = $this->choice(
            'What should this module include?',
            $choices,
            null,
            null,
            true
        );

        $components = array_keys(array_filter(
            $this->componentLabels,
            static fn (string $label): bool => in_array($label, $selected, true)
        ));

        if ($components === []) {
            $this->error('Select at least one component.');
            return null;
        }

        return $this->normalizeComponents($components);
    }

    /** @param list<string> $components */
    private function validateComponents(array $components): ?array
    {
        $invalid = array_diff($components, array_keys($this->paths));

        if ($invalid !== []) {
            $this->error('Unknown component(s): ' . implode(', ', $invalid));
            $this->line('Available: ' . implode(', ', array_keys($this->paths)));
            return null;
        }

        return $this->normalizeComponents($components);
    }

    /** @param list<string> $components */
    private function normalizeComponents(array $components): array
    {
        if (in_array('controllers', $components, true)) {
            $components[] = 'models';
            $components[] = 'routes';
        }

        if (in_array('factories', $components, true)) {
            $components[] = 'models';
        }

        if (in_array('feature-tests', $components, true)) {
            $components[] = 'routes';
        }

        if (in_array('unit-tests', $components, true)) {
            $components[] = 'services';
        }

        return array_values(array_unique($components));
    }

    /** @param list<string> $components */
    private function createProvider(Filesystem $files, string $modulePath, string $name, array $components): void
    {
        $routeLoader = in_array('routes', $components, true)
            ? "        \$this->loadRoutesFrom(__DIR__ . '/../../Routes/api.php');\n"
            : '';

        $content = <<<PHP
<?php

namespace Modules\\{$name}\\App\\Providers;

use Illuminate\\Support\\ServiceProvider;

class {$name}ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        \$this->mergeConfigFrom(__DIR__ . '/../../Config/config.php', '{$this->configKey($name)}');
    }

    public function boot(): void
    {
{$routeLoader}    }
}
PHP;

        $this->put($files, "{$modulePath}/App/Providers/{$name}ServiceProvider.php", $content);
    }

    /** @param list<string> $components */
    private function createComponents(Filesystem $files, string $modulePath, string $name, array $components): void
    {
        $namespace = "Modules\\{$name}";
        $parameter = Str::camel($name);
        $route = Str::kebab(Str::pluralStudly($name));
        $table = Str::snake(Str::pluralStudly($name));
        $vars = [
            '__NS__' => $namespace,
            '__NAME__' => $name,
            '__PARAM__' => $parameter,
            '__ROUTE__' => $route,
            '__TABLE__' => $table,
        ];

        if (in_array('models', $components, true)) {
            $this->putTemplate($files, "{$modulePath}/App/Models/{$name}.php", <<<'PHP'
<?php

namespace __NS__\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class __NAME__ extends Model
{
    use HasFactory;

    protected $guarded = [];
}
PHP
            , $vars);
        }

        if (in_array('requests', $components, true)) {
            $this->putTemplate($files, "{$modulePath}/App/Http/Requests/{$name}Request.php", <<<'PHP'
<?php

namespace __NS__\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class __NAME__Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
PHP
            , $vars);
        }

        if (in_array('services', $components, true)) {
            $this->putTemplate($files, "{$modulePath}/App/Services/{$name}Service.php", <<<'PHP'
<?php

namespace __NS__\App\Services;

class __NAME__Service
{
}
PHP
            , $vars);
        }

        if (in_array('resources', $components, true)) {
            $this->putTemplate($files, "{$modulePath}/App/Http/Resources/{$name}Resource.php", <<<'PHP'
<?php

namespace __NS__\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class __NAME__Resource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
PHP
            , $vars);
        }

        if (in_array('controllers', $components, true)) {
            $resource = in_array('resources', $components, true);
            $resourceUse = $resource ? "use {$namespace}\\App\\Http\\Resources\\{$name}Resource;\n" : '';
            $index = $resource
                ? "return response()->json({$name}Resource::collection({$name}::query()->paginate()));"
                : "return response()->json({$name}::query()->paginate());";
            $store = $resource
                ? "return response()->json(new {$name}Resource(\$model), 201);"
                : "return response()->json(\$model, 201);";
            $show = $resource ? "new {$name}Resource(\${$parameter})" : "\${$parameter}";

            $controller = strtr(<<<'PHP'
<?php

namespace __NS__\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
__RESOURCE_USE__use __NS__\App\Models\__NAME__;

class __NAME__Controller
{
    public function index(): JsonResponse
    {
        __INDEX__
    }

    public function store(Request $request): JsonResponse
    {
        $model = __NAME__::create($request->all());

        __STORE__
    }

    public function show(__NAME__ $__PARAM__): JsonResponse
    {
        return response()->json(__SHOW__);
    }

    public function update(Request $request, __NAME__ $__PARAM__): JsonResponse
    {
        $__PARAM__->update($request->all());

        return response()->json(__SHOW__);
    }

    public function destroy(__NAME__ $__PARAM__): JsonResponse
    {
        $__PARAM__->delete();

        return response()->noContent();
    }
}
PHP
            , [
                '__NS__' => $namespace,
                '__NAME__' => $name,
                '__PARAM__' => $parameter,
                '__RESOURCE_USE__' => $resourceUse,
                '__INDEX__' => $index,
                '__STORE__' => $store,
                '__SHOW__' => $show,
            ]);

            $this->put($files, "{$modulePath}/App/Http/Controllers/{$name}Controller.php", $controller);
        }

        if (in_array('factories', $components, true) && in_array('models', $components, true)) {
            $this->putTemplate($files, "{$modulePath}/Database/Factories/{$name}Factory.php", <<<'PHP'
<?php

namespace __NS__\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use __NS__\App\Models\__NAME__;

class __NAME__Factory extends Factory
{
    protected $model = __NAME__::class;

    public function definition(): array
    {
        return [];
    }
}
PHP
            , $vars);
        }

        if (in_array('seeders', $components, true)) {
            $this->putTemplate($files, "{$modulePath}/Database/Seeders/{$name}Seeder.php", <<<'PHP'
<?php

namespace __NS__\Database\Seeders;

use Illuminate\Database\Seeder;

class __NAME__Seeder extends Seeder
{
    public function run(): void
    {
    }
}
PHP
            , $vars);
        }

        if (in_array('routes', $components, true) && in_array('controllers', $components, true)) {
            $this->putTemplate($files, "{$modulePath}/Routes/api.php", <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;
use __NS__\App\Http\Controllers\__NAME__Controller;

Route::apiResource('__ROUTE__', __NAME__Controller::class);
PHP
            , $vars);
        }

        if (in_array('middleware', $components, true)) {
            $this->putTemplate($files, "{$modulePath}/App/Http/Middleware/{$name}Middleware.php", <<<'PHP'
<?php

namespace __NS__\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class __NAME__Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
PHP
            , $vars);
        }

        if (in_array('console', $components, true)) {
            $vars['__SIGNATURE__'] = Str::kebab($name) . ':run';

            $this->putTemplate($files, "{$modulePath}/App/Console/{$name}Command.php", <<<'PHP'
<?php

namespace __NS__\App\Console;

use Illuminate\Console\Command;

class __NAME__Command extends Command
{
    protected $signature = '__SIGNATURE__';

    protected $description = 'Run the __NAME__ module command';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
PHP
            , $vars);
        }

        if (in_array('feature-tests', $components, true)) {
            $this->putTemplate($files, "{$modulePath}/Tests/Feature/{$name}Test.php", <<<'PHP'
<?php

namespace __NS__\Tests\Feature;

use Tests\TestCase;

class __NAME__Test extends TestCase
{
    public function test_module_route_is_registered(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('__ROUTE__.index'));
    }
}
PHP
            , $vars);
        }

        if (in_array('unit-tests', $components, true)) {
            $this->putTemplate($files, "{$modulePath}/Tests/Unit/{$name}ServiceTest.php", <<<'PHP'
<?php

namespace __NS__\Tests\Unit;

use PHPUnit\Framework\TestCase;
use __NS__\App\Services\__NAME__Service;

class __NAME__ServiceTest extends TestCase
{
    public function test_service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(__NAME__Service::class, new __NAME__Service());
    }
}
PHP
            , $vars);
        }
    }

    /** @param list<string> $components */
    private function createManifest(Filesystem $files, string $modulePath, string $name, array $components): void
    {
        $manifest = [
            'name' => $name,
            'namespace' => "Modules\\{$name}",
            'provider' => "Modules\\{$name}\\App\\Providers\\{$name}ServiceProvider",
            'version' => '1.0.0',
            'description' => "{$name} module",
            'enabled' => true,
            'components' => $components,
        ];

        $this->put($files, "{$modulePath}/module.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    private function configKey(string $name): string
    {
        return Str::kebab($name);
    }

    /** @param array<string, string> $variables */
    private function putTemplate(Filesystem $files, string $path, string $template, array $variables = []): void
    {
        $files->put($path, strtr($template, $variables) . PHP_EOL);
    }

    private function put(Filesystem $files, string $path, string $contents): void
    {
        $files->put($path, $contents);
    }
}
