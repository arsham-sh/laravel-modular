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

        $v = [
            'name' => $name,
            'namespace' => "Modules\\{$name}",
            'parameter' => Str::camel($name),
            'route' => Str::kebab(Str::pluralStudly($name)),
        ];

        try {
            $this->write("{$root}/Config/config.php", <<<'PHP'
<?php

return [
    'enabled' => true,
];
PHP);

            $this->provider($root, $v, $components);

            foreach ($components as $component) {
                match ($component) {
                    'models' => $this->model($root, $v, in_array('database', $components, true)),
                    'requests' => $this->requests($root, $v),
                    'services' => $this->service($root, $v),
                    'resources' => $this->resource($root, $v),
                    'policies' => $this->policy($root, $v),
                    'controllers' => $this->controller($root, $v, $components),
                    'routes' => $this->routes($root, $v, $components),
                    'database' => $this->database($root, $v),
                    'middleware' => $this->middleware($root, $v),
                    'console' => $this->console($root, $v),
                    'feature-tests' => $this->test($root, $v, 'Feature'),
                    'unit-tests' => $this->test($root, $v, 'Unit'),
                    'traits' => $this->trait($root, $v),
                    default => null,
                };
            }

            $this->write(
                "{$root}/module.json",
                json_encode([
                    'name' => $name,
                    'namespace' => $v['namespace'],
                    'provider' => "{$v['namespace']}\\App\\Providers\\{$name}ServiceProvider",
                    'version' => '1.0.0',
                    'description' => "{$name} module",
                    'enabled' => true,
                    'components' => array_values($components),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
            );

            $this->sharedSupport();
        } catch (\Throwable $e) {
            if ($this->files->isDirectory($root)) {
                $this->files->deleteDirectory($root);
            }

            throw $e;
        }
    }

    private function provider(string $root, array $v, array $components): void
    {
        $imports = '';
        $boot = '';

        if (in_array('policies', $components, true)) {
            $imports .= "use Illuminate\\Support\\Facades\\Gate;\n";
            $boot .= "        Gate::policy(\\{$v['namespace']}\\App\\Models\\{$v['name']}::class, \\{$v['namespace']}\\App\\Policies\\{$v['name']}Policy::class);\n";
        }

        if (in_array('routes', $components, true)) {
            $boot .= "        \$this->loadRoutesFrom(__DIR__ . '/../../Routes/api.php');\n";
        }

        if (in_array('database', $components, true)) {
            $boot .= "        \$this->loadMigrationsFrom(__DIR__ . '/../../Database/Migrations');\n";
        }

        if (in_array('console', $components, true)) {
            $boot .= "        \$this->commands([\\{$v['namespace']}\\App\\Console\\{$v['name']}Command::class]);\n";
        }

        $template = <<<'PHP'
<?php

namespace {{namespace}}\App\Providers;

use Illuminate\Support\ServiceProvider;
{{imports}}
final class {{name}}ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../Config/config.php',
            '{{config}}'
        );
    }

    public function boot(): void
    {
{{boot}}    }
}
PHP;

        $this->write("{$root}/App/Providers/{$v['name']}ServiceProvider.php", $this->render($template, [
            'namespace' => $v['namespace'],
            'name' => $v['name'],
            'imports' => rtrim($imports),
            'boot' => rtrim($boot),
            'config' => Str::snake(Str::pluralStudly($v['name'])),
        ]));
    }

    private function model(string $root, array $v, bool $database): void
    {
        $factory = $database ? "use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\n" : '';
        $trait = $database ? "    use HasFactory;\n\n" : '';

        $template = <<<'PHP'
<?php

namespace {{namespace}}\App\Models;

use Illuminate\Database\Eloquent\Model;
{{factory}}
class {{name}} extends Model
{
{{trait}}    protected $fillable = [
        'name',
    ];
}
PHP;

        $this->write("{$root}/App/Models/{$v['name']}.php", $this->render($template, [
            'namespace' => $v['namespace'],
            'name' => $v['name'],
            'factory' => rtrim($factory),
            'trait' => $trait,
        ]));
    }

    private function requests(string $root, array $v): void
    {
        $template = <<<'PHP'
<?php

namespace {{namespace}}\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class {{type}}{{name}}Request extends FormRequest
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
PHP;

        foreach (['Store', 'Update'] as $type) {
            $this->write("{$root}/App/Http/Requests/{$type}{$v['name']}Request.php", $this->render($template, [
                'namespace' => $v['namespace'],
                'type' => $type,
                'name' => $v['name'],
            ]));
        }
    }

    private function service(string $root, array $v): void
    {
        $template = <<<'PHP'
<?php

namespace {{namespace}}\App\Services;

use {{namespace}}\App\Models\{{name}};

final class {{name}}Service
{
    public function paginate()
    {
        return {{name}}::query()->paginate();
    }

    public function create(array $data): {{name}}
    {
        return {{name}}::create($data);
    }

    public function update({{name}} ${{parameter}}, array $data): {{name}}
    {
        ${{parameter}}->update($data);

        return ${{parameter}}->refresh();
    }

    public function delete({{name}} ${{parameter}}): void
    {
        ${{parameter}}->delete();
    }
}
PHP;

        $this->write("{$root}/App/Services/{$v['name']}Service.php", $this->render($template, $v));
    }

    private function resource(string $root, array $v): void
    {
        $template = <<<'PHP'
<?php

namespace {{namespace}}\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class {{name}}Resource extends JsonResource
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
PHP;

        $this->write("{$root}/App/Http/Resources/{$v['name']}Resource.php", $this->render($template, $v));
    }

    private function policy(string $root, array $v): void
    {
        $template = <<<'PHP'
<?php

namespace {{namespace}}\App\Policies;

use {{namespace}}\App\Models\{{name}};
use Illuminate\Contracts\Auth\Authenticatable;

final class {{name}}Policy
{
    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

    public function view(Authenticatable $user, {{name}} ${{parameter}}): bool
    {
        return true;
    }

    public function create(Authenticatable $user): bool
    {
        return true;
    }

    public function update(Authenticatable $user, {{name}} ${{parameter}}): bool
    {
        return true;
    }

    public function delete(Authenticatable $user, {{name}} ${{parameter}}): bool
    {
        return true;
    }
}
PHP;

        $this->write("{$root}/App/Policies/{$v['name']}Policy.php", $this->render($template, $v));
    }

    private function controller(string $root, array $v, array $components): void
    {
        $hasService = in_array('services', $components, true);
        $hasRequests = in_array('requests', $components, true);
        $hasResource = in_array('resources', $components, true);

        $imports = [
            "use {$v['namespace']}\\App\\Models\\{$v['name']};",
            'use Modules\\Shared\\App\\Traits\\HttpResponses;',
        ];

        if ($hasService) {
            $imports[] = "use {$v['namespace']}\\App\\Services\\{$v['name']}Service;";
        }

        if ($hasRequests) {
            $imports[] = "use {$v['namespace']}\\App\\Http\\Requests\\Store{$v['name']}Request;";
            $imports[] = "use {$v['namespace']}\\App\\Http\\Requests\\Update{$v['name']}Request;";
        } else {
            $imports[] = 'use Illuminate\\Http\\Request;';
        }

        if ($hasResource) {
            $imports[] = "use {$v['namespace']}\\App\\Http\\Resources\\{$v['name']}Resource;";
        }

        $requestStore = $hasRequests ? "Store{$v['name']}Request" : 'Request';
        $requestUpdate = $hasRequests ? "Update{$v['name']}Request" : 'Request';
        $input = $hasRequests ? '$request->validated()' : '$request->all()';
        $index = $hasService
            ? '$this->service->paginate()'
            : "{$v['name']}::query()->paginate()";
        $create = $hasService
            ? '$this->service->create(' . $input . ')'
            : "{$v['name']}::create({$input})";
        $update = $hasService
            ? '$this->service->update($' . $v['parameter'] . ', ' . $input . ')'
            : '$' . $v['parameter'] . '->update(' . $input . ')';
        $delete = $hasService
            ? '$this->service->delete($' . $v['parameter'] . ')'
            : '$' . $v['parameter'] . '->delete()';

        $template = <<<'PHP'
<?php

namespace {{namespace}}\App\Http\Controllers;

{{imports}}

final class {{name}}Controller
{
{{serviceConstructor}}
    public function index()
    {
        ${{parameter}}s = {{index}};

        return $this->success([
            '{{parameter}}s' => ${{parameter}}s,
        ]);
    }

    public function store({{requestStore}} $request)
    {
        ${{parameter}} = {{create}};

        return $this->success(
            ['{{parameter}}' => ${{parameter}}],
            '{{name}} created successfully',
            201
        );
    }

    public function show({{name}} ${{parameter}})
    {
        return $this->success([
            '{{parameter}}' => {{show}},
        ]);
    }

    public function update({{requestUpdate}} $request, {{name}} ${{parameter}})
    {
        {{updateStatement}}

        return $this->success(
            ['{{parameter}}' => ${{parameter}}],
            '{{name}} updated successfully'
        );
    }

    public function destroy({{name}} ${{parameter}})
    {
        {{delete}};

        return $this->success(
            null,
            '{{name}} deleted successfully'
        );
    }
}
PHP;

        $updateStatement = $hasService
            ? '$' . $v['parameter'] . ' = ' . $update . ';'
            : '$' . $v['parameter'] . '->update(' . $input . ');';

        $show = $hasResource
            ? "new {$v['name']}Resource(\${$v['parameter']})"
            : '$' . $v['parameter'];

        $constructor = $hasService
            ? "    use HttpResponses;\n\n    public function __construct(\n        private readonly {$v['name']}Service \$service,\n    ) {\n    }\n"
            : "    use HttpResponses;\n";

        $this->write("{$root}/App/Http/Controllers/{$v['name']}Controller.php", $this->render($template, [
            'namespace' => $v['namespace'],
            'name' => $v['name'],
            'parameter' => $v['parameter'],
            'imports' => implode(PHP_EOL, $imports),
            'serviceConstructor' => $constructor,
            'index' => $hasResource ? "{$v['name']}Resource::collection({$index})" : $index,
            'requestStore' => $requestStore,
            'requestUpdate' => $requestUpdate,
            'create' => $hasResource ? "new {$v['name']}Resource({$create})" : $create,
            'show' => $show,
            'updateStatement' => $updateStatement,
            'delete' => $delete,
        ]));
    }

    private function routes(string $root, array $v, array $components): void
    {
        $controller = "{$v['name']}Controller";
        $body = $this->isBasic($components)
            ? "Route::get('{$v['route']}', [{$controller}::class, 'index']);\nRoute::post('{$v['route']}', [{$controller}::class, 'store']);"
            : "Route::apiResource('{$v['route']}', {$controller}::class);";

        $template = <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;
use {{namespace}}\App\Http\Controllers\{{controller}};

{{body}}
PHP;

        $this->write("{$root}/Routes/api.php", $this->render($template, [
            'namespace' => $v['namespace'],
            'controller' => $controller,
            'body' => $body,
        ]));
    }

    private function database(string $root, array $v): void
    {
        $migration = date('Y_m_d_His') . "_create_{$v['route']}_table.php";

        $template = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{{route}}', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{{route}}');
    }
};
PHP;

        $this->write("{$root}/Database/Migrations/{$migration}", $this->render($template, ['route' => $v['route']]));

        $factory = <<<'PHP'
<?php

namespace {{namespace}}\Database\Factories;

use {{namespace}}\App\Models\{{name}};
use Illuminate\Database\Eloquent\Factories\Factory;

class {{name}}Factory extends Factory
{
    protected $model = {{name}}::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
        ];
    }
}
PHP;

        $this->write("{$root}/Database/Factories/{$v['name']}Factory.php", $this->render($factory, $v));
    }

    private function middleware(string $root, array $v): void
    {
        $template = <<<'PHP'
<?php

namespace {{namespace}}\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class {{name}}Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
PHP;

        $this->write("{$root}/App/Http/Middleware/{$v['name']}Middleware.php", $this->render($template, $v));
    }

    private function console(string $root, array $v): void
    {
        $template = <<<'PHP'
<?php

namespace {{namespace}}\App\Console;

use Illuminate\Console\Command;

final class {{name}}Command extends Command
{
    protected $signature = '{{route}}:run';
    protected $description = 'Run the {{name}} module command.';

    public function handle(): int
    {
        $this->info('{{name}} command executed.');

        return self::SUCCESS;
    }
}
PHP;

        $this->write("{$root}/App/Console/{$v['name']}Command.php", $this->render($template, $v));
    }

    private function test(string $root, array $v, string $type): void
    {
        $template = <<<'PHP'
<?php

namespace {{namespace}}\Tests\{{type}};

use Tests\TestCase;

class {{name}}Test extends TestCase
{
    public function test_module_smoke_test(): void
    {
        $this->assertTrue(true);
    }
}
PHP;

        $this->write("{$root}/Tests/{$type}/{$v['name']}Test.php", $this->render($template, [
            ...$v,
            'type' => $type,
        ]));
    }

    private function trait(string $root, array $v): void
    {
        $template = <<<'PHP'
<?php

namespace {{namespace}}\App\Traits;

trait {{name}}Trait
{
}
PHP;

        $this->write("{$root}/App/Traits/{$v['name']}Trait.php", $this->render($template, $v));
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

    private function render(string $template, array $variables): string
    {
        $replacements = [];

        foreach ($variables as $key => $value) {
            $replacements["{{{$key}}}"] = (string) $value;
        }

        $result = strtr($template, $replacements);

        if (preg_match('/\{\{[^}]+\}\}/', $result) === 1) {
            throw new RuntimeException('Generator template contains an unresolved placeholder.');
        }

        return $result;
    }

    private function write(string $path, string $content): void
    {
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $content);
    }

    private function isBasic(array $components): bool
    {
        return $components === ModulePreset::Basic->components();
    }
}
