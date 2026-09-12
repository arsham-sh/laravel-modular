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

    protected $description = 'Create a new Laravel module with a sensible default structure';

    /** @var array<string, string> */
    private array $paths = [
        'console' => 'App/Console',
        'controllers' => 'App/Http/Controllers',
        'middleware' => 'App/Http/Middleware',
        'requests' => 'App/Http/Requests',
        'models' => 'App/Models',
        'services' => 'App/Services',
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

        if ($this->confirm('Add a console directory?', false)) {
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
        $files->put("{$modulePath}/Config/config.php", "<?php\n\nreturn [\n    'enabled' => true,\n];\n");
    }

    /** @param list<string> $components */
    private function createProvider(Filesystem $files, string $modulePath, string $name, array $components): void
    {
        $provider = "<?php\n\nnamespace Modules\\{$name}\\App\\Providers;\n\nuse Illuminate\\Support\\ServiceProvider;\n\nclass {$name}ServiceProvider extends ServiceProvider\n{\n    public function register(): void\n    {\n        \\$this->mergeConfigFrom(__DIR__ . '/../../Config/config.php', '{$name}');\n    }\n\n    public function boot(): void\n    {\n";

        if (in_array('routes', $components, true)) {
            $provider .= "        \\$this->loadRoutesFrom(__DIR__ . '/../../Routes/api.php');\n";
        }

        if (in_array('migrations', $components, true)) {
            $provider .= "        \\$this->loadMigrationsFrom(__DIR__ . '/../../Database/Migrations');\n";
        }

        $provider .= "    }\n}\n";
        $files->put("{$modulePath}/App/Providers/{$name}ServiceProvider.php", $provider);
    }

    /** @param list<string> $components */
    private function createComponents(Filesystem $files, string $modulePath, string $name, array $components): void
    {
        $namespace = "Modules\\{$name}";

        if (in_array('controllers', $components, true)) {
            $files->put("{$modulePath}/App/Http/Controllers/{$name}Controller.php", "<?php\n\nnamespace {$namespace}\\App\\Http\\Controllers;\n\nuse Illuminate\\Http\\JsonResponse;\n\nclass {$name}Controller\n{\n    public function index(): JsonResponse\n    {\n        return response()->json([]);\n    }\n}\n");
        }

        if (in_array('middleware', $components, true)) {
            $files->put("{$modulePath}/App/Http/Middleware/{$name}Middleware.php", "<?php\n\nnamespace {$namespace}\\App\\Http\\Middleware;\n\nuse Closure;\nuse Illuminate\\Http\\Request;\nuse Symfony\\Component\\HttpFoundation\\Response;\n\nclass {$name}Middleware\n{\n    public function handle(Request \\$request, Closure \\$next): Response\n    {\n        return \\$next(\\$request);\n    }\n}\n");
        }

        if (in_array('requests', $components, true)) {
            $files->put("{$modulePath}/App/Http/Requests/{$name}Request.php", "<?php\n\nnamespace {$namespace}\\App\\Http\\Requests;\n\nuse Illuminate\\Foundation\\Http\\FormRequest;\n\nclass {$name}Request extends FormRequest\n{\n    public function authorize(): bool\n    {\n        return true;\n    }\n\n    public function rules(): array\n    {\n        return [];\n    }\n}\n");
        }

        if (in_array('models', $components, true)) {
            $files->put("{$modulePath}/App/Models/{$name}.php", "<?php\n\nnamespace {$namespace}\\App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass {$name} extends Model\n{\n    use HasFactory;\n\n    protected \\$guarded = [];\n}\n");
        }

        if (in_array('services', $components, true)) {
            $files->put("{$modulePath}/App/Services/{$name}Service.php", "<?php\n\nnamespace {$namespace}\\App\\Services;\n\nclass {$name}Service\n{\n    // Module business logic belongs here.\n}\n");
        }

        if (in_array('factories', $components, true)) {
            $files->put("{$modulePath}/Database/Factories/{$name}Factory.php", "<?php\n\nnamespace {$namespace}\\Database\\Factories;\n\nuse Illuminate\\Database\\Eloquent\\Factories\\Factory;\nuse {$namespace}\\App\\Models\\{$name};\n\nclass {$name}Factory extends Factory\n{\n    protected \\$model = {$name}::class;\n\n    public function definition(): array\n    {\n        return [];\n    }\n}\n");
        }

        if (in_array('migrations', $components, true)) {
            $table = Str::snake(Str::pluralStudly($name));
            $timestamp = date('Y_m_d_His');
            $files->put("{$modulePath}/Database/Migrations/{$timestamp}_create_{$table}_table.php", "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        Schema::create('{$table}', function (Blueprint \\$table): void {\n            \\$table->id();\n            \\$table->timestamps();\n        });\n    }\n\n    public function down(): void\n    {\n        Schema::dropIfExists('{$table}');\n    }\n};\n");
        }

        if (in_array('seeders', $components, true)) {
            $files->put("{$modulePath}/Database/Seeders/{$name}Seeder.php", "<?php\n\nnamespace {$namespace}\\Database\\Seeders;\n\nuse Illuminate\\Database\\Seeder;\n\nclass {$name}Seeder extends Seeder\n{\n    public function run(): void\n    {\n        // Seed module data here.\n    }\n}\n");
        }

        if (in_array('routes', $components, true)) {
            $route = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n";
            if (in_array('controllers', $components, true)) {
                $route .= "use {$namespace}\\App\\Http\\Controllers\\{$name}Controller;\n\n";
                $route .= "Route::get('/{$name}', [{$name}Controller::class, 'index']);\n";
            } else {
                $route .= "\n";
            }
            $files->put("{$modulePath}/Routes/api.php", $route);
        }

        if (in_array('feature-tests', $components, true)) {
            $files->put("{$modulePath}/Tests/Feature/{$name}Test.php", "<?php\n\nnamespace {$namespace}\\Tests\\Feature;\n\nuse Tests\\TestCase;\n\nclass {$name}Test extends TestCase\n{\n    public function test_module_can_be_loaded(): void\n    {\n        \\$this->assertTrue(true);\n    }\n}\n");
        }

        if (in_array('unit-tests', $components, true)) {
            $files->put("{$modulePath}/Tests/Unit/{$name}ServiceTest.php", "<?php\n\nnamespace {$namespace}\\Tests\\Unit;\n\nuse PHPUnit\\Framework\\TestCase;\n\nclass {$name}ServiceTest extends TestCase\n{\n    public function test_basic_unit_test(): void\n    {\n        \\$this->assertTrue(true);\n    }\n}\n");
        }
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
