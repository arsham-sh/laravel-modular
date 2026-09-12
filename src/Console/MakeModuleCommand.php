<?php

namespace Arsham\LaravelModular\Console;

use Arsham\LaravelModular\Generators\ModuleComponents;
use Arsham\LaravelModular\Generators\ModuleGenerator;
use Arsham\LaravelModular\Generators\ModulePreset;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeModuleCommand extends Command
{
    protected $signature = 'module:make
        {name : The name of the module}
        {--preset= : Generation preset: minimal, normal, or all}
        {--components=* : Generate specific components instead of a preset}
        {--minimal : Backward-compatible alias for --preset=minimal}
        {--no-prompts : Skip interactive selection and use the normal preset}';

    protected $description = 'Create a Laravel module';

    public function handle(ModuleGenerator $generator): int
    {
        $components = $this->resolveComponents();
        if ($components === null) {
            return self::FAILURE;
        }

        try {
            $generator->generate($this->argument('name'), $components);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info('Module [' . Str::studly($this->argument('name')) . '] created successfully.');
        return self::SUCCESS;
    }

    private function resolveComponents(): ?array
    {
        $requested = $this->option('components');
        if ($requested !== []) {
            $requested = array_values(array_unique(array_filter(array_map(
                static fn (string $value): string => Str::kebab(trim($value)), $requested
            ))));
            return $this->validated($requested);
        }

        $preset = $this->option('minimal') ? ModulePreset::Minimal->value : $this->option('preset');
        if ($preset !== null) {
            return $this->preset($preset);
        }

        if ($this->option('no-prompts') || ! $this->input->isInteractive()) {
            return ModulePreset::Normal->components();
        }

        $selected = $this->choice(
            'How much should be generated?',
            ModulePreset::descriptions(),
            ModulePreset::Normal->value
        );

        return $this->preset($selected);
    }

    private function preset(string $value): ?array
    {
        $preset = ModulePreset::tryFrom(Str::lower(trim($value)));
        if ($preset === null) {
            $this->error("Unknown preset [{$value}]. Use minimal, normal, or all.");
            return null;
        }

        return $preset->components();
    }

    private function validated(array $components): ?array
    {
        $invalid = array_diff($components, array_keys(ModuleComponents::PATHS));
        if ($invalid !== []) {
            $this->error('Unknown component(s): ' . implode(', ', $invalid));
            $this->line('Available: ' . implode(', ', array_keys(ModuleComponents::PATHS)));
            return null;
        }

        return ModuleComponents::normalize($components);
    }
}
