# Changelog

All notable changes to `laravel-protector` will be documented in this file.

## [v4.0.0 - 2026-XX-XX](https://github.com/cybex-gmbh/laravel-protector/compare/v3.2.1...v4.0.0)

> [!WARNING]
> Breaking changes!
>
> For more information, see the [upgrade guide](UPGRADE-GUIDE.md#v3-to-v4).

### General

System

- The minimum required PHP version is now 8.4.
- The minimum required Laravel version is now 12.1.1.
- Added MariaDB driver support
- Dropped official MySQL support

Configuration

- Config keys and `.env` keys have been renamed and restructured.
- Disk handling has been extended and is now configured via the `filesystems.php` config file.
- Restructured the `Protector` class by splitting it into `Protector` and `ProtectorConfig`. Configuration can no longer be accessed after the `Protector` instance has been
  created.
- Configured `Protector` instances are now created through the new `ProtectorConfigurator` class.
- The Protector now operates on two disk
    - local disk for temporary file handling, such as decryption or import
    - storage disk for storing dumps
    - by default, for all operations, the Protector will create copies on the local disk for processing, and delete it afterwards
- The Protector dump endpoint route name has been changed.
- To support config caching, .env key names can no longer be changed during runtime.

Commands

- Importing remote dumps using `protector:import` will now delete downloaded files after importing.
- Various command options have been removed or renamed
- Reformatted the output of the `protector:keys` and `protector:token` commands to easier spot relevant information.

API

- Some methods throw different or more detailed exceptions.
- Some method names and signatures have changed.
- Some methods have been removed.
- Dump metadata structure has changed from a flat array to a hierarchical structure grouped by [MetadataProviders](README.md#dump-metadata).
- Metadata files (`.meta`) are written alongside dumps and used by the interactive import, to avoid downloading database dump files just for metadata.
- The Protector will no longer allow creating dumps in directories.

### Features

- Added support for Laravel 13.
- Added MariaDB support via Laravel's `mariadb` driver and dedicated `MariaDbSchemaStateProxy`.
- The metadata which is appended at the end of a dump file can now be customized, see the [Dump Metadata README section](README.md#dump-metadata) for more information.
- More options can now be configured per Protector instance via `ProtectorConfigurator`, see the [ProtectorConfiguratorContract](src/Contracts/ProtectorConfiguratorContract.php)
  for all configuration options.
- The `Protector` now fully operates on Laravel disks, which can be configured separately.
  The `protector_local` disk is used for temporary files, while the `protector_storage` disk is used for storing dumps and metadata files.
- The `protector:import` command will now delete downloaded files after importing.
- A new `protector:download` command was added, which allows downloading dumps to a configured storage disk with optional import.
- The `protector:import`, `protector:export` and `protector:download` commands and their corresponding methods
  now support an optional `--disk` option to specify the disk to use for the operation.
- A new `protector:cleanup-storage` command was added, which will delete all files on the storage disk root, which have a corresponding `.meta` file.
- A new `protector:cleanup-local` command was added, which will delete all temporary files on the local disk root, which are older than 1 day.
    - New config options are available for cleanup, see the [Cleanup of Protector disks README section](README.md#cleanup-of-protector-disks) for more information.

### Fixes

- Fixed an issue where the Protector would not work when caching the config using `php artisan config:cache` or similar.
- Fixed an issue for MySQL where `CREATE DATABASE` statements were not included despite being enabled in the configuration.

### Development

- The dev image has been updated and Laravel Sail has been removed. Please check the [development section of the README](README.md#development) for usage.
- Running MySQL tests on the new image will no longer work, as the MySQL CLI command is only an alias to mariadb and does not fully support the MySQL server.

## [v3.2.1 - 2026-02-02](https://github.com/cybex-gmbh/laravel-protector/compare/v3.2.0...v3.2.1)

- Internal shell calls now use the same method as used for dumping databases
- An example app with the package installed has been added for easier testing

## [v3.2.0 - 2025-04-08](https://github.com/cybex-gmbh/laravel-protector/compare/v3.1.2...v3.2.0)

- Add Laravel 12 support
- All protector resources can now be published at once using `php artisan vendor:publish --tag=protector`
- The default config now allows setting most configuration values via the .env file
- The max packet length for MySQL is now included in the metadata at the end of a dump file

## [v3.1.2 - 2024-12-18](https://github.com/cybex-gmbh/laravel-protector/compare/v3.1.1...v3.1.2)

- Fix an issue where dumping postgres databases failed. Configurations will now also be applied correctly to postgres database dumps

## [v3.1.1 - 2024-12-11](https://github.com/cybex-gmbh/laravel-protector/compare/v3.1.0...v3.1.1)

- Fix an issue where the tablespaces configuration option was not applied
- Protector configuration options can now be enabled and disabled. For all options take a look at the [HasConfiguration file](src/Traits/HasConfiguration.php)

## [v3.1.0 - 2024-09-09](https://github.com/cybex-gmbh/laravel-protector/compare/v3.0.0...v3.1.0)

- Add PostgreSQL support

## [v3.0.0 - 2024-03-15](https://github.com/cybex-gmbh/laravel-protector/compare/v2.0.0...v3.0.0)

- Add Laravel 11 Support

## [v2.0.0 - 2023-02-23](https://github.com/cybex-gmbh/laravel-protector/compare/v1.5.0...v2.0.0)

- Add Laravel 10 compatibility
- Add support for laravel/sanctum ^3.0
- Use Laravel's SchemaState for creating dumps

## [v1.5.0 - 2022-09-06](https://github.com/cybex-gmbh/laravel-protector/compare/v1.4.1...v1.5.0)

- Real time output for database migrations
- Added unit tests
- Fix MariaDB support

## [v1.4.1 - 2022-03-24](https://github.com/cybex-gmbh/laravel-protector/compare/v1.3.0...v1.4.1)

- Fixed RDS compatibility

## [v1.3.0 - 2022-03-04](https://github.com/cybex-gmbh/laravel-protector/compare/v1.1.0...v1.3.0)

- Added migration option
- Feature/download chunking
- Streaming

## [v1.1.0 - 2021-08-04](https://github.com/cybex-gmbh/laravel-protector/compare/v1.0.1...v1.1.0)

- Migrations now need to be explicitly published, as they are optional for some use cases and because they might need to be modified. Run
  `php artisan vendor:publish --tag=protector.migrations` to publish the protector migration to your `database/migrations` folder.
- Removed the Guzzle dependency, as it is already required by Laravel.

## [v1.0.1 - 2021-08-02](https://github.com/cybex-gmbh/laravel-protector/compare/v1.0.0...v1.0.1)

- initial release
- sending and receiving database dumps
- user identification through tokens
- optional sodium x25519 encryption
