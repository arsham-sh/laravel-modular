# Laravel Modular

A lightweight Laravel module generator for building modular Laravel applications without third-party module packages.

## Requirements

* PHP 8.3+
* Laravel 13

## Installation

Install the package via Composer:

```bash
composer require arsham-sh/laravel-modular
```

Laravel will automatically discover and register the package service provider.

## Usage

### Create a module

```bash
php artisan module:make Auth
```

In interactive mode, the generator asks which preset to use:

```text
How much should be generated? [normal]:
  [minimal] Minimal - model and request for a lightweight module
  [normal] Normal - controller, request, model, service, and API routes
  [all] All - normal plus resources, policies, database, middleware, console, and tests
```

You can select a preset directly:

```bash
php artisan module:make Auth --preset=minimal
php artisan module:make Auth --preset=normal
php artisan module:make Auth --preset=all
```

`--minimal` is kept as a backward-compatible alias for `--preset=minimal`.

### Select components explicitly

For scripting or custom module layouts, generate specific components:

```bash
php artisan module:make Auth \
    --components=controllers \
    --components=models \
    --components=routes
```

Component dependencies are resolved automatically. For example, controllers pull in models, requests, and routes.

For CI or other non-interactive environments, use the normal preset automatically with:

```bash
php artisan module:make Auth --no-prompts
```

### Generate a controller

Create a controller inside an existing module:

```bash
php artisan module:make-controller Auth User
```

For a resource-style controller:

```bash
php artisan module:make-controller Auth User --resource
```

The resource option creates `index`, `store`, `show`, `update`, and `destroy` methods.

## Generated module structure

A normal module contains the common application pieces:

```text
Modules/
└── Auth/
    ├── App/
    │   ├── Http/
    │   │   ├── Controllers/AuthController.php
    │   │   └── Requests/AuthRequest.php
    │   ├── Models/Auth.php
    │   ├── Providers/AuthServiceProvider.php
    │   └── Services/AuthService.php
    ├── Config/config.php
    ├── Routes/api.php
    └── module.json
```

The `all` preset additionally generates resources, policies, database migrations/factories/seeders, middleware, a console command, and feature/unit tests.

## Development

Install development dependencies and run the package test suite with:

```bash
composer install
composer test
```

The tests cover preset definitions and component dependency resolution. Generated-module integration coverage is kept separate from the package's basic unit tests so the generator can be evolved without hiding filesystem/runtime failures.

## Philosophy

Laravel Modular keeps modules organized and independent while staying close to Laravel's native structure.

The generator favors sensible presets and explicit component selection instead of forcing users through a long component checklist.

It does not require a third-party modular architecture package.

## License

Laravel Modular is open-sourced software licensed under the [MIT license](LICENSE).
