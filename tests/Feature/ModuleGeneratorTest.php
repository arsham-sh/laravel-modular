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
        $this->assertFileDoesNotExist($this->modulePath . '/App/Models/BasicExample.php');
        $this->assertFileDoesNotExist($this->modulePath . '/App/Services/BasicExampleService.php');
        $this->assertFileDoesNotExist($this->modulePath . '/App/Traits/HttpResponses.php');
    }

    public function test_normal_generation_creates_a_complete_database_backed_module(): void
    {
        $this->generate('NormalExample', ModulePreset::Normal->components());

        $this->assertFileExists($this->modulePath . '/App/Models/NormalExample.php');
        $this->assertFileExists($this->modulePath . '/App/Http/Requests/NormalExampleRequest.php');
        $this->assertFileExists($this->modulePath . '/App/Services/NormalExampleService.php');
        $this->assertFileExists($this->modulePath . '/Database/Factories/NormalExampleFactory.php');
        $this->assertFileExists($this->modulePath . '/Routes/api.php');
        $this->assertStringContainsString("Route::apiResource('normal-examples'", file_get_contents($this->modulePath . '/Routes/api.php'));
        $this->assertStringContainsString("'name' => ['required', 'string', 'max:255']", file_get_contents($this->modulePath . '/App/Http/Requests/NormalExampleRequest.php'));
        $this->assertStringContainsString("protected \$fillable = [", file_get_contents($this->modulePath . '/App/Models/NormalExample.php'));
        $this->assertStringContainsString("'name' => fake()->name()", file_get_contents($this->modulePath . '/Database/Factories/NormalExampleFactory.php'));
    }

    private function generate(string $name, array $components): void
    {
        $this->modulePath = base_path("Modules/{$name}");
        app(ModuleGenerator::class)->generate($name, $components);
    }
}
