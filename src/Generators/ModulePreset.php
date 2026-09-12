<?php

namespace Arsham\LaravelModular\Generators;

enum ModulePreset: string
{
    case Minimal = 'minimal';
    case Normal = 'normal';
    case All = 'all';

    public function description(): string
    {
        return match ($this) {
            self::Minimal => 'Minimal - model and request for a lightweight module',
            self::Normal => 'Normal - controller, request, model, service, and API routes',
            self::All => 'All - normal plus resources, policies, database, middleware, console, and tests',
        };
    }

    public function components(): array
    {
        return match ($this) {
            self::Minimal => ['models', 'requests'],
            self::Normal => ['controllers', 'requests', 'models', 'services', 'routes'],
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
