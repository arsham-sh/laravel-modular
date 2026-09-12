<?php

namespace Arsham\LaravelModular\Generators;

final class ModuleComponents
{
    public const PATHS = [
        'controllers' => 'App/Http/Controllers',
        'requests' => 'App/Http/Requests',
        'models' => 'App/Models',
        'services' => 'App/Services',
        'resources' => 'App/Http/Resources',
        'policies' => 'App/Policies',
        'database' => 'Database',
        'routes' => 'Routes',
        'middleware' => 'App/Http/Middleware',
        'console' => 'App/Console',
        'feature-tests' => 'Tests/Feature',
        'unit-tests' => 'Tests/Unit',
    ];

    public static function normalize(array $components): array
    {
        $components = array_values(array_unique($components));
        if (in_array('controllers', $components, true)) {
            $components = array_merge($components, ['models', 'requests', 'routes']);
        }
        if (array_intersect(['resources', 'policies', 'database'], $components)) {
            $components[] = 'models';
        }
        if (in_array('feature-tests', $components, true)) {
            $components[] = 'routes';
        }
        if (in_array('unit-tests', $components, true)) {
            $components[] = 'services';
        }
        return array_values(array_unique($components));
    }

    public static function validate(array $components): ?array
    {
        $invalid = array_diff($components, array_keys(self::PATHS));
        return $invalid === [] ? self::normalize($components) : null;
    }
}
