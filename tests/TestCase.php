<?php

namespace Arsham\LaravelModular\Tests;

use Arsham\LaravelModular\LaravelModularServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelModularServiceProvider::class];
    }
}
