<?php

namespace Arsham\LaravelModular\Generators;

enum ModulePreset: string
{
    case Basic = 'basic';
    case Normal = 'normal';
    case Advanced = 'advanced';
    case All = 'all';

    public function description(): string
    {
        return match ($this) {
            self::Basic => 'Basic - controller, provider, config, and API routes',
            self::Normal => 'Normal - database-backed CRUD with a simple controller',
            self::Advanced => 'Advanced - normal plus form requests, service, feature tests, and unit tests',
            self::All => 'All - advanced plus resources, policies, database, middleware, console, and tests',
        };
    }

    public function components(): array
    {
        return match ($this) {
            self::Basic => ['controllers', 'routes'],
            self::Normal => [
                'controllers', 'models', 'database', 'routes',
            ],
            self::Advanced => [
                'controllers', 'requests', 'models', 'services', 'database', 'routes',
                'feature-tests', 'unit-tests',
            ],
            self::All => [
                'controllers', 'requests', 'models', 'services', 'resources', 'policies',
                'database', 'routes', 'middleware', 'console', 'feature-tests', 'unit-tests',
            ],
        };
    }

    public static function descriptions(): array
    {
        return array_combine(
            array_map(static fn (self $preset): string => $preset->value, self::cases()),
            array_map(static fn (self $preset): string => $preset->description(), self::cases())
        );
    }
}
