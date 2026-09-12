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
        $this->sharedSupport();

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

    private function sharedSupport(): void
    {
        $root = base_path('Modules/Shared');
        $traitPath = "{$root}/App/Traits/HttpResponses.php";

        if ($this->files->exists($traitPath)) {
            return;
        }

        $this->directory("{$root}/App/Traits");
        $this->file($traitPath, <<<'PHP'
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

        $this->file("{$root}/module.json", json_encode([
            'name' => 'Shared',
            'namespace' => 'Modules\\Shared',
            'provider' => null,
            'version' => '1.0.0',
            'description' => 'Shared module support',
            'enabled' => true,
            'components' => ['traits'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    private function provider(string $root, string $name, array $components): void
    {
        $namespace = "Modules\\{$name}";
        $boot = [];

        if (in_array('routes', $components, true)) {
            $boot[] = "        \$this->loadRoutesFrom(__DIR__ . '/../../Routes/api.php');";
        }

        if (in_array('database', $components, true)) {
            $boot[] = "        \$this->loadMigrationsFrom(__DIR__ . '/../../Database/Migrations');";
        }

        if (in_array('policies', $components, true)) {
            $boot[] = "        Gate::policy(\\{$namespace}\\App\\Models\\{$name}::class, \\{$namespace}\\App\\Policies\\{$name}Policy::class);";
        }

        if (in_array('console', $components, true)) {
            $boot[] = "        \$this->commands([\\{$namespace}\\App\\Console\\{$name}Command::class]);";
        }

        $imports = in_array('policies', $components, true)
            ? "\nuse Illuminate\\Support\\Facades\\Gate;\n"
            : '';

        $this->file("{$root}/App/Providers/{$name}ServiceProvider.php", <<<PHP
<?php

namespace {$namespace}\\App\\Providers;

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
        $namespace = "Modules\\{$name}";
        $parameter = Str::camel($name);
        $route = Str::kebab(Str::pluralStudly($name));
        $variables = [
            '__NS__' => $namespace,
            '__NAME__' => $name,
            '__PARAM__' => $parameter,
            '__ROUTE__' => $route,
        ];

        if (in_array('models', $components, true)) {
            $this->model($root, $name, $variables, in_array('database', $components, true));
        }
        if (in_array('requests', $components, true)) {
            $this->requests($root, $name, $variables);
        }
        if (in_array('services', $components, true)) {
            $this->service($root, $name, $variables);
        }
        if (in_array('resources', $components, true)) {
            $this->resource($root, $name, $variables);
        }
        if (in_array('policies', $components, true)) {
            $this->policy($root, $name, $variables);
        }
        if (in_array('controllers', $components, true)) {
            if ($this->isBasic($components)) {
                $this->basicController($root, $name, $variables);
            } elseif (in_array('services', $components, true)) {
                $this->serviceController($root, $name, $variables, in_array('resources', $components, true));
            } else {
                $this->normalController($root, $name, $variables, in_array('resources', $components, true));
            }
        }
        if (in_array('database', $components, true)) {
            $this->database($root, $name, $variables);
        }
        if (in_array('routes', $components, true)) {
            $this->routes($root, $name, $variables, $this->isBasic($components));
        }
        if (in_array('middleware', $components, true)) {
            $this->middleware($root, $name, $variables);
        }
        if (in_array('console', $components, true)) {
            $this->console($root, $name, $variables);
        }
        if (in_array('feature-tests', $components, true)) {
            $this->featureTest($root, $name, $variables);
        }
        if (in_array('unit-tests', $components, true)) {
            $this->unitTest($root, $name, $variables);
        }
    }

    private function model(string $root, string $name, array $variables, bool $database): void
    {
        $imports = $database ? "use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\n" : '';
        $factory = $database ? "    use HasFactory;\n\n" : '';

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
PHP, $variables + [
            '__IMPORTS__' => $imports,
            '__FACTORY__' => $factory,
        ]);
    }

    private function requests(string $root, string $name, array $variables): void
    {
        $path = "{$root}/App/Http/Requests";

        foreach (['Store', 'Update'] as $type) {
            $this->template("{$path}/{$type}{$name}Request.php", <<<'PHP'
<?php

namespace __NS__\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class __TYPE____NAME__Request extends FormRequest
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
PHP, $variables + ['__TYPE__' => $type]);
        }
    }

    private function service(string $root, string $name, array $variables): void
    {
        $this->template("{$root}/App/Services/{$name}Service.php", <<<'PHP'
<?php

namespace __NS__\App\Services;

use __NS__\App\Models\__NAME__;

final class __NAME__Service
{
    public function paginate()
    {
        return __NAME__::query()->paginate();
    }

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
PHP, $variables);
    }

    private function resource(string $root, string $name, array $variables): void
    {
        $this->template("{$root}/App/Http/Resources/{$name}Resource.php", <<<'PHP'
<?php

namespace __NS__\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class __NAME__Resource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
PHP, $variables);
    }

    private function policy(string $root, string $name, array $variables): void
    {
        $this->template("{$root}/App/Policies/{$name}Policy.php", <<<'PHP'
<?php

namespace __NS__\App\Policies;

use __NS__\App\Models\__NAME__;
use Illuminate\Contracts\Auth\Authenticatable;

final class __NAME__Policy
{
    public function viewAny(Authenticatable $user): bool { return true; }
    public function view(Authenticatable $user, __NAME__ $__PARAM__): bool { return true; }
    public function create(Authenticatable $user): bool { return true; }
    public function update(Authenticatable $user, __NAME__ $__PARAM__): bool { return true; }
    public function delete(Authenticatable $user, __NAME__ $__PARAM__): bool { return true; }
}
PHP, $variables);
    }

    private function basicController(string $root, string $name, array $variables): void
    {
        $this->template("{$root}/App/Http/Controllers/{$name}Controller.php", <<<'PHP'
<?php

namespace __NS__\App\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Shared\App\Traits\HttpResponses;

final class __NAME__Controller
{
    use HttpResponses;

    public function index()
    {
        return $this->success([
            'message' => '__NAME__ module is working.',
        ]);
    }

    public function store(Request $request)
    {
        return $this->success($request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]));
    }
}
PHP, $variables);
    }

    private function normalController(string $root, string $name, array $variables, bool $resource): void
    {
        $resourceImport = $resource ? "use __NS__\\App\\Http\\Resources\\__NAME__Resource;\n" : '';
        $index = $resource ? '__NAME__Resource::collection(__NAME__::query()->paginate())' : '$__PARAM__';
        $show = $resource ? 'new __NAME__Resource($__PARAM__)' : '$__PARAM__';

        $this->template("{$root}/App/Http/Controllers/{$name}Controller.php", <<<'PHP'
<?php

namespace __NS__\App\Http\Controllers;

use __NS__\App\Models\__NAME__;
use Illuminate\Http\Request;
use Modules\Shared\App\Traits\HttpResponses;
__RESOURCE_IMPORT__
final class __NAME__Controller
{
    use HttpResponses;

    public function index()
    {
        return $this->success(['__PARAM__s' => __INDEX__]);
    }

    public function store(Request $request)
    {
        $__PARAM__ = __NAME__::create($request->all());

        return $this->success(['__PARAM__' => $__PARAM__], '__NAME__ created successfully', 201);
    }

    public function show(__NAME__ $__PARAM__)
    {
        return $this->success(['__PARAM__' => __SHOW__]);
    }

    public function update(Request $request, __NAME__ $__PARAM__)
    {
        $__PARAM__->update($request->all());

        return $this->success(['__PARAM__' => $__PARAM__->fresh()], '__NAME__ updated successfully');
    }

    public function destroy(__NAME__ $__PARAM__)
    {
        $__PARAM__->delete();

        return $this->success(null, '__NAME__ deleted successfully');
    }
}
PHP, $variables + [
            '__RESOURCE_IMPORT__' => $resourceImport,
            '__INDEX__' => str_replace('__NAME__', $name, $index),
            '__SHOW__' => str_replace('__NAME__', $name, $show),
        ]);
    }

    private function serviceController(string $root, string $name, array $variables, bool $resource): void
    {
        $resourceImport = $resource ? "use __NS__\\App\\Http\\Resources\\__NAME__Resource;\n" : '';
        $index = $resource ? '__NAME__Resource::collection($this->service->paginate())' : '$this->service->paginate()';
        $show = $resource ? 'new __NAME__Resource($__PARAM__)' : '$__PARAM__';
        $created = $resource ? 'new __NAME__Resource($this->service->create($request->validated()))' : '$this->service->create($request->validated())';
        $updated = $resource ? 'new __NAME__Resource($this->service->update($__PARAM__, $request->validated()))' : '$this->service->update($__PARAM__, $request->validated())';

        $this->template("{$root}/App/Http/Controllers/{$name}Controller.php", <<<'PHP'
<?php

namespace __NS__\App\Http\Controllers;

use __NS__\App\Http\Requests\Store__NAME__Request;
use __NS__\App\Http\Requests\Update__NAME__Request;
use __NS__\App\Models\__NAME__;
use __NS__\App\Services\__NAME__Service;
use Modules\Shared\App\Traits\HttpResponses;
__RESOURCE_IMPORT__
final class __NAME__Controller
{
    use HttpResponses;

    public function __construct(
        private readonly __NAME__Service $service,
    ) {
    }

    public function index()
    {
        $__PARAM__s = $this->service->paginate();

        return $this->success([
            '__PARAM__s' => $__PARAM__s,
        ]);
    }

    public function store(Store__NAME__Request $request)
    {
        $__PARAM__ = __CREATED__;

        return $this->success(
            ['__PARAM__' => $__PARAM__],
            '__NAME__ created successfully',
            201
        );
    }

    public function show(__NAME__ $__PARAM__)
    {
        return $this->success([
            '__PARAM__' => __SHOW__,
        ]);
    }

    public function update(Update__NAME__Request $request, __NAME__ $__PARAM__)
    {
        $__PARAM__ = __UPDATED__;

        return $this->success(
            ['__PARAM__' => $__PARAM__],
            '__NAME__ updated successfully'
        );
    }

    public function destroy(__NAME__ $__PARAM__)
    {
        $this->service->delete($__PARAM__);

        return $this->success(
            null,
            '__NAME__ deleted successfully'
        );
    }
}
PHP, $variables + [
            '__RESOURCE_IMPORT__' => $resourceImport,
            '__INDEX__' => str_replace('__NAME__', $name, $index),
            '__SHOW__' => str_replace('__NAME__', $name, $show),
            '__CREATED__' => str_replace('__NAME__', $name, $created),
            '__UPDATED__' => str_replace('__NAME__', $name, $updated),
        ]);
    }

    private function routes(string $root, string $name, array $variables, bool $basic): void
    {
        $this->template("{$root}/Routes/api.php", <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;
use __NS__\App\Http\Controllers\__NAME__Controller;

__ROUTES__
PHP, $variables + [
            '__ROUTES__' => $basic
                ? "Route::get('__ROUTE__', [__NAME__Controller::class, 'index']);\nRoute::post('__ROUTE__', [__NAME__Controller::class, 'store']);"
                : "Route::apiResource('__ROUTE__', __NAME__Controller::class);",
        ]);
    }

    private function database(string $root, string $name, array $variables): void
    {
        $this->template("{$root}/Database/Migrations/" . date('Y_m_d_His') . "_create_" . $variables['__ROUTE__'] . "_table.php", <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('__ROUTE__', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('__ROUTE__');
    }
};
PHP, $variables);

        $this->template("{$root}/Database/Factories/{$name}Factory.php", <<<'PHP'
<?php

namespace __NS__\Database\Factories;

use __NS__\App\Models\__NAME__;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<__NAME__>
 */
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
PHP, $variables);
    }

    private function middleware(string $root, string $name, array $variables): void
    {
        $this->template("{$root}/App/Http/Middleware/{$name}Middleware.php", <<<'PHP'
<?php

namespace __NS__\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class __NAME__Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
PHP, $variables);
    }

    private function console(string $root, string $name, array $variables): void
    {
        $this->template("{$root}/App/Console/{$name}Command.php", <<<'PHP'
<?php

namespace __NS__\App\Console;

use Illuminate\Console\Command;

final class __NAME__Command extends Command
{
    protected $signature = '__ROUTE__:run';
    protected $description = 'Run the __NAME__ module command.';

    public function handle(): int
    {
        $this->info('__NAME__ command executed.');

        return self::SUCCESS;
    }
}
PHP, $variables);
    }

    private function featureTest(string $root, string $name, array $variables): void
    {
        $this->template("{$root}/Tests/Feature/{$name}Test.php", <<<'PHP'
<?php

namespace __NS__\Tests\Feature;

use Tests\TestCase;

class __NAME__Test extends TestCase
{
    public function test_module_smoke_test(): void
    {
        $this->assertTrue(true);
    }
}
PHP, $variables);
    }

    private function unitTest(string $root, string $name, array $variables): void
    {
        $this->template("{$root}/Tests/Unit/{$name}Test.php", <<<'PHP'
<?php

namespace __NS__\Tests\Unit;

use Tests\TestCase;

class __NAME__Test extends TestCase
{
    public function test_module_unit_test(): void
    {
        $this->assertTrue(true);
    }
}
PHP, $variables);
    }

    private function manifest(string $root, string $name, array $components): void
    {
        $this->file("{$root}/module.json", json_encode([
            'name' => $name,
            'namespace' => "Modules\\{$name}",
            'provider' => "Modules\\{$name}\\App\\Providers\\{$name}ServiceProvider",
            'version' => '1.0.0',
            'description' => "{$name} module",
            'enabled' => true,
            'components' => array_values($components),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    private function template(string $path, string $template, array $variables): void
    {
        $this->file($path, str_replace(array_keys($variables), array_values($variables), $template));
    }

    private function file(string $path, string $content): void
    {
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $content);
    }

    private function directory(string $path): void
    {
        $this->files->ensureDirectoryExists($path);
    }

    private function lines(array $lines): string
    {
        return $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL;
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
