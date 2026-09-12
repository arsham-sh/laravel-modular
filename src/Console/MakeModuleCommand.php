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
        {--no-prompts : Skip optional questions}';

    protected $description = 'Create a complete Laravel module with sensible defaults';

    /** @var array<string, string> */
    private array $paths = [
        'console' => 'App/Console', 'controllers' => 'App/Http/Controllers',
        'middleware' => 'App/Http/Middleware', 'requests' => 'App/Http/Requests',
        'models' => 'App/Models', 'services' => 'App/Services', 'jobs' => 'App/Jobs',
        'events' => 'App/Events', 'listeners' => 'App/Listeners', 'policies' => 'App/Policies',
        'resources' => 'App/Http/Resources', 'factories' => 'Database/Factories',
        'migrations' => 'Database/Migrations', 'seeders' => 'Database/Seeders',
        'routes' => 'Routes', 'feature-tests' => 'Tests/Feature', 'unit-tests' => 'Tests/Unit',
    ];

    /** @var list<string> */
    private array $defaultComponents = [
        'controllers', 'requests', 'models', 'services', 'jobs', 'events', 'listeners',
        'policies', 'resources', 'migrations', 'factories', 'seeders', 'routes',
        'feature-tests', 'unit-tests',
    ];

    /** @var list<string> */
    private array $coreComponents = ['controllers', 'requests', 'models', 'services', 'routes'];

    public function handle(Filesystem $files): int
    {
        $name = Str::studly($this->argument('name'));
        $modulePath = base_path("Modules/{$name}");

        if ($files->exists($modulePath)) {
            $this->error("Module [{$name}] already exists.");
            return self::FAILURE;
        }

        $components = $this->components();
        if ($components === null) return self::FAILURE;

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
        $this->line('Components: ' . implode(', ', $components));
        $this->line("Location: Modules/{$name}");

        return self::SUCCESS;
    }

    /** @return list<string>|null */
    private function components(): ?array
    {
        $requested = $this->option('components');
        if ($requested !== []) {
            $requested = array_values(array_unique(array_filter(array_map(
                static fn (string $value): string => Str::kebab(trim($value)), $requested
            ))));
            $invalid = array_diff($requested, array_keys($this->paths));
            if ($invalid !== []) {
                $this->error('Unknown component(s): ' . implode(', ', $invalid));
                $this->line('Available: ' . implode(', ', array_keys($this->paths)));
                return null;
            }
            return $requested;
        }

        if ($this->option('minimal') || $this->option('no-prompts') || ! $this->input->isInteractive()) {
            return $this->option('minimal') ? $this->coreComponents : $this->defaultComponents;
        }

        $components = $this->defaultComponents;
        $this->comment('Creating a complete module by default.');
        if ($this->confirm('Add middleware?', false)) $components[] = 'middleware';
        if ($this->confirm('Add a console command?', false)) $components[] = 'console';
        return array_values(array_unique($components));
    }

    /** @param list<string> $components */
    private function createProvider(Filesystem $files, string $modulePath, string $name, array $components): void
    {
        $boot = '';
        if (in_array('routes', $components, true)) {
            $boot .= "        \$this->loadRoutesFrom(__DIR__ . '/../../Routes/api.php');" . PHP_EOL;
        }
        if (in_array('migrations', $components, true)) {
            $boot .= "        \$this->loadMigrationsFrom(__DIR__ . '/../../Database/Migrations');" . PHP_EOL;
        }

        $content = "<?php\n\nnamespace Modules\\{$name}\\App\\Providers;\n\nuse Illuminate\\Support\\ServiceProvider;\n\nclass {$name}ServiceProvider extends ServiceProvider\n{\n    public function register(): void\n    {\n        \$this->mergeConfigFrom(__DIR__ . '/../../Config/config.php', '" . Str::kebab($name) . "');\n    }\n\n    public function boot(): void\n    {\n{$boot}    }\n}\n";
        $this->put($files, "{$modulePath}/App/Providers/{$name}ServiceProvider.php", $content);
    }

    /** @param list<string> $components */
    private function createComponents(Filesystem $files, string $modulePath, string $name, array $components): void
    {
        $ns = "Modules\\{$name}";
        $param = Str::camel($name);
        $table = Str::snake(Str::pluralStudly($name));
        $route = Str::kebab(Str::pluralStudly($name));
        $vars = ['__NS__' => $ns, '__NAME__' => $name, '__PARAM__' => $param, '__TABLE__' => $table, '__ROUTE__' => $route];

        if (in_array('models', $components, true)) {
            $factoryMethod = in_array('factories', $components, true)
                ? "\n    protected static function newFactory(): \\Illuminate\\Database\\Eloquent\\Factories\\Factory\n    {\n        return \\{$ns}\\Database\\Factories\\{$name}Factory::new();\n    }\n"
                : '';
            $this->putTemplate($files, "{$modulePath}/App/Models/{$name}.php", <<<'PHP'
<?php

namespace __NS__\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class __NAME__ extends Model
{
    use HasFactory;

    protected $guarded = [];__FACTORY__
}
PHP
            , ['__FACTORY__' => $factoryMethod] + $vars);
        }

        if (in_array('requests', $components, true)) {
            $this->putTemplate($files, "{$modulePath}/App/Http/Requests/{$name}Request.php", <<<'PHP'
<?php

namespace __NS__\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class __NAME__Request extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array { return []; }
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
    // Put module business logic here.
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
            $resourceUse = in_array('resources', $components, true)
                ? "use {$ns}\\App\\Http\\Resources\\{$name}Resource;\n"
                : '';
            $index = in_array('resources', $components, true)
                ? "return {$name}Resource::collection({$name}::query()->paginate());"
                : "return response()->json({$name}::query()->paginate());";
            $store = in_array('resources', $components, true)
                ? "return response()->json(new {$name}Resource(\$model), 201);"
                : "return response()->json(\$model, 201);";
            $show = in_array('resources', $components, true)
                ? "new {$name}Resource(\${$param})"
                : "\${$param}";
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
            , array_merge($vars, [
                '__RESOURCE_USE__' => $resourceUse,
                '__INDEX__' => $index,
                '__STORE__' => $store,
                '__SHOW__' => $show,
            ]));
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

    public function definition(): array { return []; }
}
PHP
            , $vars);
        }

        if (in_array('migrations', $components, true)) {
            $file = date('Y_m_d_His') . "_create_{$table}_table.php";
            $this->putTemplate($files, "{$modulePath}/Database/Migrations/{$file}", <<<'PHP'
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
    public function run(): void {}
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

        if (in_array('jobs', $components, true)) {
            $this->putTemplate($files, "{$modulePath}/App/Jobs/{$name}Job.php", <<<'PHP'
<?php

namespace __NS__\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class __NAME__Job implements ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}
PHP
            , $vars);
        }

        if (in_array('events', $components, true)) {
            $this->putTemplate($files, "{$modulePath}/App/Events/{$name}Event.php", <<<'PHP'
<?php

namespace __NS__\App\Events;

class __NAME__Event
{
    public function __construct(public readonly mixed $payload = null) {}
}
PHP
            , $vars);
        }

        if (in_array('listeners', $components, true) && in_array('events', $components, true)) {
            $this->putTemplate($files, "{$modulePath}/App/Listeners/{$name}EventListener.php", <<<'PHP'
<?php

namespace __NS__\App\Listeners;

use __NS__\App\Events\__NAME__Event;

class __NAME__EventListener
{
    public function handle(__NAME__Event $event): void {}
}
PHP
            , $vars);
        }

        if (in_array('policies', $components, true) && in_array('models', $components, true)) {
            $this->putTemplate($files, "{$modulePath}/App/Policies/{$name}Policy.php", <<<'PHP'
<?php

namespace __NS__\App\Policies;

use __NS__\App\Models\__NAME__;

class __NAME__Policy
{
    public function viewAny(mixed $user): bool { return true; }
    public function view(mixed $user, __NAME__ $__PARAM__): bool { return true; }
    public function create(mixed $user): bool { return true; }
    public function update(mixed $user, __NAME__ $__PARAM__): bool { return true; }
    public function delete(mixed $user, __NAME__ $__PARAM__): bool { return true; }
}
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
        $this->info('__NAME__ module command is ready.');
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
    public function test_module_endpoint_is_available(): void
    {
        $this->getJson('/api/__ROUTE__')->assertOk();
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

class __NAME__ServiceTest extends TestCase
{
    public function test_service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(__NS__\App\Services\__NAME__Service::class, new __NS__\App\Services\__NAME__Service());
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

    /** @param array<string, string> $vars */
    private function putTemplate(Filesystem $files, string $path, string $template, array $vars = []): void
    {
        $this->put($files, $path, strtr($template, $vars));
    }

    private function put(Filesystem $files, string $path, string $contents): void
    {
        $files->put($path, $contents);
    }
}
