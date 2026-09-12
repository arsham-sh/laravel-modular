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

    public function test_normal_preset_is_a_simple_database_backed_crud_module(): void
    {
        $this->assertSame(
            ['controllers', 'models', 'database', 'routes'],
            ModulePreset::Normal->components()
        );
    }

    public function test_advanced_preset_adds_validation_services_and_feature_tests(): void
    {
        $this->assertSame(
            [
                'controllers', 'requests', 'models', 'services', 'database', 'routes',
                'feature-tests',
            ],
            ModulePreset::Advanced->components()
        );
    }
}
