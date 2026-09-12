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
            $this->request($root, $name, $variables);
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
            } else {
                $this->controller($root, $name, $variables, in_array('resources', $components, true));
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
        $imports = $database
            ? "use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\n"
            : '';
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

    private function request(string $root, string $name, array $variables): void
    {
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
PHP, $variables);
    }

    private function service(string $root, string $name, array $variables): void
    {
        $this->template("{$root}/App/Services/{$name}Service.php", <<<'PHP'
<?php

namespace __NS__\App\Services;

use __NS__\App\Models\__NAME__;

final class __NAME__Service
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
    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

    public function view(Authenticatable $user, __NAME__ $__PARAM__): bool
    {
        return true;
    }

    public function create(Authenticatable $user): bool
    {
        return true;
    }

    public function update(Authenticatable $user, __NAME__ $__PARAM__): bool
    {
        return true;
    }

    public function delete(Authenticatable $user, __NAME__ $__PARAM__): bool
    {
        return true;
    }
}
PHP, $variables);
    }

    private function basicController(string $root, string $name, array $variables): void
    {
        $this->template("{$root}/App/Http/Controllers/{$name}Controller.php", <<<'PHP'
<?php

namespace __NS__\App\Http\Controllers;

use Illuminate\Http\Request;

final class __NAME__Controller
{
    public function index(): array
    {
        return [
            'message' => '__NAME__ module is working.',
        ];
    }

    public function store(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);
    }
}
PHP, $variables);
    }

    private function controller(string $root, string $name, array $variables, bool $resource): void
    {
        $resourceImport = $resource
            ? "use __NS__\\App\\Http\\Resources\\__NAME__Resource;\n"
            : '';
        $index = $resource
            ? '__NAME__Resource::collection(__NAME__::query()->paginate())'
            : '__NAME__::query()->paginate()';
        $show = $resource ? 'new __NAME__Resource($__PARAM__)' : '$__PARAM__';

        $this->template("{$root}/App/Http/Controllers/{$name}Controller.php", <<<'PHP'
<?php

namespace __NS__\App\Http\Controllers;

use __NS__\App\Http\Requests\__NAME__Request;
use __NS__\App\Models\__NAME__;
use __NS__\App\Services\__NAME__Service;
__RESOURCE_IMPORT__
final class __NAME__Controller
{
    public function __construct(
        private readonly __NAME__Service $service,
    ) {
    }

    public function index()
    {
        return __INDEX__;
    }

    public function store(__NAME__Request $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(__NAME__ $__PARAM__)
    {
        return __SHOW__;
    }

    public function update(__NAME__Request $request, __NAME__ $__PARAM__)
    {
        return $this->service->update($__PARAM__, $request->validated());
    }

    public function destroy(__NAME__ $__PARAM__)
    {
        $this->service->delete($__PARAM__);

        return response()->noContent();
    }
}
PHP, $variables + [
            '__RESOURCE_IMPORT__' => $resourceImport,
            '__INDEX__' => str_replace('__NAME__', $name, $index),
            '__SHOW__' => str_replace('__NAME__', $name, $show),
        ]);
    }

    private function routes(string $root, string $name, array $variables, bool $basic): void
    {
        $controller = "use __NS__\\App\\Http\\Controllers\\__NAME__Controller;\n";
        $routes = $basic
            ? "Route::get('__ROUTE__', [__NAME__Controller::class, 'index']);\nRoute::post('__ROUTE__', [__NAME__Controller::class, 'store']);"
            : "Route::apiResource('__ROUTE__', __NAME__Controller::class);";

        $this->template("{$root}/Routes/api.php", <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;
__CONTROLLER_IMPORT__
__ROUTES__
PHP, $variables + [
            '__CONTROLLER_IMPORT__' => $controller,
            '__ROUTES__' => $routes,
        ]);
    }

    private function database(string $root, string $name, array $variables): void
    {
        $table = Str::snake(Str::pluralStudly($name));
        $timestamp = date('Y_m_d_His');
        $migrationVariables = ['__TABLE__' => $table];

        $this->template("{$root}/Database/Migrations/{$timestamp}_create_{$table}_table.php", <<<'PHP'
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
PHP, $migrationVariables);

        $this->template("{$root}/Database/Factories/{$name}Factory.php", <<<'PHP'
<?php

namespace __NS__\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use __NS__\App\Models\__NAME__;

final class __NAME__Factory extends Factory
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

        $this->template("{$root}/Database/Seeders/{$name}Seeder.php", <<<'PHP'
<?php

namespace __NS__\Database\Seeders;

use Illuminate\Database\Seeder;

final class __NAME__Seeder extends Seeder
{
    public function run(): void
    {
        // Add module-specific seed data here.
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
        $variables['__SIGNATURE__'] = Str::kebab($name) . ':run';

        $this->template("{$root}/App/Console/{$name}Command.php", <<<'PHP'
<?php

namespace __NS__\App\Console;

use Illuminate\Console\Command;

final class __NAME__Command extends Command
{
    protected $signature = '__SIGNATURE__';
    protected $description = 'Run the __NAME__ module command';

    public function handle(): int
    {
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

use __NS__\App\Models\__NAME__;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class __NAME__Test extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_the_module_records(): void
    {
        __NAME__::factory()->create(['name' => 'First record']);

        $this->getJson('/__ROUTE__')
            ->assertSuccessful()
            ->assertJsonFragment(['name' => 'First record']);
    }

    public function test_store_creates_a_record(): void
    {
        $this->postJson('/__ROUTE__', ['name' => 'New record'])
            ->assertCreated()
            ->assertJsonPath('name', 'New record');

        $this->assertDatabaseHas('__TABLE__', ['name' => 'New record']);
    }

    public function test_store_validates_the_name(): void
    {
        $this->postJson('/__ROUTE__', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_show_update_and_destroy_work(): void
    {
        $model = __NAME__::factory()->create(['name' => 'Original']);

        $this->getJson("/__ROUTE__/{$model->id}")
            ->assertSuccessful()
            ->assertJsonPath('name', 'Original');

        $this->putJson("/__ROUTE__/{$model->id}", ['name' => 'Updated'])
            ->assertSuccessful()
            ->assertJsonPath('name', 'Updated');

        $this->deleteJson("/__ROUTE__/{$model->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('__TABLE__', ['id' => $model->id]);
    }
}
PHP, $variables + ['__TABLE__' => Str::snake(Str::pluralStudly($name))]);
    }

    private function unitTest(string $root, string $name, array $variables): void
    {
        $this->template("{$root}/Tests/Unit/{$name}ServiceTest.php", <<<'PHP'
<?php

namespace __NS__\Tests\Unit;

use __NS__\App\Models\__NAME__;
use __NS__\App\Services\__NAME__Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class __NAME__ServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_creates_updates_and_deletes_models(): void
    {
        $service = new __NAME__Service();

        $model = $service->create(['name' => 'Created']);
        $this->assertDatabaseHas('__TABLE__', ['id' => $model->id, 'name' => 'Created']);

        $model = $service->update($model, ['name' => 'Updated']);
        $this->assertSame('Updated', $model->name);

        $service->delete($model);
        $this->assertDatabaseMissing('__TABLE__', ['id' => $model->id]);
    }
}
PHP, $variables + ['__TABLE__' => Str::snake(Str::pluralStudly($name))]);
    }

    private function isBasic(array $components): bool
    {
        return $components === ['controllers', 'routes'];
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

        $this->file(
            "{$root}/module.json",
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );
    }

    private function configKey(string $name): string
    {
        return Str::kebab($name);
    }

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
        if ($this->files->isDirectory($path)) {
            return;
        }

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
