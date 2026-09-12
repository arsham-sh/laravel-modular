<?php

namespace Arsham\LaravelModular\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeModuleCommand extends Command
{
    protected $signature = 'module:make
                            {name : The name of the module}
                            {--components=* : Components to generate, e.g. controllers,models,services}
                            {--minimal : Generate only the core module components}
                            {--no-prompts : Use defaults without asking optional component questions}';

    protected $description = 'Create a complete Laravel module with sensible defaults';

    /** @var array<string, string> */
    private array $paths = [
        'console' => 'App/Console',
        'controllers' => 'App/Http/Controllers',
        'middleware' => 'App/Http/Middleware',
        'requests' => 'App/Http/Requests',
        'models' => 'App/Models',
        'services' => 'App/Services',
        'jobs' => 'App/Jobs',
        'events' => 'App/Events',
        'listeners' => 'App/Listeners',
        'policies' => 'App/Policies',
        'resources' => 'App/Http/Resources',
        'factories' => 'Database/Factories',
        'migrations' => 'Database/Migrations',
        'seeders' => 'Database/Seeders',
        'routes' => 'Routes',
        'feature-tests' => 'Tests/Feature',
        'unit-tests' => 'Tests/Unit',
    ];

    /** @var list<string> */
    private array $defaultComponents = [
        'controllers',
        'requests',
        'models',
        'services',
        'jobs',
        'events',
        'listeners',
        'policies',
        'resources',
        'migrations',
        'factories',
        'seeders',
        'routes',
        'feature-tests',
        'unit-tests',
    ];

    /** @var list<string> */
    private array $coreComponents = [
        'controllers',
        'requests',
        'models',
        'services',
        'routes',
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

        $this->createDirectories($files, $modulePath, $components);
        $this->createConfig($files, $modulePath);
        $this->createProvider($files, $modulePath, $name, $components);
        $this->createComponents($files, $modulePath, $name, $components);
        $this->createModuleManifest($files, $modulePath, $name, $components);

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
            $requested = array_values(array_filter(array_map(
                static fn (string $component): string => Str::kebab(trim($component)),
                $requested
            )));

            $invalid = array_diff($requested, array_keys($this->paths));
            if ($invalid !== []) {
                $this->error('Unknown component(s): ' . implode(', ', $invalid));
                $this->line('Available: ' . implode(', ', array_keys($this->paths)));
                return null;
            }

            return array_values(array_unique($requested));
        }

        if ($this->option('minimal') || $this->option('no-prompts') || ! $this->input->isInteractive()) {
            return $this->option('minimal') ? $this->coreComponents : $this->defaultComponents;
        }

        $components = $this->defaultComponents;

        $this->newLine();
        $this->comment('Creating a complete module by default. No component-number roulette required.');

        if ($this->confirm('Add a middleware?', false)) {
            $components[] = 'middleware';
        }

        if ($this->confirm('Add a console command?', false)) {
            $components[] = 'console';
        }

        return array_values(array_unique($components));
    }

    /** @param list<string> $components */
    private function createDirectories(Filesystem $files, string $modulePath, array $components): void
    {
        $files->makeDirectory("{$modulePath}/Config", 0755, true);
        $files->makeDirectory("{$modulePath}/App/Providers", 0755, true);

        foreach ($components as $component) {
            $files->makeDirectory("{$modulePath}/{$this->paths[$component]}", 0755, true);
        }
    }

    private function createConfig(Filesystem $files, string $modulePath): void
    {
        $files->put("{$modulePath}/Config/config.php", <<<'PHP'
<?php

return [
    'enabled' => true,
];
PHP
        . PHP_EOL);
    }

    /** @param list<string> $components */
    private function createProvider(Filesystem $files, string $modulePath, string $name, array $components): void
    {
        $boot = '';

        if (in_array('routes', $components, true)) {
            $boot .= "        \\$this->loadRoutesFrom(__DIR__ . '/../../Routes/api.php');\n";
        }

        if (in_array('migrations', $components, true)) {
            $boot .= "        \\$this->loadMigrationsFrom(__DIR__ . '/../../Database/Migrations');\n";
        }

        $provider = <<<'PHP'
<?php

namespace Modules\{{NAME}}\App\Providers;

use Illuminate\Support\ServiceProvider;

class {{NAME}}ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../Config/config.php', '{{CONFIG_KEY}}');
    }

    public function boot(): void
    {
{{BOOT}}    }
}
PHP;

        $provider = $this->render($provider, [
            '{{NAME}}' => $name,
            '{{CONFIG_KEY}}' => Str::kebab($name),
            '{{BOOT}}' => $boot,
        ]);

        $files->put("{$modulePath}/App/Providers/{$name}ServiceProvider.php", $provider . PHP_EOL);
    }

    /** @param list<string> $components */
    private function createComponents(Filesystem $files, string $modulePath, string $name, array $components): void
    {
        $namespace = "Modules\\{$name}";
        $table = Str::snake(Str::pluralStudly($name));
        $pluralRoute = Str::kebab(Str::pluralStudly($name));

        if (in_array('controllers', $components, true)) {
            $content = <<<'PHP'
<?php

namespace {{NAMESPACE}}\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use {{NAMESPACE}}\App\Http\Resources\{{NAME}}Resource;
use {{NAMESPACE}}\App\Models\{{NAME}};

class {{NAME}}Controller
{
    public function index(): JsonResponse
    {
        return response()->json({{RESOURCE}}::collection({{NAME}}::query()->paginate()));
    }

    public function store(Request $request): JsonResponse
    {
        $model = {{NAME}}::create($request->all());

        return response()->json(new {{RESOURCE}}($model), 201);
    }

    public function show({{NAME}} ${{PARAM}}): JsonResponse
    {
        return response()->json(new {{RESOURCE}}(${{PARAM}}));
    }

    public function update(Request $request, {{NAME}} ${{PARAM}}): JsonResponse
    {
        ${{PARAM}}->update($request->all());

        return response()->json(new {{RESOURCE}}(${{PARAM}}));
    }

    public function destroy({{NAME}} ${{PARAM}}): JsonResponse
    {
        ${{PARAM}}->delete();

        return response()->noContent();
    }
}
PHP;

            if (! in_array('resources', $components, true)) {
                $content = str_replace(
                    'use {{NAMESPACE}}\\App\\Http\\Resources\\{{NAME}}Resource;\n',
                    '',
                    $content
                );
                $content = str_replace('{{RESOURCE}}', 'collect', $content);
                $content = str_replace('new {{RESOURCE}}($model)', '$model', $content);
                $content = str_replace('new {{RESOURCE}}(${{PARAM}})', '${{PARAM}}', $content);
            } else {
                $content = str_replace('{{RESOURCE}}', '{{NAME}}Resource', $content);
            }

            $content = $this->render($content, [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $name,
                '{{PARAM}}' => Str::camel($name),
            ]);

            $files->put("{$modulePath}/App/Http/Controllers/{$name}Controller.php", $content . PHP_EOL);
        }

        if (in_array('middleware', $components, true)) {
            $content = <<<'PHP'
<?php

namespace {{NAMESPACE}}\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class {{NAME}}Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
PHP;
            $files->put("{$modulePath}/App/Http/Middleware/{$name}Middleware.php", $this->render($content, [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $name,
            ]) . PHP_EOL);
        }

        if (in_array('requests', $components, true)) {
            $content = <<<'PHP'
<?php

namespace {{NAMESPACE}}\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class {{NAME}}Request extends FormRequest
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
PHP;
            $files->put("{$modulePath}/App/Http/Requests/{$name}Request.php", $this->render($content, [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $name,
            ]) . PHP_EOL);
        }

        if (in_array('models', $components, true)) {
            $content = <<<'PHP'
<?php

namespace {{NAMESPACE}}\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class {{NAME}} extends Model
{
    use HasFactory;

    protected $guarded = [];
}
PHP;
            $files->put("{$modulePath}/App/Models/{$name}.php", $this->render($content, [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $name,
            ]) . PHP_EOL);
        }

        if (in_array('services', $components, true)) {
            $content = <<<'PHP'
<?php

namespace {{NAMESPACE}}\App\Services;

class {{NAME}}Service
{
    // Put module business logic here.
}
PHP;
            $files->put("{$modulePath}/App/Services/{$name}Service.php", $this->render($content, [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $name,
            ]) . PHP_EOL);
        }

        if (in_array('jobs', $components, true)) {
            $content = <<<'PHP'
<?php

namespace {{NAMESPACE}}\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class {{NAME}}Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // Put asynchronous module work here.
    }
}
PHP;
            $files->put("{$modulePath}/App/Jobs/{$name}Job.php", $this->render($content, [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $name,
            ]) . PHP_EOL);
        }

        if (in_array('events', $components, true)) {
            $content = <<<'PHP'
<?php

namespace {{NAMESPACE}}\App\Events;

class {{NAME}}Event
{
    public function __construct(public readonly mixed $payload = null)
    {
    }
}
PHP;
            $files->put("{$modulePath}/App/Events/{$name}Event.php", $this->render($content, [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $name,
            ]) . PHP_EOL);
        }

        if (in_array('listeners', $components, true) && in_array('events', $components, true)) {
            $content = <<<'PHP'
<?php

namespace {{NAMESPACE}}\App\Listeners;

use {{NAMESPACE}}\App\Events\{{NAME}}Event;

class {{NAME}}EventListener
{
    public function handle({{NAME}}Event $event): void
    {
        // Handle the module event here.
    }
}
PHP;
            $files->put("{$modulePath}/App/Listeners/{$name}EventListener.php", $this->render($content, [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $name,
            ]) . PHP_EOL);
        }

        if (in_array('policies', $components, true) && in_array('models', $components, true)) {
            $content = <<<'PHP'
<?php

namespace {{NAMESPACE}}\App\Policies;

use {{NAMESPACE}}\App\Models\{{NAME}};

class {{NAME}}Policy
{
    public function viewAny(mixed $user): bool
    {
        return true;
    }

    public function view(mixed $user, {{NAME}} ${{PARAM}}): bool
    {
        return true;
    }

    public function create(mixed $user): bool
    {
        return true;
    }

    public function update(mixed $user, {{NAME}} ${{PARAM}}): bool
    {
        return true;
    }

    public function delete(mixed $user, {{NAME}} ${{PARAM}}): bool
    {
        return true;
    }
}
PHP;
            $files->put("{$modulePath}/App/Policies/{$name}Policy.php", $this->render($content, [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $name,
                '{{PARAM}}' => Str::camel($name),
            ]) . PHP_EOL);
        }

        if (in_array('resources', $components, true) && in_array('models', $components, true)) {
            $content = <<<'PHP'
<?php

namespace {{NAMESPACE}}\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class {{NAME}}Resource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
PHP;
            $files->put("{$modulePath}/App/Http/Resources/{$name}Resource.php", $this->render($content, [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $name,
            ]) . PHP_EOL);
        }

        if (in_array('factories', $components, true) && in_array('models', $components, true)) {
            $content = <<<'PHP'
<?php

namespace {{NAMESPACE}}\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use {{NAMESPACE}}\App\Models\{{NAME}};

class {{NAME}}Factory extends Factory
{
    protected $model = {{NAME}}::class;

    public function definition(): array
    {
        return [];
    }
}
PHP;
            $files->put("{$modulePath}/Database/Factories/{$name}Factory.php", $this->render($content, [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $name,
            ]) . PHP_EOL);
        }

        if (in_array('migrations', $components, true)) {
            $timestamp = date('Y_m_d_His');
            $content = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{{TABLE}}', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{{TABLE}}');
    }
};
PHP;
            $files->put("{$modulePath}/Database/Migrations/{$timestamp}_create_{$table}_table.php", $this->render($content, [
                '{{TABLE}}' => $table,
            ]) . PHP_EOL);
        }

        if (in_array('seeders', $components, true)) {
            $content = <<<'PHP'
<?php

namespace {{NAMESPACE}}\Database\Seeders;

use Illuminate\Database\Seeder;

class {{NAME}}Seeder extends Seeder
{
    public function run(): void
    {
        // Seed module data here.
    }
}
PHP;
            $files->put("{$modulePath}/Database/Seeders/{$name}Seeder.php", $this->render($content, [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $name,
            ]) . PHP_EOL);
        }

        if (in_array('routes', $components, true) && in_array('controllers', $components, true)) {
            $content = <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;
use {{NAMESPACE}}\App\Http\Controllers\{{NAME}}Controller;

Route::apiResource('{{ROUTE}}', {{NAME}}Controller::class);
PHP;
            $files->put("{$modulePath}/Routes/api.php", $this->render($content, [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $name,
                '{{ROUTE}}' => $pluralRoute,
            ]) . PHP_EOL);
        }

        if (in_array('console', $components, true)) {
            $content = <<<'PHP'
<?php

namespace {{NAMESPACE}}\App\Console;

use Illuminate\Console\Command;

class {{NAME}}Command extends Command
{
    protected $signature = '{{SIGNATURE}}';

    protected $description = 'Run the {{NAME}} module command';

    public function handle(): int
    {
        $this->info('{{NAME}} module command is ready.');

        return self::SUCCESS;
    }
}
PHP;
            $files->put("{$modulePath}/App/Console/{$name}Command.php", $this->render($content, [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $name,
                '{{SIGNATURE}}' => Str::kebab($name) . ':run',
            ]) . PHP_EOL);
        }

        if (in_array('feature-tests', $components, true)) {
            $content = <<<'PHP'
<?php

namespace {{NAMESPACE}}\Tests\Feature;

use Tests\TestCase;

class {{NAME}}Test extends TestCase
{
    public function test_module_endpoint_is_available(): void
    {
        $this->getJson('/api/{{ROUTE}}')->assertOk();
    }
}
PHP;
            $files->put("{$modulePath}/Tests/Feature/{$name}Test.php", $this->render($content, [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $name,
                '{{ROUTE}}' => $pluralRoute,
            ]) . PHP_EOL);
        }

        if (in_array('unit-tests', $components, true)) {
            $content = <<<'PHP'
<?php

namespace {{NAMESPACE}}\Tests\Unit;

use PHPUnit\Framework\TestCase;

class {{NAME}}ServiceTest extends TestCase
{
    public function test_service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(
            \{{NAMESPACE}}\App\Services\{{NAME}}Service::class,
            new \{{NAMESPACE}}\App\Services\{{NAME}}Service()
        );
    }
}
PHP;
            $files->put("{$modulePath}/Tests/Unit/{$name}ServiceTest.php", $this->render($content, [
                '{{NAMESPACE}}' => $namespace,
                '{{NAME}}' => $name,
            ]) . PHP_EOL);
        }
    }

    /** @param array<string, string> $replacements */
    private function render(string $template, array $replacements): string
    {
        return strtr($template, $replacements);
    }

    /** @param list<string> $components */
    private function createModuleManifest(Filesystem $files, string $modulePath, string $name, array $components): void
    {
        $files->put("{$modulePath}/module.json", json_encode([
            'name' => $name,
            'namespace' => "Modules\\{$name}",
            'provider' => "Modules\\{$name}\\App\\Providers\\{$name}ServiceProvider",
            'version' => '1.0.0',
            'description' => "{$name} module",
            'enabled' => true,
            'components' => $components,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
}
