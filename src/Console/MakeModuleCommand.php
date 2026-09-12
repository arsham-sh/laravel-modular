<?php

namespace Arsham\LaravelModular\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeModuleCommand extends Command
{
    protected $signature = 'module:make
        {name : The name of the module}
        {--preset= : Generation preset: minimal, normal, or all}
        {--components=* : Generate specific components instead of a preset}
        {--minimal : Backward-compatible alias for --preset=minimal}
        {--no-prompts : Skip interactive selection and use the normal preset}';

    protected $description = 'Create a Laravel module';

    /** @var array<string, string> */
    private array $paths = [
        'controllers' => 'App/Http/Controllers',
        'requests' => 'App/Http/Requests',
        'models' => 'App/Models',
        'services' => 'App/Services',
        'resources' => 'App/Http/Resources',
        'database' => 'Database',
        'routes' => 'Routes',
        'middleware' => 'App/Http/Middleware',
        'console' => 'App/Console',
        'feature-tests' => 'Tests/Feature',
        'unit-tests' => 'Tests/Unit',
    ];

    /** @var list<string> */
    private array $normalComponents = ['controllers', 'requests', 'models', 'services', 'routes'];

    /** @var list<string> */
    private array $allComponents = [
        'controllers', 'requests', 'models', 'services', 'resources',
        'database', 'routes', 'middleware', 'console', 'feature-tests', 'unit-tests',
    ];

    /** @var array<string, string> */
    private array $componentLabels = [
        'controllers' => 'Controller',
        'requests' => 'Form request',
        'models' => 'Model',
        'services' => 'Service',
        'resources' => 'API resource',
        'database' => 'Database (migration, factory, seeder)',
        'routes' => 'API routes',
        'middleware' => 'Middleware',
        'console' => 'Console command',
        'feature-tests' => 'Feature tests',
        'unit-tests' => 'Unit tests',
    ];

    /** Create the requested module. */
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

        $this->writeFile($files, "{$modulePath}/Config/config.php", "<?php\n\nreturn [\n    'enabled' => true,\n];\n");
        $this->createProvider($files, $modulePath, $name, $components);
        $this->createComponents($files, $modulePath, $name, $components);
        $this->createManifest($files, $modulePath, $name, $components);

        $this->info("Module [{$name}] created successfully.");
        return self::SUCCESS;
    }

    /** Determine which components should be generated. */
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

        $preset = $this->option('preset');
        if ($this->option('minimal')) {
            $preset = 'minimal';
        }

        if ($preset !== null) {
            return $this->presetComponents($preset);
        }

        if ($this->option('no-prompts') || ! $this->input->isInteractive()) {
            return $this->normalComponents;
        }

        $preset = $this->choice(
            'How much should be generated?',
            [
                'minimal' => 'Minimal - module config, provider, and model only',
                'normal' => 'Normal - controller, request, model, service, and API routes',
                'all' => 'All - normal plus resources, database, middleware, console, and tests',
            ],
            'normal'
        );

        return $this->presetComponents($preset);
    }

    /** Return the components for a named generation preset. */
    private function presetComponents(string $preset): ?array
    {
        return match (Str::lower(trim($preset))) {
            'minimal' => ['models'],
            'normal' => $this->normalComponents,
            'all' => $this->allComponents,
            default => $this->invalidPreset($preset),
        };
    }

    /** Report an invalid preset. */
    private function invalidPreset(string $preset): ?array
    {
        $this->error("Unknown preset [{$preset}]. Use minimal, normal, or all.");
        return null;
    }

    /** Validate explicitly requested components. */
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

    /** Add required components for explicitly selected components. */
    private function normalizeComponents(array $components): array
    {
        if (in_array('controllers', $components, true)) {
            $components[] = 'models';
            $components[] = 'requests';
            $components[] = 'routes';
        }

        if (in_array('resources', $components, true)) {
            $components[] = 'models';
        }

        if (in_array('database', $components, true)) {
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

    /** Generate the module service provider. */
    private function createProvider(Filesystem $files, string $modulePath, string $name, array $components): void
    {
        $routeLoader = in_array('routes', $components, true)
            ? "        \$this->loadRoutesFrom(__DIR__ . '/../../Routes/api.php');\n"
            : '';
        $migrationLoader = in_array('database', $components, true)
            ? "        \$this->loadMigrationsFrom(__DIR__ . '/../../Database/Migrations');\n"
            : '';

        $content = <<<PHP
<?php

namespace Modules\\{$name}\\App\\Providers;

use Illuminate\\Support\\ServiceProvider;

class {$name}ServiceProvider extends ServiceProvider
{
    /** Register module services and configuration. */
    public function register(): void
    {
        \$this->mergeConfigFrom(__DIR__ . '/../../Config/config.php', '{$this->configKey($name)}');
    }

    /** Bootstrap module resources. */
    public function boot(): void
    {
{$routeLoader}{$migrationLoader}    }
}
PHP;

        $this->writeFile($files, "{$modulePath}/App/Providers/{$name}ServiceProvider.php", $content . PHP_EOL);
    }

    /** Generate the selected module files. */
    private function createComponents(Filesystem $files, string $modulePath, string $name, array $components): void
    {
        $namespace = "Modules\\{$name}";
        $parameter = Str::camel($name);
        $route = Str::kebab(Str::pluralStudly($name));
        $vars = [
            '__NS__' => $namespace,
            '__NAME__' => $name,
            '__PARAM__' => $parameter,
            '__ROUTE__' => $route,
        ];

        if (in_array('models', $components, true)) {
            $database = in_array('database', $components, true);
            $this->writeTemplate($files, "{$modulePath}/App/Models/{$name}.php", <<<'PHP'
<?php

namespace __NS__\App\Models;

use Illuminate\Database\Eloquent\Model;
__FACTORY_USE__
class __NAME__ extends Model
{
__FACTORY_CODE__    /** The attributes that can be mass assigned. */
    protected $guarded = [];
}
PHP
            , $vars + [
                '__FACTORY_USE__' => $database
                    ? "use Illuminate\\Database\\Eloquent\\Factories\\Factory;\nuse Illuminate\\Database\\Eloquent\\Factories\\HasFactory;"
                    : '',
                '__FACTORY_CODE__' => $database
                    ? "    use HasFactory;\n\n    /** Return the module's factory for this model. */\n    protected static function newFactory(): Factory\n    {\n        return \\{$namespace}\\Database\\Factories\\{$name}Factory::new();\n    }\n\n"
                    : '',
            ]);
        }

        if (in_array('requests', $components, true)) {
            $this->writeTemplate($files, "{$modulePath}/App/Http/Requests/{$name}Request.php", <<<'PHP'
<?php

namespace __NS__\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class __NAME__Request extends FormRequest
{
    /** Determine whether the user can make this request. */
    public function authorize(): bool
    {
        return true;
    }

    /** Return the validation rules for the request. */
    public function rules(): array
    {
        return [];
    }
}
PHP
            , $vars);
        }

        if (in_array('services', $components, true)) {
            $this->writeTemplate($files, "{$modulePath}/App/Services/{$name}Service.php", <<<'PHP'
<?php

namespace __NS__\App\Services;

class __NAME__Service
{
    // Add the module's business logic here.
}
PHP
            , $vars);
        }

        if (in_array('resources', $components, true)) {
            $this->writeTemplate($files, "{$modulePath}/App/Http/Resources/{$name}Resource.php", <<<'PHP'
<?php

namespace __NS__\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class __NAME__Resource extends JsonResource
{
    /** Transform the resource into an array. */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
PHP
            , $vars);
        }

        if (in_array('controllers', $components, true)) {
            $this->createController($files, $modulePath, $name, $namespace, $parameter, in_array('resources', $components, true));
            $this->createHttpResponsesTrait($files, $modulePath, $namespace);
        }

        if (in_array('database', $components, true)) {
            $this->createDatabase($files, $modulePath, $name, $namespace, $vars);
        }

        if (in_array('routes', $components, true)) {
            $this->writeTemplate($files, "{$modulePath}/Routes/api.php", in_array('controllers', $components, true)
                ? <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;
use __NS__\App\Http\Controllers\__NAME__Controller;

Route::apiResource('__ROUTE__', __NAME__Controller::class);
PHP
                : <<<'PHP'
<?php

// Define module routes here.
PHP
            , $vars);
        }

        if (in_array('middleware', $components, true)) {
            $this->writeTemplate($files, "{$modulePath}/App/Http/Middleware/{$name}Middleware.php", <<<'PHP'
<?php

namespace __NS__\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class __NAME__Middleware
{
    /** Handle an incoming request. */
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
            $this->writeTemplate($files, "{$modulePath}/App/Console/{$name}Command.php", <<<'PHP'
<?php

namespace __NS__\App\Console;

use Illuminate\Console\Command;

class __NAME__Command extends Command
{
    protected $signature = '__SIGNATURE__';
    protected $description = 'Run the __NAME__ module command';

    /** Execute the console command. */
    public function handle(): int
    {
        return self::SUCCESS;
    }
}
PHP
            , $vars);
        }

        if (in_array('feature-tests', $components, true)) {
            $this->writeTemplate($files, "{$modulePath}/Tests/Feature/{$name}Test.php", <<<'PHP'
<?php

namespace __NS__\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class __NAME__Test extends TestCase
{
    /** Ensure the module resource route is registered. */
    public function test_module_routes_are_registered(): void
    {
        $route = Route::getRoutes()->getByName('__ROUTE__.index');

        $this->assertNotNull($route);
        $this->assertSame('/__ROUTE__', $route->uri());
    }
}
PHP
            , $vars);
        }

        if (in_array('unit-tests', $components, true)) {
            $this->writeTemplate($files, "{$modulePath}/Tests/Unit/{$name}ServiceTest.php", <<<'PHP'
<?php

namespace __NS__\Tests\Unit;

use PHPUnit\Framework\TestCase;
use __NS__\App\Services\__NAME__Service;

class __NAME__ServiceTest extends TestCase
{
    /** Ensure the generated service can be instantiated. */
    public function test_service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(__NAME__Service::class, new __NAME__Service());
    }
}
PHP
            , $vars);
        }
    }

    /** Generate the database bundle for the module. */
    private function createDatabase(Filesystem $files, string $modulePath, string $name, string $namespace, array $vars): void
    {
        $table = Str::snake(Str::pluralStudly($name));
        $migration = date('Y_m_d_His') . '_create_' . $table . '_table.php';

        $this->writeTemplate($files, "{$modulePath}/Database/Migrations/{$migration}", <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        Schema::create('__TABLE__', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::dropIfExists('__TABLE__');
    }
};
PHP
        , ['__TABLE__' => $table]);

        $this->writeTemplate($files, "{$modulePath}/Database/Factories/{$name}Factory.php", <<<'PHP'
<?php

namespace __NS__\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use __NS__\App\Models\__NAME__;

class __NAME__Factory extends Factory
{
    protected $model = __NAME__::class;

    /** Define the model's default state. */
    public function definition(): array
    {
        return [];
    }
}
PHP
        , $vars);

        $this->writeTemplate($files, "{$modulePath}/Database/Seeders/{$name}Seeder.php", <<<'PHP'
<?php

namespace __NS__\Database\Seeders;

use Illuminate\Database\Seeder;

class __NAME__Seeder extends Seeder
{
    /** Seed the module database tables. */
    public function run(): void
    {
        // Add module seed data here.
    }
}
PHP
        , $vars);
    }

    /** Generate a resource controller. */
    private function createController(Filesystem $files, string $modulePath, string $name, string $namespace, string $parameter, bool $resource): void
    {
        $resourceUse = $resource ? "use {$namespace}\\App\\Http\\Resources\\{$name}Resource;\n" : '';
        $show = $resource ? "new {$name}Resource(\${$parameter})" : "\${$parameter}";
        $index = $resource
            ? "return \$this->success({$name}Resource::collection({$name}::query()->paginate()));"
            : "return \$this->success({$name}::query()->paginate());";
        $store = $resource
            ? "return \$this->success(new {$name}Resource(\$model), 'Created successfully.', 201);"
            : "return \$this->success(\$model, 'Created successfully.', 201);";

        $content = strtr(<<<'PHP'
<?php

namespace __NS__\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use __NS__\App\Http\Requests\__NAME__Request;
__RESOURCE_USE__use __NS__\App\Models\__NAME__;
use __NS__\App\Traits\HttpResponses;

class __NAME__Controller
{
    use HttpResponses;

    /** Display a paginated list of resources. */
    public function index(): JsonResponse
    {
        __INDEX__
    }

    /** Store a new resource. */
    public function store(__NAME__Request $request): JsonResponse
    {
        $model = __NAME__::create($request->validated());

        __STORE__
    }

    /** Display a single resource. */
    public function show(__NAME__ $__PARAM__): JsonResponse
    {
        return $this->success(__SHOW__);
    }

    /** Update an existing resource. */
    public function update(__NAME__Request $request, __NAME__ $__PARAM__): JsonResponse
    {
        $__PARAM__->update($request->validated());

        return $this->success(__SHOW__, 'Updated successfully.');
    }

    /** Delete a resource. */
    public function destroy(__NAME__ $__PARAM__): JsonResponse
    {
        $__PARAM__->delete();

        return $this->success(null, 'Deleted successfully.');
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

        $this->writeFile($files, "{$modulePath}/App/Http/Controllers/{$name}Controller.php", $content);
    }

    /** Generate a shared JSON response helper for module controllers. */
    private function createHttpResponsesTrait(Filesystem $files, string $modulePath, string $namespace): void
    {
        $path = "{$modulePath}/App/Traits/HttpResponses.php";
        $files->makeDirectory(dirname($path), 0755, true);

        $this->writeFile($files, $path, <<<PHP
<?php

namespace {$namespace}\\App\\Traits;

use Illuminate\\Http\\JsonResponse;

trait HttpResponses
{
    /** Return a successful JSON response. */
    protected function success(mixed \$data = null, ?string \$message = null, int \$code = 200): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => \$message,
            'data' => \$data,
        ], \$code);
    }

    /** Return an error JSON response. */
    protected function error(mixed \$data = null, ?string \$message = null, int \$code = 500): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => \$message,
            'data' => \$data,
        ], \$code);
    }
}
PHP);
    }

    /** Write the module manifest. */
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

        $this->writeFile($files, "{$modulePath}/module.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /** Return the module configuration key. */
    private function configKey(string $name): string
    {
        return Str::kebab($name);
    }

    /** Write a template after replacing module placeholders. */
    private function writeTemplate(Filesystem $files, string $path, string $template, array $variables): void
    {
        $this->writeFile($files, $path, strtr($template, $variables) . PHP_EOL);
    }

    /** Write generated contents to disk. */
    private function writeFile(Filesystem $files, string $path, string $contents): void
    {
        $files->makeDirectory(dirname($path), 0755, true);
        $files->put($path, $contents);
    }
}
