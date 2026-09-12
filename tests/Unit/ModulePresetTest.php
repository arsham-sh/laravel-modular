<?php

namespace Arsham\LaravelModular\Tests\Unit;

use Arsham\LaravelModular\Generators\ModulePreset;
use PHPUnit\Framework\TestCase;

class ModulePresetTest extends TestCase
{
    public function test_all_presets_have_descriptions_and_components(): void
    {
        foreach (ModulePreset::cases() as $preset) {
            $this->assertNotSame('', $preset->description());
            $this->assertNotEmpty($preset->components());
        }
    }

    public function test_basic_preset_contains_only_the_essential_components(): void
    {
        $this->assertSame(
            ['controllers', 'routes'],
            ModulePreset::Basic->components()
        );
    }

    public function test_normal_preset_contains_database_crud_and_tests(): void
    {
        $this->assertSame(
            [
                'controllers', 'requests', 'models', 'services', 'database', 'routes',
                'feature-tests', 'unit-tests',
            ],
            ModulePreset::Normal->components()
        );
    }
}
