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
        'controllers', 'requests', 'models', 'services', 'jobs', 'events',
        'listeners', 'policies', 'resources', 'migrations', 'factories',
        'seeders', 'routes', 'feature-tests', 'unit-tests',
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
        if ($components === null) {
            return self::FAILURE;
        }

        $files->makeDirectory("{$modulePath}/Config", 0755, true);
        $files->makeDirectory("{$modulePath}/App/Providers", 0755, true);
        foreach ($components as $component) {
            $files->makeDirectory("{$modulePath}/{$this->paths[$component]}", 0755, true);
        }

        $this->put($files, "{$modulePath}/Config/config.php", "<?php\n\nreturn [\n    'enabled' => true,\n];\n");
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
                static fn (string $value): string => Str::kebab(trim($value)),
                $requested
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
            $boot .= '        $this->loadRoutesFrom(__DIR__ . \'/../../Routes/api.php\');' . PHP_EOL;
        }
        if (in_array('migrations', $components, true)) {
            $boot .= '        $this->loadMigrationsFrom(__DIR__ . \'/../../Database/Migrations\');' . PHP_EOL;
        }

        $configKey = Str::kebab($name);
        $content = "<?php\n\nnamespace Modules\\{$name}\\App\\Providers;\n\nuse Illuminate\\Support\\ServiceProvider;\n\nclass {$name}ServiceProvider extends ServiceProvider\n{\n    public function register(): void\n    {\n";
        $content .= '        $this->mergeConfigFrom(__DIR__ . \'/../../Config/config.php\', ' . var_export($configKey, true) . ');' . PHP_EOL;
        $content .= "    }\n\n    public function boot(): void\n    {\n{$boot}    }\n}\n";

        $this->put($files, "{$modulePath}/App/Providers/{$name}ServiceProvider.php", $content);
    }

    /** @param list<string> $components */
    private function createComponents(Filesystem $files, string $modulePath, string $name, array $components): void
    {
        $ns = "Modules\\{$name}";
        $param = Str::camel($name);
        $table = Str::snake(Str::pluralStudly($name));
        $route = Str::kebab(Str::pluralStudly($name));

        if (in_array('models', $components, true)) {
            $factory = in_array('factories', $components, true)
                ? "\n    protected static function newFactory(): \\Illuminate\\Database\\Eloquent\\Factories\\Factory\n    {\n        return \\{$ns}\\Database\\Factories\\{$name}Factory::new();\n    }\n"
                : '';
            $this->put($files, "{$modulePath}/App/Models/{$name}.php", "<?php\n\nnamespace {$ns}\\App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass {$name} extends Model\n{\n    use HasFactory;\n\n    protected \\$guarded = [];{$factory}\n}\n");
        }

        if (in_array('requests', $components, true)) {
            $this->put($files, "{$modulePath}/App/Http/Requests/{$name}Request.php", "<?php\n\nnamespace {$ns}\\App\\Http\\Requests;\n\nuse Illuminate\\Foundation\\Http\\FormRequest;\n\nclass {$name}Request extends FormRequest\n{\n    public function authorize(): bool { return true; }\n\n    public function rules(): array { return []; }\n}\n");
        }

        if (in_array('services', $components, true)) {
            $this->put($files, "{$modulePath}/App/Services/{$name}Service.php", "<?php\n\nnamespace {$ns}\\App\\Services;\n\nclass {$name}Service\n{\n    // Put module business logic here.\n}\n");
        }

        if (in_array('resources', $components, true)) {
            $this->put($files, "{$modulePath}/App/Http/Resources/{$name}Resource.php", "<?php\n\nnamespace {$ns}\\App\\Http\\Resources;\n\nuse Illuminate\\Http\\Request;\nuse Illuminate\\Http\\Resources\\Json\\JsonResource;\n\nclass {$name}Resource extends JsonResource\n{\n    public function toArray(Request \\$request): array { return parent::toArray(\\$request); }\n}\n");
        }

        if (in_array('controllers', $components, true)) {
            $resource = in_array('resources', $components, true);
            $resourceUse = $resource ? "use {$ns}\\App\\Http\\Resources\\{$name}Resource;\n" : '';
            $index = $resource
                ? "return {$name}Resource::collection({$name}::query()->paginate());"
                : "return response()->json({$name}::query()->paginate());";
            $store = $resource
                ? "return response()->json(new {$name}Resource(\$model), 201);"
                : "return response()->json(\$model, 201);";
            $show = $resource ? "new {$name}Resource(\${$param})" : "\${$param}";
            $this->put($files, "{$modulePath}/App/Http/Controllers/{$name}Controller.php", "<?php\n\nnamespace {$ns}\\App\\Http\\Controllers;\n\nuse Illuminate\\Http\\JsonResponse;\nuse Illuminate\\Http\\Request;\n{$resourceUse}use {$ns}\\App\\Models\\{$name};\n\nclass {$name}Controller\n{\n    public function index(): JsonResponse\n    {\n        {$index}\n    }\n\n    public function store(Request \\$request): JsonResponse\n    {\n        \\$model = {$name}::create(\\$request->all());\n        {$store}\n    }\n\n    public function show({$name} \\${$param}): JsonResponse\n    {\n        return response()->json({$show});\n    }\n\n    public function update(Request \\$request, {$name} \\${$param}): JsonResponse\n    {\n        \\${$param}->update(\\$request->all());\n        return response()->json({$show});\n    }\n\n    public function destroy({$name} \\${$param}): JsonResponse\n    {\n        \\${$param}->delete();\n        return response()->noContent();\n    }\n}\n");
        }

        if (in_array('factories', $components, true) && in_array('models', $components, true)) {
            $this->put($files, "{$modulePath}/Database/Factories/{$name}Factory.php", "<?php\n\nnamespace {$ns}\\Database\\Factories;\n\nuse Illuminate\\Database\\Eloquent\\Factories\\Factory;\nuse {$ns}\\App\\Models\\{$name};\n\nclass {$name}Factory extends Factory\n{\n    protected \\$model = {$name}::class;\n\n    public function definition(): array { return []; }\n}\n");
        }

        if (in_array('migrations', $components, true)) {
            $file = date('Y_m_d_His') . "_create_{$table}_table.php";
            $this->put($files, "{$modulePath}/Database/Migrations/{$file}", "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void { Schema::create('{$table}', function (Blueprint \\$table): void { \\$table->id(); \\$table->timestamps(); }); }\n    public function down(): void { Schema::dropIfExists('{$table}'); }\n};\n");
        }

        if (in_array('seeders', $components, true)) {
            $this->put($files, "{$modulePath}/Database/Seeders/{$name}Seeder.php", "<?php\n\nnamespace {$ns}\\Database\\Seeders;\n\nuse Illuminate\\Database\\Seeder;\n\nclass {$name}Seeder extends Seeder\n{\n    public function run(): void {}\n}\n");
        }

        if (in_array('routes', $components, true) && in_array('controllers', $components, true)) {
            $this->put($files, "{$modulePath}/Routes/api.php", "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\nuse {$ns}\\App\\Http\\Controllers\\{$name}Controller;\n\nRoute::apiResource('{$route}', {$name}Controller::class);\n");
        }

        if (in_array('jobs', $components, true)) {
            $this->put($files, "{$modulePath}/App/Jobs/{$name}Job.php", "<?php\n\nnamespace {$ns}\\App\\Jobs;\n\nuse Illuminate\\Bus\\Queueable;\nuse Illuminate\\Contracts\\Queue\\ShouldQueue;\n\nclass {$name}Job implements ShouldQueue\n{\n    use Queueable;\n\n    public function handle(): void {}\n}\n");
        }

        if (in_array('events', $components, true)) {
            $this->put($files, "{$modulePath}/App/Events/{$name}Event.php", "<?php\n\nnamespace {$ns}\\App\\Events;\n\nclass {$name}Event\n{\n    public function __construct(public readonly mixed \\$payload = null) {}\n}\n");
        }

        if (in_array('listeners', $components, true) && in_array('events', $components, true)) {
            $this->put($files, "{$modulePath}/App/Listeners/{$name}EventListener.php", "<?php\n\nnamespace {$ns}\\App\\Listeners;\n\nuse {$ns}\\App\\Events\\{$name}Event;\n\nclass {$name}EventListener\n{\n    public function handle({$name}Event \\$event): void {}\n}\n");
        }

        if (in_array('policies', $components, true) && in_array('models', $components, true)) {
            $this->put($files, "{$modulePath}/App/Policies/{$name}Policy.php", "<?php\n\nnamespace {$ns}\\App\\Policies;\n\nuse {$ns}\\App\\Models\\{$name};\n\nclass {$name}Policy\n{\n    public function viewAny(mixed \\$user): bool { return true; }\n    public function view(mixed \\$user, {$name} \\${$param}): bool { return true; }\n    public function create(mixed \\$user): bool { return true; }\n    public function update(mixed \\$user, {$name} \\${$param}): bool { return true; }\n    public function delete(mixed \\$user, {$name} \\${$param}): bool { return true; }\n}\n");
        }

        if (in_array('middleware', $components, true)) {
            $this->put($files, "{$modulePath}/App/Http/Middleware/{$name}Middleware.php", "<?php\n\nnamespace {$ns}\\App\\Http\\Middleware;\n\nuse Closure;\nuse Illuminate\\Http\\Request;\nuse Symfony\\Component\\HttpFoundation\\Response;\n\nclass {$name}Middleware\n{\n    public function handle(Request \\$request, Closure \\$next): Response { return \\$next(\\$request); }\n}\n");
        }

        if (in_array('console', $components, true)) {
            $signature = Str::kebab($name) . ':run';
            $this->put($files, "{$modulePath}/App/Console/{$name}Command.php", "<?php\n\nnamespace {$ns}\\App\\Console;\n\nuse Illuminate\\Console\\Command;\n\nclass {$name}Command extends Command\n{\n    protected \\$signature = '{$signature}';\n    protected \\$description = 'Run the {$name} module command';\n    public function handle(): int { \\$this->info('{$name} module command is ready.'); return self::SUCCESS; }\n}\n");
        }

        if (in_array('feature-tests', $components, true)) {
            $this->put($files, "{$modulePath}/Tests/Feature/{$name}Test.php", "<?php\n\nnamespace {$ns}\\Tests\\Feature;\n\nuse Tests\\TestCase;\n\nclass {$name}Test extends TestCase\n{\n    public function test_module_endpoint_is_available(): void { \\$this->getJson('/api/{$route}')->assertOk(); }\n}\n");
        }

        if (in_array('unit-tests', $components, true)) {
            $this->put($files, "{$modulePath}/Tests/Unit/{$name}ServiceTest.php", "<?php\n\nnamespace {$ns}\\Tests\\Unit;\n\nuse PHPUnit\\Framework\\TestCase;\n\nclass {$name}ServiceTest extends TestCase\n{\n    public function test_service_can_be_instantiated(): void { \\$this->assertInstanceOf(\\{$ns}\\App\\Services\\{$name}Service::class, new \\{$ns}\\App\\Services\\{$name}Service()); }\n}\n");
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

    private function put(Filesystem $files, string $path, string $contents): void
    {
        $files->put($path, $contents);
    }
}