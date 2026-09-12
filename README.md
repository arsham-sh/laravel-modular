# Laravel Modular

A lightweight Laravel module generator for building modular Laravel applications without third-party module packages.

## Requirements

* PHP 8.3+
* Laravel 13

## Installation

Install the package via Composer:

```bash
composer require arsham/laravel-modular
```

Laravel will automatically discover and register the package service provider.

## Usage

### Create a complete module

```bash
php artisan module:make Auth
```

By default this creates a useful, complete module instead of making you select a numbered list of folders. The generated module includes controllers, requests, models, services, migrations, factories, seeders, routes, and feature/unit tests.

During interactive use, the generator only asks about less-common additions such as middleware and a console directory:

```text
Creating a complete module by default. No component-number roulette required.

Add a middleware? (yes/no) [no]:
Add a console directory? (yes/no) [no]:
```

The result looks like:

```text
Modules/
└── Auth/
    ├── App/
    │   ├── Http/
    │   │   ├── Controllers/AuthController.php
    │   │   ├── Middleware/          # optional
    │   │   └── Requests/AuthRequest.php
    │   ├── Models/Auth.php
    │   ├── Providers/AuthServiceProvider.php
    │   └── Services/AuthService.php
    ├── Config/config.php
    ├── Database/
    │   ├── Factories/AuthFactory.php
    │   ├── Migrations/*_create_auths_table.php
    │   └── Seeders/AuthSeeder.php
    ├── Routes/api.php
    ├── Tests/
    │   ├── Feature/AuthTest.php
    │   └── Unit/AuthServiceTest.php
    └── module.json
```

### Create only the core module

For a smaller module, use:

```bash
php artisan module:make Auth --minimal
```

This creates controllers, requests, models, services, and routes, plus the module config and service provider.

You can also explicitly select components when scripting:

```bash
php artisan module:make Auth --components=controllers --components=models --components=routes
```

For CI or other non-interactive environments, the normal complete defaults are used automatically. Use `--no-prompts` to make that intent explicit.

### Generate a controller

Create a controller inside an existing module:

```bash
php artisan module:make-controller Auth User
```

This creates:

```text
Modules/Auth/App/Http/Controllers/UserController.php
```

For a resource-style controller:

```bash
php artisan module:make-controller Auth User --resource
```

The resource option creates `index`, `store`, `show`, `update`, and `destroy` methods.

## Philosophy

Laravel Modular keeps modules organized and independent while staying close to Laravel's native structure.

The generator favors sensible defaults over interactive component selection. Optional architecture should be requested explicitly, while the common module pieces are created automatically.

It does not require a third-party modular architecture package.

## License

Laravel Modular is open-sourced software licensed under the [MIT license](LICENSE).
