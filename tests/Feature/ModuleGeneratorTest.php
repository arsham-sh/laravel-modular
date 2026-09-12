<?php

namespace Arsham\LaravelModular\Tests\Feature;

use Arsham\LaravelModular\Generators\ModuleGenerator;
use Arsham\LaravelModular\Generators\ModulePreset;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

class ModuleGeneratorTest extends TestCase
{
    private string $modulePath;

    protected function tearDown(): void
    {
        if (isset($this->modulePath)) {
            app(Filesystem::class)->deleteDirectory($this->modulePath);
        }

        parent::tearDown();
    }

    public function test_basic_generation_contains_only_essential_files(): void
    {
        $this->generate('BasicExample', ModulePreset::Basic->components());

        $this->assertFileExists($this->modulePath . '/App/Http/Controllers/BasicExampleController.php');
        $this->assertFileExists($this->modulePath . '/Routes/api.php');
        $this->assertFileExists($this->modulePath . '/App/Providers/BasicExampleServiceProvider.php');
        $this->assertFileExists(base_path('Modules/Shared/App/Traits/HttpResponses.php'));
        $this->assertFileDoesNotExist($this->modulePath . '/App/Models/BasicExample.php');
        $this->assertFileDoesNotExist($this->modulePath . '/App/Services/BasicExampleService.php');
        $this->assertFileDoesNotExist($this->modulePath . '/App/Http/Requests');
        $this->assertFileDoesNotExist($this->modulePath . '/Tests');

        $routes = file_get_contents($this->modulePath . '/Routes/api.php');
        $this->assertStringContainsString('use Modules\\BasicExample\\App\\Http\\Controllers\\BasicExampleController;', $routes);
        $this->assertStringContainsString("Route::get('basic-examples', [BasicExampleController::class, 'index']);", $routes);
        $this->assertStringContainsString("Route::post('basic-examples', [BasicExampleController::class, 'store']);", $routes);
    }

    public function test_normal_generation_creates_a_simple_database_backed_crud_module(): void
    {
        $this->generate('NormalExample', ModulePreset::Normal->components());

        $this->assertFileExists($this->modulePath . '/App/Models/NormalExample.php');
        $this->assertFileExists($this->modulePath . '/Database/Factories/NormalExampleFactory.php');
        $this->assertFileExists($this->modulePath . '/Routes/api.php');
        $this->assertFileDoesNotExist($this->modulePath . '/App/Http/Requests');
        $this->assertFileDoesNotExist($this->modulePath . '/App/Services');
        $this->assertFileDoesNotExist($this->modulePath . '/Tests');

        $controller = file_get_contents($this->modulePath . '/App/Http/Controllers/NormalExampleController.php');
        $routes = file_get_contents($this->modulePath . '/Routes/api.php');

        $this->assertStringContainsString('Request $request', $controller);
        $this->assertStringContainsString('NormalExample::create($request->all())', $controller);
        $this->assertStringContainsString('$normalExample->update($request->all())', $controller);
        $this->assertStringContainsString('use Modules\\Shared\\App\\Traits\\HttpResponses;', $controller);
        $this->assertStringContainsString('use HttpResponses;', $controller);
        $this->assertStringContainsString("Route::apiResource('normal-examples', NormalExampleController::class);", $routes);
        $this->assertStringNotContainsString('__NAME__', $routes);
        $this->assertStringNotContainsString('__ROUTE__', $routes);
        $this->assertStringNotContainsString('NormalExampleService', $controller);
    }

    public function test_advanced_generation_separates_validation_and_application_logic(): void
    {
        $this->generate('AdvancedExample', ModulePreset::Advanced->components());

        $this->assertFileExists($this->modulePath . '/App/Http/Requests/StoreAdvancedExampleRequest.php');
        $this->assertFileExists($this->modulePath . '/App/Http/Requests/UpdateAdvancedExampleRequest.php');
        $this->assertFileExists($this->modulePath . '/App/Services/AdvancedExampleService.php');
        $this->assertFileExists($this->modulePath . '/Tests/Feature/AdvancedExampleTest.php');

        $storeRequest = file_get_contents($this->modulePath . '/App/Http/Requests/StoreAdvancedExampleRequest.php');
        $updateRequest = file_get_contents($this->modulePath . '/App/Http/Requests/UpdateAdvancedExampleRequest.php');
        $controller = file_get_contents($this->modulePath . '/App/Http/Controllers/AdvancedExampleController.php');
        $routes = file_get_contents($this->modulePath . '/Routes/api.php');

        $this->assertStringContainsString("'name' => ['required', 'string', 'max:255']", $storeRequest);
        $this->assertStringContainsString("'name' => ['required', 'string', 'max:255']", $updateRequest);
        $this->assertStringContainsString('use Modules\\Shared\\App\\Traits\\HttpResponses;', $controller);
        $this->assertStringContainsString('use HttpResponses;', $controller);
        $this->assertStringContainsString('StoreAdvancedExampleRequest $request', $controller);
        $this->assertStringContainsString('UpdateAdvancedExampleRequest $request', $controller);
        $this->assertStringContainsString('$this->service->paginate()', $controller);
        $this->assertStringContainsString('$this->service->create($request->validated())', $controller);
        $this->assertStringContainsString('$this->service->update($advancedExample, $request->validated())', $controller);
        $this->assertStringContainsString('$this->service->delete($advancedExample)', $controller);
        $this->assertStringContainsString("'AdvancedExample created successfully'", $controller);
        $this->assertStringContainsString("'AdvancedExample updated successfully'", $controller);
        $this->assertStringContainsString("'AdvancedExample deleted successfully'", $controller);
        $this->assertStringContainsString("Route::apiResource('advanced-examples', AdvancedExampleController::class);", $routes);
        $this->assertStringNotContainsString('__NAME__', $controller);
        $this->assertStringNotContainsString('__PARAM__', $controller);
    }

    private function generate(string $name, array $components): void
    {
        $this->modulePath = base_path("Modules/{$name}");
        app(ModuleGenerator::class)->generate($name, $components);
    }
}
