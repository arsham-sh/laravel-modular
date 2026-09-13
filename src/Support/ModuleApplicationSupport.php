<?php

namespace Arsham\LaravelModular\Support;

use Illuminate\Filesystem\Filesystem;

final class ModuleApplicationSupport
{
    public function __construct(private readonly Filesystem $files)
    {
    }

    public function prepare(): void
    {
        $this->ensureHttpResponsesTrait();
        $this->ensurePhpunitModuleSuites();
    }

    public function updateModuleControllers(string $module): void
    {
        $directory = base_path("Modules/{$module}/App/Http/Controllers");

        if (! $this->files->isDirectory($directory)) {
            return;
        }

        foreach ($this->files->files($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = $this->files->get($file->getPathname());
            $updated = str_replace(
                'use Modules\\Shared\\App\\Traits\\HttpResponses;',
                'use App\\Traits\\HttpResponses;',
                $content
            );

            if ($updated !== $content) {
                $this->files->put($file->getPathname(), $updated);
            }
        }
    }

    public function removeGeneratedSharedSupport(bool $sharedRootExistedBeforeGeneration): void
    {
        if ($sharedRootExistedBeforeGeneration) {
            return;
        }

        $root = base_path('Modules/Shared');

        if ($this->files->isDirectory($root)) {
            $this->files->deleteDirectory($root);
        }
    }

    private function ensureHttpResponsesTrait(): void
    {
        $path = base_path('app/Traits/HttpResponses.php');

        if ($this->files->exists($path)) {
            return;
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, <<<'PHP'
<?php

namespace App\Traits;

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
    }

    private function ensurePhpunitModuleSuites(): void
    {
        $path = base_path('phpunit.xml');
        $feature = <<<'XML'
        <testsuite name="Module Feature">
            <directory suffix="Test.php">Modules/*/Tests/Feature</directory>
        </testsuite>
XML;
        $unit = <<<'XML'
        <testsuite name="Module Unit">
            <directory suffix="Test.php">Modules/*/Tests/Unit</directory>
        </testsuite>
XML;

        if (! $this->files->exists($path)) {
            $this->files->put($path, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
{$feature}
{$unit}
    </testsuites>
</phpunit>
XML
            );

            return;
        }

        $content = $this->files->get($path);
        $suites = [];

        if (! str_contains($content, '<testsuite name="Module Feature">')) {
            $suites[] = $feature;
        }

        if (! str_contains($content, '<testsuite name="Module Unit">')) {
            $suites[] = $unit;
        }

        if ($suites === []) {
            return;
        }

        $insert = implode(PHP_EOL, $suites);

        if (str_contains($content, '</testsuites>')) {
            $content = str_replace('</testsuites>', $insert . PHP_EOL . '    </testsuites>', $content);
        } elseif (str_contains($content, '</phpunit>')) {
            $testsuites = "    <testsuites>" . PHP_EOL
                . $insert . PHP_EOL
                . "    </testsuites>" . PHP_EOL;
            $content = str_replace('</phpunit>', $testsuites . '</phpunit>', $content);
        } else {
            throw new \RuntimeException('Invalid phpunit.xml: missing </phpunit>.');
        }

        $this->files->put($path, $content);
    }
}
