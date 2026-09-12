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

    private array $paths = [
        'controllers' => 'App/Http/Controllers', 'requests' => 'App/Http/Requests',
        'models' => 'App/Models', 'services' => 'App/Services',
        'resources' => 'App/Http/Resources', 'policies' => 'App/Policies',
        'database' => 'Database', 'routes' => 'Routes',
        'middleware' => 'App/Http/Middleware', 'console' => 'App/Console',
        'feature-tests' => 'Tests/Feature', 'unit-tests' => 'Tests/Unit',
    ];

    private array $presetDescriptions = [
        'minimal' => 'Minimal - model and request for a lightweight module',
        'normal' => 'Normal - controller, request, model, service, and API routes',
        'all' => 'All - normal plus resources, policies, database, middleware, console, and tests',
    ];

    private array $normalComponents = ['controllers', 'requests', 'models', 'services', 'routes'];

    private array $allComponents = [
        'controllers', 'requests', 'models', 'services', 'resources', 'policies',
        'database', 'routes', 'middleware', 'console', 'feature-tests', 'unit-tests',
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

        $this->ensureDirectory($files, $modulePath);
        $this->ensureDirectory($files, "{$modulePath}/Config");
        $this->ensureDirectory($files, "{$modulePath}/App/Providers");

        foreach ($components as $component) {
            $this->ensureDirectory($files, "{$modulePath}/{$this->paths[$component]}");
        }

        $this->writeFile($files, "{$modulePath}/Config/config.php", "<?php\n\nreturn [\n    'enabled' => true,\n];\n");
        $this->createProvider($files, $modulePath, $name, $components);
        $this->createComponents($files, $modulePath, $name, $components);
        $this->createManifest($files, $modulePath, $name, $components);

        $this->info("Module [{$name}] created successfully.");
        return self::SUCCESS;
    }

    private function components(): ?array
    {
        $requested = $this->option('components');
        if ($requested !== []) {
            $requested = array_values(array_unique(array_filter(array_map(
                static fn (string $value): string => Str::kebab(trim($value)), $requested
            ))));
            return $this->validateComponents($requested);
        }

        $preset = $this->option('minimal') ? 'minimal' : $this->option('preset');
        if ($preset !== null) {
            return $this->presetComponents($preset);
        }

        if ($this->option('no-prompts') || ! $this->input->isInteractive()) {
            return $this->normalComponents;
        }

        $preset = $this->choice(
            'How much should be generated?',
            $this->presetDescriptions,
            'normal'
        );

        return $this->presetComponents($preset);
    }

    private function presetComponents(string $preset): ?array
    {
        return match (Str::lower(trim($preset))) {
            'minimal' => ['models', 'requests'],
            'normal' => $this->normalComponents,
            'all' => $this->allComponents,
            default => $this->invalidPreset($preset),
        };
    }

    private function invalidPreset(string $preset): ?array
    {
        $this->error("Unknown preset [{$preset}]. Use minimal, normal, or all.");
        return null;
    }

    private function validateComponents(array $components): ?array
    {
        $invalid = array_diff($components, array_keys($this->paths));
        if ($invalid !== []) {
            $this->error('Unknown component(s): ' . implode(', ', $invalid));
            $this->line('Available: ' . implode(', ', array_keys($this->paths)));
            return null;
        }

        if (in_array('controllers', $components, true)) {
            $components = array_merge($components, ['models', 'requests', 'routes']);
        }
        if (in_array('resources', $components, true) || in_array('policies', $components, true) || in_array('database', $components, true)) {
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

    private function createProvider(Filesystem $files, string $modulePath, string $name, array $components): void
    {
        $routes = in_array('routes', $components, true)
            ? "        \$this->loadRoutesFrom(__DIR__ . '/../../Routes/api.php');\n" : '';
        $migrations = in_array('database', $components, true)
            ? "        \$this->loadMigrationsFrom(__DIR__ . '/../../Database/Migrations');\n" : '';

        $this->writeFile($files, "{$modulePath}/App/Providers/{$name}ServiceProvider.php", <<<PHP
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
{$routes}{$migrations}    }
}
PHP);
    }

    private function createComponents(Filesystem $files, string $modulePath, string $name, array $components): void
    {
        $ns = "Modules\\{$name}";
        $parameter = Str::camel($name);
        $route = Str::kebab(Str::pluralStudly($name));
        $vars = ['__NS__' => $ns, '__NAME__' => $name, '__PARAM__' => $parameter, '__ROUTE__' => $route];

        if (in_array('models', $components, true)) {
            $factory = in_array('database', $components, true);
            $this->template($files, "{$modulePath}/App/Models/{$name}.php", <<<'PHP'
<?php

namespace __NS__\App\Models;

use Illuminate\Database\Eloquent\Model;
__FACTORY_IMPORT__
class __NAME__ extends Model
{
__FACTORY_CODE__    protected $guarded = [];
}
PHP, $vars + [
                '__FACTORY_IMPORT__' => $factory ? "use Illuminate\\Database\\Eloquent\\Factories\\Factory;\nuse Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\n" : '',
                '__FACTORY_CODE__' => $factory ? "    use HasFactory;\n\n    protected static function newFactory(): Factory\n    {\n        return \\{$ns}\\Database\\Factories\\{$name}Factory::new();\n    }\n\n" : '',
            ]);
        }

        if (in_array('requests', $components, true)) {
            $this->template($files, "{$modulePath}/App/Http/Requests/{$name}Request.php", <<<'PHP'
<?php

namespace __NS__\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class __NAME__Request extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array { return []; }
}
PHP, $vars);
        }

        if (in_array('services', $components, true)) {
            $this->template($files, "{$modulePath}/App/Services/{$name}Service.php", <<<'PHP'
<?php

namespace __NS__\App\Services;

class __NAME__Service
{
    // Add the module's business logic here.
}
PHP, $vars);
        }

        if (in_array('resources', $components, true)) {
            $this->template($files, "{$modulePath}/App/Http/Resources/{$name}Resource.php", <<<'PHP'
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
PHP, $vars);
        }

        if (in_array('policies', $components, true)) {
            $this->template($files, "{$modulePath}/App/Policies/{$name}Policy.php", <<<'PHP'
<?php

namespace __NS__\App\Policies;

use __NS__\App\Models\__NAME__;
use Illuminate\Foundation\Auth\User;

class __NAME__Policy
{
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, __NAME__ $__PARAM__): bool { return true; }
    public function create(User $user): bool { return true; }
    public function update(User $user, __NAME__ $__PARAM__): bool { return true; }
    public function delete(User $user, __NAME__ $__PARAM__): bool { return true; }
}
PHP, $vars);
        }

        if (in_array('controllers', $components, true)) {
            $resource = in_array('resources', $components, true);
            $this->createController($files, $modulePath, $name, $ns, $parameter, $resource);
            $this->createHttpResponsesTrait($files, $modulePath, $ns);
        }

        if (in_array('database', $components, true)) {
            $this->createDatabase($files, $modulePath, $name, $ns, $vars);
        }

        if (in_array('routes', $components, true)) {
            $routeFile = in_array('controllers', $components, true)
                ? "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\nuse {$ns}\\App\\Http\\Controllers\\{$name}Controller;\n\nRoute::apiResource('{$route}', {$name}Controller::class);\n"
                : "<?php\n\n// Define module routes here.\n";
            $this->writeFile($files, "{$modulePath}/Routes/api.php", $routeFile);
        }

        if (in_array('middleware', $components, true)) {
            $this->template($files, "{$modulePath}/App/Http/Middleware/{$name}Middleware.php", <<<'PHP'
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
PHP, $vars);
        }

        if (in_array('console', $components, true)) {
            $vars['__SIGNATURE__'] = Str::kebab($name) . ':run';
            $this->template($files, "{$modulePath}/App/Console/{$name}Command.php", <<<'PHP'
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
PHP, $vars);
        }

        if (in_array('feature-tests', $components, true)) {
            $this->template($files, "{$modulePath}/Tests/Feature/{$name}Test.php", <<<'PHP'
<?php

namespace __NS__\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class __NAME__Test extends TestCase
{
    public function test_module_routes_are_registered(): void
    {
        $route = Route::getRoutes()->getByName('__ROUTE__.index');
        $this->assertNotNull($route);
        $this->assertSame('/__ROUTE__', $route->uri());
    }
}
PHP, $vars);
        }

        if (in_array('unit-tests', $components, true)) {
            $this->template($files, "{$modulePath}/Tests/Unit/{$name}ServiceTest.php", <<<'PHP'
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
PHP, $vars);
        }
    }

    private function createDatabase(Filesystem $files, string $modulePath, string $name, string $ns, array $vars): void
    {
        $table = Str::snake(Str::pluralStudly($name));
        $migration = date('Y_m_d_His') . "_create_{$table}_table.php";

        $this->template($files, "{$modulePath}/Database/Migrations/{$migration}", <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('__TABLE__', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('__TABLE__');
    }
};
PHP, ['__TABLE__' => $table]);

        $this->template($files, "{$modulePath}/Database/Factories/{$name}Factory.php", <<<'PHP'
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
PHP, $vars);

        $this->template($files, "{$modulePath}/Database/Seeders/{$name}Seeder.php", <<<'PHP'
<?php

namespace __NS__\Database\Seeders;

use Illuminate\Database\Seeder;

class __NAME__Seeder extends Seeder
{
    public function run(): void
    {
        // Add module seed data here.
    }
}
PHP, $vars);
    }

    private function createController(Filesystem $files, string $modulePath, string $name, string $ns, string $parameter, bool $resource): void
    {
        $resourceUse = $resource ? "use {$ns}\\App\\Http\\Resources\\{$name}Resource;\n" : '';
        $index = $resource ? "return \$this->success({$name}Resource::collection({$name}::query()->paginate()));" : "return \$this->success({$name}::query()->paginate());";
        $show = $resource ? "new {$name}Resource(\${$parameter})" : "\${$parameter}";
        $store = $resource ? "return \$this->success(new {$name}Resource(\$model), 'Created successfully.', 201);" : "return \$this->success(\$model, 'Created successfully.', 201);";

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

    public function index(): JsonResponse
    {
        __INDEX__
    }

    public function store(__NAME__Request $request): JsonResponse
    {
        $model = __NAME__::create($request->validated());
        __STORE__
    }

    public function show(__NAME__ $__PARAM__): JsonResponse
    {
        return $this->success(__SHOW__);
    }

    public function update(__NAME__Request $request, __NAME__ $__PARAM__): JsonResponse
    {
        $__PARAM__->update($request->validated());
        return $this->success(__SHOW__, 'Updated successfully.');
    }

    public function destroy(__NAME__ $__PARAM__): JsonResponse
    {
        $__PARAM__->delete();
        return $this->success(null, 'Deleted successfully.');
    }
}
PHP, [
            '__NS__' => $ns, '__NAME__' => $name, '__PARAM__' => $parameter,
            '__RESOURCE_USE__' => $resourceUse, '__INDEX__' => $index,
            '__STORE__' => $store, '__SHOW__' => $show,
        ]);

        $this->writeFile($files, "{$modulePath}/App/Http/Controllers/{$name}Controller.php", $content);
    }

    private function createHttpResponsesTrait(Filesystem $files, string $modulePath, string $ns): void
    {
        $this->template($files, "{$modulePath}/App/Traits/HttpResponses.php", <<<PHP
<?php

namespace {$ns}\\App\\Traits;

use Illuminate\\Http\\JsonResponse;

trait HttpResponses
{
    protected function success(mixed \$data = null, ?string \$message = null, int \$code = 200): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => \$message, 'data' => \$data], \$code);
    }

    protected function error(mixed \$data = null, ?string \$message = null, int \$code = 500): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => \$message, 'data' => \$data], \$code);
    }
}
PHP, []);
    }

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

    private function configKey(string $name): string
    {
        return Str::kebab($name);
    }

    private function template(Filesystem $files, string $path, string $content, array $variables): void
    {
        $this->writeFile($files, $path, strtr($content, $variables));
    }

    private function ensureDirectory(Filesystem $files, string $path): void
    {
        if ($files->isDirectory($path)) {
            return;
        }

        if ($files->exists($path)) {
            throw new \RuntimeException("Cannot create directory [{$path}] because a file already exists at that path.");
        }

        $files->makeDirectory($path, 0755, true);
    }

    private function writeFile(Filesystem $files, string $path, string $contents): void
    {
        $this->ensureDirectory($files, dirname($path));
        $files->put($path, $contents);
    }
}
