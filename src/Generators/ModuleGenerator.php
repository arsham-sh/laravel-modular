<?php

namespace Arsham\LaravelModular\Generators;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

final class ModuleGenerator
{
    public function __construct(private readonly Filesystem $files)
    {
    }

    public function generate(string $name, array $components): void
    {
        $name = Str::studly(trim($name));

        if ($name === '') {
            throw new RuntimeException('Module name cannot be empty.');
        }

        $components = ModuleComponents::validate($components);

        if ($components === null) {
            throw new RuntimeException('Invalid module component selection.');
        }

        $root = base_path("Modules/{$name}");

        if ($this->files->exists($root)) {
            throw new RuntimeException("Module [{$name}] already exists.");
        }

        try {
            $this->directory($root);
            $this->write("{$root}/Config/config.php", "<?php\n\nreturn [\n    'enabled' => true,\n];\n");
            $this->sharedSupport();
            $this->provider($root, $name, $components);
            $this->generateComponents($root, $name, $components);
            $this->write(
                "{$root}/module.json",
                json_encode([
                    'name' => $name,
                    'namespace' => "Modules\\{$name}",
                    'provider' => "Modules\\{$name}\\App\\Providers\\{$name}ServiceProvider",
                    'version' => '1.0.0',
                    'description' => "{$name} module",
                    'enabled' => true,
                    'components' => array_values($components),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
            );
        } catch (\Throwable $e) {
            if ($this->files->isDirectory($root)) {
                $this->files->deleteDirectory($root);
            }

            throw $e;
        }
    }

    private function generateComponents(string $root, string $name, array $components): void
    {
        $variables = [
            'name' => $name,
            'namespace' => "Modules\\{$name}",
            'parameter' => Str::camel($name),
            'route' => Str::kebab(Str::pluralStudly($name)),
        ];

        foreach ($components as $component) {
            match ($component) {
                'models' => $this->model($root, $variables, in_array('database', $components, true)),
                'requests' => $this->requests($root, $variables),
                'services' => $this->service($root, $variables),
                'resources' => $this->resource($root, $variables),
                'policies' => $this->policy($root, $variables),
                'controllers' => $this->controller($root, $variables, $components),
                'database' => $this->database($root, $variables),
                'routes' => $this->routes($root, $variables, $components),
                'middleware' => $this->middleware($root, $variables),
                'console' => $this->console($root, $variables),
                'feature-tests' => $this->test($root, $variables, 'Feature'),
                'unit-tests' => $this->test($root, $variables, 'Unit'),
                'traits' => $this->trait($root, $variables),
                default => null,
            };
        }
    }

    private function sharedSupport(): void
    {
        $path = base_path('Modules/Shared/App/Traits/HttpResponses.php');

        if ($this->files->exists($path)) {
            return;
        }

        $this->write($path, <<<'PHP'
<?php

namespace Modules\Shared\App\Traits;

use Illuminate\Http\JsonResponse;

trait HttpResponses
{
    protected function success(
        mixed $data = null,
        ?string $message = null,
        int $code = 200
    ): JsonResponse {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    protected function error(
        mixed $data = null,
        ?string $message = null,
        int $code = 500
    ): JsonResponse {
        return response()->json([
            'status' => 'error',
            'message' => $message,
            'data' => $data,
        ], $code);
    }
}
PHP);
    }

    private function provider(string $root, string $name, array $components): void
    {
        $namespace = "Modules\\{$name}";
        $imports = [];
        $boot = [];

        if (in_array('policies', $components, true)) {
            $imports[] = 'use Illuminate\\Support\\Facades\\Gate;';
            $boot[] = "        Gate::policy(\\{$namespace}\\App\\Models\\{$name}::class, \\{$namespace}\\App\\Policies\\{$name}Policy::class);";
        }

        if (in_array('routes', $components, true)) {
            $boot[] = "        \$this->loadRoutesFrom(__DIR__ . '/../../Routes/api.php');";
        }

        if (in_array('database', $components, true)) {
            $boot[] = "        \$this->loadMigrationsFrom(__DIR__ . '/../../Database/Migrations');";
        }

        if (in_array('console', $components, true)) {
            $boot[] = "        \$this->commands([\\{$namespace}\\App\\Console\\{$name}Command::class]);";
        }

        $importsText = $imports === [] ? '' : implode(PHP_EOL, $imports) . PHP_EOL;
        $bootText = $boot === [] ? '' : implode(PHP_EOL, $boot) . PHP_EOL;

        $this->write("{$root}/App/Providers/{$name}ServiceProvider.php", <<<PHP
<?php

namespace {$namespace}\App\Providers;

use Illuminate\Support\ServiceProvider;
{$importsText}
final class {$name}ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        \$this->mergeConfigFrom(
            __DIR__ . '/../../Config/config.php',
            '{$this->configKey($name)}'
        );
    }

    public function boot(): void
    {
{$bootText}    }
}
PHP);
    }

    private function model(string $root, array $v, bool $database): void
    {
        $imports = $database
            ? "use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\n"
            : '';
        $factory = $database ? "\n    use HasFactory;\n" : '';

        $this->write("{$root}/App/Models/{$v['name']}.php", <<<PHP
<?php

namespace {$v['namespace']}\App\Models;

use Illuminate\Database\Eloquent\Model;
{$imports}
class {$v['name']} extends Model
{
{$factory}
    protected \$fillable = [
        'name',
    ];
}
PHP);
    }

    private function requests(string $root, array $v): void
    {
        foreach (['Store', 'Update'] as $type) {
            $this->write("{$root}/App/Http/Requests/{$type}{$v['name']}Request.php", <<<PHP
<?php

namespace {$v['namespace']}\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class {$type}{$v['name']}Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
PHP);
        }
    }

    private function service(string $root, array $v): void
    {
        $this->write("{$root}/App/Services/{$v['name']}Service.php", <<<PHP
<?php

namespace {$v['namespace']}\App\Services;

use {$v['namespace']}\App\Models\{$v['name']};

final class {$v['name']}Service
{
    public function paginate()
    {
        return {$v['name']}::query()->paginate();
    }

    public function create(array \$data): {$v['name']}
    {
        return {$v['name']}::create(\$data);
    }

    public function update({$v['name']} \${$v['parameter']}, array \$data): {$v['name']}
    {
        \${$v['parameter']}->update(\$data);

        return \${$v['parameter']}->refresh();
    }

    public function delete({$v['name']} \${$v['parameter']}): void
    {
        \${$v['parameter']}->delete();
    }
}
PHP);
    }

    private function resource(string $root, array $v): void
    {
        $this->write("{$root}/App/Http/Resources/{$v['name']}Resource.php", <<<PHP
<?php

namespace {$v['namespace']}\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class {$v['name']}Resource extends JsonResource
{
    public function toArray(Request \$request): array
    {
        return [
            'id' => \$this->id,
            'name' => \$this->name,
            'created_at' => \$this->created_at,
            'updated_at' => \$this->updated_at,
        ];
    }
}
PHP);
    }

    private function policy(string $root, array $v): void
    {
        $this->write("{$root}/App/Policies/{$v['name']}Policy.php", <<<PHP
<?php

namespace {$v['namespace']}\App\Policies;

use {$v['namespace']}\App\Models\{$v['name']};
use Illuminate\Contracts\Auth\Authenticatable;

final class {$v['name']}Policy
{
    public function viewAny(Authenticatable \$user): bool
    {
        return true;
    }

    public function view(Authenticatable \$user, {$v['name']} \${$v['parameter']}): bool
    {
        return true;
    }

    public function create(Authenticatable \$user): bool
    {
        return true;
    }

    public function update(Authenticatable \$user, {$v['name']} \${$v['parameter']}): bool
    {
        return true;
    }

    public function delete(Authenticatable \$user, {$v['name']} \${$v['parameter']}): bool
    {
        return true;
    }
}
PHP);
    }

    private function controller(string $root, array $v, array $components): void
    {
        $service = in_array('services', $components, true);
        $requests = in_array('requests', $components, true);
        $resource = in_array('resources', $components, true);

        if ($service) {
            $imports = [
                "use {$v['namespace']}\\App\\Models\\{$v['name']};",
                "use {$v['namespace']}\\App\\Services\\{$v['name']}Service;",
                'use Modules\\Shared\\App\\Traits\\HttpResponses;',
            ];

            if ($requests) {
                $imports[] = "use {$v['namespace']}\\App\\Http\\Requests\\Store{$v['name']}Request;";
                $imports[] = "use {$v['namespace']}\\App\\Http\\Requests\\Update{$v['name']}Request;";
            } else {
                $imports[] = 'use Illuminate\\Http\\Request;';
            }

            if ($resource) {
                $imports[] = "use {$v['namespace']}\\App\\Http\\Resources\\{$v['name']}Resource;";
            }

            $imports = implode(PHP_EOL, $imports);
            $requestStore = $requests ? "Store{$v['name']}Request" : 'Request';
            $requestUpdate = $requests ? "Update{$v['name']}Request" : 'Request';
            $input = $requests ? '$request->validated()' : '$request->all()';
            $index = $resource
                ? "{$v['name']}Resource::collection(\$this->service->paginate())"
                : '$this->service->paginate()';
            $created = $resource
                ? "new {$v['name']}Resource(\$this->service->create({$input}))"
                : "\$this->service->create({$input})";
            $updated = $resource
                ? "new {$v['name']}Resource(\$this->service->update(\${$v['parameter']}, {$input}))"
                : "\$this->service->update(\${$v['parameter']}, {$input})";
            $shown = $resource
                ? "new {$v['name']}Resource(\${$v['parameter']})"
                : "\${$v['parameter']}";

            $content = <<<PHP
<?php

namespace {$v['namespace']}\App\Http\Controllers;

{$imports}

final class {$v['name']}Controller
{
    use HttpResponses;

    public function __construct(
        private readonly {$v['name']}Service \$service,
    ) {
    }

    public function index()
    {
        \${$v['parameter']}s = {$index};

        return \$this->success([
            '{$v['parameter']}s' => \${$v['parameter']}s,
        ]);
    }

    public function store({$requestStore} \$request)
    {
        \${$v['parameter']} = {$created};

        return \$this->success(
            ['{$v['parameter']}' => \${$v['parameter']}],
            '{$v['name']} created successfully',
            201
        );
    }

    public function show({$v['name']} \${$v['parameter']})
    {
        return \$this->success([
            '{$v['parameter']}' => {$shown},
        ]);
    }

    public function update({$requestUpdate} \$request, {$v['name']} \${$v['parameter']})
    {
        \${$v['parameter']} = {$updated};

        return \$this->success(
            ['{$v['parameter']}' => \${$v['parameter']}],
            '{$v['name']} updated successfully'
        );
    }

    public function destroy({$v['name']} \${$v['parameter']})
    {
        \$this->service->delete(\${$v['parameter']});

        return \$this->success(
            null,
            '{$v['name']} deleted successfully'
        );
    }
}
PHP;
        } else {
            $imports = implode(PHP_EOL, [
                "use {$v['namespace']}\\App\\Models\\{$v['name']};",
                'use Illuminate\\Http\\Request;',
                'use Modules\\Shared\\App\\Traits\\HttpResponses;',
            ]);

            if ($resource) {
                $imports .= PHP_EOL . "use {$v['namespace']}\\App\\Http\\Resources\\{$v['name']}Resource;";
            }

            $index = $resource
                ? "{$v['name']}Resource::collection({$v['name']}::query()->paginate())"
                : "{$v['name']}::query()->paginate()";
            $shown = "\${$v['parameter']}";

            $content = <<<PHP
<?php

namespace {$v['namespace']}\App\Http\Controllers;

{$imports}

final class {$v['name']}Controller
{
    use HttpResponses;

    public function index()
    {
        \${$v['parameter']}s = {$index};

        return \$this->success([
            '{$v['parameter']}s' => \${$v['parameter']}s,
        ]);
    }

    public function store(Request \$request)
    {
        \${$v['parameter']} = {$v['name']}::create(\$request->all());

        return \$this->success(
            ['{$v['parameter']}' => \${$v['parameter']}],
            '{$v['name']} created successfully',
            201
        );
    }

    public function show({$v['name']} \${$v['parameter']})
    {
        return \$this->success([
            '{$v['parameter']}' => {$shown},
        ]);
    }

    public function update(Request \$request, {$v['name']} \${$v['parameter']})
    {
        \${$v['parameter']}->update(\$request->all());

        return \$this->success([
            '{$v['parameter']}' => \${$v['parameter']}->refresh(),
        ], '{$v['name']} updated successfully');
    }

    public function destroy({$v['name']} \${$v['parameter']})
    {
        \${$v['parameter']}->delete();

        return \$this->success(
            null,
            '{$v['name']} deleted successfully'
        );
    }
}
PHP;
        }

        $this->write("{$root}/App/Http/Controllers/{$v['name']}Controller.php", $content);
    }

    private function routes(string $root, array $v, array $components): void
    {
        $controller = "{$v['name']}Controller";
        $body = $this->isBasic($components)
            ? "Route::get('{$v['route']}', [{$controller}::class, 'index']);\nRoute::post('{$v['route']}', [{$controller}::class, 'store']);"
            : "Route::apiResource('{$v['route']}', {$controller}::class);";

        $this->write("{$root}/Routes/api.php", <<<PHP
<?php

use Illuminate\Support\Facades\Route;
use {$v['namespace']}\App\Http\Controllers\{$controller};

{$body}
PHP);
    }

    private function database(string $root, array $v): void
    {
        $migration = date('Y_m_d_His') . "_create_{$v['route']}_table.php";

        $this->write("{$root}/Database/Migrations/{$migration}", <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$v['route']}', function (Blueprint \$table): void {
            \$table->id();
            \$table->string('name');
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$v['route']}');
    }
};
PHP);

        $this->write("{$root}/Database/Factories/{$v['name']}Factory.php", <<<PHP
<?php

namespace {$v['namespace']}\Database\Factories;

use {$v['namespace']}\App\Models\{$v['name']};
use Illuminate\Database\Eloquent\Factories\Factory;

class {$v['name']}Factory extends Factory
{
    protected \$model = {$v['name']}::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
        ];
    }
}
PHP);
    }

    private function middleware(string $root, array $v): void
    {
        $this->write("{$root}/App/Http/Middleware/{$v['name']}Middleware.php", <<<PHP
<?php

namespace {$v['namespace']}\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class {$v['name']}Middleware
{
    public function handle(Request \$request, Closure \$next): Response
    {
        return \$next(\$request);
    }
}
PHP);
    }

    private function console(string $root, array $v): void
    {
        $this->write("{$root}/App/Console/{$v['name']}Command.php", <<<PHP
<?php

namespace {$v['namespace']}\App\Console;

use Illuminate\Console\Command;

final class {$v['name']}Command extends Command
{
    protected \$signature = '{$v['route']}:run';
    protected \$description = 'Run the {$v['name']} module command.';

    public function handle(): int
    {
        \$this->info('{$v['name']} command executed.');

        return self::SUCCESS;
    }
}
PHP);
    }

    private function test(string $root, array $v, string $type): void
    {
        $this->write("{$root}/Tests/{$type}/{$v['name']}Test.php", <<<PHP
<?php

namespace {$v['namespace']}\Tests\{$type};

use Tests\TestCase;

class {$v['name']}Test extends TestCase
{
    public function test_module_{$type}_test(): void
    {
        \$this->assertTrue(true);
    }
}
PHP);
    }

    private function trait(string $root, array $v): void
    {
        $this->write("{$root}/App/Traits/{$v['name']}Trait.php", <<<PHP
<?php

namespace {$v['namespace']}\App\Traits;

trait {$v['name']}Trait
{
}
PHP);
    }

    private function write(string $path, string $content): void
    {
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $content);
    }

    private function directory(string $path): void
    {
        $this->files->ensureDirectoryExists($path);
    }

    private function configKey(string $name): string
    {
        return Str::snake(Str::pluralStudly($name));
    }

    private function isBasic(array $components): bool
    {
        return $components === ModulePreset::Basic->components();
    }
}
