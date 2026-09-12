<?php

namespace Arsham\LaravelModular\Generators;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

final class ModuleGenerator
{
    public function __construct(private readonly Filesystem $files) {}

    public function generate(string $name, array $components): void
    {
        $name = Str::studly($name);
        $root = base_path("Modules/{$name}");

        if ($this->files->exists($root)) {
            throw new RuntimeException("Module [{$name}] already exists.");
        }

        $components = ModuleComponents::normalize($components);

        try {
            $this->directory($root);
            $this->directory("{$root}/Config");
            $this->directory("{$root}/App/Providers");
            foreach ($components as $component) {
                $this->directory("{$root}/" . ModuleComponents::PATHS[$component]);
            }

            $this->file("{$root}/Config/config.php", "<?php\n\nreturn [\n    'enabled' => true,\n];\n");
            $this->provider($root, $name, $components);
            $this->components($root, $name, $components);
            $this->manifest($root, $name, $components);
        } catch (\Throwable $e) {
            if ($this->files->isDirectory($root)) {
                $this->files->deleteDirectory($root);
            }
            throw $e;
        }
    }

    private function provider(string $root, string $name, array $components): void
    {
        $ns = "Modules\\{$name}";
        $boot = [];
        if (in_array('routes', $components, true)) {
            $boot[] = "        \$this->loadRoutesFrom(__DIR__ . '/../../Routes/api.php');";
        }
        if (in_array('database', $components, true)) {
            $boot[] = "        \$this->loadMigrationsFrom(__DIR__ . '/../../Database/Migrations');";
        }
        if (in_array('policies', $components, true)) {
            $boot[] = "        Gate::policy(\\{$ns}\\App\\Models\\{$name}::class, \\{$ns}\\App\\Policies\\{$name}Policy::class);";
        }
        if (in_array('console', $components, true)) {
            $boot[] = "        \$this->commands([\\{$ns}\\App\\Console\\{$name}Command::class]);";
        }

        $imports = in_array('policies', $components, true)
            ? "\nuse Illuminate\\Support\\Facades\\Gate;\n"
            : '';

        $this->file("{$root}/App/Providers/{$name}ServiceProvider.php", <<<PHP
<?php

namespace {$ns}\\App\\Providers;

use Illuminate\\Support\\ServiceProvider;{$imports}
class {$name}ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        \$this->mergeConfigFrom(__DIR__ . '/../../Config/config.php', '{$this->configKey($name)}');
    }

    public function boot(): void
    {
{$this->lines($boot)}    }
}
PHP);
    }

    private function components(string $root, string $name, array $components): void
    {
        $ns = "Modules\\{$name}";
        $param = Str::camel($name);
        $route = Str::kebab(Str::pluralStudly($name));
        $v = ['__NS__' => $ns, '__NAME__' => $name, '__PARAM__' => $param, '__ROUTE__' => $route];

        if (in_array('models', $components, true)) {
            $factory = in_array('database', $components, true);
            $this->template("{$root}/App/Models/{$name}.php", <<<'PHP'
<?php

namespace __NS__\App\Models;

use Illuminate\Database\Eloquent\Model;
__IMPORTS__
class __NAME__ extends Model
{
__FACTORY__    protected $fillable = [
        'name',
    ];
}
PHP, $v + [
                '__IMPORTS__' => $factory ? "use Illuminate\\Database\\Eloquent\\Factories\\Factory;\nuse Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\n" : '',
                '__FACTORY__' => $factory ? "    use HasFactory;\n\n    protected static function newFactory(): Factory\n    {\n        return \\{$ns}\\Database\\Factories\\{$name}Factory::new();\n    }\n\n" : '',
            ]);
        }

        if (in_array('requests', $components, true)) {
            $this->template("{$root}/App/Http/Requests/{$name}Request.php", <<<'PHP'
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
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
PHP, $v);
        }

        if (in_array('services', $components, true)) {
            $this->template("{$root}/App/Services/{$name}Service.php", <<<'PHP'
<?php

namespace __NS__\App\Services;

use __NS__\App\Models\__NAME__;

class __NAME__Service
{
    public function create(array $data): __NAME__
    {
        return __NAME__::create($data);
    }

    public function update(__NAME__ $__PARAM__, array $data): __NAME__
    {
        $__PARAM__->update($data);

        return $__PARAM__->refresh();
    }

    public function delete(__NAME__ $__PARAM__): void
    {
        $__PARAM__->delete();
    }
}
PHP, $v);
        }

        if (in_array('resources', $components, true)) {
            $this->template("{$root}/App/Http/Resources/{$name}Resource.php", <<<'PHP'
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
PHP, $v);
        }

        if (in_array('policies', $components, true)) {
            $this->template("{$root}/App/Policies/{$name}Policy.php", <<<'PHP'
<?php

namespace __NS__\App\Policies;

use __NS__\App\Models\__NAME__;
use Illuminate\Contracts\Auth\Authenticatable;

class __NAME__Policy
{
    public function viewAny(Authenticatable $user): bool { return true; }
    public function view(Authenticatable $user, __NAME__ $__PARAM__): bool { return true; }
    public function create(Authenticatable $user): bool { return true; }
    public function update(Authenticatable $user, __NAME__ $__PARAM__): bool { return true; }
    public function delete(Authenticatable $user, __NAME__ $__PARAM__): bool { return true; }
}
PHP, $v);
        }

        if (in_array('controllers', $components, true)) {
            if ($this->isBasic($components)) {
                $this->basicController($root, $name, $ns, $route);
            } else {
                $this->controller($root, $name, $ns, $param, in_array('resources', $components, true), in_array('services', $components, true));
            }
        }

        if (in_array('database', $components, true)) {
            $this->database($root, $name, $ns, $v);
        }

        if (in_array('routes', $components, true)) {
            $controller = in_array('controllers', $components, true)
                ? "use {$ns}\\App\\Http\\Controllers\\{$name}Controller;\n\nRoute::apiResource('{$route}', {$name}Controller::class);\n"
                : "// Define module routes here.\n";
            $this->file("{$root}/Routes/api.php", "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n{$controller}");
        }

        if (in_array('middleware', $components, true)) {
            $this->template("{$root}/App/Http/Middleware/{$name}Middleware.php", <<<'PHP'
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
PHP, $v);
        }

        if (in_array('console', $components, true)) {
            $v['__SIGNATURE__'] = Str::kebab($name) . ':run';
            $this->template("{$root}/App/Console/{$name}Command.php", <<<'PHP'
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
PHP, $v);
        }

        if (in_array('feature-tests', $components, true)) {
            $this->template("{$root}/Tests/Feature/{$name}Test.php", <<<'PHP'
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
PHP, $v);
        }

        if (in_array('unit-tests', $components, true)) {
            $this->template("{$root}/Tests/Unit/{$name}ServiceTest.php", <<<'PHP'
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
PHP, $v);
        }
    }

    private function isBasic(array $components): bool
    {
        return $components === ['controllers', 'routes'];
    }

    private function basicController(string $root, string $name, string $ns, string $route): void
    {
        $this->template("{$root}/App/Http/Controllers/{$name}Controller.php", <<<'PHP'
<?php

namespace __NS__\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class __NAME__Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'message' => '__NAME__ module is working.',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        // Keep small module-specific logic here until it becomes worth extracting.
        return response()->json($validated, 201);
    }
}
PHP, ['__NS__' => $ns, '__NAME__' => $name, '__ROUTE__' => $route]);
    }

    private function controller(string $root, string $name, string $ns, string $param, bool $resource, bool $service): void
    {
        $resourceUse = $resource ? "use {$ns}\\App\\Http\\Resources\\{$name}Resource;\n" : '';
        $serviceUse = $service ? "use {$ns}\\App\\Services\\{$name}Service;\n" : '';
        $index = $resource ? "{$name}Resource::collection({$name}::query()->paginate())" : "{$name}::query()->paginate()";
        $show = $resource ? "new {$name}Resource(\${$param})" : "\${$param}";
        $store = $service
            ? "        \$model = \$this->service->create(\$request->validated());\n        return \$this->success(\$model, 'Created successfully.', 201);"
            : "        \$model = {$name}::create(\$request->validated());\n        return \$this->success(\$model, 'Created successfully.', 201);";
        $update = $service
            ? "        return \$this->success(\$this->service->update(\${$param}, \$request->validated()), 'Updated successfully.');"
            : "        \${$param}->update(\$request->validated());\n        return \$this->success({$show}, 'Updated successfully.');";
        $delete = $service
            ? "        \$this->service->delete(\${$param});"
            : "        \${$param}->delete();";
        $constructor = $service
            ? "    public function __construct(\n        private readonly {$name}Service \$service,\n    ) {\n    }\n\n"
            : '';

        $this->template("{$root}/App/Http/Controllers/{$name}Controller.php", <<<'PHP'
<?php

namespace __NS__\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use __NS__\App\Http\Requests\__NAME__Request;
__RESOURCE____SERVICE__use __NS__\App\Models\__NAME__;
use __NS__\App\Traits\HttpResponses;

class __NAME__Controller
{
    use HttpResponses;

__CONSTRUCTOR__    public function index(): JsonResponse
    {
        return $this->success(__INDEX__);
    }

    public function store(__NAME__Request $request): JsonResponse
    {
__STORE__
    }

    public function show(__NAME__ $__PARAM__): JsonResponse
    {
        return $this->success(__SHOW__);
    }

    public function update(__NAME__Request $request, __NAME__ $__PARAM__): JsonResponse
    {
__UPDATE__
    }

    public function destroy(__NAME__ $__PARAM__): JsonResponse
    {
__DELETE__
        return $this->success(null, 'Deleted successfully.', 204);
    }
}
PHP, [
            '__NS__' => $ns, '__NAME__' => $name, '__PARAM__' => $param,
            '__RESOURCE__' => $resourceUse, '__SERVICE__' => $serviceUse,
            '__INDEX__' => $index, '__SHOW__' => $show, '__STORE__' => $store,
            '__UPDATE__' => $update, '__DELETE__' => $delete, '__CONSTRUCTOR__' => $constructor,
        ]);

        if (!$service) {
            $this->file("{$root}/App/Traits/HttpResponses.php", $this->httpResponses($ns));
        } else {
            $this->file("{$root}/App/Traits/HttpResponses.php", $this->httpResponses($ns));
        }
    }

    private function httpResponses(string $ns): string
    {
        return strtr(<<<'PHP'
<?php

namespace __NS__\App\Traits;

use Illuminate\Http\JsonResponse;

trait HttpResponses
{
    protected function success(mixed $data = null, ?string $message = null, int $code = 200): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data], $code);
    }

    protected function error(mixed $data = null, ?string $message = null, int $code = 500): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message, 'data' => $data], $code);
    }
}
PHP, ['__NS__' => $ns]);
    }

    private function database(string $root, string $name, string $ns, array $v): void
    {
        $table = Str::snake(Str::pluralStudly($name));
        $stamp = date('Y_m_d_His');
        $this->template("{$root}/Database/Migrations/{$stamp}_create_{$table}_table.php", <<<'PHP'
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
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('__TABLE__');
    }
};
PHP, ['__TABLE__' => $table]);
        $this->template("{$root}/Database/Factories/{$name}Factory.php", <<<'PHP'
<?php

namespace __NS__\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use __NS__\App\Models\__NAME__;

class __NAME__Factory extends Factory
{
    protected $model = __NAME__::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
        ];
    }
}
PHP, $v);
        $this->template("{$root}/Database/Seeders/{$name}Seeder.php", <<<'PHP'
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
PHP, $v);
    }

    private function manifest(string $root, string $name, array $components): void
    {
        $data = [
            'name' => $name,
            'namespace' => "Modules\\{$name}",
            'provider' => "Modules\\{$name}\\App\\Providers\\{$name}ServiceProvider",
            'version' => '1.0.0',
            'description' => "{$name} module",
            'enabled' => true,
            'components' => $components,
        ];
        $this->file("{$root}/module.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    private function configKey(string $name): string { return Str::kebab($name); }

    private function lines(array $lines): string
    {
        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    private function template(string $path, string $content, array $variables): void
    {
        $this->file($path, strtr($content, $variables));
    }

    private function directory(string $path): void
    {
        if ($this->files->isDirectory($path)) return;
        if ($this->files->exists($path)) {
            throw new RuntimeException("Cannot create directory [{$path}] because a file already exists at that path.");
        }
        $this->files->makeDirectory($path, 0755, true);
    }

    private function file(string $path, string $contents): void
    {
        $this->directory(dirname($path));
        $this->files->put($path, $contents);
    }
}
