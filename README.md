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

Create a new module with:

```bash
php artisan module:make Auth
```

This generates the module structure inside the `Modules` directory:

```text
Modules/
└── Auth/
    ├── App/
    │   ├── Console/
    │   ├── Http/
    │   │   ├── Controllers/
    │   │   ├── Middleware/
    │   │   └── Requests/
    │   ├── Models/
    │   ├── Providers/
    │   └── Services/
    ├── Database/
    │   ├── Factories/
    │   ├── Migrations/
    │   └── Seeders/
    ├── Routes/
    │   └── api.php
    ├── Tests/
    │   ├── Feature/
    │   └── Unit/
    └── module.json
```

## Example

```bash
php artisan module:make User
```

The command creates:

```text
Modules/
└── User/
```

Each module can contain its own controllers, requests, models, services, database resources, routes, and tests.

## Philosophy

Laravel Modular is designed to keep modules organized and independent while staying close to Laravel's native structure.

It does not require a third-party modular architecture package.

## License

Laravel Modular is open-sourced software licensed under the [MIT license](LICENSE).
