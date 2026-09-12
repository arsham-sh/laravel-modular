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
        $name = Str::studly(trim($name));
        if ($name === '') throw new RuntimeException('Module name cannot be empty.');

        $components = ModuleComponents::validate($components);
        if ($components === null) throw new RuntimeException('Invalid module component selection.');

        $root = base_path("Modules/{$name}");
        if ($this->files->exists($root)) throw new RuntimeException("Module [{$name}] already exists.");

        try {
            $this->directory($root);
            $this->write("{$root}/Config/config.php", "<?php\n\nreturn ['enabled' => true];\n");
            $this->sharedSupport();
            $this->provider($root, $name, $components);
            $this->generateComponents($root, $name, $components);
            $this->write("{$root}/module.json", json_encode([
                'name' => $name,
                'namespace' => "Modules\\{$name}",
                'provider' => "Modules\\{$name}\\App\\Providers\\{$name}ServiceProvider",
                'version' => '1.0.0',
                'description' => "{$name} module",
                'enabled' => true,
                'components' => array_values($components),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        } catch (\Throwable $e) {
            if ($this->files->isDirectory($root)) $this->files->deleteDirectory($root);
            throw $e;
        }
    }

    private function generateComponents(string $root, string $name, array $components): void
    {
        $v = [
            'name' => $name,
            'ns' => "Modules\\{$name}",
            'param' => Str::camel($name),
            'route' => Str::kebab(Str::pluralStudly($name)),
        ];

        foreach ($components as $component) {
            match ($component) {
                'models' => $this->model($root, $v, in_array('database', $components, true)),
                'requests' => $this->requests($root, $v),
                'services' => $this->service($root, $v),
                'resources' => $this->resource($root, $v),
                'policies' => $this->policy($root, $v),
                'controllers' => $this->controller($root, $v, $components),
                'database' => $this->database($root, $v),
                'routes' => $this->routes($root, $v, $components),
                'middleware' => $this->middleware($root, $v),
                'console' => $this->console($root, $v),
                'feature-tests' => $this->test($root, $v, 'Feature'),
                'unit-tests' => $this->test($root, $v, 'Unit'),
                'traits' => $this->trait($root, $v),
                default => null,
            };
        }
    }

    private function sharedSupport(): void
    {
        $path = base_path('Modules/Shared/App/Traits/HttpResponses.php');
        if ($this->files->exists($path)) return;

        $this->write($path, <<<'PHP'
<?php

namespace Modules\Shared\App\Traits;

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
PHP);
    }

    private function provider(string $root, string $name, array $components): void
    {
        $ns = "Modules\\{$name}";
        $imports = in_array('policies', $components, true) ? "use Illuminate\\Support\\Facades\\Gate;\n" : '';
        $boot = [];
        if (in_array('routes', $components, true)) $boot[] = "        \$this->loadRoutesFrom(__DIR__ . '/../../Routes/api.php');";
        if (in_array('database', $components, true)) $boot[] = "        \$this->loadMigrationsFrom(__DIR__ . '/../../Database/Migrations');";
        if (in_array('policies', $components, true)) $boot[] = "        Gate::policy(\\{$ns}\\App\\Models\\{$name}::class, \\{$ns}\\App\\Policies\\{$name}Policy::class);";
        if (in_array('console', $components, true)) $boot[] = "        \$this->commands([\\{$ns}\\App\\Console\\{$name}Command::class]);";

        $this->write("{$root}/App/Providers/{$name}ServiceProvider.php", "<?php\n\nnamespace {$ns}\\App\\Providers;\n\nuse Illuminate\\Support\\ServiceProvider;\n{$imports}\nfinal class {$name}ServiceProvider extends ServiceProvider\n{\n    public function register(): void\n    {\n        \$this->mergeConfigFrom(__DIR__ . '/../../Config/config.php', '" . Str::snake(Str::pluralStudly($name)) . "');\n    }\n\n    public function boot(): void\n    {\n" . implode("\n", $boot) . "\n    }\n}\n");
    }

    private function model(string $root, array $v, bool $database): void
    {
        $factory = $database ? "use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\n\n    use HasFactory;\n" : '';
        $this->write("{$root}/App/Models/{$v['name']}.php", "<?php\n\nnamespace {$v['ns']}\\App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n{$factory}\nclass {$v['name']} extends Model\n{\n    protected \$fillable = ['name'];\n}\n");
    }

    private function requests(string $root, array $v): void
    {
        foreach (['Store', 'Update'] as $type) {
            $this->write("{$root}/App/Http/Requests/{$type}{$v['name']}Request.php", "<?php\n\nnamespace {$v['ns']}\\App\\Http\\Requests;\n\nuse Illuminate\\Foundation\\Http\\FormRequest;\n\nclass {$type}{$v['name']}Request extends FormRequest\n{\n    public function authorize(): bool { return true; }\n\n    public function rules(): array { return ['name' => ['required', 'string', 'max:255']]; }\n}\n");
        }
    }

    private function service(string $root, array $v): void
    {
        $this->write("{$root}/App/Services/{$v['name']}Service.php", "<?php\n\nnamespace {$v['ns']}\\App\\Services;\n\nuse {$v['ns']}\\App\\Models\\{$v['name']};\n\nfinal class {$v['name']}Service\n{\n    public function paginate() { return {$v['name']}::query()->paginate(); }\n\n    public function create(array \$data): {$v['name']} { return {$v['name']}::create(\$data); }\n\n    public function update({$v['name']} \${$v['param']}, array \$data): {$v['name']} { \${$v['param']}->update(\$data); return \${$v['param']}->refresh(); }\n\n    public function delete({$v['name']} \${$v['param']}): void { \${$v['param']}->delete(); }\n}\n");
    }

    private function resource(string $root, array $v): void
    {
        $this->write("{$root}/App/Http/Resources/{$v['name']}Resource.php", "<?php\n\nnamespace {$v['ns']}\\App\\Http\\Resources;\n\nuse Illuminate\\Http\\Request;\nuse Illuminate\\Http\\Resources\\Json\\JsonResource;\n\nclass {$v['name']}Resource extends JsonResource\n{\n    public function toArray(Request \$request): array { return ['id' => \$this->id, 'name' => \$this->name, 'created_at' => \$this->created_at, 'updated_at' => \$this->updated_at]; }\n}\n");
    }

    private function policy(string $root, array $v): void
    {
        $this->write("{$root}/App/Policies/{$v['name']}Policy.php", "<?php\n\nnamespace {$v['ns']}\\App\\Policies;\n\nuse {$v['ns']}\\App\\Models\\{$v['name']};\nuse Illuminate\\Contracts\\Auth\\Authenticatable;\n\nfinal class {$v['name']}Policy\n{\n    public function viewAny(Authenticatable \$user): bool { return true; }\n    public function view(Authenticatable \$user, {$v['name']} \${$v['param']}): bool { return true; }\n    public function create(Authenticatable \$user): bool { return true; }\n    public function update(Authenticatable \$user, {$v['name']} \${$v['param']}): bool { return true; }\n    public function delete(Authenticatable \$user, {$v['name']} \${$v['param']}): bool { return true; }\n}\n");
    }

    private function controller(string $root, array $v, array $components): void
    {
        $service = in_array('services', $components, true);
        $requests = in_array('requests', $components, true);
        $resource = in_array('resources', $components, true);
        $imports = "use Modules\\Shared\\App\\Traits\\HttpResponses;\n";

        if ($service) {
            $imports .= "use {$v['ns']}\\App\\Services\\{$v['name']}Service;\n";
            if ($requests) $imports .= "use {$v['ns']}\\App\\Http\\Requests\\Store{$v['name']}Request;\nuse {$v['ns']}\\App\\Http\\Requests\\Update{$v['name']}Request;\n";
            else $imports .= "use Illuminate\\Http\\Request;\n";
            if ($resource) $imports .= "use {$v['ns']}\\App\\Http\\Resources\\{$v['name']}Resource;\n";
            $methods = $this->serviceMethods($v, $requests, $resource);
            $constructor = "    public function __construct(private readonly {$v['name']}Service \$service) {}\n\n";
        } else {
            $imports = "use Illuminate\\Http\\Request;\nuse {$v['ns']}\\App\\Models\\{$v['name']};\n{$imports}";
            if ($resource) $imports .= "use {$v['ns']}\\App\\Http\\Resources\\{$v['name']}Resource;\n";
            $methods = $this->normalMethods($v, $resource);
            $constructor = '';
        }

        $this->write("{$root}/App/Http/Controllers/{$v['name']}Controller.php", "<?php\n\nnamespace {$v['ns']}\\App\\Http\\Controllers;\n\n{$imports}\nfinal class {$v['name']}Controller\n{\n    use HttpResponses;\n\n{$constructor}{$methods}}\n");
    }

    private function serviceMethods(array $v, bool $requests, bool $resource): string
    {
        $store = $requests ? "Store{$v['name']}Request" : 'Request';
        $update = $requests ? "Update{$v['name']}Request" : 'Request';
        $input = $requests ? '$request->validated()' : '$request->all()';
        $index = $resource ? "{$v['name']}Resource::collection(\$this->service->paginate())" : '$this->service->paginate()';
        $create = $resource ? "new {$v['name']}Resource(\$this->service->create({$input}))" : "\$this->service->create({$input})";
        $updateCall = "\$this->service->update(\${$v['param']}, {$input})";
        $updated = $resource ? "new {$v['name']}Resource({$updateCall})" : $updateCall;
        $show = $resource ? "new {$v['name']}Resource(\${$v['param']})" : "\${$v['param']}";

        return "    public function index()\n    {\n        \${$v['param']}s = {$index};\n        return \$this->success(['{$v['param']}s' => \${$v['param']}s]);\n    }\n\n    public function store({$store} \$request)\n    {\n        \${$v['param']} = {$create};\n        return \$this->success(['{$v['param']}' => \${$v['param']}], '{$v['name']} created successfully', 201);\n    }\n\n    public function show({$v['name']} \${$v['param']})\n    {\n        return \$this->success(['{$v['param']}' => {$show}]);\n    }\n\n    public function update({$update} \$request, {$v['name']} \${$v['param']})\n    {\n        \${$v['param']} = {$updated};\n        return \$this->success(['{$v['param']}' => \${$v['param']}], '{$v['name']} updated successfully');\n    }\n\n    public function destroy({$v['name']} \${$v['param']})\n    {\n        \$this->service->delete(\${$v['param']});\n        return \$this->success(null, '{$v['name']} deleted successfully');\n    }\n\n";
    }

    private function normalMethods(array $v, bool $resource): string
    {
        $index = $resource ? "{$v['name']}Resource::collection({$v['name']}::query()->paginate())" : "{$v['name']}::query()->paginate()";
        $show = $resource ? "new {$v['name']}Resource(\${$v['param']})" : "\${$v['param']}";
        return "    public function index() { \${$v['param']}s = {$index}; return \$this->success(['{$v['param']}s' => \${$v['param']}s]); }\n\n    public function store(Request \$request) { \${$v['param']} = {$v['name']}::create(\$request->all()); return \$this->success(['{$v['param']}' => \${$v['param']}], '{$v['name']} created successfully', 201); }\n\n    public function show({$v['name']} \${$v['param']}) { return \$this->success(['{$v['param']}' => {$show}]); }\n\n    public function update(Request \$request, {$v['name']} \${$v['param']}) { \${$v['param']}->update(\$request->all()); return \$this->success(['{$v['param']}' => \${$v['param']}->refresh()], '{$v['name']} updated successfully'); }\n\n    public function destroy({$v['name']} \${$v['param']}) { \${$v['param']}->delete(); return \$this->success(null, '{$v['name']} deleted successfully'); }\n\n";
    }

    private function routes(string $root, array $v, array $components): void
    {
        $body = $this->isBasic($components)
            ? "Route::get('{$v['route']}', [{$v['name']}Controller::class, 'index']);\nRoute::post('{$v['route']}', [{$v['name']}Controller::class, 'store']);"
            : "Route::apiResource('{$v['route']}', {$v['name']}Controller::class);";
        $this->write("{$root}/Routes/api.php", "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\nuse {$v['ns']}\\App\\Http\\Controllers\\{$v['name']}Controller;\n\n{$body}\n");
    }

    private function database(string $root, array $v): void
    {
        $this->write("{$root}/Database/Migrations/" . date('Y_m_d_His') . "_create_{$v['route']}_table.php", "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration {\n    public function up(): void { Schema::create('{$v['route']}', function (Blueprint \$table) { \$table->id(); \$table->string('name'); \$table->timestamps(); }); }\n    public function down(): void { Schema::dropIfExists('{$v['route']}'); }\n};\n");
        $this->write("{$root}/Database/Factories/{$v['name']}Factory.php", "<?php\n\nnamespace {$v['ns']}\\Database\\Factories;\n\nuse {$v['ns']}\\App\\Models\\{$v['name']};\nuse Illuminate\\Database\\Eloquent\\Factories\\Factory;\n\nclass {$v['name']}Factory extends Factory\n{\n    protected \$model = {$v['name']}::class;\n    public function definition(): array { return ['name' => fake()->name()]; }\n}\n");
    }

    private function middleware(string $root, array $v): void
    {
        $this->write("{$root}/App/Http/Middleware/{$v['name']}Middleware.php", "<?php\n\nnamespace {$v['ns']}\\App\\Http\\Middleware;\n\nuse Closure;\nuse Illuminate\\Http\\Request;\nuse Symfony\\Component\\HttpFoundation\\Response;\n\nfinal class {$v['name']}Middleware\n{\n    public function handle(Request \$request, Closure \$next): Response { return \$next(\$request); }\n}\n");
    }

    private function console(string $root, array $v): void
    {
        $this->write("{$root}/App/Console/{$v['name']}Command.php", "<?php\n\nnamespace {$v['ns']}\\App\\Console;\n\nuse Illuminate\\Console\\Command;\n\nfinal class {$v['name']}Command extends Command\n{\n    protected \$signature = '{$v['route']}:run';\n    protected \$description = 'Run the {$v['name']} module command.';\n    public function handle(): int { \$this->info('{$v['name']} command executed.'); return self::SUCCESS; }\n}\n");
    }

    private function test(string $root, array $v, string $type): void
    {
        $this->write("{$root}/Tests/{$type}/{$v['name']}Test.php", "<?php\n\nnamespace {$v['ns']}\\Tests\\{$type};\n\nuse Tests\\TestCase;\n\nclass {$v['name']}Test extends TestCase\n{\n    public function test_module_{$type}_test(): void { \$this->assertTrue(true); }\n}\n");
    }

    private function trait(string $root, array $v): void
    {
        $this->write("{$root}/App/Traits/{$v['name']}Trait.php", "<?php\n\nnamespace {$v['ns']}\\App\\Traits;\n\ntrait {$v['name']}Trait {}\n");
    }

    private function isBasic(array $components): bool
    {
        return $components === ModulePreset::Basic->components();
    }

    private function directory(string $path): void
    {
        $this->files->ensureDirectoryExists($path);
    }

    private function write(string $path, string $content): void
    {
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $content);
    }
}
