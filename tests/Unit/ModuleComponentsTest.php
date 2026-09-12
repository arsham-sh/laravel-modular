<?php

namespace Arsham\LaravelModular\Tests\Unit;

use Arsham\LaravelModular\Generators\ModuleComponents;
use PHPUnit\Framework\TestCase;

class ModuleComponentsTest extends TestCase
{
    public function test_controllers_pull_in_required_components(): void
    {
        $this->assertSame(
            ['controllers', 'models', 'requests', 'routes'],
            ModuleComponents::normalize(['controllers'])
        );
    }

    public function test_database_related_components_pull_in_models(): void
    {
        $this->assertSame(
            ['resources', 'models'],
            ModuleComponents::normalize(['resources'])
        );
    }

    public function test_feature_and_unit_tests_pull_in_their_dependencies(): void
    {
        $this->assertSame(
            ['feature-tests', 'routes'],
            ModuleComponents::normalize(['feature-tests'])
        );

        $this->assertSame(
            ['unit-tests', 'services'],
            ModuleComponents::normalize(['unit-tests'])
        );
    }
}
