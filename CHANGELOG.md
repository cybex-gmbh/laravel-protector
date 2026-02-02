# Changelog

All notable changes to `protector` will be documented in this file.

## v3.2.1 - 2026-02-02

- Laravel 9 tests have been removed due to vulnerabilities

## v3.2.0 - 2025-04-08

- Add Laravel 12 support
- All protector resources can now be published at once using `php artisan vendor:publish --tag=protector`
- The default config now allows setting most configuration values via the .env file
- The max packet length for MySQL is now included in the metadata at the end of a dump file

## v3.1.2 - 2024-12-18

- Fix an issue where dumping postgres databases failed. Configurations will now also be applied correctly to postgres database dumps

## v3.1.1 - 2024-12-11

- Fix an issue where the tablespaces configuration option was not applied
- Protector configuration options can now be enabled and disabled. For all options take a look at the [HasConfiguration file](src/Traits/HasConfiguration.php)

## v3.1.0 - 2024-09-09

- Add PostgreSQL support

## v3.0.0 - 2024-03-15

- Add Laravel 11 Support

## v2.0.0 - 2023-02-23

- Add Laravel 10 compatibility
- Add support for laravel/sanctum ^3.0
- Use Laravel's SchemaState for creating dumps

## v1.5.0 - 2022-09-06

- Real time output for database migrations
- Added unit tests
- Fix MariaDB support

## v1.4.1 - 2022-03-24

- Fixed RDS compatibility

## v1.3.0 - 2022-03-04

- Added migration option
- Feature/download chunking
- Streaming

## v1.1.0 - 2021-08-04

- Migrations now need to be explicitly published, as they are optional for some use cases and because they might need to be modified. Run `php artisan vendor:publish --tag=protector.migrations` to publish the protector migration to your `database/migrations` folder.
- Removed the Guzzle dependency, as it is already required by Laravel.

## v1.0.1 - 2021-08-02

- initial release
- sending and receiving database dumps
- user identification through tokens
- optional sodium x25519 encryption
