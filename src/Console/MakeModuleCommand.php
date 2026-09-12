<?php

namespace Arsham\LaravelModular\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeModuleCommand extends Command
{
    protected $signature = 'module:make
                            {name : The name of the module}
                            {--components=* : Components to generate, comma-separated}
                            {--minimal : Generate only the module essentials}';

    protected $description = 'Create a new Laravel module';

    public function handle(Filesystem $files): int
    {
        $name = Str::studly($this->argument('name'));
        $modulePath = base_path("Modules/{$name}");

        if ($files->exists($modulePath)) {
            $this->error("Module [{$name}] already exists.");

            return self::FAILURE;
        }

        $available = [
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

        $components = $this->components(array_keys($available));

        $files->makeDirectory("{$modulePath}/Config", 0755, true);
        $files->makeDirectory("{$modulePath}/App/Providers", 0755, true);

        foreach ($components as $component) {
            $files->makeDirectory(
                "{$modulePath}/{$available[$component]}",
                0755,
                true
            );
        }

        $configPath = "{$modulePath}/Config/config.php";
        $files->put($configPath, <<<PHP
<?php

return [
    'enabled' => true,
];
PHP
        . PHP_EOL);

        $provider = <<<PHP
<?php

namespace Modules\\{$name}\\App\\Providers;

use Illuminate\\Support\\ServiceProvider;

class {$name}ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        \$this->mergeConfigFrom(__DIR__ . '/../../Config/config.php', '{$name}');
    }

    public function boot(): void
    {
PHP;

        if (in_array('routes', $components, true)) {
            $provider .= "        \\$this->loadRoutesFrom(__DIR__ . '/../../Routes/api.php');\n";
        }

        if (in_array('migrations', $components, true)) {
            $provider .= "        \\$this->loadMigrationsFrom(__DIR__ . '/../../Database/Migrations');\n";
        }

        $provider .= "    }\n}\n";

        $files->put(
            "{$modulePath}/App/Providers/{$name}ServiceProvider.php",
            $provider
        );

        if (in_array('controllers', $components, true)) {
            $files->put("{$modulePath}/App/Http/Controllers/{$name}Controller.php", <<<PHP
<?php

namespace Modules\\{$name}\\App\\Http\\Controllers;

use Illuminate\\Http\\JsonResponse;

class {$name}Controller
{
    public function index(): JsonResponse
    {
        return response()->json([]);
    }
}
PHP
            . PHP_EOL);
        }

        if (in_array('middleware', $components, true)) {
            $files->put("{$modulePath}/App/Http/Middleware/{$name}Middleware.php", <<<PHP
<?php

namespace Modules\\{$name}\\App\\Http\\Middleware;

use Closure;
use Illuminate\\Http\\Request;
use Symfony\\Component\\HttpFoundation\\Response;

class {$name}Middleware
{
    public function handle(Request \$request, Closure \$next): Response
    {
        return \$next(\$request);
    }
}
PHP
            . PHP_EOL);
        }

        if (in_array('requests', $components, true)) {
            $files->put("{$modulePath}/App/Http/Requests/{$name}Request.php", <<<PHP
<?php

namespace Modules\\{$name}\\App\\Http\\Requests;

use Illuminate\\Foundation\\Http\\FormRequest;

class {$name}Request extends FormRequest
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
            . PHP_EOL);
        }

        if (in_array('models', $components, true)) {
            $files->put("{$modulePath}/App/Models/{$name}.php", <<<PHP
<?php

namespace Modules\\{$name}\\App\\Models;

use Illuminate\\Database\\Eloquent\\Model;

class {$name} extends Model
{
    protected \$guarded = [];
}
PHP
            . PHP_EOL);
        }

        if (in_array('services', $components, true)) {
            $files->put("{$modulePath}/App/Services/{$name}Service.php", <<<PHP
<?php

namespace Modules\\{$name}\\App\\Services;

class {$name}Service
{
    // Module business logic belongs here.
}
PHP
            . PHP_EOL);
        }

        if (in_array('factories', $components, true)) {
            $files->put("{$modulePath}/Database/Factories/{$name}Factory.php", <<<PHP
<?php

namespace Modules\\{$name}\\Database\\Factories;

use Illuminate\\Database\\Eloquent\\Factories\\Factory;

class {$name}Factory extends Factory
{
    public function definition(): array
    {
        return [];
    }
}
PHP
            . PHP_EOL);
        }

        if (in_array('seeders', $components, true)) {
            $files->put("{$modulePath}/Database/Seeders/{$name}Seeder.php", <<<PHP
<?php

namespace Modules\\{$name}\\Database\\Seeders;

use Illuminate\\Database\\Seeder;

class {$name}Seeder extends Seeder
{
    public function run(): void
    {
        // Seed module data here.
    }
}
PHP
            . PHP_EOL);
        }

        if (in_array('routes', $components, true)) {
            $route = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n";

            if (in_array('controllers', $components, true)) {
                $route .= "use Modules\\{$name}\\App\\Http\\Controllers\\{$name}Controller;\n\n";
                $route .= "Route::get('/{$name}', [{$name}Controller::class, 'index']);\n";
            } else {
                $route .= "\n";
            }

            $files->put("{$modulePath}/Routes/api.php", $route);
        }

        if (in_array('feature-tests', $components, true)) {
            $files->put("{$modulePath}/Tests/Feature/{$name}Test.php", <<<PHP
<?php

namespace Modules\\{$name}\\Tests\\Feature;

use Tests\\TestCase;

class {$name}Test extends TestCase
{
    public function test_module_can_be_loaded(): void
    {
        \$this->assertTrue(true);
    }
}
PHP
            . PHP_EOL);
        }

        if (in_array('unit-tests', $components, true)) {
            $files->put("{$modulePath}/Tests/Unit/{$name}ServiceTest.php", <<<PHP
<?php

namespace Modules\\{$name}\\Tests\\Unit;

use PHPUnit\\Framework\\TestCase;

class {$name}ServiceTest extends TestCase
{
    public function test_basic_unit_test(): void
    {
        \$this->assertTrue(true);
    }
}
PHP
            . PHP_EOL);
        }

        $files->put(
            "{$modulePath}/module.json",
            json_encode([
                'name' => $name,
                'namespace' => "Modules\\{$name}",
                'version' => '1.0.0',
                'description' => "{$name} module",
                'enabled' => true,
                'components' => $components,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );

        $this->info("Module [{$name}] created successfully.");
        $this->line('Components: ' . implode(', ', $components));
        $this->line("Location: Modules/{$name}");

        return self::SUCCESS;
    }

    /**
     * @param array<int, string> $available
     * @return array<int, string>
     */
    private function components(array $available): array
    {
        $requested = $this->option('components');

        if ($requested !== []) {
            $requested = array_map(
                static fn (string $component): string => Str::kebab(trim($component)),
                $requested
            );

            $invalid = array_diff($requested, $available);

            if ($invalid !== []) {
                $this->error('Unknown component(s): ' . implode(', ', $invalid));
                $this->line('Available: ' . implode(', ', $available));
                return [];
            }

            return array_values(array_unique($requested));
        }

        if ($this->option('minimal') || ! $this->input->isInteractive()) {
            return [
                'controllers',
                'requests',
                'models',
                'services',
                'routes',
            ];
        }

        $default = $available;
        $selected = $this->choice(
            'Which components should be generated?',
            $available,
            null,
            null,
            true
        );

        return array_values(array_intersect($default, $selected));
    }
}
