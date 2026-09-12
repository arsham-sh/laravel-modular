# Laravel Modular

A lightweight Laravel module generator for building modular Laravel applications without a third-party module package.

## Requirements

- PHP 8.3+
- Laravel 13

## Installation

Install the package via Composer:

```bash
composer require arsham-sh/laravel-modular
```

Laravel automatically discovers the package service provider.

## Usage

### Create a module

Run the command normally to choose the components interactively:

```bash
php artisan module:make Auth
```

The generator presents the available components and lets you select one or more:

```text
Which components should be generated?
  [0] controllers
  [1] requests
  [2] models
  [3] services
  [4] resources
  [5] policies
  [6] database
  [7] routes
  [8] middleware
  [9] console
  [10] feature-tests
  [11] unit-tests
  [12] traits
```

You can also use a preset when you already know what you want:

```bash
php artisan module:make Auth --preset=basic
php artisan module:make Auth --preset=normal
php artisan module:make Auth --preset=advanced
php artisan module:make Auth --preset=all
```

Available presets:

- `basic`: controllers, models, and API routes.
- `normal`: controllers, models, database, and API routes.
- `advanced`: normal plus form requests, services, and feature/unit tests.
- `all`: advanced plus resources, policies, middleware, console, and all supported components.

For non-interactive environments, use the normal preset explicitly:

```bash
php artisan module:make Auth --no-prompts
```

### Select components explicitly

For scripts or custom module layouts, generate specific components:

```bash
php artisan module:make Auth \
    --components=controllers \
    --components=models \
    --components=routes
```

Component dependencies are resolved automatically. For example, controllers require a model and routes, while services, resources, policies, and database generation also ensure the model is included.

### Generate a controller

Create a controller inside an existing module:

```bash
php artisan module:make-controller Auth User
```

For a resource-style controller:

```bash
php artisan module:make-controller Auth User --resource
```

## Generated module structure

A typical module contains only the components selected during generation:

```text
Modules/
└── Auth/
    ├── App/
    │   ├── Http/
    │   │   ├── Controllers/AuthController.php
    │   │   └── Requests/
    │   ├── Models/Auth.php
    │   ├── Providers/AuthServiceProvider.php
    │   └── Services/
    ├── Config/config.php
    ├── Routes/api.php
    └── module.json
```

Additional selections can generate resources, policies, migrations and factories, middleware, console commands, traits, and application-level feature/unit tests.

## Development

Install the package dependencies with:

```bash
composer install
```

The package itself does not require generated-module tests. Test components are optional and are created inside the consuming Laravel application only when selected.

## Philosophy

Laravel Modular keeps modules organized and independent while staying close to Laravel's native structure.

The generator favors interactive component selection, explicit presets, and predictable component dependencies instead of silently generating an arbitrary module layout.

It does not require a third-party modular architecture package.

## License

Laravel Modular is open-sourced software licensed under the [MIT license](LICENSE).
